<?php

use App\SeoAssistant\Controller\SeoAssistantModuleController;

return [
    'web_seoassistant' => [
        'parent' => 'web',
        'access' => 'user',
        'path' => '/module/web/seo-assistant',
        'iconIdentifier' => 'module-info',
        'labels' => [
            'title' => 'SEO Assistant',
            'description' => 'Google Search Console and AI SEO recommendations',
        ],
        'routes' => [
            '_default' => [
                'target' => SeoAssistantModuleController::class . '::handleRequest',
            ],
        ],
    ],
];
