<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Service;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Extracts translatable HTML attribute values (e.g. <a title="...">) into
 * separate placeholder entries so DeepL translates them as plain text, and
 * restores the translated values afterwards.
 *
 * Stateless and side-effect free.
 */
class HtmlAttributeProcessor implements SingletonInterface
{
    /**
     * Tag/attribute pairs whose values should be translated separately.
     *
     * @var list<array{tag: string, attr: string}>
     */
    private const ATTRIBUTE_MAP = [
        ['tag' => 'a', 'attr' => 'title'],
    ];

    /**
     * Replaces translatable HTML attributes with placeholders and appends the
     * original values as separate "__ATTR__n__" entries to be translated.
     *
     * @param array<string, string> $toTranslate
     * @return array<string, string>
     */
    public function extractAttributes(array $toTranslate): array
    {
        $attrMap = [];
        $attrCounter = 1;

        foreach ($toTranslate as $field => &$value) {
            if (!is_string($value) || trim($value) === '' || !$this->isHtml($value)) {
                continue;
            }

            foreach (self::ATTRIBUTE_MAP as $map) {
                $found = $this->extractHtmlAttributes($value, $map['tag'], $map['attr']);
                foreach ($found as $attrValue) {
                    $placeholder = '__ATTR__' . $attrCounter . '__';
                    $attrMap[$placeholder] = $attrValue;
                    $value = $this->replaceHtmlAttributeWithPlaceholder($value, $map['tag'], $map['attr'], $attrValue, $placeholder);
                    $attrCounter++;
                }
            }
        }
        unset($value);

        // Add the attributes as separate entries to translate
        foreach ($attrMap as $placeholder => $original) {
            $toTranslate[$placeholder] = $original;
        }

        return $toTranslate;
    }

    /**
     * Replaces placeholders in the HTML with the translated attribute values.
     *
     * @param array<string, ?string> $attrTranslations placeholder => translated value
     */
    public function restoreAttributes(string $html, array $attrTranslations): string
    {
        foreach ($attrTranslations as $placeholder => $translatedValue) {
            if ($translatedValue !== null) {
                $html = str_replace($placeholder, $translatedValue, $html);
            }
        }

        return $html;
    }

    public function isHtml(string $value): bool
    {
        return $value !== strip_tags($value);
    }

    /**
     * Extracts all values of a specific attribute from a specific tag in HTML.
     *
     * @return list<string>
     */
    private function extractHtmlAttributes(string $html, string $tagName, string $attributeName): array
    {
        $values = [];
        if (trim($html) === '') {
            return $values;
        }

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);

        $xpath = new \DOMXPath($doc);
        $query = '//' . $tagName . '[@' . $attributeName . ']';
        foreach ($xpath->query($query) as $node) {
            /** @var \DOMElement $node */
            $values[] = $node->getAttribute($attributeName);
        }

        return $values;
    }

    /**
     * Replaces a specific attribute of a tag in an HTML string with a placeholder.
     */
    private function replaceHtmlAttributeWithPlaceholder(string $html, string $tag, string $attr, string $original, string $placeholder): string
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        $xpath = new \DOMXPath($doc);
        $query = '//' . $tag . '[@' . $attr . ']';

        foreach ($xpath->query($query) as $node) {
            /** @var \DOMElement $node */
            if ($node->getAttribute($attr) === $original) {
                $node->setAttribute($attr, $placeholder);
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $innerHTML = '';
        foreach ($body->childNodes as $child) {
            $innerHTML .= $doc->saveHTML($child);
        }

        return $innerHTML;
    }
}
