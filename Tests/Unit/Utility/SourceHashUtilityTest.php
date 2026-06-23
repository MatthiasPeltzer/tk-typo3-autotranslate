<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Utility\SourceHashUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class SourceHashUtilityTest extends UnitTestCase
{
    #[Test]
    public function computeHashIsStableForStrings(): void
    {
        self::assertSame(
            SourceHashUtility::computeHash('hello'),
            SourceHashUtility::computeHash('hello')
        );
        self::assertNotSame(
            SourceHashUtility::computeHash('hello'),
            SourceHashUtility::computeHash('world')
        );
    }

    #[Test]
    public function computeHashTreatsNonStringAsEmpty(): void
    {
        self::assertSame(
            SourceHashUtility::computeHash(''),
            SourceHashUtility::computeHash(null)
        );
        self::assertSame(
            SourceHashUtility::computeHash(''),
            SourceHashUtility::computeHash(['not', 'a', 'string'])
        );
    }

    #[Test]
    public function computeFieldHashesSkipsMissingAndNonStringColumns(): void
    {
        $record = [
            'header' => 'Title',
            'bodytext' => 'Body',
            'sorting' => 5,
        ];

        $hashes = SourceHashUtility::computeFieldHashes($record, ['header', 'bodytext', 'sorting', 'missing']);

        self::assertArrayHasKey('header', $hashes);
        self::assertArrayHasKey('bodytext', $hashes);
        self::assertArrayNotHasKey('sorting', $hashes, 'non-string columns are skipped');
        self::assertArrayNotHasKey('missing', $hashes, 'absent columns are skipped');
    }

    #[Test]
    public function resolveChangedColumnsReturnsAllWhenNoStoredHashes(): void
    {
        $record = ['header' => 'Title', 'bodytext' => 'Body'];

        self::assertSame(
            ['header', 'bodytext'],
            SourceHashUtility::resolveChangedColumns($record, ['header', 'bodytext'])
        );
    }

    #[Test]
    public function resolveChangedColumnsDetectsOnlyChangedFields(): void
    {
        $columns = ['header', 'bodytext'];
        $original = ['header' => 'Title', 'bodytext' => 'Body'];
        $storedJson = SourceHashUtility::mergeHashesForTranslatedFields($original, $columns, $columns);

        $changed = $original;
        $changed['bodytext'] = 'Body changed';
        $changed[SourceHashUtility::FIELD_NAME] = $storedJson;

        self::assertSame(
            ['bodytext'],
            SourceHashUtility::resolveChangedColumns($changed, $columns)
        );
    }

    #[Test]
    public function resolveChangedColumnsReturnsEmptyWhenNothingChanged(): void
    {
        $columns = ['header', 'bodytext'];
        $record = ['header' => 'Title', 'bodytext' => 'Body'];
        $record[SourceHashUtility::FIELD_NAME] = SourceHashUtility::mergeHashesForTranslatedFields($record, $columns, $columns);

        self::assertSame([], SourceHashUtility::resolveChangedColumns($record, $columns));
    }

    #[Test]
    public function decodeStoredHashesIgnoresInvalidPayloads(): void
    {
        self::assertSame([], SourceHashUtility::decodeStoredHashes(''));
        self::assertSame([], SourceHashUtility::decodeStoredHashes('not json'));
        self::assertSame([], SourceHashUtility::decodeStoredHashes('[1,2,3]'));
        self::assertSame(
            ['header' => 'abc'],
            SourceHashUtility::decodeStoredHashes('{"header":"abc","bad":123}')
        );
    }

    #[Test]
    public function mergeHashesOnlyUpdatesTranslatedFieldsAndKeepsOthers(): void
    {
        $columns = ['header', 'bodytext'];
        $record = ['header' => 'Title', 'bodytext' => 'Body'];
        $initial = SourceHashUtility::mergeHashesForTranslatedFields($record, $columns, $columns);

        $updated = ['header' => 'New title', 'bodytext' => 'New body', SourceHashUtility::FIELD_NAME => $initial];
        // Only header was translated this run.
        $merged = SourceHashUtility::mergeHashesForTranslatedFields($updated, $columns, ['header']);
        $decoded = SourceHashUtility::decodeStoredHashes($merged);

        self::assertSame(SourceHashUtility::computeHash('New title'), $decoded['header']);
        self::assertSame(SourceHashUtility::computeHash('Body'), $decoded['bodytext'], 'untranslated field keeps its previous hash');
    }
}
