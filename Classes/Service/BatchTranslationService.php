<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Service;

use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use ThieleUndKlose\Autotranslate\Domain\Model\BatchItem;
use ThieleUndKlose\Autotranslate\Utility\LogUtility;
use ThieleUndKlose\Autotranslate\Utility\Records;
use ThieleUndKlose\Autotranslate\Utility\TranslationHelper;
use ThieleUndKlose\Autotranslate\Utility\Translator;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for translating batch items
 */
final class BatchTranslationService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * Translate all content for a batch item
     */
    public function translate(BatchItem $item): bool
    {
        $site = $this->getSiteForItem($item);
        if (!$site) {
            return false;
        }

        if (!$this->validateTargetLanguage($item, $site)) {
            return false;
        }

        if (!$this->validatePage($item)) {
            return false;
        }

        try {
            $this->translateAllTables($item, $site);
        } catch (\Exception $e) {
            $this->logError($item, 'Translation failed: {error}', ['error' => $e->getMessage()]);
            return false;
        }
        return true;
    }

    /**
     * Get site configuration for batch item
     */
    private function getSiteForItem(BatchItem $item): ?Site
    {
        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            return $siteFinder->getSiteByPageId($item->getPid());
        } catch (Exception $e) {
            $this->logError($item, 'No site configuration found for pid {pid}.', ['pid' => $item->getPid()]);
            return null;
        }
    }

    /**
     * Log error and set error message on batch item
     */
    private function logError(BatchItem $item, string $message, array $context): void
    {
        LogUtility::log($this->logger, $message, $context, LogUtility::MESSAGE_ERROR);
        $item->setError(LogUtility::interpolate($message, $context));
    }

    /**
     * Validate that target language exists in site configuration
     */
    private function validateTargetLanguage(BatchItem $item, Site $site): bool
    {
        $languages = TranslationHelper::possibleTranslationLanguages($site->getLanguages());

        if (!isset($languages[$item->getSysLanguageUid()])) {
            $this->logError($item, 'Target language ({targetLanguage}) not in site languages ({siteLanguages}).', [
                'targetLanguage' => $item->getSysLanguageUid(),
                'siteLanguages' => implode(',', array_keys($languages)),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Validate that page exists
     */
    private function validatePage(BatchItem $item): bool
    {
        $pageRecord = Records::getRecord('pages', $item->getPid());

        if ($pageRecord === null) {
            LogUtility::log($this->logger, 'Page not found ({pid}).', ['pid' => $item->getPid()], LogUtility::MESSAGE_WARNING);
            return false;
        }

        return true;
    }

    /**
     * Translate all configured tables for a batch item
     */
    private function translateAllTables(BatchItem $item, Site $site): void
    {
        $translator = GeneralUtility::makeInstance(Translator::class, $item->getPid());
        $defaultLanguage = TranslationHelper::defaultLanguageFromSiteConfiguration($site);
        $targetLanguageUid = $item->getSysLanguageUid();
        $mode = $item->getMode();

        foreach (TranslationHelper::tablesToTranslate() as $table) {
            $this->translateTable($translator, $table, $item, $defaultLanguage, $targetLanguageUid, $mode);
        }
    }

    /**
     * Translate a single table
     */
    private function translateTable(
        Translator $translator,
        string $table,
        BatchItem $item,
        SiteLanguage $defaultLanguage,
        int $targetLanguageUid,
        string $mode
    ): void {
        if ($table === 'pages') {
            $translator->translate($table, $item->getPid(), null, (string)$targetLanguageUid, $mode);
            return;
        }

        $constraints = $this->buildConstraints($table, $item->getPid(), $defaultLanguage->getLanguageId());

        if ($table === 'tt_content') {
            $this->translateContent($translator, $constraints, $targetLanguageUid, $mode);
        } else {
            $this->translateRecords($translator, $table, $constraints, $targetLanguageUid, $mode);
        }
    }

    /**
     * Build query constraints for fetching records
     */
    private function buildConstraints(string $table, int $pid, int $languageId): \Closure
    {
        return static function (QueryBuilder $queryBuilder) use ($table, $pid, $languageId): void {
            $queryBuilder->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT))
            );

            // Exclude deleted records if delete field exists
            $deleteField = $GLOBALS['TCA'][$table]['ctrl']['delete'] ?? null;
            if (is_string($deleteField) && $deleteField !== '') {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($deleteField, $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                );
            }
        };
    }

    /**
     * Translate tt_content records (handles Grid Elements)
     */
    private function translateContent(Translator $translator, callable $constraints, int $targetLanguageUid, string $mode): void
    {
        // Translate Grid Elements first (if extension is loaded)
        $this->translateGridElements($translator, $constraints, $targetLanguageUid, $mode);

        // Translate regular content
        $this->translateRegularContent($translator, $constraints, $targetLanguageUid, $mode);
    }

    /**
     * Translate Grid Elements containers and their children
     */
    private function translateGridElements(Translator $translator, callable $constraints, int $targetLanguageUid, string $mode): void
    {
        if (!ExtensionManagementUtility::isLoaded('gridelements')) {
            return;
        }

        // Find top-level containers only
        $containerConstraints = static function (QueryBuilder $queryBuilder) use ($constraints): void {
            $constraints($queryBuilder);
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter('gridelements_pi1')),
                $queryBuilder->expr()->eq('tx_gridelements_container', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            );
        };

        $containers = Records::getRecords('tt_content', 'uid', $containerConstraints);

        foreach ($containers as $containerUid) {
            $this->translateContainerRecursively($translator, $constraints, (int)$containerUid, $targetLanguageUid, $mode);
        }
    }

    /**
     * Recursively translate a container and its children
     */
    private function translateContainerRecursively(
        Translator $translator,
        callable $constraints,
        int $containerUid,
        int $targetLanguageUid,
        string $mode
    ): void {
        // Translate the container itself
        $translator->translate('tt_content', $containerUid, null, (string)$targetLanguageUid, $mode);

        // Find and translate children
        $childConstraints = static function (QueryBuilder $queryBuilder) use ($constraints, $containerUid): void {
            $constraints($queryBuilder);
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'tx_gridelements_container',
                    $queryBuilder->createNamedParameter($containerUid, Connection::PARAM_INT)
                )
            );
        };
        $children = Records::getRecords('tt_content', 'uid', $childConstraints);

        foreach ($children as $childUid) {
            $record = Records::getRecord('tt_content', (int)$childUid);

            if ($record === null) {
                continue;
            }

            if ($record['CType'] === 'gridelements_pi1') {
                // Nested container - recurse
                $this->translateContainerRecursively($translator, $constraints, (int)$childUid, $targetLanguageUid, $mode);
            } else {
                // Regular content element
                $translator->translate('tt_content', (int)$childUid, null, (string)$targetLanguageUid, $mode);
            }
        }
    }

    /**
     * Translate regular (non-Grid Element) content
     */
    private function translateRegularContent(Translator $translator, callable $constraints, int $targetLanguageUid, string $mode): void
    {
        $records = Records::getRecords('tt_content', 'uid', $constraints);

        foreach ($records as $uid) {
            $record = Records::getRecord('tt_content', (int)$uid);

            if ($record === null) {
                continue;
            }

            // Skip Grid Elements and their children
            if ($this->isGridElement($record)) {
                continue;
            }

            $translator->translate('tt_content', (int)$uid, null, (string)$targetLanguageUid, $mode);
        }
    }

    /**
     * Check if record is a Grid Element or child of one
     */
    private function isGridElement(array $record): bool
    {
        if (!ExtensionManagementUtility::isLoaded('gridelements')) {
            return false;
        }

        return $record['CType'] === 'gridelements_pi1'
            || ($record['tx_gridelements_container'] ?? 0) > 0;
    }

    /**
     * Translate records from a generic table
     */
    private function translateRecords(Translator $translator, string $table, callable $constraints, int $targetLanguageUid, string $mode): void
    {
        $records = Records::getRecords($table, 'uid', $constraints);

        foreach ($records as $uid) {
            $translator->translate($table, (int)$uid, null, (string)$targetLanguageUid, $mode);
        }
    }
}
