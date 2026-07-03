<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.contact_details',
        'contact_details',
        'content-elements-textmedia',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['contact_details'] = 'content-elements-textmedia';

$GLOBALS['TCA']['tt_content']['types']['contact_details'] = [
    'showitem' => '
        --div--;General,
            CType, header, hero_gradient_word, hero_eyebrow, bodytext,
        --div--;Contact,
            footer_brand_name, footer_address_line_1, footer_address_line_2, footer_phone, footer_email,
        --div--;Media,
            media,
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
