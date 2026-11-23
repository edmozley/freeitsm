<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Files thumbnails (GH #424), through the REAL endpoints over HTTP.
 *
 *   - an uploaded picture gets a WebP thumbnail at upload (made once, by the server, at
 *     most 256 px); a PNG claiming 20,000 x 20,000 pixels is set aside as too_large
 *     WITHOUT being decoded; a PDF gets none (a browser draws it);
 *   - the folder listing says ready / draw; a copy with the same fingerprint shares the
 *     thumbnail;
 *   - seeing a thumbnail needs View (no access = 404) and is NOT recorded as opening the
 *     file; a watermarked folder serves none; switched off serves none;
 *   - a PDF's bytes for drawing (?source=1) and sending a thumbnail ('put') need Upload;
 *     a non-picture is refused; a picture with something hidden after it is re-encoded
 *     and nothing of it survives;
 *   - Make thumbnail remakes a picture; deleting the last version with a fingerprint
 *     removes its thumbnail; the Thumbnails tab counts the backlog and clears pictures.
 *
 * Creates analysts, a folder and files named ZZTH, removes them all in `finally`.
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/files-thumbnails.php
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/files/acl.php';
require_once 'includes/files/audit.php';
require_once 'includes/files/storage.php';
require_once 'includes/files/names.php';
require_once 'includes/files/thumbnails.php';
require_once 'includes/services/files_versions.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }
if (!FilesThumbs::ready($conn)) { echo "(skipped: run Database Verification - files_thumbnails is missing)\n"; exit(0); }
if (!FilesThumbs::gd()) { echo "(skipped: GD is not installed in this PHP)\n"; exit(0); }

$sessDir = rtrim(ini_get('session.save_path') ?: 'C:/wamp64/tmp', '/\\');
$made = ['analysts' => [], 'folder' => 0, 'sessions' => [], 'shas' => []];
$enabledBefore = filesSetting($conn, 'thumbnails_enabled', '');
$forge = function (int $id, string $user, int $admin = 0) use ($sessDir, &$made) {
    $sid = 'zzth' . $id . bin2hex(random_bytes(4));
    file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $id . ';analyst_username|s:' . strlen($user) . ':"' . $user . '";analyst_name|s:4:"ZZTH";is_admin|i:' . $admin . ';csrf_token|s:1:"x";');
    $made['sessions'][] = "$sessDir/sess_$sid";
    return $sid;
};
/** One HTTP request; returns [status, body, content-type, headers]. */
$http = function (string $sid, string $path, $body = null, bool $raw = false) use ($base) {
    $ch = curl_init("$base/$path");
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_COOKIE => "PHPSESSID=$sid", CURLOPT_HEADER => true,
          CURLOPT_HTTPHEADER => ['X-CSRF-Token: x', 'Content-Type: ' . ($raw ? 'application/octet-stream' : 'application/json')]];
    if ($body !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = $raw ? $body : json_encode($body); }
    curl_setopt_array($ch, $o);
    $r = (string)curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), substr($r, $hs), (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE), substr($r, 0, $hs)];
};
$json = fn(array $r) => json_decode($r[1], true) ?: [];
$upload = function (string $sid, int $folder, string $name, string $bytes, int $itemId = 0) use ($http, $json) {
    $st = $json($http($sid, 'api/files/upload.php?action=start', ['folder_id' => $folder, 'name' => $name, 'size' => strlen($bytes), 'on_conflict' => 'version', 'item_id' => $itemId]));
    if (empty($st['success'])) throw new RuntimeException("start $name: " . json_encode($st));
    $chunk = (int)$st['chunk'];
    for ($o = 0; $o < strlen($bytes); $o += $chunk) $http($sid, 'api/files/upload.php?action=chunk&token=' . $st['token'] . '&offset=' . $o, substr($bytes, $o, $chunk), true);
    $fin = $json($http($sid, 'api/files/upload.php?action=finish', ['token' => $st['token']]));
    if (empty($fin['success'])) throw new RuntimeException("finish $name: " . json_encode($fin));
    return (int)$fin['item_id'];
};
$png = function (int $w, int $h, array $rgb) { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb)); ob_start(); imagepng($im); return ob_get_clean(); };
$jpeg = function (int $w, int $h) { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200)); ob_start(); imagejpeg($im, null, 85); return ob_get_clean(); };
// A PNG whose header CLAIMS 20,000 x 20,000 pixels - getimagesize() reads that; decoding would need 1.6 GB.
$hugePng = function () { $ihdr = pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0); $c = 'IHDR' . $ihdr; return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $c . pack('N', crc32($c)) . pack('N', 0) . 'IEND' . pack('N', crc32('IEND')); };
$pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
$row = function (string $sha) use ($conn) { $st = $conn->prepare('SELECT status, mime_type, width, height, made_by, made_by_id, LENGTH(data) AS bytes, data FROM files_thumbnails WHERE sha256 = ?'); $st->execute([$sha]); return $st->fetch(PDO::FETCH_ASSOC) ?: null; };
$shaOf = fn(int $item) => (string)$conn->query("SELECT v.sha256 FROM files_items i JOIN files_versions v ON v.id = i.current_version_id WHERE i.id = $item")->fetchColumn();
$listItem = function (string $sid, int $folder, int $item) use ($http, $json) { foreach (($json($http($sid, "api/files/list.php?folder=$folder"))['items'] ?? []) as $i) if ((int)$i['id'] === $item) return $i; return null; };

try {
    filesSaveSetting($conn, 'thumbnails_enabled', '1');
    $mk = function (string $user, int $admin = 0) use ($conn, &$made) {
        $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules, can_access_all_tenants) VALUES (?, '!', ?, ?, 1, ?, 1, 1)")
             ->execute([$user, "ZZTH $user", "$user@example.invalid", $admin]);
        return $made['analysts'][] = (int)$conn->lastInsertId();
    };
    $full = $mk('zzthfull'); $viewer = $mk('zzthview'); $stranger = $mk('zzthnone'); $admin = $mk('zzthadmin', 1);
    $conn->prepare("INSERT INTO files_folders (parent_id, name, inherit_permissions, created_by, created_datetime) VALUES (NULL, 'ZZTH thumbnails test', 1, ?, UTC_TIMESTAMP())")->execute([$full]);
    $folder = $made['folder'] = (int)$conn->lastInsertId();
    $grant = $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level, granted_by, granted_datetime) VALUES (?, 'analyst', ?, ?, ?, UTC_TIMESTAMP())");
    $grant->execute([$folder, $full, FilesAcl::FULL, $full]);
    $grant->execute([$folder, $viewer, FilesAcl::VIEW, $full]);
    $grant->execute([$folder, $admin, FilesAcl::FULL, $full]);
    $sF = $forge($full, 'zzthfull'); $sV = $forge($viewer, 'zzthview'); $sN = $forge($stranger, 'zzthnone'); $sA = $forge($admin, 'zzthadmin', 1);

    echo "Made once, at upload\n";
    $iPng  = $upload($sF, $folder, 'ZZTH photo.png', $png(1200, 900, [200, 60, 40]));
    $iJpg  = $upload($sF, $folder, 'ZZTH photo.jpg', $jpeg(800, 2000));
    $iHuge = $upload($sF, $folder, 'ZZTH huge.png', $hugePng());
    $iPdf  = $upload($sF, $folder, 'ZZTH report.pdf', $pdf);
    foreach ([$iPng, $iJpg, $iHuge, $iPdf] as $i) $made['shas'][] = $shaOf($i);
    $r = $row($shaOf($iPng));
    check('a PNG gets a thumbnail at upload: ready, by the server, WebP, 256 px wide, small',
        $r && $r['status'] === 'ready' && $r['made_by'] === 'server' && $r['mime_type'] === 'image/webp' && (int)$r['width'] === 256 && (int)$r['height'] === 192 && $r['bytes'] < 20000, json_encode($r ? array_diff_key($r, ['data' => 1]) : null));
    $r = $row($shaOf($iJpg));
    check('a tall JPEG keeps its shape: 256 px on its LONGEST side', $r && (int)$r['height'] === 256 && (int)$r['width'] === 102, json_encode($r ? array_diff_key($r, ['data' => 1]) : null));
    $r = $row($shaOf($iHuge));
    check('a PNG claiming 20,000 x 20,000 is set aside as too_large, without being decoded', $r && $r['status'] === 'too_large' && $r['data'] === null);
    check('a PDF gets none at upload (only a browser can draw it)', $row($shaOf($iPdf)) === null);

    echo "The folder listing\n";
    check('a picture with one is "ready", a PDF without is "draw", a set-aside one has none',
        ($listItem($sF, $folder, $iPng)['thumb'] ?? '') === 'ready' && ($listItem($sF, $folder, $iPdf)['thumb'] ?? '') === 'draw'
        && array_key_exists('thumb', (array)$listItem($sF, $folder, $iHuge)) && $listItem($sF, $folder, $iHuge)['thumb'] === null);
    // A copy: another item sharing the PNG's version bytes and fingerprint.
    $v = $conn->query("SELECT * FROM files_versions WHERE item_id = $iPng")->fetch(PDO::FETCH_ASSOC);
    $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes, mime_type, created_by, created_datetime) VALUES (?, 'ZZTH copy.png', ?, 'image/png', ?, UTC_TIMESTAMP())")->execute([$folder, $v['size_bytes'], $full]);
    $iCopy = (int)$conn->lastInsertId();
    $conn->prepare("INSERT INTO files_versions (item_id, version_no, storage_path, size_bytes, mime_type, sha256, uploaded_by, uploaded_datetime) VALUES (?, 1, ?, ?, 'image/png', ?, ?, UTC_TIMESTAMP())")->execute([$iCopy, $v['storage_path'], $v['size_bytes'], $v['sha256'], $full]);
    $conn->prepare('UPDATE files_items SET current_version_id = ? WHERE id = ?')->execute([(int)$conn->lastInsertId(), $iCopy]);
    $rowsBefore = (int)$conn->query('SELECT COUNT(*) FROM files_thumbnails')->fetchColumn();
    check('a copy with the same fingerprint shares the thumbnail - "ready", no new row',
        ($listItem($sF, $folder, $iCopy)['thumb'] ?? '') === 'ready' && (int)$conn->query('SELECT COUNT(*) FROM files_thumbnails')->fetchColumn() === $rowsBefore);

    echo "Seeing one\n";
    $views = fn() => (int)$conn->query("SELECT COUNT(*) FROM files_audit WHERE action = 'view' AND item_id IN ($iPng, $iJpg)")->fetchColumn();
    $v0 = $views();
    $g = $http($sF, "api/files/thumbnail.php?item=$iPng&s=x");
    check('someone with access gets the WebP, cacheable for good', $g[0] === 200 && str_starts_with($g[2], 'image/webp') && str_contains($g[3], 'immutable') && str_starts_with($g[1], 'RIFF'), "$g[0] $g[2]");
    check('someone with View only sees it too', $http($sV, "api/files/thumbnail.php?item=$iJpg")[0] === 200);
    check('someone with no access to the folder gets 404', $http($sN, "api/files/thumbnail.php?item=$iPng")[0] === 404);
    check('showing thumbnails is NOT recorded as opening the file', $views() === $v0, ($views() - $v0) . ' view rows added');
    check('a set-aside picture serves nothing', $http($sF, "api/files/thumbnail.php?item=$iHuge")[0] === 404);
    $conn->prepare('UPDATE files_folders SET watermark = 1 WHERE id = ?')->execute([$folder]);
    check('a watermarked folder serves no thumbnail, and lists none', $http($sF, "api/files/thumbnail.php?item=$iPng")[0] === 404 && ($wm = $listItem($sF, $folder, $iPng)) && array_key_exists('thumb', $wm) && $wm['thumb'] === null);
    $conn->prepare('UPDATE files_folders SET watermark = NULL WHERE id = ?')->execute([$folder]);
    filesSaveSetting($conn, 'thumbnails_enabled', '0');
    check('switched off: none served', $http($sF, "api/files/thumbnail.php?item=$iPng")[0] === 404);
    filesSaveSetting($conn, 'thumbnails_enabled', '1');

    echo "A PDF drawn by a browser\n";
    check("a PDF's bytes for drawing are refused to View only", $http($sV, "api/files/thumbnail.php?item=$iPdf&source=1")[0] === 403);
    $s = $http($sF, "api/files/thumbnail.php?item=$iPdf&source=1");
    check('...and given to someone with Upload, as the PDF', $s[0] === 200 && str_starts_with($s[1], '%PDF'), "$s[0]");
    $drawn = $png(300, 400, [250, 250, 250]);
    $put = fn(string $sid, string $bytes, string $type = 'png') => $json($http($sid, 'api/files/thumbnail.php', ['action' => 'put', 'item' => $iPdf, 'data' => "data:image/$type;base64," . base64_encode($bytes)]));
    check('sending one with View only is refused', empty($put($sV, $drawn)['success']) && $row($shaOf($iPdf)) === null);
    check('sending something that is not a picture is refused', empty($put($sF, 'just some text pretending')['success']) && $row($shaOf($iPdf)) === null);
    $r = $put($sF, $drawn . 'ZZTH-HIDDEN-PAYLOAD');
    $t = $row($shaOf($iPdf));
    check('a picture with something hidden after it is accepted - re-encoded, and nothing hidden survives',
        !empty($r['success']) && $t && $t['status'] === 'ready' && $t['made_by'] === 'browser' && (int)$t['made_by_id'] === $full && !str_contains((string)$t['data'], 'ZZTH-HIDDEN') && $t['mime_type'] === 'image/webp', json_encode($r));
    check('once it has one, its bytes are no longer handed out for drawing', $http($sF, "api/files/thumbnail.php?item=$iPdf&source=1")[0] === 409);

    echo "Make thumbnail, and removal\n";
    $conn->prepare('DELETE FROM files_thumbnails WHERE sha256 = ?')->execute([$shaOf($iJpg)]);
    $m = $json($http($sV, 'api/files/thumbnail.php', ['action' => 'make', 'item' => $iJpg]));
    check('Make thumbnail remakes a picture - View is enough, the server does it', ($m['status'] ?? '') === 'ready' && ($row($shaOf($iJpg))['status'] ?? '') === 'ready', json_encode($m));
    $m = $json($http($sV, 'api/files/thumbnail.php', ['action' => 'make', 'item' => $iPdf]));
    check('...but a PDF needs Upload (its picture comes from a browser)', empty($m['success']));
    check('...and with Upload the browser is told to draw it', !empty($json($http($sF, 'api/files/thumbnail.php', ['action' => 'make', 'item' => $iPdf]))['draw']));
    $oldSha = $shaOf($iJpg);
    $upload($sF, $folder, 'ZZTH photo.jpg', $jpeg(640, 480), $iJpg);   // a new version: a new fingerprint
    $made['shas'][] = $shaOf($iJpg);
    check('a new version gets its own thumbnail; the old one stays while its version exists', $shaOf($iJpg) !== $oldSha && $row($shaOf($iJpg)) && $row($oldSha));
    $old = $conn->query("SELECT id FROM files_versions WHERE item_id = $iJpg AND sha256 = " . $conn->quote($oldSha))->fetchColumn();
    FilesVersionsService::deleteVersion($conn, $full, filesItem($conn, $iJpg), (int)$old);
    check('deleting the last version with a fingerprint removes its thumbnail', $row($oldSha) === null);

    echo "The Thumbnails tab\n";
    $conn->prepare('DELETE FROM files_thumbnails WHERE sha256 = ?')->execute([$shaOf($iJpg)]);
    $d = $json($http($sA, 'api/files/settings.php?tab=thumbnails'));
    check('the backlog counts the picture with none', !empty($d['success']) && ($d['backlog']['pictures'] ?? 0) >= 1 && ($d['backlog']['set_aside']['too_large'] ?? 0) >= 1, json_encode($d['backlog'] ?? $d));
    check('the tab needs its capability (someone without it is refused)', empty($json($http($sV, 'api/files/settings.php?tab=thumbnails'))['success']));
    $c = $json($http($sA, 'api/files/settings.php', ['tab' => 'thumbnails_pictures']));
    check('Clear makes the pictures', !empty($c['success']) && in_array('ready', $c['made'] ?? [], true) && ($row($shaOf($iJpg))['status'] ?? '') === 'ready', json_encode($c['made'] ?? $c));
    $p = $json($http($sA, 'api/files/settings.php', ['tab' => 'thumbnails_pdfs']));
    check('...and hands out no PDF that already has one', !empty($p['success']) && !in_array($iPdf, $p['items'] ?? [], true));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($made['folder']) {
        $f = $made['folder'];
        $vers = $conn->query("SELECT DISTINCT v.storage_path FROM files_versions v JOIN files_items i ON i.id = v.item_id WHERE i.folder_id = $f")->fetchAll(PDO::FETCH_COLUMN);
        $conn->exec("DELETE v FROM files_versions v JOIN files_items i ON i.id = v.item_id WHERE i.folder_id = $f");
        $conn->exec("DELETE FROM files_uploads WHERE folder_id = $f");
        $conn->exec("DELETE FROM files_items WHERE folder_id = $f");
        foreach ($vers as $p) { try { filesPurgeBytesIfUnused($conn, $p); } catch (Throwable $e) {} }
        $conn->exec("DELETE FROM files_permissions WHERE folder_id = $f");
        $conn->exec("DELETE FROM files_audit WHERE folder_id = $f");
        $conn->exec("DELETE FROM files_folders WHERE id = $f");
    }
    FilesThumbs::pruneOrphans($conn);
    if ($made['analysts']) { $in = implode(',', $made['analysts']); foreach (['user_preferences', 'files_audit'] as $tb) { try { $conn->exec("DELETE FROM $tb WHERE analyst_id IN ($in)"); } catch (Throwable $e) {} } $conn->exec("DELETE FROM analysts WHERE id IN ($in)"); }
    if ($enabledBefore === '') $conn->exec("DELETE FROM system_settings WHERE setting_key = 'thumbnails_enabled'"); else filesSaveSetting($conn, 'thumbnails_enabled', $enabledBefore);
    foreach ($made['sessions'] as $s) @unlink($s);
    $left = (int)$conn->query("SELECT (SELECT COUNT(*) FROM files_folders WHERE name LIKE 'ZZTH %') + (SELECT COUNT(*) FROM analysts WHERE username LIKE 'zzth%')")->fetchColumn();
    $orph = $made['shas'] ? (int)$conn->query('SELECT COUNT(*) FROM files_thumbnails WHERE sha256 IN (' . implode(',', array_map([$conn, 'quote'], array_filter($made['shas']))) . ')')->fetchColumn() : 0;
    check('cleanup: nothing named ZZTH left, and no thumbnail of a test file', $left === 0 && $orph === 0, "$left left, $orph thumbnails");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
