<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.benefit_strip',
        'benefit_strip',
        'content-bullets',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['benefit_strip'] = 'content-bullets';

$GLOBALS['TCA']['tt_content']['types']['benefit_strip'] = [
    'showitem' => '
        --div--;General,
            CType,
        --div--;Points,
            tx_sitepackage_value_points,
        --div--;Language,
            --palette--;;language,
        --div--;Access,
            --palette--;;hidden, --palette--;;access,
        --div--;Categories,
            categories
    ',
];
