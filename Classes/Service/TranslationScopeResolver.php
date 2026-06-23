<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Service;

use ThieleUndKlose\Autotranslate\Utility\Records;
use ThieleUndKlose\Autotranslate\Utility\SourceHashUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves which configured columns actually need translation for a given save
 * and target language, honouring the "translate changed fields only" setting and
 * the l10n_state = custom flag.
 *
 * Stateless and side-effect free apart from read-only DB/config lookups.
 */
class TranslationScopeResolver implements SingletonInterface
{
    /**
     * Fields ignored when detecting changes on a record update.
     *
     * Canonical list, also consumed by {@see \ThieleUndKlose\Autotranslate\Utility\Translator}.
     *
     * @var list<string>
     */
    public const CHANGE_DETECTION_IGNORE_FIELDS = [
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
        'autotranslate_last',
        'autotranslate_exclude',
        'autotranslate_languages',
        'autotranslate_source_hash',
    ];

    public function resolveSingleTargetLanguageId(?string $languagesToTranslate): ?int
    {
        if ($languagesToTranslate === null || $languagesToTranslate === '') {
            return null;
        }

        if (str_contains($languagesToTranslate, ',')) {
            return null;
        }

        return (int)$languagesToTranslate;
    }

    public function isTranslateChangedFieldsOnlyEnabled(): bool
    {
        try {
            $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('autotranslate');
        } catch (\Throwable) {
            return false;
        }

        return (bool)($extensionConfiguration['translateChangedFieldsOnly'] ?? true);
    }

    /**
     * @param list<string> $configuredColumns
     * @param array<string, mixed>|null $existingTranslation
     * @param array<string, mixed>|null $changedFields
     * @param array<string, mixed>|null $sourceRecord
     * @return list<string>
     */
    public function resolveColumnsForRecord(
        array $configuredColumns,
        ?array $existingTranslation,
        ?string $datamapStatus,
        ?array $changedFields,
        ?array $sourceRecord = null,
        ?int $singleTargetLanguageId = null,
    ): array {
        $columns = $existingTranslation === null
            ? $configuredColumns
            : $this->resolveColumnsToTranslate(
                $configuredColumns,
                $datamapStatus,
                $changedFields,
                $sourceRecord,
                $singleTargetLanguageId
            );

        return $this->filterColumnsRespectingCustomTranslation(
            $columns,
            $existingTranslation,
            $changedFields,
            $datamapStatus
        );
    }

    /**
     * @param list<string> $configuredColumns
     * @param array<string, mixed>|null $changedFields
     * @param array<string, mixed>|null $sourceRecord
     * @return list<string>
     */
    public function resolveColumnsToTranslate(
        array $configuredColumns,
        ?string $datamapStatus,
        ?array $changedFields,
        ?array $sourceRecord = null,
        ?int $singleTargetLanguageId = null,
        ?string $table = null,
        int $recordUid = 0,
    ): array {
        if (!$this->isTranslateChangedFieldsOnlyEnabled()) {
            return $configuredColumns;
        }

        if ($datamapStatus === 'new') {
            return $configuredColumns;
        }

        if ($datamapStatus === 'update' && $changedFields !== null) {
            $changedFieldNames = array_diff(array_keys($changedFields), self::CHANGE_DETECTION_IGNORE_FIELDS);

            return array_values(array_intersect($configuredColumns, $changedFieldNames));
        }

        if ($datamapStatus === null && $changedFields === null && $sourceRecord !== null) {
            if (
                $singleTargetLanguageId !== null
                && $table !== null
                && $recordUid > 0
                && Records::getRecordTranslation($table, $recordUid, $singleTargetLanguageId) === null
            ) {
                return $configuredColumns;
            }

            return SourceHashUtility::resolveChangedColumns($sourceRecord, $configuredColumns);
        }

        return $configuredColumns;
    }

    /**
     * Skip fields marked custom in l10n_state unless the source field changed in this save.
     *
     * @param list<string> $columns
     * @param array<string, mixed>|null $existingTranslation
     * @param array<string, mixed>|null $changedFields
     * @return list<string>
     */
    private function filterColumnsRespectingCustomTranslation(
        array $columns,
        ?array $existingTranslation,
        ?array $changedFields,
        ?string $datamapStatus
    ): array {
        if ($existingTranslation === null || empty($existingTranslation['l10n_state'])) {
            return $columns;
        }

        $l10nState = json_decode((string)$existingTranslation['l10n_state'], true);
        if (!is_array($l10nState) || $l10nState === []) {
            return $columns;
        }

        $sourceChangedFields = null;
        if ($datamapStatus === 'update' && $changedFields !== null) {
            $sourceChangedFields = array_diff(array_keys($changedFields), self::CHANGE_DETECTION_IGNORE_FIELDS);
        }

        return array_values(array_filter(
            $columns,
            static function (string $column) use ($l10nState, $sourceChangedFields, $datamapStatus, $changedFields): bool {
                if (($l10nState[$column] ?? null) !== 'custom') {
                    return true;
                }

                if ($datamapStatus === null && $changedFields === null) {
                    return true;
                }

                if ($sourceChangedFields === null) {
                    return false;
                }

                return in_array($column, $sourceChangedFields, true);
            }
        ));
    }
}
