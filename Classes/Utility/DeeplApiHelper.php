<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Utility;

use DeepL\AuthorizationException;
use DeepL\DeepLException;
use DeepL\Translator;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DeeplApiHelper
{
    /**
     * Validate the DeepL API key and return usage information.
     *
     * @return array{isValid: bool, usage: mixed, charactersLeft: ?int, error: ?string}
     */
    public static function checkApiKey(?string $apiKey): array
    {
        if (!$apiKey) {
            return ['isValid' => false, 'usage' => null, 'charactersLeft' => 0, 'error' => null];
        }

        try {
            $translator = new Translator($apiKey);
            $usage = $translator->getUsage();
            $charactersLeft = isset($usage->character)
                ? $usage->character->limit - $usage->character->count
                : null;

            return ['isValid' => true, 'usage' => $usage, 'charactersLeft' => $charactersLeft, 'error' => null];
        } catch (AuthorizationException $e) {
            return ['isValid' => false, 'usage' => null, 'charactersLeft' => 0, 'error' => $e->getMessage()];
        } catch (DeepLException $e) {
            return ['isValid' => false, 'usage' => null, 'charactersLeft' => 0, 'error' => 'DeepL error: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['isValid' => false, 'usage' => null, 'charactersLeft' => 0, 'error' => 'Unexpected error: ' . $e->getMessage()];
        }
    }

    /**
     * Validate the DeepL API key and return display-ready usage info, cached for
     * a short period. Used by the backend module so it does not perform a live
     * DeepL usage request on every render. Only the derived display strings are
     * stored (never the DeepL usage object), so the cache stays serializable.
     *
     * @return array{isValid: bool, usageText: ?string, charactersLeft: ?int, error: ?string}
     */
    public static function checkApiKeyForDisplay(?string $apiKey, int $ttl = 300): array
    {
        if (!$apiKey) {
            return ['isValid' => false, 'usageText' => null, 'charactersLeft' => 0, 'error' => null];
        }

        $cacheIdentifier = 'deepl_usage_display_' . md5($apiKey);

        try {
            $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('autotranslate');
        } catch (NoSuchCacheException) {
            $cache = null;
        }

        if ($cache?->has($cacheIdentifier)) {
            $data = $cache->get($cacheIdentifier);
            if (is_array($data)) {
                return $data;
            }
        }

        $details = self::checkApiKey($apiKey);
        $result = [
            'isValid' => $details['isValid'],
            'usageText' => $details['usage'] !== null ? (string)$details['usage'] : null,
            'charactersLeft' => $details['charactersLeft'],
            'error' => $details['error'],
        ];

        // Cache valid lookups for the full TTL; cache failures only briefly so a
        // transient DeepL outage is not pinned to the module for long.
        $cache?->set($cacheIdentifier, $result, [], $result['isValid'] ? $ttl : min($ttl, 60));

        return $result;
    }

    /**
     * Get DeepL languages (source or target) with caching.
     *
     * @param string $type 'source' or 'target'
     * @return array<int, array{0: string, 1: string}>
     */
    public static function getCachedLanguages(string $apiKey, string $type = 'source'): array
    {
        if (!in_array($type, ['source', 'target'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid type "%s". Allowed values are "source" or "target".', $type));
        }

        $cacheIdentifier = 'deepl_' . $type . '_languages_' . md5($apiKey);

        try {
            $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('autotranslate');
        } catch (NoSuchCacheException) {
            $cache = null;
        }

        if ($cache?->has($cacheIdentifier)) {
            $data = $cache->get($cacheIdentifier);
            if (is_array($data)) {
                return $data;
            }
        }

        try {
            $translator = new Translator($apiKey);
            $languages = $type === 'source'
                ? $translator->getSourceLanguages()
                : $translator->getTargetLanguages();

            $result = [];
            foreach ($languages as $language) {
                $result[] = [$language->name, $language->code];
            }

            $cache?->set($cacheIdentifier, $result, [], 86400 * 7);

            return $result;
        } catch (\Exception) {
            return [];
        }
    }
}
