<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.process_timeline',
        'process_timeline',
        'content-menu-abstract',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['process_timeline'] = 'content-menu-abstract';

$GLOBALS['TCA']['tt_content']['types']['process_timeline'] = [
    'showitem' => '
        --div--;General,
            CType, header, process_eyebrow,
        --div--;Steps,
            tx_sitepackage_process_steps,
        --div--;Language,
            --palette--;;language,
        --div--;Access,
            --palette--;;hidden, --palette--;;access,
        --div--;Categories,
            categories
    ',
];
