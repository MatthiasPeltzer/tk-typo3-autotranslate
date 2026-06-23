<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\UserFunction;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class Tca
{
    /**
     * Get label for batches from language and translation date
     *
     * @param array<string, mixed> $parameters
     */
    public function batchLabel(array &$parameters): void
    {
        if (!isset($parameters['row']['uid'])) {
            $parameters['title'] = 'item deleted from backend module';
            return;
        }

        $record = BackendUtility::getRecord($parameters['table'], $parameters['row']['uid']);
        if (!$record) {
            return;
        }

        $languageId = $record['sys_language_uid'];
        $translationTimestamp = $record['translate'];
        $translationDate = date('d-m-Y H:i', $translationTimestamp);

        if (!isset($parameters['row']['pid'])) {
            $parameters['title'] = (string)$parameters['row']['uid'];
            return;
        }

        $pageId = (int)$parameters['row']['pid'];
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $parameters['title'] = $translationDate;

        try {
            $site = $siteFinder->getSiteByPageId($pageId);
            foreach ($site->getAllLanguages() as $siteLanguage) {
                if ($siteLanguage->getLanguageId() === $languageId) {
                    $parameters['title'] = $siteLanguage->getTitle() . ' - ' . $parameters['title'];
                    break;
                }
            }
        } catch (SiteNotFoundException) {
            // Site not found, keep default title
        }
    }

    /**
     * Build a readable label for glossary records.
     *
     * @param array<string, mixed> $parameters
     */
    public function glossaryLabel(array &$parameters): void
    {
        $sourceLang = $this->extractFieldValue($parameters['row']['source_lang'] ?? null);
        $targetLang = $this->extractFieldValue($parameters['row']['target_lang'] ?? null);
        if ($sourceLang !== '' && $targetLang !== '') {
            $parameters['title'] = $sourceLang . ' → ' . $targetLang;
            return;
        }

        if ($sourceLang !== '') {
            $parameters['title'] = $sourceLang;
            return;
        }

        if ($targetLang !== '') {
            $parameters['title'] = $targetLang;
            return;
        }

        $uid = $this->extractFieldValue($parameters['row']['uid'] ?? null);
        $parameters['title'] = $uid !== '' ? $uid : 'New glossary';
    }

    private function extractFieldValue(mixed $value): string
    {
        if (is_array($value)) {
            $firstValue = reset($value);
            if (is_scalar($firstValue)) {
                return trim((string)$firstValue);
            }

            return '';
        }

        if (is_scalar($value)) {
            return trim((string)$value);
        }

        return '';
    }
}
