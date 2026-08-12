<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\EventListener;

use ThieleUndKlose\Autotranslate\Utility\TranslationHelper;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;

/**
 * Tables configured through additionalTables/additionalReferenceTables receive their
 * tracking columns from TCA at runtime, so the database schema has to be extended at
 * runtime as well. ext_tables.sql can only declare the tables shipped with this
 * extension, which would leave configured tables with TCA fields but no columns.
 */
final class AdditionalTablesSchemaListener
{
    /**
     * @var array<string, string>
     */
    private const COLUMN_DEFINITIONS = [
        'autotranslate_exclude' => "tinyint(4) DEFAULT '0' NOT NULL",
        'autotranslate_languages' => 'varchar(255) DEFAULT NULL',
        'autotranslate_last' => "int(11) DEFAULT '0' NOT NULL",
        'autotranslate_source_hash' => 'mediumtext',
    ];

    /**
     * Reference tables are never translated on their own, so they carry no language selection.
     *
     * @var list<string>
     */
    private const REFERENCE_TABLE_COLUMNS = [
        'autotranslate_exclude',
        'autotranslate_last',
        'autotranslate_source_hash',
    ];

    /**
     * Already declared in ext_tables.sql.
     *
     * @var list<string>
     */
    private const STATICALLY_DEFINED_TABLES = [
        'pages',
        'tt_content',
        'tx_news_domain_model_news',
        'sys_file_reference',
    ];

    public function __invoke(AlterTableDefinitionStatementsEvent $event): void
    {
        foreach ($this->resolveColumnsPerTable() as $table => $columns) {
            $event->addSqlData($this->createTableStatement($table, $columns));
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function resolveColumnsPerTable(): array
    {
        $tables = [];

        foreach (TranslationHelper::additionalReferenceTables() as $table) {
            $tables[$table] = self::REFERENCE_TABLE_COLUMNS;
        }

        // A table translated in its own right needs the full set, even when it is
        // additionally referenced by another table.
        foreach (TranslationHelper::additionalTables() as $table) {
            $tables[$table] = array_keys(self::COLUMN_DEFINITIONS);
        }

        return array_diff_key($tables, array_flip(self::STATICALLY_DEFINED_TABLES));
    }

    /**
     * @param list<string> $columns
     */
    private function createTableStatement(string $table, array $columns): string
    {
        $definitions = array_map(
            static fn (string $column): string => '    ' . $column . ' ' . self::COLUMN_DEFINITIONS[$column],
            $columns
        );

        return sprintf("CREATE TABLE %s (\n%s\n);\n", $table, implode(",\n", $definitions));
    }
}
