<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Deleting a folder in Files needs Modify on everything inside it (GH #293), and
 * the refusal never names a folder the person cannot see.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/files-folder-delete-refusal.php
 *
 * ZZ folders, each tree granted to one administrator (Files permissions give an
 * administrator nothing extra, so they are an ordinary person here):
 *   Mixed/   Modify  - Hidden/ (stops inheriting, nothing granted) and Seen/ (View only)
 *   Locked/  Modify  - Secret/ (stops inheriting, nothing granted)
 *   Open/    Modify  - Inside/ (inherits)
 * Called over real HTTP through api/files/folder.php with a forged session, then
 * the D020 report. CONTROL: grant Modify on the blockers and Mixed/ deletes.
 * Everything ZZ is removed in finally.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/files/acl.php';

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if (!$expected || !$base) { echo "(skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n"; exit(0); }
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

$tag = 'ZZ293D-' . bin2hex(random_bytes(3));
$folders = [];
$sid = 'zz293' . bin2hex(random_bytes(10));
$csrf = bin2hex(random_bytes(32));
$sessFile = rtrim(ini_get('session.save_path') ?: 'c:/wamp64/tmp', "/\\") . '/sess_' . $sid;
$call = function (string $path, ?array $post = null) use ($base, $sid, $csrf): array {
    $ch = curl_init("$base/$path");
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIE => "PHPSESSID=$sid",
             CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $csrf]];
    if ($post !== null) { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($post); }
    curl_setopt_array($ch, $opts);
    $raw = (string)curl_exec($ch);
    return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), $raw, json_decode($raw, true)];
};
$mk = function (?int $parent, string $name, bool $inherit = true) use ($conn, &$folders, $tag): int {
    $conn->prepare("INSERT INTO files_folders (parent_id, name, inherit_permissions) VALUES (?, ?, ?)")
         ->execute([$parent, $parent ? $name : "$tag $name", $inherit ? 1 : 0]);
    FilesAcl::reset();
    return $folders[] = (int)$conn->lastInsertId();
};
$deleted = function (int $id) use ($conn): bool {
    $s = $conn->prepare("SELECT deleted_datetime IS NOT NULL FROM files_folders WHERE id = ?");
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
};

try {
    $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $grant = function (int $folder, int $level) use ($conn, $admin) {
        $conn->prepare("INSERT INTO files_permissions (folder_id, principal_type, principal_id, level) VALUES (?, 'analyst', ?, ?) ON DUPLICATE KEY UPDATE level = VALUES(level)")
             ->execute([$folder, $admin, $level]);
        FilesAcl::reset();
    };
    $mixed  = $mk(null, 'Mixed');   $grant($mixed, FilesAcl::MODIFY);
    $hidden = $mk($mixed, 'ZZ Hidden-' . $tag, false);
    $seen   = $mk($mixed, 'ZZ Seen', false);   $grant($seen, FilesAcl::VIEW);
    $locked = $mk(null, 'Locked');  $grant($locked, FilesAcl::MODIFY);
    $secret = $mk($locked, 'ZZ Secret-' . $tag, false);
    $open   = $mk(null, 'Open');    $grant($open, FilesAcl::MODIFY);
    $inside = $mk($open, 'ZZ Inside');

    echo "The rule, directly\n";
    check('a folder whose inside all inherits Modify has no blockers', FilesAcl::blockersInside($conn, $admin, $open) === [] && FilesAcl::refusalInside($conn, $admin, $open) === null);
    check('the blockers are the two folders that stop inheriting', FilesAcl::blockersInside($conn, $admin, $mixed) === [$hidden, $seen] || FilesAcl::blockersInside($conn, $admin, $mixed) === [$seen, $hidden]);
    $m = (string)FilesAcl::refusalInside($conn, $admin, $mixed);
    check('it names the one they can see', str_contains($m, '"ZZ Seen"') && str_contains($m, 'other items'), $m);
    check('and never the one they cannot', !str_contains($m, 'Hidden'), $m);
    $m = (string)FilesAcl::refusalInside($conn, $admin, $locked);
    check('when every blocker is hidden: no name, no count', str_contains($m, 'contains folders you do not have access to') && !str_contains($m, 'Secret') && !preg_match('/\d/', str_replace($tag, '', $m)), $m);

    echo "Over HTTP, through api/files/folder.php\n";
    file_put_contents($sessFile, 'analyst_id|i:' . $admin . ';analyst_name|s:6:"ZZ293D";is_admin|i:1;csrf_token|s:64:"' . $csrf . '";');
    [$code, $raw, $r] = $call('api/files/folder.php', ['action' => 'delete', 'id' => $mixed]);
    check('a folder with a hidden and a visible blocker is refused', ($r['success'] ?? null) === false && !$deleted($mixed), "$code $raw");
    check('the message the person sees names only the visible one', str_contains((string)($r['error'] ?? ''), 'ZZ Seen') && !str_contains($raw, 'Hidden'), $raw);
    [$code, $raw, $r] = $call('api/files/folder.php', ['action' => 'delete', 'id' => $locked]);
    check('a folder with only a hidden blocker is refused, naming nothing', ($r['success'] ?? null) === false && !$deleted($locked) && !str_contains($raw, 'Secret'), $raw);
    $a = $conn->prepare("SELECT COUNT(*) FROM files_audit WHERE analyst_id = ? AND action = 'denied' AND folder_id IN (?, ?)");
    try { $a->execute([$admin, $mixed, $locked]); check('each refusal is in the Files audit', (int)$a->fetchColumn() === 2); }
    catch (Throwable $e) { check('each refusal is in the Files audit', false, $e->getMessage()); }
    [$code, $raw, $r] = $call('api/files/folder.php', ['action' => 'delete', 'id' => $open]);
    check('a folder with nothing in the way is deleted, as before', ($r['success'] ?? null) === true && $deleted($open), $raw);

    echo "D020, the administrator's report\n";
    [$code, $raw] = $call("api/system/debug-tools/D020_files_folder_delete.php?analyst=$admin&folder=$mixed");
    check('the verdict is FAIL with the message they are shown', $code === 200 && str_contains($raw, 'FAIL - 2 folder(s) inside fail') && str_contains($raw, 'ZZ Seen'), substr($raw, 0, 400));
    check('the hidden folder is listed for the administrator, with why', preg_match('/\[FAIL\] ZZ Hidden-\S+\/.*does NOT inherit/', $raw) && str_contains($raw, 'inheritance is off here'), $raw);
    check('the folder itself passes', preg_match('/^\[PASS\] ' . preg_quote("$tag Mixed", '/') . '\//m', $raw) === 1, $raw);
    $ch = curl_init("$base/api/system/debug-tools/D020_files_folder_delete.php?analyst=$admin&folder=$mixed");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $anon = (string)curl_exec($ch);
    check('without an administrator session it shows nothing', curl_getinfo($ch, CURLINFO_HTTP_CODE) === 403 && !str_contains($anon, 'Hidden'));

    echo "Moving a folder: the same rule, through api/files/transfer.php\n";
    $dest = $mk(null, 'Dest');  $grant($dest, FilesAcl::MODIFY);
    $parentOf = function (int $id) use ($conn): ?int {
        $s = $conn->prepare("SELECT parent_id FROM files_folders WHERE id = ?");
        $s->execute([$id]);
        $p = $s->fetchColumn();
        return $p === null || $p === false ? null : (int)$p;
    };
    [$code, $raw, $r] = $call('api/files/transfer.php', ['mode' => 'move', 'target' => $dest, 'entries' => [['type' => 'folder', 'id' => $locked]]]);
    $err = (string)($r['failed'][0]['error'] ?? '');
    check('a folder holding a hidden folder is not moved', ($r['done'] ?? null) === 0 && $parentOf($locked) === null, $raw);
    check('the message says move, and names nothing hidden', str_starts_with($err, 'You cannot move') && !str_contains($raw, 'Secret'), $raw);

    echo "Buried deep: 10 folders, each with 10, each with 10 (1,110 folders), one locked at the bottom\n";
    $deep = $mk(null, 'Deep');  $grant($deep, FilesAcl::MODIFY);
    $bottom = [];
    for ($i = 1; $i <= 10; $i++) {
        $l1 = $mk($deep, "L1-$i");
        for ($j = 1; $j <= 10; $j++) {
            $l2 = $mk($l1, "L2-$i-$j");
            for ($k = 1; $k <= 10; $k++) $bottom["$i-$j-$k"] = $mk($l2, "L3-$i-$j-$k");
        }
    }
    // The 777th of 1,000 bottom folders stops inheriting; a file sits in it.
    $buried = $mk($bottom['7-7-7'], 'ZZ Buried-' . $tag, false);
    $conn->prepare("INSERT INTO files_items (folder_id, name, size_bytes) VALUES (?, ?, 0)")->execute([$buried, "$tag private.docx"]);
    FilesAcl::reset();
    check('the tree is all there: 1,112 folders', count(FilesAcl::subtreeTopFirst($conn, $deep)) === 1112);
    check('the check finds the one buried four levels down', FilesAcl::blockersInside($conn, $admin, $deep) === [$buried]);
    [$code, $raw, $r] = $call('api/files/folder.php', ['action' => 'delete', 'id' => $deep]);
    check('deleting the top folder is refused, naming nothing', ($r['success'] ?? null) === false && !$deleted($deep) && !str_contains($raw, 'Buried') && !str_contains($raw, 'private'), $raw);
    [$code, $raw, $r] = $call('api/files/transfer.php', ['mode' => 'move', 'target' => $dest, 'entries' => [['type' => 'folder', 'id' => $deep]]]);
    check('moving the top folder is refused too', ($r['done'] ?? null) === 0 && $parentOf($deep) === null, $raw);
    [$code, $raw] = $call("api/system/debug-tools/D020_files_folder_delete.php?analyst=$admin&folder=$deep");
    check('D020 points straight at it, and at its file', preg_match('/\[FAIL\] ZZ Buried-\S+\//', $raw) && preg_match('/\[FAIL\] ' . preg_quote("$tag private.docx", '/') . '/', $raw) && substr_count($raw, '[FAIL]') === 2, substr_count($raw, '[FAIL]') . ' FAIL lines');

    echo "CONTROL: give Modify on the buried folder\n";
    $grant($buried, FilesAcl::MODIFY);
    [$code, $raw, $r] = $call('api/files/transfer.php', ['mode' => 'move', 'target' => $dest, 'entries' => [['type' => 'folder', 'id' => $deep]]]);
    check('now the top folder moves', ($r['done'] ?? null) === 1 && $parentOf($deep) === $dest, $raw);

    echo "CONTROL: give Modify on the blockers\n";
    $grant($hidden, FilesAcl::MODIFY);
    $grant($seen, FilesAcl::MODIFY);
    [$code, $raw, $r] = $call('api/files/folder.php', ['action' => 'delete', 'id' => $mixed]);
    check('now the same folder deletes', ($r['success'] ?? null) === true && $deleted($mixed), $raw);
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    @unlink($sessFile);
    if ($folders) {
        $in = implode(',', $folders);
        try { $conn->exec("DELETE FROM files_audit WHERE folder_id IN ($in)"); } catch (Throwable) {}
        $conn->exec("DELETE FROM files_items WHERE folder_id IN ($in)");
        $conn->exec("DELETE FROM files_permissions WHERE folder_id IN ($in)");
        $conn->exec("UPDATE files_folders SET parent_id = NULL WHERE id IN ($in)");
        $conn->exec("DELETE FROM files_folders WHERE id IN ($in)");
    }
    $left = (int)$conn->query("SELECT COUNT(*) FROM files_folders WHERE name LIKE " . $conn->quote("%$tag%"))->fetchColumn();
    check('cleaned up', $left === 0);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
