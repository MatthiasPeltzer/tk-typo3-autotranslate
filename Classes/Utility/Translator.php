<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Utility;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use ThieleUndKlose\Autotranslate\Service\DeeplTranslationClient;
use ThieleUndKlose\Autotranslate\Service\DeeplTranslationClientInterface;
use ThieleUndKlose\Autotranslate\Service\HtmlAttributeProcessor;
use ThieleUndKlose\Autotranslate\Service\L10nStateBuilder;
use ThieleUndKlose\Autotranslate\Service\TranslationScopeResolver;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class Translator implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const AUTOTRANSLATE_LAST = 'autotranslate_last';
    public const AUTOTRANSLATE_EXCLUDE = 'autotranslate_exclude';
    public const AUTOTRANSLATE_LANGUAGES = 'autotranslate_languages';

    public const TRANSLATE_MODE_BOTH = 'create_update';
    public const TRANSLATE_MODE_UPDATE_ONLY = 'update_only';
    public const TRANSLATE_MODE_CREATE_ONLY = 'create_only';

    /** @var list<string> Fields ignored when detecting changes on record update */
    private const CHANGE_DETECTION_IGNORE_FIELDS = [
        'pid',
        'sorting',
        'crdate',
        'tstamp',
        'cruser_id',
        'deleted',
        'hidden',
        'starttime',
        'endtime',
        'fe_group',
        'sys_language_uid',
        'l10n_parent',
        'l10n_diffsource',
        'l10n_state',
        't3ver_oid',
        't3ver_id',
        't3ver_wsid',
        't3ver_label',
        't3ver_state',
        't3ver_stage',
        't3ver_count',
        't3ver_tstamp',
        't3ver_move_id',
        self::AUTOTRANSLATE_LAST,
        self::AUTOTRANSLATE_EXCLUDE,
        self::AUTOTRANSLATE_LANGUAGES,
        SourceHashUtility::FIELD_NAME,
    ];

    private readonly ?string $apiKey;
    private readonly array $siteLanguages;
    private readonly string $deeplFormality;
    private readonly DeeplTranslationClientInterface $deeplClient;
    private readonly HtmlAttributeProcessor $htmlProcessor;
    private readonly L10nStateBuilder $l10nStateBuilder;
    private readonly TranslationScopeResolver $scopeResolver;

    public function __construct(private readonly int $pageId)
    {
        ['key' => $this->apiKey] = TranslationHelper::apiKey($this->pageId);
        $siteConfiguration = TranslationHelper::siteConfigurationValue($this->pageId);
        $this->siteLanguages = TranslationHelper::siteConfigurationValue($this->pageId, ['languages']) ?? [];
        $this->deeplFormality = is_array($siteConfiguration) ? (string)($siteConfiguration['autotranslateDeeplFormality'] ?? 'default') : 'default';
        $this->deeplClient = GeneralUtility::makeInstance(DeeplTranslationClient::class);
        $this->htmlProcessor = GeneralUtility::makeInstance(HtmlAttributeProcessor::class);
        $this->l10nStateBuilder = GeneralUtility::makeInstance(L10nStateBuilder::class);
        $this->scopeResolver = GeneralUtility::makeInstance(TranslationScopeResolver::class);
    }

    /**
     * Translate the loaded record to target languages
     *
     * @throws \RuntimeException If DeepL API key is invalid or has no characters left
     */
    public function translate(
        string $table,
        int $recordUid,
        ?DataHandler $parentObject = null,
        ?string $languagesToTranslate = null,
        string $translateMode = self::TRANSLATE_MODE_BOTH,
        ?string $datamapStatus = null,
        ?array $changedFields = null,
    ): void {
        $record = Records::getRecord($table, $recordUid);

        if ($record === null) {
            LogUtility::log($this->logger, 'Record {table}:{uid} not found, skipping translation.', [
                'table' => $table,
                'uid' => $recordUid,
            ], LogUtility::MESSAGE_WARNING);
            return;
        }

        // Exit if record is a localized one
        $parentField = TranslationHelper::translationOrigPointerField($table);
        if ($parentField === null || (int)($record[$parentField] ?? 0) > 0) {
            return;
        }

        // Exit if record is marked for exclude
        if ((int)($record[self::AUTOTRANSLATE_EXCLUDE] ?? 0) === 1) {
            return;
        }

        // Load translation columns for table
        $columns = TranslationHelper::translationTextfields($this->pageId, $table);
        if ($columns === null || $columns === []) {
            LogUtility::log($this->logger, 'No text fields configured for table {table} on page {pageId}. Check site configuration.', [
                'table' => $table,
                'pageId' => $this->pageId,
            ], LogUtility::MESSAGE_WARNING);
            return;
        }

        $singleTargetLanguageId = $this->scopeResolver->resolveSingleTargetLanguageId($languagesToTranslate);

        $columnsToTranslate = $this->scopeResolver->resolveColumnsToTranslate(
            $columns,
            $datamapStatus,
            $changedFields,
            $record,
            $singleTargetLanguageId,
            $table,
            $recordUid
        );
        $needsReferenceTranslation = $columnsToTranslate === []
            && $this->recordsNeedReferenceTranslation($table, $recordUid, $datamapStatus, $changedFields, $singleTargetLanguageId);

        if ($columnsToTranslate === [] && !$needsReferenceTranslation) {
            LogUtility::log($this->logger, 'No changed translatable fields for {table}:{uid}, skipping translation.', [
                'table' => $table,
                'uid' => $recordUid,
            ]);
            return;
        }

        $this->deeplClient->assertApiKeyUsable($this->apiKey);

        // Set target languages by record if null is given
        if ($languagesToTranslate === null) {
            $languagesToTranslate = $record[self::AUTOTRANSLATE_LANGUAGES] ?? '';
        }
        
        // Fall back to site configuration default if record has no languages set
        if ($languagesToTranslate === '' || $languagesToTranslate === null) {
            $siteConfig = TranslationHelper::siteConfigurationValue($this->pageId);
            if (is_array($siteConfig)) {
                $settings = TranslationHelper::translationSettingsDefaults($siteConfig, $table);
                $languagesToTranslate = $settings['autotranslateLanguages'] ?? '';
            }
        }

        $localizedContents = [];
        $languageIds = GeneralUtility::trimExplode(',', $languagesToTranslate, true);

        if ($languageIds === []) {
            LogUtility::log($this->logger, 'No target languages set for record {table}:{uid}.', [
                'table' => $table,
                'uid' => $recordUid,
            ], LogUtility::MESSAGE_WARNING);
            return;
        }

        $didTranslate = false;
        $translatedFieldNames = [];

        foreach ($languageIds as $languageId) {
            $localizedContents[$languageId] = [];

            // Skip translation if language matches original record
            if ((int)$languageId === (int)$record['sys_language_uid']) {
                continue;
            }

            $existingTranslation = Records::getRecordTranslation($table, $recordUid, (int)$languageId);

            if ($translateMode === self::TRANSLATE_MODE_UPDATE_ONLY && !$existingTranslation) {
                LogUtility::log($this->logger, 'No Translation of {table} with uid {uid} because mode "update only".', [
                    'table' => $table,
                    'uid' => $recordUid,
                ]);
                continue;
            }

            if ($translateMode === self::TRANSLATE_MODE_CREATE_ONLY && $existingTranslation) {
                LogUtility::log($this->logger, 'Skipping {table} with uid {uid} because mode "create only" and translation already exists.', [
                    'table' => $table,
                    'uid' => $recordUid,
                ]);
                continue;
            }

            if (!$existingTranslation) {
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $dataHandler->start([], []);
                $localizedUid = $dataHandler->localize($table, $recordUid, (int)$languageId);
            } else {
                $localizedUid = (int)$existingTranslation['uid'];
            }

            if (empty($localizedUid)) {
                LogUtility::log($this->logger, 'No Translation of {table} with uid {uid} because DataHandler localize failed.', [
                    'table' => $table,
                    'uid' => $recordUid,
                ]);
                continue;
            }

            $columnsForLanguage = $this->scopeResolver->resolveColumnsForRecord(
                $columns,
                $existingTranslation,
                $datamapStatus,
                $changedFields,
                $record,
                $singleTargetLanguageId ?? (int)$languageId
            );

            $translateReferences = $this->shouldTranslateReferences(
                $table,
                $recordUid,
                $datamapStatus,
                $changedFields,
                (bool)$existingTranslation,
                $singleTargetLanguageId ?? (int)$languageId
            );

            if ($columnsForLanguage === [] && !$translateReferences) {
                continue;
            }

            $localizedContents[$languageId][$recordUid] = $localizedUid;
            $referenceTables = TranslationHelper::additionalReferenceTables();

            if ($translateReferences) {
                foreach ($referenceTables as $referenceTable) {
                    $columnsReference = TranslationHelper::translationTextfields($this->pageId, $referenceTable);
                    $autotranslateReferences = TranslationHelper::translationReferenceColumns($this->pageId, $table, $referenceTable);

                    if (empty($autotranslateReferences)) {
                        continue;
                    }

                    foreach ($autotranslateReferences as $referenceColumn) {
                        $foreignField = $this->getReferenceForeignField($table, $referenceColumn);
                        if ($foreignField === null) {
                            continue;
                        }

                        $references = $this->findReferenceUids($table, $recordUid, $referenceTable, $referenceColumn, $foreignField);
                        if ($references === null || empty($references) || empty($columnsReference)) {
                            continue;
                        }

                        foreach ($references as $referenceUid) {
                            $referenceUid = (int)$referenceUid;
                            $referenceTranslatedFields = $this->translateReferenceChild(
                                $table,
                                $recordUid,
                                (int)$localizedUid,
                                $referenceTable,
                                $referenceColumn,
                                $referenceUid,
                                (int)$languageId,
                                (string)$foreignField,
                                $columnsReference,
                                $parentObject,
                                $datamapStatus,
                                $changedFields,
                                $translateMode
                            );
                            if ($referenceTranslatedFields !== []) {
                                $didTranslate = true;
                            }
                        }
                    }
                }
            }

            if ($columnsForLanguage !== []) {
                $translatedColumns = $this->translateRecordProperties($record, (int)$languageId, $columnsForLanguage, $table, $localizedUid);

                if (count($translatedColumns) > 0) {
                    Records::updateRecord($table, $localizedUid, $translatedColumns);
                    $didTranslate = true;
                    $translatedFieldNames = array_merge(
                        $translatedFieldNames,
                        array_values(array_intersect(array_keys($translatedColumns), $columns))
                    );
                }
            }

            if (!$existingTranslation) {
                $this->generateSlugs($table, $localizedUid);
            }
        }

        if ($didTranslate) {
            $this->persistSourceFieldHashes($table, $recordUid, $record, $columns, $translatedFieldNames);
            Records::updateRecord($table, $recordUid, [
                self::AUTOTRANSLATE_LAST => time(),
            ]);
        }
    }

    /**
     * Translate a reference record saved directly (e.g. sys_file_reference alt/title edit).
     *
     * @throws \RuntimeException If DeepL API key is invalid or has no characters left
     */
    public function translateReferenceRecord(
        string $referenceTable,
        int $referenceUid,
        ?DataHandler $parentObject = null,
        ?string $datamapStatus = null,
        ?array $changedFields = null,
        string $translateMode = self::TRANSLATE_MODE_BOTH,
    ): void {
        $record = Records::getRecord($referenceTable, $referenceUid);
        if ($record === null) {
            return;
        }

        $parentField = TranslationHelper::translationOrigPointerField($referenceTable);
        if ($parentField === null || (int)($record[$parentField] ?? 0) > 0) {
            return;
        }

        if ((int)($record['sys_language_uid'] ?? 0) > 0) {
            return;
        }

        if ((int)($record[self::AUTOTRANSLATE_EXCLUDE] ?? 0) === 1) {
            return;
        }

        $parentInfo = TranslationHelper::resolveReferenceParent($referenceTable, $record);
        if ($parentInfo === null) {
            return;
        }

        $parentTable = $parentInfo['table'];
        $parentUid = $parentInfo['uid'];
        $referenceColumn = $parentInfo['column'];

        if (!TranslationHelper::isReferenceAutotranslateEnabled(
            $this->pageId,
            $parentTable,
            $referenceTable,
            $referenceColumn
        )) {
            return;
        }

        $parentRecord = Records::getRecord($parentTable, $parentUid);
        if ($parentRecord === null) {
            return;
        }

        $parentOrigField = TranslationHelper::translationOrigPointerField($parentTable);
        if ($parentOrigField !== null && (int)($parentRecord[$parentOrigField] ?? 0) > 0) {
            return;
        }

        if ((int)($parentRecord[self::AUTOTRANSLATE_EXCLUDE] ?? 0) === 1) {
            return;
        }

        $columnsReference = TranslationHelper::translationTextfields($this->pageId, $referenceTable);
        if ($columnsReference === null || $columnsReference === []) {
            return;
        }

        $columnsToTranslate = $this->scopeResolver->resolveColumnsToTranslate(
            $columnsReference,
            $datamapStatus,
            $changedFields,
            $record
        );
        if ($columnsToTranslate === []) {
            LogUtility::log($this->logger, 'No changed translatable fields for {referenceTable}:{uid}, skipping translation.', [
                'referenceTable' => $referenceTable,
                'uid' => $referenceUid,
            ]);
            return;
        }

        $foreignField = $this->getReferenceForeignField($parentTable, $referenceColumn);
        if ($foreignField === null) {
            return;
        }

        $this->deeplClient->assertApiKeyUsable($this->apiKey);

        $languagesToTranslate = $parentRecord[self::AUTOTRANSLATE_LANGUAGES] ?? '';
        if ($languagesToTranslate === '') {
            $siteConfig = TranslationHelper::siteConfigurationValue($this->pageId);
            if (is_array($siteConfig)) {
                $settings = TranslationHelper::translationSettingsDefaults($siteConfig, $parentTable);
                $languagesToTranslate = $settings['autotranslateLanguages'] ?? '';
            }
        }

        $languageIds = GeneralUtility::trimExplode(',', $languagesToTranslate, true);
        if ($languageIds === []) {
            return;
        }

        $didTranslate = false;
        $translatedFieldNames = [];

        foreach ($languageIds as $languageId) {
            if ((int)$languageId === (int)($parentRecord['sys_language_uid'] ?? 0)) {
                continue;
            }

            $localizedParent = Records::getRecordTranslation($parentTable, $parentUid, (int)$languageId);
            if ($localizedParent === null) {
                LogUtility::log($this->logger, 'Skipping {referenceTable}:{uid} for language {languageId}: parent {parentTable}:{parentUid} has no translation.', [
                    'referenceTable' => $referenceTable,
                    'uid' => $referenceUid,
                    'languageId' => $languageId,
                    'parentTable' => $parentTable,
                    'parentUid' => $parentUid,
                ]);
                continue;
            }

            $translatedFields = $this->translateReferenceChild(
                $parentTable,
                $parentUid,
                (int)$localizedParent['uid'],
                $referenceTable,
                $referenceColumn,
                $referenceUid,
                (int)$languageId,
                $foreignField,
                $columnsReference,
                $parentObject,
                $datamapStatus,
                $changedFields,
                $translateMode
            );
            if ($translatedFields !== []) {
                $didTranslate = true;
                $translatedFieldNames = array_merge($translatedFieldNames, $translatedFields);
            }
        }

        if ($didTranslate) {
            $this->persistSourceFieldHashes($referenceTable, $referenceUid, $record, $columnsReference, $translatedFieldNames);
            Records::updateRecord($referenceTable, $referenceUid, [
                self::AUTOTRANSLATE_LAST => time(),
            ]);
        }
    }

    /**
     * @param list<string> $columnsReference
     * @return list<string> Translated field names, empty when nothing was translated
     */
    private function translateReferenceChild(
        string $parentTable,
        int $parentUid,
        int $localizedParentUid,
        string $referenceTable,
        string $referenceColumn,
        int $referenceUid,
        int $languageId,
        string $foreignField,
        array $columnsReference,
        ?DataHandler $parentObject,
        ?string $datamapStatus,
        ?array $changedFields,
        string $translateMode
    ): array {
        $referenceTranslation = Records::getRecordTranslation($referenceTable, $referenceUid, $languageId);

        if ($translateMode === self::TRANSLATE_MODE_UPDATE_ONLY && empty($referenceTranslation)) {
            LogUtility::log($this->logger, 'No {referenceTable} {referenceUid} Translation of {parentTable} with uid {uid} because mode "update only".', [
                'referenceTable' => $referenceTable,
                'parentTable' => $parentTable,
                'uid' => $parentUid,
                'referenceUid' => $referenceUid,
            ]);
            return [];
        }

        if ($translateMode === self::TRANSLATE_MODE_CREATE_ONLY && !empty($referenceTranslation)) {
            LogUtility::log($this->logger, 'Skipping {referenceTable} {referenceUid} of {parentTable} with uid {uid} because mode "create only" and translation already exists.', [
                'referenceTable' => $referenceTable,
                'parentTable' => $parentTable,
                'uid' => $parentUid,
                'referenceUid' => $referenceUid,
            ]);
            return [];
        }

        if (empty($referenceTranslation)) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], []);
            $translatedReferenceUid = (int)$dataHandler->localize($referenceTable, $referenceUid, $languageId);

            Records::updateRecord(
                $referenceTable,
                $translatedReferenceUid,
                [
                    $foreignField => $localizedParentUid,
                ]
            );
        } else {
            $translatedReferenceUid = (int)$referenceTranslation['uid'];
        }

        if ($translatedReferenceUid <= 0) {
            return [];
        }

        $recordReference = ($parentObject !== null && isset($parentObject->datamap[$referenceTable][$referenceUid]))
            ? $parentObject->datamap[$referenceTable][$referenceUid]
            : Records::getRecord($referenceTable, $referenceUid);

        if ($recordReference === null) {
            return [];
        }

        $referenceChangedFields = ($parentObject !== null && isset($parentObject->datamap[$referenceTable][$referenceUid]))
            ? $parentObject->datamap[$referenceTable][$referenceUid]
            : null;

        $effectiveStatus = $datamapStatus;
        $effectiveChangedFields = $referenceChangedFields;
        if (
            $referenceChangedFields === null
            && $datamapStatus === 'update'
            && $this->scopeResolver->isTranslateChangedFieldsOnlyEnabled()
        ) {
            $effectiveStatus = null;
        }

        $columnsForReference = $this->scopeResolver->resolveColumnsForRecord(
            $columnsReference,
            $referenceTranslation ?: null,
            $effectiveStatus,
            $effectiveChangedFields,
            $recordReference,
            $languageId
        );
        if ($columnsForReference === []) {
            return [];
        }

        $translatedColumns = $this->translateRecordProperties(
            $recordReference,
            $languageId,
            $columnsForReference,
            $referenceTable,
            $translatedReferenceUid
        );
        if ($translatedColumns === []) {
            return [];
        }

        Records::updateRecord($referenceTable, $translatedReferenceUid, $translatedColumns);

        return array_values(array_intersect(array_keys($translatedColumns), $columnsReference));
    }

    private function getReferenceForeignField(string $parentTable, string $referenceColumn): ?string
    {
        $config = $GLOBALS['TCA'][$parentTable]['columns'][$referenceColumn]['config'] ?? null;
        if (!is_array($config)) {
            return null;
        }

        $foreignField = (string)($config['foreign_field'] ?? '');

        return $foreignField !== '' ? $foreignField : null;
    }

    private function shouldTranslateReferences(
        string $table,
        int $recordUid,
        ?string $datamapStatus,
        ?array $changedFields,
        bool $existingTranslation,
        ?int $singleTargetLanguageId = null
    ): bool {
        if (!$existingTranslation) {
            return true;
        }

        if (!$this->scopeResolver->isTranslateChangedFieldsOnlyEnabled()) {
            return true;
        }

        if ($datamapStatus === 'update' && $changedFields !== null) {
            $changedFieldNames = array_diff(array_keys($changedFields), self::CHANGE_DETECTION_IGNORE_FIELDS);
            $referenceColumns = [];

            foreach (TranslationHelper::additionalReferenceTables() as $referenceTable) {
                $columns = TranslationHelper::translationReferenceColumns($this->pageId, $table, $referenceTable);
                if ($columns !== null) {
                    $referenceColumns = array_merge($referenceColumns, $columns);
                }
            }

            return array_intersect($referenceColumns, $changedFieldNames) !== [];
        }

        if ($datamapStatus === null && $changedFields === null) {
            return $this->referenceRecordsNeedTranslation($table, $recordUid, $singleTargetLanguageId);
        }

        return true;
    }

    private function recordsNeedReferenceTranslation(
        string $table,
        int $recordUid,
        ?string $datamapStatus,
        ?array $changedFields,
        ?int $singleTargetLanguageId
    ): bool {
        if ($datamapStatus === 'update' && $changedFields !== null) {
            return $this->shouldTranslateReferences($table, $recordUid, $datamapStatus, $changedFields, true, $singleTargetLanguageId);
        }

        if ($datamapStatus === null && $changedFields === null) {
            return $this->referenceRecordsNeedTranslation($table, $recordUid, $singleTargetLanguageId);
        }

        return false;
    }

    private function referenceRecordsNeedTranslation(string $table, int $recordUid, ?int $singleTargetLanguageId): bool
    {
        foreach (TranslationHelper::additionalReferenceTables() as $referenceTable) {
            $columnsReference = TranslationHelper::translationTextfields($this->pageId, $referenceTable);
            if ($columnsReference === null || $columnsReference === []) {
                continue;
            }

            $autotranslateReferences = TranslationHelper::translationReferenceColumns($this->pageId, $table, $referenceTable);
            if (empty($autotranslateReferences)) {
                continue;
            }

            foreach ($autotranslateReferences as $referenceColumn) {
                $foreignField = $this->getReferenceForeignField($table, $referenceColumn);
                if ($foreignField === null) {
                    continue;
                }

                $references = $this->findReferenceUids($table, $recordUid, $referenceTable, $referenceColumn, $foreignField);
                if ($references === null || $references === []) {
                    continue;
                }

                foreach ($references as $referenceUid) {
                    $refRecord = Records::getRecord($referenceTable, (int)$referenceUid);
                    if ($refRecord === null) {
                        continue;
                    }

                    if ($singleTargetLanguageId !== null) {
                        $refTranslation = Records::getRecordTranslation($referenceTable, (int)$referenceUid, $singleTargetLanguageId);
                        if ($refTranslation === null) {
                            return true;
                        }
                    }

                    if (SourceHashUtility::resolveChangedColumns($refRecord, $columnsReference) !== []) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @return list<int>|null
     */
    private function findReferenceUids(
        string $table,
        int $recordUid,
        string $referenceTable,
        string $referenceColumn,
        string $foreignField
    ): ?array {
        $type = $GLOBALS['TCA'][$table]['columns'][$referenceColumn]['config']['type'] ?? null;

        $references = match ($type) {
            'file' => Records::getRecords($referenceTable, 'uid', static function (QueryBuilder $queryBuilder) use ($foreignField, $recordUid, $table, $referenceColumn): void {
                $queryBuilder->where(
                    $queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter($table)),
                    $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($referenceColumn))
                );
            }),
            'inline' => Records::getRecords($referenceTable, 'uid', static function (QueryBuilder $queryBuilder) use ($foreignField, $recordUid, $referenceTable, $referenceColumn): void {
                $queryBuilder->where(
                    $queryBuilder->expr()->eq($foreignField, $queryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                );
                if (isset($GLOBALS['TCA'][$referenceTable]['columns']['fieldname'])) {
                    $queryBuilder->andWhere(
                        $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter($referenceColumn))
                    );
                }
            }),
            default => null,
        };

        if ($references === null) {
            LogUtility::log($this->logger, 'Unsupported reference type {type} for column {referenceColumn} in table {table}.', [
                'type' => $type,
                'referenceColumn' => $referenceColumn,
                'table' => $table,
            ], LogUtility::MESSAGE_WARNING);

            return null;
        }

        return array_map(static fn($uid): int => (int)$uid, $references);
    }

    /**
     * @param list<string> $configuredColumns
     * @param list<string> $translatedFieldNames
     */
    private function persistSourceFieldHashes(
        string $table,
        int $recordUid,
        array $record,
        array $configuredColumns,
        array $translatedFieldNames
    ): void {
        $translatedFieldNames = array_values(array_unique(array_intersect($translatedFieldNames, $configuredColumns)));
        if ($translatedFieldNames === []) {
            return;
        }

        try {
            Records::updateRecord($table, $recordUid, [
                SourceHashUtility::FIELD_NAME => SourceHashUtility::mergeHashesForTranslatedFields(
                    $record,
                    $configuredColumns,
                    $translatedFieldNames
                ),
            ]);
        } catch (\JsonException $exception) {
            LogUtility::log($this->logger, 'Failed to persist source field hashes for {table}:{uid}: {error}', [
                'table' => $table,
                'uid' => $recordUid,
                'error' => $exception->getMessage(),
            ], LogUtility::MESSAGE_ERROR);
        }
    }

    /**
     * Translate the given record properties
     */
    public function translateRecordProperties(array $record, int $targetLanguageUid, array $columns, string $table, int $localizedUid): array
    {
        $translatedColumns = [];

        try {
            $toTranslateObject = array_intersect_key($record, array_flip($columns));
            $toTranslate = array_filter($toTranslateObject, static fn($value) => is_string($value) && $value !== '');
            $deeplSourceLang = $this->deeplSourceLanguage();
            $deeplTargetLang = $this->deeplTargetLanguage($targetLanguageUid);
            $result = null;
            $glossary = null;

            if ($deeplTargetLang === null) {
                throw new \RuntimeException(
                    'No DeepL target language configured for language uid ' . $targetLanguageUid
                    . '. Please set "deeplTargetLang" in Site Configuration → Languages for this language.'
                );
            }

            if (count($toTranslate) > 0) {
                $toTranslate = $this->htmlProcessor->extractAttributes($toTranslate);

                // Get optional glossary handled by 3rd party extension
                $glossary = $this->deeplClient->resolveGlossary($deeplSourceLang, $deeplTargetLang, $this->pageId, $this->apiKey);

                // FlexForm is never translated as raw text (that would corrupt plugin
                // settings and bypass TYPO3's native FlexForm localization). It is carried
                // verbatim into the translation via the "fieldsToCopy" extension setting.
                unset($toTranslate['pi_flexform']);

                $result = empty($toTranslate) ? [] : $this->deeplClient->translateTexts($record, $table, $toTranslate, $deeplSourceLang, $deeplTargetLang, $this->deeplFormality, $glossary, $this->apiKey);
            }

            $keys = array_keys($toTranslate);
            if (!empty($result)) {
                $translatedAttributes = [];
                foreach ($result as $k => $v) {
                    if ($v === null) {
                        continue;
                    }

                    $field = $keys[$k];
                    if (str_starts_with($field, '__ATTR__')) {
                        $translatedAttributes[$field] = $v->text;
                    }
                }

                foreach ($result as $k => $v) {
                    if ($v === null) {
                        continue;
                    }

                    $field = $keys[$k];
                    if (str_starts_with($field, '__ATTR__')) {
                        continue;
                    }
                    $translatedValue = $this->htmlProcessor->restoreAttributes($v->text, $translatedAttributes);
                    $translatedColumns[$field] = $translatedValue;
                }
            }

            // Add fields to copy in translation from extension configuration
            $fieldsToCopy = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('autotranslate', 'fieldsToCopy');
            $fields = $fieldsToCopy ? GeneralUtility::trimExplode(',', $fieldsToCopy, true) : [];
            foreach ($record as $field => $value) {
                if (isset($record[$field]) && !isset($translatedColumns[$field]) && in_array($field, $fields, true)) {
                    $translatedColumns[$field] = $value;
                }
            }

            if (!empty($translatedColumns)) {
                $translatedColumns['l10n_state'] = $this->l10nStateBuilder->build(
                    $table,
                    $targetLanguageUid,
                    array_keys($translatedColumns),
                    $localizedUid,
                    (int)($record['uid'] ?? 0)
                );
            }

            // Set date and time of translation
            $translatedColumns[self::AUTOTRANSLATE_LAST] = time();

            LogUtility::log($this->logger, 'Successful translated to target language {deeplTargetLang}.', [
                'deeplTargetLang' => $deeplTargetLang,
                'fieldCount' => count($translatedColumns),
                'fields' => array_keys($translatedColumns),
            ]);
        } catch (\Exception $e) {
            LogUtility::log($this->logger, 'Translation Error: {error}.', ['error' => $e->getMessage()], LogUtility::MESSAGE_ERROR);
            throw $e;
        }

        return $translatedColumns;
    }

    private function deeplSourceLanguage(): ?string
    {
        foreach ($this->siteLanguages as $language) {
            if ($language['languageId'] === 0) {
                return empty($language['deeplSourceLang']) ? null : $language['deeplSourceLang'];
            }
        }

        return null;
    }

    private function deeplTargetLanguage(int $languageId): ?string
    {
        foreach ($this->siteLanguages as $language) {
            if ($language['languageId'] === $languageId) {
                return $language['deeplTargetLang'] ?? null;
            }
        }

        return null;
    }

    /**
     * Generate slugs for a translated record
     */
    private function generateSlugs(string $table, int $uid): void
    {
        $slugFields = SlugUtility::slugFields($table);
        if (empty($slugFields)) {
            return;
        }

        $record = Records::getRecord($table, $uid);
        $fieldsToUpdate = [];

        foreach (array_keys($slugFields) as $field) {
            $slug = SlugUtility::generateSlug($record, $table, $field);
            if ($slug !== null) {
                $fieldsToUpdate[$field] = $slug;
            }
        }

        if (!empty($fieldsToUpdate)) {
            Records::updateRecord($table, $uid, $fieldsToUpdate);
        }
    }
}
