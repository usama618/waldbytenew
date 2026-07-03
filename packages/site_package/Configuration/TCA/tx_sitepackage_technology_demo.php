<?php

declare(strict_types=1);

defined('TYPO3') or die();

return [
    'ctrl' => [
        'title' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_technology_demo',
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
                title, bodytext,
                --div--;Media,
                    logo, image,
                --div--;Actions,
                    primary_label, primary_link, secondary_label, secondary_link,
                --div--;Meta,
                    note_text
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
        'title' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_technology_demo.title',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim,required',
            ],
        ],
        'bodytext' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_technology_demo.bodytext',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'eval' => 'trim',
            ],
        ],
        'logo' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_technology_demo.logo',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
                'allowed' => 'common-image-types',
            ],
        ],
        'image' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_technology_demo.image',
            'config' => [
                'type' => 'file',
                'maxitems' => 1,
                'allowed' => 'common-image-types',
            ],
        ],
        'primary_label' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_technology_demo.primary_label',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'primary_link' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_technology_demo.primary_link',
            'config' => [
                'type' => 'link',
                'allowedTypes' => ['page', 'url', 'record', 'folder', 'file', 'email', 'telephone'],
            ],
        ],
        'secondary_label' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_technology_demo.secondary_label',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
        'secondary_link' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_technology_demo.secondary_link',
            'config' => [
                'type' => 'link',
                'allowedTypes' => ['page', 'url', 'record', 'folder', 'file', 'email', 'telephone'],
            ],
        ],
        'note_text' => [
            'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:tx_sitepackage_technology_demo.note_text',
            'config' => [
                'type' => 'input',
                'max' => 255,
                'eval' => 'trim',
            ],
        ],
    ],
];
