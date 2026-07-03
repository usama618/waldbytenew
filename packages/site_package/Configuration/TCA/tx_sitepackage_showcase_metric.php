<?php

declare(strict_types=1);

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_metric',
        'label' => 'value_text',
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
            'showitem' => 'value_text, label',
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
        'value_text' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_metric.value_text',
            'config' => [
                'type' => 'input',
                'max' => 64,
            ],
        ],
        'label' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_showcase_metric.label',
            'config' => [
                'type' => 'input',
                'max' => 255,
            ],
        ],
    ],
];
