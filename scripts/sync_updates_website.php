<?php
/**
 * Sync FreeITSM updates -> the freeitsm.co.uk Updates dashboard (a mashup).
 *
 * Three sources, merged by update number:
 *   1. updates.html  — AUTHORITATIVE for the IDs it curates: real publish dates
 *                      (its weekly-update headings), polished titles/bodies, and
 *                      deep-dive links. Covers roughly Jan–May.
 *   2. CHANGELOG.local.md — every other entry (mainly Jun onward), title/body
 *                      split from the description.
 *   3. git history   — the ship commit (and, for changelog-only entries, the date).
 *
 * Each update is upserted via the site write API, keyed on update_number, so
 * re-running updates rather than duplicating.
 *
 * Usage:
 *   php scripts/sync_updates_website.php --dry-run
 *   php scripts/sync_updates_website.php --url=https://freeitsm.co.uk/api/updates.php --key=THEKEY
 * Flags: --dry-run  --limit=N  --html=<path to updates.html>  --repo=<github repo url>
 */

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$dryRun  = !empty($args['dry-run']);
$apiUrl  = $args['url'] ?? 'http://localhost/freeitsm/api/updates.php';
$apiKey  = $args['key'] ?? 'local-dev-key';
$limit   = isset($args['limit']) ? (int)$args['limit'] : 0;
// Migrate only the pre-numbering entries, leaving every numbered row untouched.
$onlyUnnumbered = !empty($args['unnumbered-only']);
$repoUrl = rtrim($args['repo'] ?? 'https://github.com/edmozley/freeitsm', '/');

$root = dirname(__DIR__);
$changelog = $root . '/CHANGELOG.local.md';
// The curated archive lives in this repo, NOT on the website: the live
// updates.html is now a redirect stub, but this file remains authoritative for
// the real publish dates of the Jan–May entries (git commit dates are wrong for
// them — the changelog was written in batches).
$htmlPath  = $args['html'] ?? ($root . '/data/updates-archive.html');

$md = @file_get_contents($changelog);
if ($md === false) { fwrite(STDERR, "Cannot read $changelog\n"); exit(1); }

// ---- git: map every update number -> [ship commit, date] in ONE traversal ----
function build_ship_map(string $root): array {
    $null = (stripos(PHP_OS, 'WIN') === 0) ? '2>NUL' : '2>/dev/null';
    // --format inline (escapeshellarg strips '%' on Windows); '@@'/'__C__' survive cmd.exe.
    $cmd = 'git -C ' . escapeshellarg($root) . ' log --reverse --format=__C__%H@@%cs -p -- CHANGELOG.local.md ' . $null;
    $out = (string)shell_exec($cmd);
    $map = []; $hash = null; $date = null;
    foreach (explode("\n", $out) as $line) {
        if (strncmp($line, '__C__', 5) === 0) {
            [$hash, $date] = array_pad(explode('@@', substr($line, 5)), 2, null);
        } elseif (isset($line[0]) && $line[0] === '+' && preg_match('/^\+\|\s*(\d{2,4})\s*\|/', $line, $m)) {
            $num = (int)$m[1];
            if (!isset($map[$num])) $map[$num] = [$hash ?: null, $date ?: null];
        }
    }
    return $map;
}

// ---- light markdown -> HTML (for changelog bodies) ----
function md_inline(string $s): string {
    // Descriptions already carry HTML entities (&mdash;, &ldquo;, &rarr;). Escaping without
    // decoding first turns the & into &amp; and the reader sees the entity as text.
    $s = htmlspecialchars(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');
    $s = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2">$1</a>', $s);
    $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<!\*)\*(?!\*)([^*]+?)\*(?!\*)/s', '<em>$1</em>', $s);
    $s = preg_replace('/`([^`]+)`/s', '<code>$1</code>', $s);
    return $s;
}
function strip_markdown(string $s): string { return trim(preg_replace(['/\*\*/', '/\*/', '/`/'], '', $s)); }
function split_desc(string $desc): array {
    $desc = trim($desc);
    if (preg_match('/^\*\*(.+?)\*\*\s*(.*)$/s', $desc, $m)) {
        $title = rtrim(trim(strip_markdown($m[1])), '.');
        $body  = trim($m[2]) !== '' ? md_inline(trim($m[2])) : md_inline($m[1]);
        return [$title, $body];
    }
    $parts = preg_split('/(?<=\.)\s+/', $desc, 2);
    return [rtrim(trim(strip_markdown($parts[0])), '.'), md_inline($desc)];
}

// ---- parse updates.html (the curated source) ----
function innerHTML(DOMNode $n): string {
    $h = '';
    foreach ($n->childNodes as $c) $h .= $n->ownerDocument->saveHTML($c);
    return trim($h);
}
/** Curated entries from updates.html.
 *  Returns the numbered ones keyed on update number; the handful that predate the
 *  numbering scheme (Jan–early Feb 2026) have no data-id and come back via $unnumbered. */
function parse_updates_html(string $path, array &$unnumbered = []): array {
    if (!is_file($path)) return [];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTMLFile($path);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $out = [];
    $has = "contains(concat(' ',normalize-space(@class),' '),' %s ')";
    foreach ($xp->query('//*[' . sprintf($has, 'update-entry') . ']') as $entry) {
        $day = trim($xp->evaluate("string(.//*[contains(@class,'date-day')])", $entry));
        $mon = trim($xp->evaluate("string(.//*[contains(@class,'date-month')])", $entry));
        $yr  = trim($xp->evaluate("string(.//*[contains(@class,'date-year')])", $entry));
        $date = ($day && $mon && $yr) ? date('Y-m-d', strtotime("$day $mon $yr")) : null;
        foreach ($xp->query('.//*[' . sprintf($has, 'update-item') . ']', $entry) as $item) {
            $id = (int)$item->getAttribute('data-id');
            $badge = (string)$xp->evaluate("string(.//*[contains(@class,'update-badge')]/@class)", $item);
            $type  = strpos($badge, 'feature') !== false ? 'feature' : (strpos($badge, 'fix') !== false ? 'fix' : 'improvement');
            $title = trim($xp->evaluate("string(.//h3)", $item));
            $pNode = $xp->query(".//p", $item)->item(0);
            $row = [
                'date'   => $date,
                'type'   => $type,
                'module' => trim($xp->evaluate("string(.//*[contains(@class,'update-module')])", $item)),
                'title'  => $title,
                'body'   => $pNode ? innerHTML($pNode) : '',
                'deep'   => ($d = (string)$xp->evaluate("string(.//a[contains(@href,'deep-dive')]/@href)", $item)) !== '' ? $d : null,
            ];
            if ($id) { $out[$id] = $row; }
            elseif ($title !== '' && $date) { $unnumbered[] = $row; }
        }
    }
    return $out;
}

// ---- parse changelog rows ----
$clMap = [];
foreach (explode("\n", $md) as $line) {
    if (preg_match('/^\|\s*(\d{2,4})\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*(.*?)\s*\|\s*$/', $line, $m)) {
        $clMap[(int)$m[1]] = ['module' => trim($m[2]), 'type' => strtolower(trim($m[3])), 'desc' => $m[4]];
    }
}

// ---- POST helper ----
function api_post(string $url, string $key, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Api-Key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    if ($body === false) return ['ok' => false, 'error' => $err ?: 'no response'];
    $j = json_decode($body, true);
    return ['ok' => $code < 300 && !empty($j['success']), 'code' => $code, 'json' => $j, 'raw' => $body];
}

// ---- merge the three sources ----
fwrite(STDERR, "Mapping commits from git history…\n");
$shipMap = build_ship_map($root);
$htmlUnnumbered = [];
$htmlMap = parse_updates_html($htmlPath, $htmlUnnumbered);
fwrite(STDERR, sprintf("Sources: %d curated (updates.html, + %d pre-numbering) + %d changelog. Merging…\n",
    count($htmlMap), count($htmlUnnumbered), count($clMap)));

$ids = $onlyUnnumbered ? [] : array_keys($clMap + $htmlMap);   // union of update numbers
rsort($ids);                            // newest first
if ($limit > 0) $ids = array_slice($ids, 0, $limit);

$typeMap = ['feature' => 'feature', 'improvement' => 'improvement', 'fix' => 'fix'];
fwrite(STDERR, sprintf("%s %d updates -> %s\n", $dryRun ? "[DRY RUN]" : "Syncing", count($ids), $apiUrl));

$ok = 0; $fail = 0;
foreach ($ids as $id) {
    $h = $htmlMap[$id] ?? null;
    $c = $clMap[$id] ?? null;
    [$gh, $gd] = $shipMap[$id] ?? [null, null];

    // updates.html wins when it has a usable title; else fall back to the changelog.
    if ($h && $h['title'] !== '') {
        $type = $h['type']; $module = $h['module']; $title = $h['title'];
        $body = $h['body'] !== '' ? $h['body'] : ($c ? split_desc($c['desc'])[1] : $title);
        $deep = $h['deep'];
        $date = $h['date'] ?: ($gd ?: date('Y-m-d'));
        $src  = 'H';
    } elseif ($c) {
        [$title, $body] = split_desc($c['desc']);
        $type = $typeMap[$c['type']] ?? 'improvement'; $module = $c['module']; $deep = null;
        $date = $gd ?: date('Y-m-d');
        $src  = 'C';
    } else {
        continue;
    }

    $payload = [
        'action' => 'upsert', 'update_number' => $id, 'published_date' => $date,
        'type' => $type, 'module' => $module, 'title' => $title, 'body' => $body,
        'github_url' => $gh ? "$repoUrl/commit/$gh" : null,
        'deep_dive_url' => $deep,
    ];

    if ($dryRun) {
        printf("  #%-4d %s %-10s %-11s %-13s %s\n", $id, $src, $date, $type,
            mb_strimwidth($module, 0, 13, ''), mb_strimwidth($title, 0, 52, '…'));
        $ok++;
        continue;
    }
    // Retry transient failures (shared host can wobble under a long run).
    $res = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $res = api_post($apiUrl, $apiKey, $payload);
        if ($res['ok']) break;
        // Don't retry a real validation error (4xx) — only transient/network/5xx.
        if (isset($res['code']) && $res['code'] >= 400 && $res['code'] < 500) break;
        usleep(600000);   // 0.6s backoff
    }
    if ($res['ok']) { $ok++; }
    else { $fail++; fwrite(STDERR, sprintf("  #%-4d FAILED: %s\n", $id, $res['error'] ?? json_encode($res['json'] ?? $res['raw']))); }
}

// ---- Entries that predate the numbering scheme ----
// These carry no update number, so the API keys them on date + title instead.
// sort_order restarts per day to preserve the order updates.html lists them in.
// A limited run targets a specific slice of numbered updates. These 16 have no
// number, so they can only key on date+title — and because upsert overwrites every
// column, replaying them would null the wiki_url and release_id an upsert cannot
// supply. A limited run therefore leaves them alone.
if ($htmlUnnumbered && $limit <= 0) {
    fwrite(STDERR, sprintf("\n%s %d pre-numbering updates (no update number)\n",
        $dryRun ? "[DRY RUN]" : "Syncing", count($htmlUnnumbered)));
    $perDay = [];
    foreach ($htmlUnnumbered as $u) {
        $sort = $perDay[$u['date']] = ($perDay[$u['date']] ?? -1) + 1;
        $payload = [
            'action' => 'upsert', 'published_date' => $u['date'],
            'type' => $u['type'], 'module' => $u['module'] ?: null,
            'title' => $u['title'], 'body' => $u['body'],
            'deep_dive_url' => $u['deep'], 'sort_order' => $sort,
        ];
        if ($dryRun) {
            printf("  %-5s H %-10s %-11s %-13s %s\n", '—', $u['date'], $u['type'],
                mb_strimwidth($u['module'], 0, 13, ''), mb_strimwidth($u['title'], 0, 52, '…'));
            $ok++;
            continue;
        }
        $res = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $res = api_post($apiUrl, $apiKey, $payload);
            if ($res['ok']) break;
            if (isset($res['code']) && $res['code'] >= 400 && $res['code'] < 500) break;
            usleep(600000);
        }
        if ($res['ok']) { $ok++; }
        else { $fail++; fwrite(STDERR, sprintf("  %s FAILED: %s\n", $u['title'], $res['error'] ?? json_encode($res['json'] ?? $res['raw']))); }
    }
}

fwrite(STDERR, "\nDone: $ok ok, $fail failed" . ($dryRun ? " (dry run — nothing sent)" : "") . "\n");
