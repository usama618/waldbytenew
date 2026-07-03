<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.project_cta_banner',
        'project_cta_banner',
        'content-call-to-action',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['project_cta_banner'] = 'content-call-to-action';

$GLOBALS['TCA']['tt_content']['types']['project_cta_banner'] = [
    'showitem' => '
        --div--;General,
            CType, header, bodytext,
        --div--;Actions,
            cta_primary_label, cta_primary_link, cta_secondary_label, cta_secondary_link,
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
