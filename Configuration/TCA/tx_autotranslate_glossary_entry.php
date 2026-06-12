<?php

declare(strict_types=1);

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary_entry',
        'label' => 'source_term',
        'iconfile' => 'EXT:autotranslate/Resources/Public/Icons/Glossary.svg',
        'hideTable' => true,
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
            'showitem' => 'source_term, target_term',
        ],
    ],
    'columns' => [
        'hidden' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'glossary' => [
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'source_term' => [
            'exclude' => false,
            'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary_entry.source_term',
            'config' => [
                'type' => 'input',
                'required' => true,
                'size' => 40,
                'max' => 255,
            ],
        ],
        'target_term' => [
            'exclude' => false,
            'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary_entry.target_term',
            'description' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:autotranslate_glossary_entry.target_term.description',
            'config' => [
                'type' => 'input',
                'required' => true,
                'size' => 40,
                'max' => 255,
            ],
        ],
    ],
];
