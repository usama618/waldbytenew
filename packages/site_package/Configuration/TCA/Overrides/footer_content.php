<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.footer_content',
        'footer_content',
        'content-menu-related',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['footer_content'] = 'content-menu-related';

$GLOBALS['TCA']['tt_content']['types']['footer_content'] = [
    'showitem' => '
        --div--;General,
            CType, footer_brand_name, footer_brand_text,
        --div--;Brand,
            media,
        --div--;Contact,
            footer_address_line_1, footer_address_line_2, footer_email, footer_phone, footer_copyright,
        --div--;Social,
            footer_social_dribbble, footer_social_linkedin, footer_social_x, footer_social_instagram,
        --div--;Language,
            --palette--;;language,
        --div--;Access,
            --palette--;;hidden, --palette--;;access
    ',
    'columnsOverrides' => [
        'media' => [
            'config' => [
                'maxitems' => 1,
                'minitems' => 0,
            ],
        ],
    ],
];
