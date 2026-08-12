<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\Test;
use ThieleUndKlose\Autotranslate\EventListener\AdditionalTablesSchemaListener;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AdditionalTablesSchemaListenerTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private AdditionalTablesSchemaListener $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new AdditionalTablesSchemaListener();

        $tables = [
            'tx_test_media',
            'tx_test_reference',
            'pages',
            'tt_content',
            'tx_news_domain_model_news',
            'sys_file_reference',
        ];

        foreach ($tables as $table) {
            $GLOBALS['TCA'][$table]['ctrl'] = [
                'languageField' => 'sys_language_uid',
                'transOrigPointerField' => 'l10n_parent',
            ];
        }
    }

    private function configure(string $additionalTables, string $additionalReferenceTables): void
    {
        // Both helper calls fetch their own instance.
        for ($i = 0; $i < 2; $i++) {
            $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
            $extensionConfiguration->method('get')->willReturnCallback(
                static fn (string $extension, string $path = ''): string => match ($path) {
                    'additionalTables' => $additionalTables,
                    'additionalReferenceTables' => $additionalReferenceTables,
                    default => '',
                }
            );
            GeneralUtility::addInstance(ExtensionConfiguration::class, $extensionConfiguration);
        }
    }

    /**
     * @return list<string>
     */
    private function collectSqlData(string $additionalTables, string $additionalReferenceTables): array
    {
        $this->configure($additionalTables, $additionalReferenceTables);
        $event = new AlterTableDefinitionStatementsEvent([]);
        ($this->subject)($event);

        return $event->getSqlData();
    }

    #[Test]
    public function addsFullColumnSetForConfiguredAdditionalTable(): void
    {
        $sqlData = $this->collectSqlData('tx_test_media', '');

        self::assertCount(1, $sqlData);
        self::assertStringContainsString('CREATE TABLE tx_test_media (', $sqlData[0]);
        self::assertStringContainsString('autotranslate_exclude', $sqlData[0]);
        self::assertStringContainsString('autotranslate_languages', $sqlData[0]);
        self::assertStringContainsString('autotranslate_last', $sqlData[0]);
        self::assertStringContainsString('autotranslate_source_hash', $sqlData[0]);
    }

    #[Test]
    public function omitsLanguageSelectionForReferenceOnlyTable(): void
    {
        $sqlData = $this->collectSqlData('', 'tx_test_reference');

        self::assertCount(1, $sqlData);
        self::assertStringContainsString('CREATE TABLE tx_test_reference (', $sqlData[0]);
        self::assertStringContainsString('autotranslate_source_hash', $sqlData[0]);
        self::assertStringNotContainsString('autotranslate_languages', $sqlData[0]);
    }

    #[Test]
    public function usesFullColumnSetWhenTableIsTranslatedAndReferenced(): void
    {
        $sqlData = $this->collectSqlData('tx_test_media', 'tx_test_media');

        self::assertCount(1, $sqlData);
        self::assertStringContainsString('autotranslate_languages', $sqlData[0]);
    }

    #[Test]
    public function skipsTablesAlreadyDeclaredInExtTablesSql(): void
    {
        $sqlData = $this->collectSqlData('pages,tt_content,tx_news_domain_model_news', 'sys_file_reference');

        self::assertSame([], $sqlData);
    }

    #[Test]
    public function skipsTablesWithoutTranslationSupport(): void
    {
        $GLOBALS['TCA']['tx_test_untranslatable']['ctrl'] = ['languageField' => 'sys_language_uid'];

        $sqlData = $this->collectSqlData('tx_test_untranslatable', '');

        self::assertSame([], $sqlData);
    }
}
