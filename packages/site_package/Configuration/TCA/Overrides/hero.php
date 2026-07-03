<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.hero',
        'hero',
        'content-special',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['hero'] = 'content-special';

$GLOBALS['TCA']['tt_content']['types']['hero'] = [
    'showitem' => '
        --div--;General,
            CType, header, hero_gradient_word, hero_eyebrow, bodytext,
        --div--;Actions,
            hero_primary_label, hero_primary_link, hero_secondary_label, hero_secondary_link,
        --div--;Trust,
            tx_sitepackage_trust_items,
        --div--;Media,
            media, assets,
        --div--;Logos,
            tx_sitepackage_partner_logos,
        --div--;Metrics,
            hero_score_label, hero_score_value, hero_score_total,
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
        'assets' => [
            'config' => [
                'maxitems' => 1,
                'minitems' => 0,
            ],
        ],
    ],
];
