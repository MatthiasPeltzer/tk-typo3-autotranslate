<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Service;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use ThieleUndKlose\Autotranslate\Utility\LogUtility;
use ThieleUndKlose\Autotranslate\Utility\Records;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Builds the l10n_state JSON for auto-translated fields.
 *
 * Auto-translated fields are marked as "custom" so TYPO3 keeps the translated
 * value instead of overwriting it from the source on the next localization.
 */
class L10nStateBuilder implements SingletonInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @param list<string> $translatedFields
     */
    public function build(string $table, int $targetLanguageUid, array $translatedFields, int $localizedUid, int $sourceUid): string
    {
        if (!isset($GLOBALS['TCA'][$table]['ctrl']['transOrigDiffSourceField'])) {
            return '{}';
        }

        try {
            $existingTranslation = null;
            if ($sourceUid > 0) {
                $existingTranslation = Records::getRecordTranslation($table, $sourceUid, $targetLanguageUid);
            }
            if ($existingTranslation === null) {
                $existingTranslation = Records::getRecord($table, $localizedUid);
            }

            $l10nState = [];
            if ($existingTranslation && !empty($existingTranslation['l10n_state'])) {
                $l10nState = json_decode($existingTranslation['l10n_state'], true) ?: [];
            }

            foreach ($translatedFields as $field) {
                $l10nState[$field] = 'custom';
            }

            return json_encode($l10nState);
        } catch (\Exception $e) {
            LogUtility::log($this->logger, 'Error building l10n_state: {error}', [
                'error' => $e->getMessage(),
                'table' => $table,
            ], LogUtility::MESSAGE_ERROR);

            return '{}';
        }
    }
}
