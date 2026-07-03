<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.project_showcase',
        'project_showcase',
        'content-carousel',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['project_showcase'] = 'content-carousel';

$GLOBALS['TCA']['tt_content']['types']['project_showcase'] = [
    'showitem' => '
        --div--;General,
            CType, header,
        --div--;Slides,
            tx_sitepackage_showcase_slides,
        --div--;Language,
            --palette--;;language,
        --div--;Access,
            --palette--;;hidden, --palette--;;access,
        --div--;Categories,
            categories
    ',
];
