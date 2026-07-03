<?php

defined('TYPO3') or die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
    'site_package',
    'Configuration/Sets/SitePackage/',
    'Site Package'
);
