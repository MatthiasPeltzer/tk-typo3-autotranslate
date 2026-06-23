<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Service;

use ThieleUndKlose\Autotranslate\Domain\Dto\Glossary;

/**
 * Boundary to the DeepL API. Implementations encapsulate every outbound DeepL
 * call (quota validation, glossary resolution and translation) so the core
 * translation flow can be exercised in tests with a fake client.
 */
interface DeeplTranslationClientInterface
{
    /**
     * @throws \RuntimeException If the DeepL API key is invalid or has no characters left
     */
    public function assertApiKeyUsable(?string $apiKey): void;

    public function resolveGlossary(?string $sourceLang, ?string $targetLang, int $pageId, ?string $apiKey): ?Glossary;

    /**
     * Translate the given field values, preserving the order of $toTranslate.
     *
     * @param array<string, mixed> $record
     * @param array<string, string> $toTranslate field name => source text (may contain HTML and __ATTR__ placeholders)
     * @return array<int, object|null> Translation results (objects exposing a public ->text), one per input in order
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
    ): array;
}
