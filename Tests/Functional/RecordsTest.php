<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Utility\Records;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional coverage of Records::updateRecord null-write semantics.
 */
final class RecordsTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['thieleundklose/autotranslate'];

    #[Test]
    public function updateRecordSkipsNullValuesByDefault(): void
    {
        $uid = $this->createContentRow(['header' => 'X', 'autotranslate_languages' => '1']);

        Records::updateRecord('tt_content', $uid, ['autotranslate_languages' => null]);

        self::assertSame(
            '1',
            Records::getRecord('tt_content', $uid, 'autotranslate_languages'),
            'a sparse null property must not clear the column by default'
        );
    }

    #[Test]
    public function updateRecordWritesNullWhenExplicitlyOptedIn(): void
    {
        $uid = $this->createContentRow(['header' => 'X', 'autotranslate_languages' => '1']);

        Records::updateRecord('tt_content', $uid, ['autotranslate_languages' => null], true);

        self::assertNull(
            Records::getRecord('tt_content', $uid, 'autotranslate_languages'),
            'writeNullValues = true must set the column to NULL'
        );
    }

    #[Test]
    public function updateRecordWritesScalarValues(): void
    {
        $uid = $this->createContentRow(['header' => 'X']);

        Records::updateRecord('tt_content', $uid, ['header' => 'Y']);

        self::assertSame('Y', Records::getRecord('tt_content', $uid, 'header'));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createContentRow(array $fields): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $connection->insert('tt_content', array_merge([
            'pid' => 1,
            'sys_language_uid' => 0,
        ], $fields));

        return (int)$connection->lastInsertId();
    }
}
