<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\Tests\Support\AutotranslateTcaManifest;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class TcaBootstrapFunctionalTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = AutotranslateTcaManifest::FUNCTIONAL_TEST_EXTENSIONS;

    #[Test]
    public function customTablesAreRegisteredInTca(): void
    {
        foreach (AutotranslateTcaManifest::CUSTOM_TABLES as $table) {
            self::assertArrayHasKey($table, $GLOBALS['TCA']);
            self::assertArrayHasKey('ctrl', $GLOBALS['TCA'][$table]);
            self::assertArrayHasKey('columns', $GLOBALS['TCA'][$table]);
            self::assertArrayHasKey('types', $GLOBALS['TCA'][$table]);
        }
    }

    #[Test]
    public function tcaSchemaBuildsForAutotranslateTables(): void
    {
        $factory = GeneralUtility::makeInstance(TcaSchemaFactory::class);

        foreach (AutotranslateTcaManifest::SCHEMA_TABLES as $table) {
            self::assertTrue($factory->has($table));
            self::assertSame($table, $factory->get($table)->getName());
        }
    }

    #[Test]
    public function coreTableOverridesExposeAutotranslateColumns(): void
    {
        foreach (AutotranslateTcaManifest::OVERRIDDEN_CORE_TABLE_COLUMNS as $table => $columns) {
            self::assertArrayHasKey($table, $GLOBALS['TCA']);

            foreach ($columns as $column) {
                self::assertArrayHasKey($column, $GLOBALS['TCA'][$table]['columns']);
            }
        }
    }

    #[Test]
    public function languagePaletteIncludesAutotranslateFieldsOnTtContent(): void
    {
        $palette = $GLOBALS['TCA']['tt_content']['palettes']['language']['showitem'] ?? '';
        self::assertIsString($palette);
        self::assertStringContainsString('autotranslate_exclude', $palette);
        self::assertStringContainsString('autotranslate_languages', $palette);
    }
}
