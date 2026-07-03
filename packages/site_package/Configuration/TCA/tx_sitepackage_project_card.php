<?php

declare(strict_types=1);

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_project_card',
        'label' => 'title',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'cruser_id' => 'cruser_id',
        'sortby' => 'sorting_foreign',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'iconfile' => 'EXT:site_package/Resources/Public/Icons/Extension.svg',
    ],
    'types' => [
        '1' => [
            'showitem' => '
                badge_label, title, bodytext,
                --div--;Meta,
                    meta_primary, meta_secondary,
                --div--;Visual,
                    image,
                --div--;Metrics,
                    tx_sitepackage_project_metrics,
                --div--;Link,
                    cta_label, cta_link
            ',
        ],
    ],
    'columns' => [
        'hidden' => [
            'exclude' => true,
            'label' => 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.hidden',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
            ],
        ],
        'badge_label' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_project_card.badge_label',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_project_card.title',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim,required',
            ],
        ],
        'bodytext' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_project_card.bodytext',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'eval' => 'trim',
            ],
        ],
        'meta_primary' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_project_card.meta_primary',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'meta_secondary' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_project_card.meta_secondary',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'image' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_project_card.image',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
                'allowed' => 'common-image-types',
            ],
        ],
        'tx_sitepackage_project_metrics' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_project_card.metrics',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_sitepackage_showcase_metric',
                'foreign_field' => 'uid_foreign',
                'foreign_table_field' => 'tablename',
                'foreign_match_fields' => [
                    'fieldname' => 'tx_sitepackage_project_metrics',
                ],
                'appearance' => [
                    'expandSingle' => true,
                    'useSortable' => true,
                    'newRecordLinkAddTitle' => true,
                ],
            ],
        ],
        'cta_label' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_project_card.cta_label',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'cta_link' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_project_card.cta_link',
            'config' => [
                'type' => 'link',
                'allowedTypes' => ['page', 'url', 'record', 'folder', 'file', 'email', 'telephone'],
            ],
        ],
    ],
];
