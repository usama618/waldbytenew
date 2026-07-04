<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'SEO Assistant',
    'description' => 'Imports Google Search Console data and creates reviewable SEO recommendations.',
    'category' => 'module',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'backend' => '13.4.0-13.4.99',
        ],
        'conflicts' => [
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'App\\SeoAssistant\\' => 'Classes',
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
