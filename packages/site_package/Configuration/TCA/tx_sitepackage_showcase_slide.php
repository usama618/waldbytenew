<?php

declare(strict_types=1);

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_slide',
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
                eyebrow, title, bodytext,
                --div--;Actions,
                    cta_label, cta_link,
                --div--;Visual,
                    badge_label, image,
                --div--;Metrics,
                    tx_sitepackage_showcase_metrics
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
        'eyebrow' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_slide.eyebrow',
            'config' => [
                'type' => 'input',
                'max' => 255,
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_slide.title',
            'config' => [
                'type' => 'input',
                'max' => 255,
            ],
        ],
        'bodytext' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_slide.bodytext',
            'config' => [
                'type' => 'text',
                'enableRichtext' => true,
            ],
        ],
        'cta_label' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_slide.cta_label',
            'config' => [
                'type' => 'input',
                'max' => 255,
            ],
        ],
        'cta_link' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_slide.cta_link',
            'config' => [
                'type' => 'link',
                'allowedTypes' => ['page', 'url', 'record', 'folder', 'file', 'email', 'telephone'],
            ],
        ],
        'badge_label' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_slide.badge_label',
            'config' => [
                'type' => 'input',
                'max' => 255,
            ],
        ],
        'image' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_slide.image',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
                'minitems' => 0,
                'allowed' => 'jpg,jpeg,png,webp,avif,svg',
            ],
        ],
        'tx_sitepackage_showcase_metrics' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_slide.metrics',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_sitepackage_showcase_metric',
                'foreign_field' => 'uid_foreign',
                'foreign_table_field' => 'tablename',
                'foreign_match_fields' => [
                    'fieldname' => 'tx_sitepackage_showcase_metrics',
                ],
                'appearance' => [
                    'expandSingle' => true,
                    'useSortable' => true,
                    'newRecordLinkAddTitle' => true,
                ],
            ],
        ],
    ],
];
