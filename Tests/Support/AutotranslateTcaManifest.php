<?php

declare(strict_types=1);

namespace ThieleUndKlose\Autotranslate\Tests\Support;

/**
 * Single source of truth for autotranslate TCA expectations used by configuration tests.
 */
final class AutotranslateTcaManifest
{
    /**
     * @var list<string>
     */
    public const FUNCTIONAL_TEST_EXTENSIONS = ['thieleundklose/autotranslate'];

    /**
     * @var list<string>
     */
    public const CUSTOM_TABLES = [
        'tx_autotranslate_batch_item',
        'tx_autotranslate_glossary',
        'tx_autotranslate_glossary_entry',
    ];

    /**
     * @var array<string, list<string>>
     */
    public const OVERRIDDEN_CORE_TABLE_COLUMNS = [
        'tt_content' => ['autotranslate_exclude', 'autotranslate_languages', 'autotranslate_last'],
        'pages' => ['autotranslate_exclude', 'autotranslate_languages', 'autotranslate_last'],
    ];

    /**
     * @var list<string>
     */
    public const SCHEMA_TABLES = [
        'tt_content',
        'pages',
        'tx_autotranslate_batch_item',
        'tx_autotranslate_glossary',
        'tx_autotranslate_glossary_entry',
    ];
}
