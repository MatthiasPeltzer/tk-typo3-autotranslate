<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\UserFunction\FormEngine;

use ThieleUndKlose\Autotranslate\Utility\DeeplApiHelper;
use ThieleUndKlose\Autotranslate\Utility\TranslationHelper;

final class GlossaryDeepLLanguageItems
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function sourceItems(array &$parameters): void
    {
        $this->populateItems($parameters, 'source');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function targetItems(array &$parameters): void
    {
        $this->populateItems($parameters, 'target');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function populateItems(array &$parameters, string $type): void
    {
        $parameters['items'] = [
            ['label' => '', 'value' => ''],
        ];

        $pageId = (int)($parameters['row']['pid'] ?? $parameters['effectivePid'] ?? 0);
        if ($pageId <= 0) {
            return;
        }

        $apiKey = TranslationHelper::apiKey($pageId)['key'] ?? null;
        if ($apiKey === null || $apiKey === '') {
            return;
        }

        foreach (DeeplApiHelper::getCachedLanguages($apiKey, $type) as $languageItem) {
            $parameters['items'][] = [
                'label' => $languageItem[0],
                'value' => $languageItem[1],
            ];
        }
    }
}
