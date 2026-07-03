<?php

declare(strict_types=1);

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_value_point',
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
            'showitem' => 'icon, title, bodytext',
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
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_value_point.icon',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_value_point.icon.bolt', 'bolt'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_value_point.icon.layers', 'layers'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_value_point.icon.chart', 'chart'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_value_point.icon.target', 'target'],
                ],
                'default' => 'bolt',
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_value_point.title',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim,required',
            ],
        ],
        'bodytext' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_value_point.bodytext',
            'config' => [
                'type' => 'text',
                'rows' => 4,
                'eval' => 'trim',
            ],
        ],
    ],
];
