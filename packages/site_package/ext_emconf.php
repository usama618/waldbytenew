<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Site Package',
    'description' => 'Generic TYPO3 site package base for custom projects',
    'category' => 'templates',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'fluid_styled_content' => '13.4.0-13.4.99',
            'rte_ckeditor' => '13.4.0-13.4.99',
        ],
        'conflicts' => [
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'App\\SitePackage\\' => 'Classes',
        ],
    ],
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearCacheOnLoad' => 1,
    'author' => '',
    'author_email' => '',
    'author_company' => '',
    'version' => '1.0.0',
];
