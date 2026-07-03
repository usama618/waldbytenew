<?php

declare(strict_types=1);

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_trust_item',
        'label' => 'label',
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
            'showitem' => 'icon, label',
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
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_trust_item.icon',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_trust_item.icon.check', 'check'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_trust_item.icon.bolt', 'bolt'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_trust_item.icon.shield', 'shield'],
                    ['LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_trust_item.icon.heart', 'heart'],
                ],
                'default' => 'check',
            ],
        ],
        'label' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_trust_item.label',
            'config' => [
                'type' => 'input',
                'max' => 255,
            ],
        ],
    ],
];
