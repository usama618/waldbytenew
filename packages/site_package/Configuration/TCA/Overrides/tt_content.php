<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

$additionalColumns = [
    'hero_eyebrow' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.hero_eyebrow',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'hero_gradient_word' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.hero_gradient_word',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'hero_primary_label' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.hero_primary_label',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'hero_primary_link' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.hero_primary_link',
        'config' => [
            'type' => 'link',
            'allowedTypes' => ['page', 'url', 'record', 'folder', 'file', 'email', 'telephone'],
        ],
    ],
    'hero_secondary_label' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.hero_secondary_label',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'hero_secondary_link' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.hero_secondary_link',
        'config' => [
            'type' => 'link',
            'allowedTypes' => ['page', 'url', 'record', 'folder', 'file', 'email', 'telephone'],
        ],
    ],
    'hero_score_label' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.hero_score_label',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'hero_score_value' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.hero_score_value',
        'config' => [
            'type' => 'input',
            'max' => 32,
        ],
    ],
    'hero_score_total' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.hero_score_total',
        'config' => [
            'type' => 'input',
            'max' => 32,
        ],
    ],
    'tx_sitepackage_trust_items' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.tx_sitepackage_trust_items',
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_sitepackage_trust_item',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablename',
            'foreign_match_fields' => [
                'fieldname' => 'tx_sitepackage_trust_items',
            ],
            'appearance' => [
                'expandSingle' => true,
                'useSortable' => true,
                'newRecordLinkAddTitle' => true,
            ],
        ],
    ],
    'tx_sitepackage_showcase_slides' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.tx_sitepackage_showcase_slides',
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_sitepackage_showcase_slide',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablename',
            'foreign_match_fields' => [
                'fieldname' => 'tx_sitepackage_showcase_slides',
            ],
            'appearance' => [
                'expandSingle' => true,
                'useSortable' => true,
                'newRecordLinkAddTitle' => true,
            ],
        ],
    ],
    'tx_sitepackage_partner_logos' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.tx_sitepackage_partner_logos',
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_sitepackage_partner_logo',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablename',
            'foreign_match_fields' => [
                'fieldname' => 'tx_sitepackage_partner_logos',
            ],
            'appearance' => [
                'expandSingle' => true,
                'useSortable' => true,
                'newRecordLinkAddTitle' => true,
            ],
        ],
    ],
    'tx_sitepackage_value_points' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.tx_sitepackage_value_points',
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_sitepackage_value_point',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablename',
            'foreign_match_fields' => [
                'fieldname' => 'tx_sitepackage_value_points',
            ],
            'appearance' => [
                'expandSingle' => true,
                'useSortable' => true,
                'newRecordLinkAddTitle' => true,
            ],
        ],
    ],
    'services_eyebrow' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.services_eyebrow',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'services_link_label' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.services_link_label',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'services_link' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.services_link',
        'config' => [
            'type' => 'link',
            'allowedTypes' => ['page', 'url', 'record', 'folder', 'file', 'email', 'telephone'],
        ],
    ],
    'process_eyebrow' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.process_eyebrow',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'testimonials_eyebrow' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.testimonials_eyebrow',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'blog_list_page' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.blog_list_page',
        'config' => [
            'type' => 'group',
            'allowed' => 'pages',
            'size' => 1,
            'maxitems' => 1,
            'minitems' => 0,
            'suggestOptions' => [
                'default' => [
                    'searchWholePhrase' => 1,
                ],
            ],
        ],
    ],
    'cta_primary_label' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.cta_primary_label',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'cta_primary_link' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.cta_primary_link',
        'config' => [
            'type' => 'link',
            'allowedTypes' => ['page', 'url', 'record', 'folder', 'file', 'email', 'telephone'],
        ],
    ],
    'cta_secondary_label' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.cta_secondary_label',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'cta_secondary_link' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.cta_secondary_link',
        'config' => [
            'type' => 'link',
            'allowedTypes' => ['page', 'url', 'record', 'folder', 'file', 'email', 'telephone'],
        ],
    ],
    'footer_brand_name' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.footer_brand_name',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'footer_brand_text' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.footer_brand_text',
        'config' => [
            'type' => 'text',
            'rows' => 3,
        ],
    ],
    'footer_address_line_1' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.footer_address_line_1',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'footer_address_line_2' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.footer_address_line_2',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'footer_email' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.footer_email',
        'config' => [
            'type' => 'input',
            'eval' => 'trim,email',
            'max' => 255,
        ],
    ],
    'footer_phone' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.footer_phone',
        'config' => [
            'type' => 'input',
            'eval' => 'trim',
            'max' => 255,
        ],
    ],
    'footer_copyright' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.footer_copyright',
        'config' => [
            'type' => 'input',
            'max' => 255,
        ],
    ],
    'footer_social_dribbble' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.footer_social_dribbble',
        'config' => [
            'type' => 'link',
            'allowedTypes' => ['url'],
        ],
    ],
    'footer_social_linkedin' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.footer_social_linkedin',
        'config' => [
            'type' => 'link',
            'allowedTypes' => ['url'],
        ],
    ],
    'footer_social_x' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.footer_social_x',
        'config' => [
            'type' => 'link',
            'allowedTypes' => ['url'],
        ],
    ],
    'footer_social_instagram' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.footer_social_instagram',
        'config' => [
            'type' => 'link',
            'allowedTypes' => ['url'],
        ],
    ],
    'tx_sitepackage_service_items' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.tx_sitepackage_service_items',
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_sitepackage_service_item',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablename',
            'foreign_match_fields' => [
                'fieldname' => 'tx_sitepackage_service_items',
            ],
            'appearance' => [
                'expandSingle' => true,
                'useSortable' => true,
                'newRecordLinkAddTitle' => true,
            ],
        ],
    ],
    'tx_sitepackage_process_steps' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.tx_sitepackage_process_steps',
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_sitepackage_process_step',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablename',
            'foreign_match_fields' => [
                'fieldname' => 'tx_sitepackage_process_steps',
            ],
            'appearance' => [
                'expandSingle' => true,
                'useSortable' => true,
                'newRecordLinkAddTitle' => true,
            ],
            'minitems' => 0,
            'maxitems' => 4,
        ],
    ],
    'tx_sitepackage_testimonial_items' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.tx_sitepackage_testimonial_items',
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_sitepackage_testimonial_item',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablename',
            'foreign_match_fields' => [
                'fieldname' => 'tx_sitepackage_testimonial_items',
            ],
            'appearance' => [
                'expandSingle' => true,
                'useSortable' => true,
                'newRecordLinkAddTitle' => true,
            ],
        ],
    ],
    'tx_sitepackage_technology_demos' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.tx_sitepackage_technology_demos',
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_sitepackage_technology_demo',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablename',
            'foreign_match_fields' => [
                'fieldname' => 'tx_sitepackage_technology_demos',
            ],
            'appearance' => [
                'expandSingle' => true,
                'useSortable' => true,
                'newRecordLinkAddTitle' => true,
            ],
        ],
    ],
    'tx_sitepackage_project_cards' => [
        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tt_content.tx_sitepackage_project_cards',
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_sitepackage_project_card',
            'foreign_field' => 'uid_foreign',
            'foreign_table_field' => 'tablename',
            'foreign_match_fields' => [
                'fieldname' => 'tx_sitepackage_project_cards',
            ],
            'appearance' => [
                'expandSingle' => true,
                'useSortable' => true,
                'newRecordLinkAddTitle' => true,
            ],
        ],
    ],
];

ExtensionManagementUtility::addTCAcolumns('tt_content', $additionalColumns);

// The dedicated "Header Only" element represents the page's primary heading.
$GLOBALS['TCA']['tt_content']['types']['header']['columnsOverrides']['header_layout']['config']['default'] = 1;
