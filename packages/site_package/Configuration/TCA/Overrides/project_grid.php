<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.project_grid',
        'project_grid',
        'content-elements-textmedia',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['project_grid'] = 'content-elements-textmedia';

$GLOBALS['TCA']['tt_content']['types']['project_grid'] = [
    'showitem' => '
        --div--;General,
            CType, header, hero_eyebrow, bodytext,
        --div--;Projects,
            tx_sitepackage_project_cards,
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
