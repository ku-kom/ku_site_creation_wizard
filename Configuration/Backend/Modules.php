<?php

return [
    'site_wizard' => [
        'parent' => 'site',
        'position' => ['before' => 'site_configuration'],
        'access' => 'user',
        'path' => '/module/site/wizard',
        'iconIdentifier' => 'module-urls',
        'labels' => 'LLL:EXT:ku_sitewizard/Resources/Private/Language/locallang_sitewizard_module.xlf',
        'routes' => [
            '_default' => [
                'target' => \CopenhagenUniversity\SiteWizard\Backend\Controller\SiteWizardController::class . '::handleRequest',
            ],
        ],
    ],
];