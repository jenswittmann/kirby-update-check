<?php

use Kirby\Cms\App;

App::plugin('jenswittmann/kirby-update-check', [
    'options' => [
        'cache'        => true,
        // required: the route stays disabled without a token
        'token'        => null,
        // optional: raises GitHub's API limit from 60 to 5000 requests/hour
        'githubToken'  => null,
        // defaults to the sender of Kirby's login code emails
        'from'         => null,
        'fromName'     => null,
        // only send an email if at least one update is a security update
        'securityOnly' => false,
        // introduction at the top of the email, empty string to disable
        'intro'        => 'Updates are available for your Kirby site. Please review the changes below and update the affected packages. Entries marked "Security" should be installed as soon as possible.',
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
