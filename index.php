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
        // optional: additional recipients, only emailed if there is a security update
        'securityEmails' => [],
        // for hosts without a cronjob: run the check once a day after a frontend request
        'frontend'       => false,
        // minimum number of days between two emails, 0 = no limit (every change is mailed)
        'interval'       => 0,
        // the same for emails that contain a security update
        'securityInterval' => 0,
        // introduction at the top of the email, empty string to disable
        'intro'        => 'Updates are available for your Kirby site. Please review the changes below and update the affected packages. Entries marked "Security" should be installed as soon as possible.',
    ],
    'hooks' => [
        'system.loadPlugins:after' => function () {
            $kirby  = kirby();
            $option = fn (string $key) => $kirby->option('jenswittmann.kirby-update-check.' . $key);

            if (!$option('frontend') || PHP_SAPI === 'cli' || $kirby->request()->method() !== 'GET') {
                return;
            }

            // only real page requests, no Panel, API, media or cron route
            $skip = [$kirby->option('panel.slug', 'panel'), $kirby->option('api.slug', 'api'), 'media', 'cron'];

            if (in_array($kirby->request()->path()->first(), $skip, true)) {
                return;
            }

            $cache = $kirby->cache('jenswittmann.kirby-update-check');

            if ($cache->get('lastRun') !== null) {
                return;
            }

            // set first, so parallel requests don't start a second check
            $cache->set('lastRun', time(), 24 * 60);

            // run after the response has been sent, so the visitor doesn't wait
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }

                ignore_user_abort(true);
                set_time_limit(120);

                try {
                    require __DIR__ . '/check.php';
                } catch (Throwable $e) {
                    error_log('kirby-update-check: ' . $e->getMessage());
                }
            });
        },
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
