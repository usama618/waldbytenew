<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.usp_block',
        'usp_block',
        'content-bullets',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['usp_block'] = 'content-bullets';

$GLOBALS['TCA']['tt_content']['types']['usp_block'] = [
    'showitem' => '
        --div--;General,
            CType, header, hero_gradient_word, hero_eyebrow, bodytext,
        --div--;Points,
            tx_sitepackage_value_points,
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
