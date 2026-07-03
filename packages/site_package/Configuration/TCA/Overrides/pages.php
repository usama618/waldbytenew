<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

$GLOBALS['TCA']['pages']['columns']['blog_badge'] = [
    'label' => 'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:pages.blog_badge',
    'config' => [
        'type' => 'input',
        'max' => 255,
        'default' => 'BLOG',
    ],
];

ExtensionManagementUtility::addTCAcolumns('pages', [
    'blog_badge' => $GLOBALS['TCA']['pages']['columns']['blog_badge'],
]);

ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    'blog_badge',
    '137',
    'after:featured_image'
);
