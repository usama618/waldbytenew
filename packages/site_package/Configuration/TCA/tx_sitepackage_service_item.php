<?php

declare(strict_types=1);

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item',
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
                icon, title, bodytext,
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
        'icon' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item.icon',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    [
                        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item.icon.code',
                        'value' => 'code',
                    ],
                    [
                        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item.icon.cart',
                        'value' => 'cart',
                    ],
                    [
                        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item.icon.rocket',
                        'value' => 'rocket',
                    ],
                    [
                        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item.icon.cloud',
                        'value' => 'cloud',
                    ],
                    [
                        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item.icon.search',
                        'value' => 'search',
                    ],
                    [
                        'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item.icon.chart',
                        'value' => 'chart',
                    ],
                ],
                'default' => 'code',
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item.title',
            'config' => [
                'type' => 'input',
                'max' => 255,
            ],
        ],
        'bodytext' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item.bodytext',
            'config' => [
                'type' => 'text',
                'enableRichtext' => true,
                'rows' => 8,
            ],
        ],
        'cta_label' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item.cta_label',
            'config' => [
                'type' => 'input',
                'max' => 255,
            ],
        ],
        'cta_link' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_service_item.cta_link',
            'config' => [
                'type' => 'link',
                'allowedTypes' => ['page', 'url', 'record', 'folder', 'file', 'email', 'telephone'],
            ],
        ],
    ],
];
