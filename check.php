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

// Security incidents, end-of-life and custom notices from getkirby.com, the same
// data as in the Panel's system view (its plugin versions lag, hence Packagist below)
$statuses = ['getkirby/cms' => $kirby->system()->updateStatus()];

foreach ($kirby->plugins() as $plugin) {
    $statuses[$plugin->name()] = $plugin->updateStatus();
}

$font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
$mono = 'font-family:ui-monospace,Menlo,Consolas,monospace;';
$esc  = fn (string $v) => htmlspecialchars($v, ENT_QUOTES);

// Markdown to HTML, with headings and spacing sized for the inside of a box
$md = fn (string $markdown) => preg_replace(
    ['/<h[1-6]>/', '/<\/h[1-6]>/', '/<(p|ul|ol)>/'],
    ['<p style="margin:12px 0 4px;font-size:13px;font-weight:600;">', '</p>', '<$1 style="margin:0 0 8px;">'],
    $kirby->markdown($markdown)
);

// A box (card) with an optional heading
$box = fn (string $content, string $title = '') => "<div style=\"margin:0 0 16px;padding:16px 20px;background:#ffffff;border:1px solid #e0e0e0;border-radius:3px;{$font}font-size:14px;line-height:1.5;color:#16171a;\">"
    . ($title !== '' ? "<h2 style=\"margin:0;font-size:16px;font-weight:600;\">{$title}</h2>" : '')
    . $content
    . '</div>';

$updates = [];
$cards   = [];
$rows    = [];

foreach ($installed as $name => $current) {
    $releases = $fetch("https://repo.packagist.org/p2/{$name}.json")['packages'][$name] ?? [];
    $versions = array_filter(array_map(fn ($v) => $normalize($v['version']), $releases), $isStable);
    usort($versions, 'version_compare');
    $latest = end($versions);

    if ($latest === false || version_compare($latest, $current, '<=')) {
        continue;
    }

    $notes    = [];
    $items    = [];
    $link     = "https://packagist.org/packages/{$name}";
    $security = in_array(($statuses[$name] ?? null)?->status(), ['security-update', 'security-upgrade'], true);

    // Packagist's minified format only lists the source on the first entry
    if (preg_match('~github\.com/([^/]+/[^/]+?)(\.git)?$~', $releases[0]['source']['url'] ?? '', $repo)) {
        $link = "https://github.com/{$repo[1]}/releases";

        foreach ($fetch("https://api.github.com/repos/{$repo[1]}/releases?per_page=30") as $release) {
            $version = $normalize($release['tag_name'] ?? '');

            if ($isStable($version) && version_compare($version, $current, '>') && version_compare($version, $latest, '<=')) {
                $body    = trim($release['body'] ?? '');
                $notes[] = "### {$version}\n\n" . $body . "\n\n{$release['html_url']}";
                $items[] = ['version' => $version, 'body' => $body, 'url' => $release['html_url']];

                // getkirby.com's data lags for plugins, so the notes count as well
                $security = $security || preg_match('/security|vulnerab|\bCVE-\d/i', $body) === 1;

                if ($version === $latest) {
                    $link = $release['html_url'];
                }
            }
        }
    }

    $rows[]    = compact('name', 'current', 'latest', 'link', 'security');
    $inner = '';

    foreach ($items as $item) {
        $inner .= '<div style="margin:16px 0 0;padding:12px 0 0;border-top:1px solid #e0e0e0;">'
            . '<table role="presentation" cellspacing="0" cellpadding="0" width="100%"><tr>'
            . "<td><span style=\"display:inline-block;padding:2px 8px;border-radius:3px;{$mono}font-size:12px;font-weight:600;color:#16171a;background:#efefef;\">" . $esc($item['version']) . '</span></td>'
            . '<td align="right"><a href="' . $esc($item['url']) . '" style="color:#4271ae;font-size:12px;text-decoration:none;">Release on GitHub →</a></td>'
            . '</tr></table>'
            . $md($item['body'] ?: 'No release notes.')
            . '</div>';
    }

    $cards[]   = $box($inner ?: '<p style="margin:8px 0 0;color:#5d5d5d;">No release notes found.</p>', $esc($name) . " <span style=\"{$mono}font-size:13px;font-weight:400;color:#5d5d5d;\">" . $esc($current) . ' → ' . $esc($latest) . '</span>');
    $updates[] = "## {$name}: {$current} → {$latest}\n\n" . (implode("\n\n", $notes) ?: 'No release notes found.');
}

foreach ($statuses as $name => $status) {
    foreach ($status?->messages() ?? [] as $message) {
        $notices[] = "- **{$name}:** {$message['text']} {$message['link']}";
    }
}

if (isset($notices)) {
    $notice = "## ⚠️ Security notices\n\n" . implode("\n", $notices);

    array_unshift($updates, $notice);
    array_unshift($cards, $box($md($notice)));
}

$hash = md5(implode($updates) . json_encode($rows));

if ($updates === [] || $cache->get('hash') === $hash) {
    return 'No new updates.';
}

// Summary table at the top, styled after the Panel's tables
$html = '';
$summary = '';

if ($rows !== []) {
    $th    = 'padding:8px 12px;text-align:left;font-size:12px;font-weight:600;color:#5d5d5d;background:#f7f7f7;border-bottom:1px solid #e0e0e0;';
    $td    = 'padding:10px 12px;font-size:14px;color:#16171a;border-bottom:1px solid #eeeeee;';
    $badge = fn (bool $on) => $on
        ? '<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:600;color:#ffffff;background:#d16464;">Security</span>'
        : '<span style="color:#9d9d9d;">–</span>';

    $html = '<table role="presentation" cellspacing="0" cellpadding="0" width="100%" style="border-collapse:separate;border-spacing:0;margin:0 0 32px;background:#ffffff;border:1px solid #e0e0e0;border-radius:3px;overflow:hidden;{$font}">' 
        . '<thead><tr>'
        . "<th style=\"{$th}\">Plugin</th>"
        . "<th style=\"{$th}\">Installed</th>"
        . "<th style=\"{$th}\">Latest</th>"
        . "<th style=\"{$th}\">Important</th>"
        . '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $html .= '<tr>'
            . "<td style=\"{$td}\"><a href=\"" . $esc($row['link']) . '\" style="color:#4271ae;font-weight:600;text-decoration:none;">' . $esc($row['name']) . '</a></td>'
            . "<td style=\"{$td}font-family:ui-monospace,Menlo,Consolas,monospace;\">" . $esc($row['current']) . '</td>'
            . "<td style=\"{$td}font-family:ui-monospace,Menlo,Consolas,monospace;\">" . $esc($row['latest']) . '</td>'
            . "<td style=\"{$td}\">" . $badge($row['security']) . '</td>'
            . '</tr>';
    }

    $html .= '</tbody></table>';

    $width = max(array_map(fn ($r) => strlen($r['name']), $rows));

    foreach ($rows as $row) {
        $summary .= str_pad($row['name'], $width) . "  {$row['current']} → {$row['latest']}" . ($row['security'] ? '  [SECURITY]' : '') . "\n";
    }

    $summary = trim($summary) . "\n\n---\n\n";
}

$text = $summary . implode("\n\n---\n\n", $updates);

$adminEmails = $kirby->users()->role('admin')->pluck('email');
$securityRecipients = [];

foreach ((array) $option('securityEmails') as $entry) {
    foreach (preg_split('/[\r\n,]+/', (string) $entry) as $email) {
        $email = trim($email);

        if ($email !== '') {
            $securityRecipients[] = $email;
        }
    }
}

$hasSecurityUpdate = in_array(true, array_column($rows, 'security'), true);
$to = $adminEmails;

if ($hasSecurityUpdate && $securityRecipients !== []) {
    $to = array_values(array_unique(array_merge($to, $securityRecipients)));
}

$kirby->email([
    'from'     => $option('from') ?? $kirby->option('auth.challenge.email.from'),
    'fromName' => $option('fromName') ?? $kirby->option('auth.challenge.email.fromName'),
    'to'       => $to,
    'subject'  => 'Updates for ' . $kirby->site()->title(),
    'body'     => [
        'text' => $text,
        'html' => $html . implode('', $cards),
    ],
]);

$cache->set('hash', $hash);

return 'Update email sent.';
