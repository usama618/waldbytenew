<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPlugin(
    [
        'LLL:EXT:site_package/Resources/Private/Language/locallang_db.xlf:CType.services_overview',
        'services_overview',
        'content-card',
    ],
    'CType',
    'site_package'
);

$GLOBALS['TCA']['tt_content']['ctrl']['typeicon_classes']['services_overview'] = 'content-card';

$GLOBALS['TCA']['tt_content']['types']['services_overview'] = [
    'showitem' => '
        --div--;General,
            CType, header, services_eyebrow,
        --div--;Link,
            services_link_label, services_link,
        --div--;Items,
            tx_sitepackage_service_items,
        --div--;Language,
            --palette--;;language,
        --div--;Access,
            --palette--;;hidden, --palette--;;access,
        --div--;Categories,
            categories
    ',
];
