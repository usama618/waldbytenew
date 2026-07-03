<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.about_cards',
        'about_cards',
        'content-bullets',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['about_cards'] = 'content-bullets';

$GLOBALS['TCA']['tt_content']['types']['about_cards'] = [
    'showitem' => '
        --div--;General,
            CType, header, hero_eyebrow, bodytext,
        --div--;Action,
            cta_primary_label, cta_primary_link,
        --div--;Cards,
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
