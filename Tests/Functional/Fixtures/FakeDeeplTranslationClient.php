<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Functional\Fixtures;

use ThieleUndKlose\Autotranslate\Domain\Dto\Glossary;
use ThieleUndKlose\Autotranslate\Service\DeeplTranslationClient;

/**
 * Test double for the DeepL boundary used in functional tests.
 *
 * Returns deterministic, network-free translations (each value prefixed with
 * the upper-cased target language and a colon) and records how often the real
 * translation call would have been made, so tests can assert that unchanged
 * saves do not trigger a translation.
 */
final class FakeDeeplTranslationClient extends DeeplTranslationClient
{
    public int $translateCallCount = 0;

    public function assertApiKeyUsable(?string $apiKey): void
    {
        // No remote quota check in tests.
    }

    public function resolveGlossary(?string $sourceLang, ?string $targetLang, int $pageId, ?string $apiKey): ?Glossary
    {
        return null;
    }

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

        $this->translateCallCount++;

        $prefix = strtoupper($targetLang) . ':';

        return array_map(
            static fn(string $text): object => new class ($prefix . $text) {
                public function __construct(public readonly string $text) {}
            },
            array_values($toTranslate)
        );
    }
}
