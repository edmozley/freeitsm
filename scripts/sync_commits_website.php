<?php
/**
 * Push all git commits to the freeitsm.co.uk Updates dashboard, for the
 * commit-activity heatmap. Reads `git log` (hash, date, subject) and bulk-posts
 * in batches to the site write API's sync_commits action (INSERT IGNORE on the
 * hash, so re-running is idempotent and the table auto-creates server-side).
 *
 * Usage:
 *   php scripts/sync_commits_website.php --dry-run
 *   php scripts/sync_commits_website.php --url=https://freeitsm.co.uk/api/updates.php --key=THEKEY
 */

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$dryRun = !empty($args['dry-run']);
$apiUrl = $args['url'] ?? 'http://localhost/freeitsm/api/updates.php';
$apiKey = $args['key'] ?? 'local-dev-key';
$batch  = isset($args['batch']) ? max(1, (int)$args['batch']) : 250;

$root = dirname(__DIR__);
$null = (stripos(PHP_OS, 'WIN') === 0) ? '2>NUL' : '2>/dev/null';
// Inline --format (escapeshellarg strips % on Windows); @@ delimiter, split limit 3
// keeps any @@ that appears inside a commit subject.
$cmd = 'git -C ' . escapeshellarg($root) . ' log --format=%H@@%cs@@%s ' . $null;
$out = (string)shell_exec($cmd);

$commits = [];
foreach (explode("\n", trim($out)) as $line) {
    if ($line === '') continue;
    $p = explode('@@', $line, 3);
    if (count($p) < 2) continue;
    $commits[] = ['hash' => $p[0], 'date' => $p[1], 'subject' => $p[2] ?? ''];
}

fwrite(STDERR, sprintf("%s %d commits -> %s\n", $dryRun ? "[DRY RUN]" : "Pushing", count($commits), $apiUrl));
if ($dryRun) {
    $byDay = [];
    foreach ($commits as $c) $byDay[$c['date']] = ($byDay[$c['date']] ?? 0) + 1;
    fwrite(STDERR, sprintf("  %d active days; busiest: ", count($byDay)));
    arsort($byDay);
    $top = array_slice($byDay, 0, 3, true);
    foreach ($top as $d => $n) fwrite(STDERR, "$d ($n) ");
    fwrite(STDERR, "\n");
    exit;
}

function api_post(string $url, string $key, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Api-Key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        // PHP's cURL sends no User-Agent unless told to, and the host's web application
        // firewall rejects an anonymous POST outright. Say who we are. (Same cause as
        // the updates sync — see sync_updates_website.php.)
        CURLOPT_USERAGENT => 'FreeITSM-CommitsSync/1.0 (+https://github.com/edmozley/freeitsm)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    if ($body === false) return ['ok' => false, 'error' => $err ?: 'no response'];
    $j = json_decode($body, true);
    return ['ok' => $code < 300 && !empty($j['success']), 'json' => $j, 'raw' => $body];
}

$chunks = array_chunk($commits, $batch);
$sent = 0; $fail = 0; $total = 0;
foreach ($chunks as $i => $chunk) {
    $res = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $res = api_post($apiUrl, $apiKey, ['action' => 'sync_commits', 'commits' => $chunk]);
        if ($res['ok']) break;
        usleep(700000);
    }
    if ($res['ok']) { $sent += count($chunk); $total = $res['json']['total'] ?? $total; fwrite(STDERR, sprintf("  batch %d/%d ok (%d)\n", $i + 1, count($chunks), $total)); }
    else { $fail++; fwrite(STDERR, sprintf("  batch %d FAILED: %s\n", $i + 1, $res['error'] ?? json_encode($res['json'] ?? $res['raw']))); }
}
fwrite(STDERR, "\nDone: $sent pushed, $fail batches failed, $total total on server\n");
