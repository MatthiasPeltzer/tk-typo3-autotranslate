<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Utility;

/**
 * Tracks per-field source content hashes to detect changes without DataHandler context.
 */
final class SourceHashUtility
{
    public const FIELD_NAME = 'autotranslate_source_hash';

    public static function computeHash(mixed $value): string
    {
        if (!is_string($value)) {
            $value = '';
        }

        return hash('xxh128', $value);
    }

    /**
     * @param list<string> $columns
     * @return array<string, string>
     */
    public static function computeFieldHashes(array $record, array $columns): array
    {
        $hashes = [];

        foreach ($columns as $column) {
            if (!array_key_exists($column, $record)) {
                continue;
            }

            $value = $record[$column];
            if (!is_string($value)) {
                continue;
            }

            $hashes[$column] = self::computeHash($value);
        }

        return $hashes;
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    public static function resolveChangedColumns(array $record, array $columns): array
    {
        $stored = self::decodeStoredHashes((string)($record[self::FIELD_NAME] ?? ''));
        $current = self::computeFieldHashes($record, $columns);

        if ($stored === []) {
            return $columns;
        }

        $changed = [];

        foreach ($columns as $column) {
            if (!isset($current[$column])) {
                continue;
            }

            if (!isset($stored[$column]) || $stored[$column] !== $current[$column]) {
                $changed[] = $column;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, string>
     */
    public static function decodeStoredHashes(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return [];
        }

        $hashes = [];

        foreach ($decoded as $field => $hash) {
            if (is_string($field) && is_string($hash)) {
                $hashes[$field] = $hash;
            }
        }

        return $hashes;
    }

    /**
     * @param list<string> $configuredColumns
     * @param list<string> $translatedFieldNames
     */
    public static function mergeHashesForTranslatedFields(
        array $record,
        array $configuredColumns,
        array $translatedFieldNames
    ): string {
        $stored = self::decodeStoredHashes((string)($record[self::FIELD_NAME] ?? ''));
        $current = self::computeFieldHashes($record, $configuredColumns);

        foreach ($translatedFieldNames as $field) {
            if (isset($current[$field])) {
                $stored[$field] = $current[$field];
            }
        }

        return json_encode($stored, JSON_THROW_ON_ERROR);
    }
}
