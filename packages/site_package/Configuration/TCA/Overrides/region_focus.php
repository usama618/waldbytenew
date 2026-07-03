<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.region_focus',
        'region_focus',
        'content-menu-abstract',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['region_focus'] = 'content-menu-abstract';

$GLOBALS['TCA']['tt_content']['types']['region_focus'] = [
    'showitem' => '
        --div--;General,
            CType, header, hero_eyebrow, bodytext,
        --div--;Media,
            media,
        --div--;Highlights,
            tx_sitepackage_value_points,
        --div--;Markets,
            tx_sitepackage_trust_items,
        --div--;Language,
            --palette--;;language,
        --div--;Access,
            --palette--;;hidden, --palette--;;access,
        --div--;Categories,
            categories
    ',
    'columnsOverrides' => [
        'bodytext' => [
            'config' => [
                'enableRichtext' => true,
            ],
        ],
        'media' => [
            'config' => [
                'maxitems' => 1,
                'minitems' => 0,
            ],
        ],
    ],
];
