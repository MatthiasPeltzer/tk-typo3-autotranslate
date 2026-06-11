<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

ExtensionManagementUtility::addTcaSelectItem(
    'pages',
    'module',
    [
        'label' => 'LLL:EXT:autotranslate/Resources/Private/Language/locallang_db.xlf:pages.module.autotranslate_glossary',
        'value' => 'autotranslate_glossary',
        'icon' => 'extensions-autotranslate',
    ],
    '',
    'after'
);

$GLOBALS['TCA']['pages']['ctrl']['typeicon_classes']['contains-autotranslate_glossary'] = 'extensions-autotranslate';
