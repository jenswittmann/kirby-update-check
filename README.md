# Kirby Update Check

> **Beta:** this plugin is at an early stage, and its options and behavior may still change.

Nightly update check for [Kirby CMS](https://getkirby.com). When the Kirby core or any Composer-installed plugin has a new release, all users with the `admin` role get an email with the release notes.

Kirby's Panel has its own update check under *System*, but it only shows updates when you open the Panel, and its plugin data on getkirby.com is often out of date. This plugin reads versions straight from Packagist and GitHub, and sends the result to you by email.

## Features

- **Versions:** compares the installed Kirby core and every Composer package of type `kirby-plugin` with the latest stable release on [Packagist](https://packagist.org). Alpha, beta and RC releases are ignored.
- **Release notes:** the email includes the notes of every GitHub release between the installed and the latest version, with links.
- **Security notices:** the email also includes security incidents, end-of-life warnings and custom notices from getkirby.com. This is the same data the Panel shows, read via Kirby's `updateStatus()`, and it also covers plugins that weren't installed via Composer. Kirby caches this data for 3 days, so a new notice can take up to 3 days to appear.
- **No repeated emails:** the last reported state is cached, so each set of updates is only emailed once. You get a new email as soon as anything changes.
- **Works with URL-only cronjobs:** runs through a route protected by a token, so it also works on shared hosting that can only call URLs (e.g. All-Inkl).

## Requirements

- Kirby 5
- PHP 8.1+
- A working email setup in Kirby (the same one used for login codes)

## Installation

```bash
composer require jenswittmann/kirby-update-check
```

## Setup

1. Set a random token in `site/config/config.php`:

   ```php
   return [
       'jenswittmann.kirby-update-check' => [
           // required, e.g. from: openssl rand -hex 24
           'token' => 'your-random-token',
       ],
   ];
   ```

   Better still, don't commit the token: read it from an environment variable or a `.env` file instead.

2. Add a daily cronjob that calls the check URL:

   ```
   https://example.com/cron/update-check?token=your-random-token
   ```

   On a server with regular cron access, you can call the URL with curl:

   ```
   0 3 * * * curl -fsS "https://example.com/cron/update-check?token=your-random-token" > /dev/null
   ```

   On **All-Inkl**, go to **Tools → Cronjobs** in KAS, enter the URL and pick a nightly time. Turn off the email with the cron output, or you will get "No new updates." every night.

## Options

| Option        | Default                                  | Description                                                                          |
| ------------- | ---------------------------------------- | ------------------------------------------------------------------------------------ |
| `token`       | `null`                                   | Required. The route is disabled until a token is set.                                |
| `githubToken` | `null`                                   | GitHub personal access token (no scopes needed). Raises GitHub's API limit from 60 to 5000 requests/hour. |
| `from`        | `auth.challenge.email.from` option       | Sender address of the email.                                                         |
| `fromName`    | `auth.challenge.email.fromName` option   | Sender name of the email.                                                            |

All options are set under the `jenswittmann.kirby-update-check` key.

## Responses

| Request                   | Response                      |
| ------------------------- | ----------------------------- |
| Missing or wrong token    | Regular 404 page              |
| No new updates            | `200`: `No new updates.`      |
| Updates found, email sent | `200`: `Update email sent.`   |

To send the email again for the same updates, clear the plugin cache (`site/cache/<host>/jenswittmann/kirby-update-check`).

## License

[MIT](LICENSE)
