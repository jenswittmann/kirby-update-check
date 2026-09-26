<?php

use Kirby\Cms\App;

App::plugin('jenswittmann/kirby-update-check', [
    'options' => [
        'cache'       => true,
        // required: the route stays disabled without a token
        'token'       => null,
        // optional: raises GitHub's API limit from 60 to 5000 requests/hour
        'githubToken' => null,
        // defaults to the sender of Kirby's login code emails
        'from'        => null,
        'fromName'    => null,
    ],
    'routes' => [
        [
            'pattern' => 'cron/update-check',
            'action'  => function () {
                $token = option('jenswittmann.kirby-update-check.token');

                if (empty($token) || !hash_equals($token, (string) get('token'))) {
                    return false;
                }

                return require __DIR__ . '/check.php';
            },
        ],
    ],
]);
