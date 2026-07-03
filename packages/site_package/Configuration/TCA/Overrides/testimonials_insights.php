<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.testimonials_insights',
        'testimonials_insights',
        'content-widget-list',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['testimonials_insights'] = 'content-widget-list';

$GLOBALS['TCA']['tt_content']['types']['testimonials_insights'] = [
    'showitem' => '
        --div--;General,
            CType, testimonials_eyebrow,
        --div--;Testimonials,
            tx_sitepackage_testimonial_items,
        --div--;Blog Source,
            pages, blog_list_page,
        --div--;Language,
            --palette--;;language,
        --div--;Access,
            --palette--;;hidden, --palette--;;access,
        --div--;Categories,
            categories
    ',
];
