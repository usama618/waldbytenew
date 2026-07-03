<?php

declare(strict_types=1);

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step',
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
            'showitem' => 'step_number, icon, title, bodytext',
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
        'step_number' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.step_number',
            'config' => [
                'type' => 'input',
                'max' => 8,
                'eval' => 'trim',
                'default' => '01',
            ],
        ],
        'icon' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.icon',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.icon.spark', 'spark'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.icon.brief', 'brief'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.icon.shield', 'shield'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.icon.heart', 'heart'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.icon.search', 'search'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.icon.target', 'target'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.icon.code', 'code'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.icon.chart', 'chart'],
                ],
                'default' => 'spark',
            ],
        ],
        'title' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.title',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim,required',
            ],
        ],
        'bodytext' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_process_step.bodytext',
            'config' => [
                'type' => 'text',
                'rows' => 4,
                'eval' => 'trim',
            ],
        ],
    ],
];
