<?php

declare(strict_types=1);

// Do not edit. Content will be replaced.
return [
    '/' => [
        'di' => [
            'yiisoft/router-fastroute' => [
                'config/di.php',
            ],
            'yiisoft/cache' => [
                'config/di.php',
            ],
            'yiisoft/yii-event' => [
                'config/di.php',
            ],
            'yiisoft/router' => [
                'config/di.php',
            ],
            'yiisoft/db' => [
                'config/di.php',
            ],
        ],
        'di-web' => [
            'yiisoft/router-fastroute' => [
                'config/di-web.php',
            ],
            'yiisoft/user' => [
                'config/di-web.php',
            ],
            'yiisoft/request-provider' => [
                'config/di-web.php',
            ],
            'yiisoft/yii-event' => [
                'config/di-web.php',
            ],
            'yiisoft/error-handler' => [
                'config/di-web.php',
            ],
            'yiisoft/session' => [
                'config/di-web.php',
            ],
        ],
        'params' => [
            'yiisoft/router-fastroute' => [
                'config/params.php',
            ],
            'yiisoft/user' => [
                'config/params.php',
            ],
            'yiisoft/router' => [
                'config/params.php',
            ],
            'yiisoft/session' => [
                'config/params.php',
            ],
            'yiisoft/auth' => [
                'config/params.php',
            ],
            'yiisoft/db' => [
                'config/params.php',
            ],
        ],
        'events-web' => [
            'yiisoft/request-provider' => [
                'config/events-web.php',
            ],
            'yiisoft/middleware-dispatcher' => [
                'config/events-web.php',
            ],
        ],
        'di-console' => [
            'yiisoft/yii-event' => [
                'config/di-console.php',
            ],
        ],
        'params-web' => [
            'yiisoft/yii-event' => [
                'config/params-web.php',
            ],
        ],
        'events-console' => [],
        'params-console' => [
            'yiisoft/yii-event' => [
                'config/params-console.php',
            ],
        ],
    ],
];
