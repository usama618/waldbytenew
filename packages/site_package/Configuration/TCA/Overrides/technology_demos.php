<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.technology_demos',
        'technology_demos',
        'content-gallery',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['technology_demos'] = 'content-gallery';

$GLOBALS['TCA']['tt_content']['types']['technology_demos'] = [
    'showitem' => '
        --div--;General,
            CType, header, hero_eyebrow, bodytext,
        --div--;Demos,
            tx_sitepackage_technology_demos,
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
    ],
];
