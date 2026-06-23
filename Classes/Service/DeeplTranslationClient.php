<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Service;

use DeepL\TranslateTextOptions;
use DeepL\Translator as DeeplTranslator;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use ThieleUndKlose\Autotranslate\Domain\Dto\Glossary;
use ThieleUndKlose\Autotranslate\Utility\DeeplApiHelper;
use ThieleUndKlose\Autotranslate\Utility\LogUtility;
use ThieleUndKlose\Autotranslate\Utility\TranslationHelper;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Boundary to the DeepL API: quota validation, glossary resolution and the
 * (cache-aware) translation calls.
 *
 * Not declared final so functional tests can substitute a double via
 * GeneralUtility::setSingletonInstance().
 */
class DeeplTranslationClient implements DeeplTranslationClientInterface, SingletonInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** @var array<string, DeeplTranslator> Reused per API key within the request */
    private array $translators = [];

    /** @var array<string, array{isValid: bool, usage: mixed, charactersLeft: ?int, error: ?string}> Memoized per API key */
    private array $apiKeyDetails = [];

    public function __construct(
        private readonly TranslationCacheService $cacheService,
        private readonly GlossaryService $glossaryService,
    ) {}

    public function assertApiKeyUsable(?string $apiKey): void
    {
        $deeplApiKeyDetails = $this->apiKeyDetails[(string)$apiKey] ??= DeeplApiHelper::checkApiKey($apiKey);

        if ($deeplApiKeyDetails['error']) {
            LogUtility::log($this->logger, 'DeepL API Key is not valid: {error}', [
                'error' => $deeplApiKeyDetails['error'],
            ]);
            throw new \RuntimeException('DeepL API Key is not valid: ' . $deeplApiKeyDetails['error']);
        }
        if (!$deeplApiKeyDetails['isValid']) {
            LogUtility::log($this->logger, 'DeepL API Key is not valid: {error}', [
                'error' => 'No API Key given.',
            ]);
            throw new \RuntimeException('DeepL API Key is not valid: No API Key given.');
        }
        if ($deeplApiKeyDetails['charactersLeft'] !== null && $deeplApiKeyDetails['charactersLeft'] <= 0) {
            LogUtility::log($this->logger, 'DeepL API Key has no characters left: {charactersLeft}', [
                'charactersLeft' => $deeplApiKeyDetails['charactersLeft'],
            ]);
            throw new \RuntimeException('DeepL API Key has no characters left: ' . $deeplApiKeyDetails['charactersLeft']);
        }
    }

    public function resolveGlossary(?string $sourceLang, ?string $targetLang, int $pageId, ?string $apiKey): ?Glossary
    {
        if (!$sourceLang || !$targetLang || !TranslationHelper::glossaryEnabled($pageId)) {
            return null;
        }

        return $this->glossaryService->getGlossary($sourceLang, $targetLang, $pageId, $this->translator($apiKey));
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, string> $toTranslate
     * @return array<int, object|null>
     */
    public function translateTexts(
        array $record,
        string $table,
        array $toTranslate,
        ?string $sourceLang,
        string $targetLang,
        string $deeplFormality,
        ?Glossary $glossary,
        ?string $apiKey
    ): array {
        if ($toTranslate === []) {
            return [];
        }

        $translator = $this->translator($apiKey);

        $baseOptions = [
            TranslateTextOptions::SPLIT_SENTENCES => true,
        ];
        $formality = $this->resolvedFormalityOption($deeplFormality);
        if ($formality !== null) {
            $baseOptions[TranslateTextOptions::FORMALITY] = $formality;
        }

        if ($glossary) {
            $baseOptions[TranslateTextOptions::GLOSSARY] = $glossary->glossaryId;
        }

        $htmlOptions = array_merge($baseOptions, [
            TranslateTextOptions::TAG_HANDLING => 'html',
        ]);

        $richtextMap = $this->mapRichtextFields($toTranslate, $table, $record);

        $toTranslateText = [];
        $toTranslateHtml = [];

        foreach ($toTranslate as $field => $value) {
            if (!($richtextMap[$field] ?? false)) {
                $toTranslateText[$field] = $value;
            } else {
                $toTranslateHtml[$field] = $value;
            }
        }

        // Translate text fields with cache
        $translatedTextFields = [];
        if (!empty($toTranslateText)) {
            $translatedTextFields = $this->translateWithCache(
                array_values($toTranslateText),
                $sourceLang,
                $targetLang,
                $baseOptions,
                $translator
            );
        }

        // Translate HTML fields with cache
        $translatedHtmlFields = [];
        if (!empty($toTranslateHtml)) {
            $translatedHtmlFields = $this->translateWithCache(
                array_values($toTranslateHtml),
                $sourceLang,
                $targetLang,
                $htmlOptions,
                $translator
            );
        }

        // Restore field order from $toTranslate
        $mergedResults = [];
        $textIndex = 0;
        $htmlIndex = 0;

        foreach (array_keys($toTranslate) as $field) {
            if (array_key_exists($field, $toTranslateText)) {
                $mergedResults[] = $translatedTextFields[$textIndex] ?? null;
                $textIndex++;
            } elseif (array_key_exists($field, $toTranslateHtml)) {
                $mergedResults[] = $translatedHtmlFields[$htmlIndex] ?? null;
                $htmlIndex++;
            }
        }

        return $mergedResults;
    }

    protected function translator(?string $apiKey): DeeplTranslator
    {
        return $this->translators[(string)$apiKey] ??= new DeeplTranslator((string)$apiKey);
    }

    private function resolvedFormalityOption(string $deeplFormality): ?string
    {
        return match ($deeplFormality) {
            'prefer_less', 'prefer_more' => $deeplFormality,
            default => null,
        };
    }

    /**
     * Returns an array indicating whether each field in $toTranslate is a richtext field.
     *
     * @param array<string, string> $toTranslate
     * @param array<string, mixed> $record
     * @return array<string, bool>
     */
    private function mapRichtextFields(array $toTranslate, string $table, array $record): array
    {
        $result = [];
        foreach (array_keys($toTranslate) as $columnName) {
            $result[$columnName] = $this->isRichtextField($record, $table, $columnName);
        }

        return $result;
    }

    /**
     * Check if the field is a richtext field
     *
     * @param array<string, mixed> $record
     */
    private function isRichtextField(array $record, string $table, string $columnName): bool
    {
        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$columnName]['config'] ?? null;
        if (!$fieldConfig) {
            return false;
        }

        // Check for CType specific configuration
        $ctype = $record['CType'] ?? null;
        if ($ctype && isset($GLOBALS['TCA'][$table]['types'][$ctype]['columnsOverrides'][$columnName]['config'])) {
            $fieldConfig = $GLOBALS['TCA'][$table]['types'][$ctype]['columnsOverrides'][$columnName]['config'];
        }

        return isset($fieldConfig['enableRichtext']) && $fieldConfig['enableRichtext'] === true;
    }

    /**
     * Translate texts with caching support
     *
     * @param list<string> $texts
     * @param array<string, mixed> $options
     * @return array<int, object|null>
     */
    private function translateWithCache(
        array $texts,
        ?string $sourceLang,
        string $targetLang,
        array $options,
        DeeplTranslator $translator
    ): array {
        if (empty($texts)) {
            return [];
        }

        // Check for complete cache hit first
        $completeCacheKey = $this->cacheService->generateCacheKey($texts, $sourceLang, $targetLang, $options);
        $completeCache = $this->cacheService->getCachedTranslation($completeCacheKey);

        if ($completeCache !== null) {
            LogUtility::log($this->logger, 'Complete cache hit for {count} texts', ['count' => count($texts)]);
            return $completeCache;
        }

        // Check for partial cache hits
        $partialCache = $this->cacheService->getPartialCacheHits($texts, $sourceLang, $targetLang, $options);

        $finalResults = array_fill(0, count($texts), null);

        // Fill cached results
        foreach ($partialCache['cached'] as $index => $result) {
            $finalResults[$index] = $result;
        }

        // Translate uncached texts
        if (!empty($partialCache['uncached'])) {
            LogUtility::log($this->logger, 'Translating {uncached} texts, {cached} from cache', [
                'uncached' => count($partialCache['uncached']),
                'cached' => count($partialCache['cached']),
            ]);

            $freshTranslations = $translator->translateText(
                $partialCache['uncached'],
                $sourceLang,
                $targetLang,
                $options
            );

            foreach ($freshTranslations as $resultIndex => $result) {
                $originalIndex = $partialCache['mapping'][$resultIndex];
                $finalResults[$originalIndex] = $result;
            }

            $this->cacheService->cacheIndividualTranslations(
                $partialCache['uncached'],
                $freshTranslations,
                $sourceLang,
                $targetLang,
                $options
            );
        }

        // Cache complete result (only if all results are valid)
        $validResults = array_filter($finalResults, static fn($result) => $result !== null);
        if (count($validResults) === count($finalResults)) {
            $this->cacheService->setCachedTranslation($completeCacheKey, $finalResults);
        } else {
            LogUtility::log($this->logger, 'Not caching complete result due to null values: {valid}/{total}', [
                'valid' => count($validResults),
                'total' => count($finalResults),
            ]);
        }

        return $finalResults;
    }
}
