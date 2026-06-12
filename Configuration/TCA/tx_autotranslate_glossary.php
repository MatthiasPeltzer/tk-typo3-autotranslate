<?php

declare(strict_types=1);

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary',
        'label' => 'source_lang',
        'label_userFunc' => \ThieleUndKlose\Autotranslate\UserFunction\Tca::class . '->glossaryLabel',
        'iconfile' => 'EXT:autotranslate/Resources/Public/Icons/Glossary.svg',
        'hideTable' => false,
        'crdate' => 'crdate',
        'tstamp' => 'tstamp',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => '
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:general,
                    source_lang, target_lang, entries,
                --div--;LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary.tab.sync,
                    sync_ready, last_sync, glossary_id, sync_error,
                --div--;LLL:EXT:core/Resources/Private/Language/Form/locallang_tabs.xlf:access,
                    hidden,
            ',
        ],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'default' => 0,
                'items' => [
                    ['label' => ''],
                ],
            ],
        ],
        'source_lang' => [
            'exclude' => false,
            'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary.source_lang',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'itemsProcFunc' => \ThieleUndKlose\Autotranslate\UserFunction\FormEngine\GlossaryDeepLLanguageItems::class . '->sourceItems',
                'required' => true,
                'size' => 1,
                'maxitems' => 1,
            ],
        ],
        'target_lang' => [
            'exclude' => false,
            'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary.target_lang',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'itemsProcFunc' => \ThieleUndKlose\Autotranslate\UserFunction\FormEngine\GlossaryDeepLLanguageItems::class . '->targetItems',
                'required' => true,
                'size' => 1,
                'maxitems' => 1,
            ],
        ],
        'entries' => [
            'exclude' => false,
            'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary.entries',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_autotranslate_glossary_entry',
                'foreign_field' => 'glossary',
                'appearance' => [
                    'collapseAll' => true,
                    'expandSingle' => true,
                    'useSortable' => true,
                ],
                'minitems' => 1,
            ],
        ],
        'glossary_id' => [
            'exclude' => true,
            'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary.glossary_id',
            'config' => [
                'type' => 'input',
                'readOnly' => true,
                'size' => 40,
            ],
        ],
        'last_sync' => [
            'exclude' => true,
            'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary.last_sync',
            'config' => [
                'type' => 'datetime',
                'readOnly' => true,
                'default' => 0,
            ],
        ],
        'sync_ready' => [
            'exclude' => true,
            'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary.sync_ready',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                'readOnly' => true,
                'default' => 0,
                'items' => [
                    ['label' => ''],
                ],
            ],
        ],
        'sync_error' => [
            'exclude' => true,
            'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary.sync_error',
            'config' => [
                'type' => 'text',
                'readOnly' => true,
                'rows' => 3,
            ],
        ],
    ],
];
