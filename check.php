<?php

/**
 * Compares the installed Kirby core and Composer-installed plugins against
 * Packagist and mails all admins the GitHub release notes of every newer
 * stable version, plus the security notices from getkirby.com. The last
 * reported state is cached, so the same updates are only mailed once.
 */

use Composer\InstalledVersions;
use Kirby\Http\Remote;

$kirby  = kirby();
$option = fn (string $key) => $kirby->option('jenswittmann.kirby-update-check.' . $key);
$cache  = $kirby->cache('jenswittmann.kirby-update-check');

$fetch = fn (string $url) => Remote::get($url, [
    'agent'   => 'Kirby update check',
    'headers' => array_filter([
        'Accept'        => 'application/vnd.github+json',
        'Authorization' => $option('githubToken') ? 'Bearer ' . $option('githubToken') : null,
    ]),
])->json() ?? [];

$normalize = fn (string $v) => ltrim($v, 'vV');
$isStable  = fn (string $v) => preg_match('/^\d+\.\d+\.\d+$/', $v) === 1;

// the core version is always known, even if Kirby isn't installed via Composer
$installed = ['getkirby/cms' => $kirby->version()];

foreach (InstalledVersions::getInstalledPackagesByType('kirby-plugin') as $name) {
    $installed[$name] = $normalize(InstalledVersions::getPrettyVersion($name) ?? '');
}

$updates = [];

foreach ($installed as $name => $current) {
    $releases = $fetch("https://repo.packagist.org/p2/{$name}.json")['packages'][$name] ?? [];
    $versions = array_filter(array_map(fn ($v) => $normalize($v['version']), $releases), $isStable);
    usort($versions, 'version_compare');
    $latest = end($versions);

    if ($latest === false || version_compare($latest, $current, '<=')) {
        continue;
    }

    $notes = [];

    // Packagist's minified format only lists the source on the first entry
    if (preg_match('~github\.com/([^/]+/[^/]+?)(\.git)?$~', $releases[0]['source']['url'] ?? '', $repo)) {
        foreach ($fetch("https://api.github.com/repos/{$repo[1]}/releases?per_page=30") as $release) {
            $version = $normalize($release['tag_name'] ?? '');

            if ($isStable($version) && version_compare($version, $current, '>') && version_compare($version, $latest, '<=')) {
                $notes[] = "### {$version}\n\n" . trim($release['body'] ?? '') . "\n\n{$release['html_url']}";
            }
        }
    }

    $updates[] = "## {$name}: {$current} → {$latest}\n\n" . (implode("\n\n", $notes) ?: 'No release notes found.');
}

// Security incidents, end-of-life and custom notices from getkirby.com, the same
// data as in the Panel's system view (its plugin versions lag, hence Packagist above)
$statuses = ['Kirby' => $kirby->system()->updateStatus()];

foreach ($kirby->plugins() as $plugin) {
    $statuses[$plugin->name()] = $plugin->updateStatus();
}

foreach ($statuses as $name => $status) {
    foreach ($status?->messages() ?? [] as $message) {
        $notices[] = "- **{$name}:** {$message['text']} {$message['link']}";
    }
}

if (isset($notices)) {
    array_unshift($updates, "## ⚠️ Security notices\n\n" . implode("\n", $notices));
}

$hash = md5(implode($updates));

if ($updates === [] || $cache->get('hash') === $hash) {
    return 'No new updates.';
}

$text = implode("\n\n---\n\n", $updates);

$kirby->email([
    'from'     => $option('from') ?? $kirby->option('auth.challenge.email.from'),
    'fromName' => $option('fromName') ?? $kirby->option('auth.challenge.email.fromName'),
    'to'       => $kirby->users()->role('admin')->pluck('email'),
    'subject'  => 'Updates for ' . $kirby->site()->title(),
    'body'     => ['text' => $text, 'html' => $kirby->markdown($text)],
]);

$cache->set('hash', $hash);

return 'Update email sent.';
