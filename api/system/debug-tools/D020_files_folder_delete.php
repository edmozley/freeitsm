<?php
/**
 * Debug Tool D020 - Files: can this person delete this folder?
 *
 * Deleting a folder takes everything in it, so api/files/folder.php asks for
 * Modify on the folder AND on every folder inside it (FilesAcl::deleteBlockers).
 * The person refused is told about one folder at most, and only one they can
 * already see - so a refusal can never be used to list the names of folders
 * kept from them. That leaves an administrator with a one-line message and a
 * puzzle. This report is the whole picture: every folder and file under the
 * chosen folder, the person's level on each, and PASS or FAIL.
 *
 * 🔴 ONE COPY OF THE RULE. The verdict and the message come from the same
 * FilesAcl::deleteBlockers() / deleteRefusal() the delete itself calls, so this
 * report cannot drift from what the person is actually told.
 *
 * 🔒 ADMINISTRATORS ONLY - it prints the names of folders and files the person
 * being checked may not be allowed to see. Fail closed.
 *
 * READ-ONLY. SELECTs only. Nothing is written.
 *
 * Output: plain text, section-delimited with === HEADERS === for easy skimming.
 */

@session_start();

$DIAG_ID   = 'D020';
$DIAG_NAME = 'Files - can this person delete this folder?';

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/files/acl.php';

try {
    $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin(connectToDatabase(), (int)$_SESSION['analyst_id']);
} catch (Throwable $e) {
    $__dbgAdmin = false;
}
if (!$__dbgAdmin) {
    http_response_code(403);
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "Administrator access required.\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
$out = [];
$say = function (string $line = '') use (&$out) { $out[] = $line; };
$finish = function () use (&$out) { echo implode("\n", $out) . "\n"; exit; };

try {
    $conn = connectToDatabase();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $analystId = (int)($_GET['analyst'] ?? 0);
    $folderId  = (int)($_GET['folder'] ?? 0);

    $st = $conn->prepare("SELECT full_name, is_active FROM analysts WHERE id = ?");
    $st->execute([$analystId]);
    $who = $st->fetch(PDO::FETCH_ASSOC);
    if (!$who) { $say('Choose a person.'); $finish(); }
    $tree = FilesAcl::tree($conn);
    if (!isset($tree[$folderId])) { $say('Choose a folder. (A folder in the recycle bin cannot be deleted again, so it is not listed.)'); $finish(); }

    $levels = FilesAcl::levels($conn, $analystId);
    $sub    = FilesAcl::subtreeTopFirst($conn, $folderId);
    $ids    = array_column($sub, 'id');

    // Their own grants (to them or one of their teams) on each folder, to say WHY.
    $teams = FilesAcl::teamIds($conn, $analystId);
    $in = implode(',', array_map('intval', $ids));
    $sql = "SELECT folder_id, MAX(level) FROM files_permissions WHERE folder_id IN ($in)
               AND ((principal_type = 'analyst' AND principal_id = " . (int)$analystId . ")"
         . ($teams ? " OR (principal_type = 'team' AND principal_id IN (" . implode(',', array_map('intval', $teams)) . "))" : '')
         . ") GROUP BY folder_id";
    $own = [];
    foreach ($conn->query($sql)->fetchAll(PDO::FETCH_NUM) as [$fid, $lvl]) $own[(int)$fid] = (int)$lvl;

    $files = [];
    foreach ($conn->query("SELECT folder_id, name FROM files_items WHERE deleted_datetime IS NULL AND folder_id IN ($in) ORDER BY name")->fetchAll(PDO::FETCH_NUM) as [$fid, $name]) {
        $files[(int)$fid][] = $name;
    }

    $blockers = FilesAcl::deleteBlockers($conn, $analystId, $folderId);
    $topLevel = $levels[$folderId] ?? 0;
    $allowed  = $topLevel >= FilesAcl::MODIFY && !$blockers;

    $say("=== $DIAG_ID - $DIAG_NAME ===");
    $say('Person: ' . $who['full_name'] . ($who['is_active'] ? '' : ' (inactive)'));
    $say('Folder: ' . FilesAcl::path($conn, $folderId));
    $say('Teams:  ' . (count($teams) ?: 'none'));
    $say();
    $say('=== VERDICT ===');
    if ($allowed) {
        $say('PASS - they could delete this folder and everything in it.');
    } elseif ($topLevel < FilesAcl::VIEW) {
        $say('FAIL - they cannot see this folder at all, so to them it does not exist ("Folder not found.").');
    } elseif ($topLevel < FilesAcl::MODIFY) {
        $say('FAIL - they hold ' . FilesAcl::levelKey($topLevel) . ' on the folder itself; deleting needs modify.');
        $say('They are told: "You do not have permission to do that here."');
    } else {
        $say('FAIL - ' . count($blockers) . ' folder(s) inside fail (marked FAIL below). They are told:');
        $say('  "' . FilesAcl::deleteRefusal($conn, $analystId, $folderId) . '"');
    }
    $say();
    $say('=== EVERYTHING INSIDE ===');
    $say('Deleting needs modify on every folder. A file passes or fails with its folder.');
    $say();
    $fileCap = 25;
    foreach ($sub as $s) {
        $id = $s['id'];
        $f = $tree[$id];
        $lvl = $levels[$id] ?? 0;
        $ok = $lvl >= FilesAcl::MODIFY;
        $pad = str_repeat('    ', $s['depth']);
        $say(sprintf('%s[%s] %s/   level: %s, %s', $pad, $ok ? 'PASS' : 'FAIL', $f['name'], FilesAcl::levelKey($lvl),
                     $f['parent_id'] === null ? 'top level' : ($f['inherit'] ? 'inherits' : 'does NOT inherit')));
        if (!$ok) {
            if ($s['depth'] > 0 && !$f['inherit'] && !isset($own[$id])) {
                $why = 'inheritance is off here and nothing is granted to them or their teams on this folder';
            } elseif (isset($own[$id]) && $own[$id] < FilesAcl::MODIFY && ($lvl === $own[$id])) {
                $why = 'granted ' . FilesAcl::levelKey($own[$id]) . ' here, below modify';
            } elseif ($lvl === 0) {
                $why = 'nothing granted to them or their teams here or above';
            } else {
                $why = 'the level they get from above is below modify';
            }
            $say("$pad      why: $why");
        }
        $names = $files[$id] ?? [];
        foreach (array_slice($names, 0, $fileCap) as $n) $say(sprintf('%s    [%s] %s', $pad, $ok ? 'PASS' : 'FAIL', $n));
        if (count($names) > $fileCap) $say(sprintf('%s    ... and %d more file(s), all %s', $pad, count($names) - $fileCap, $ok ? 'PASS' : 'FAIL'));
    }
    $say();
    $say('=== FIX ===');
    if ($allowed) {
        $say('Nothing to fix.');
    } else {
        $say('Either give them Modify on each FAIL folder (Files -> right-click -> Permissions),');
        $say('or have someone who holds Modify on it move it out first, or delete it themselves.');
        $say('The message they see never names a folder they cannot see, which is why it can be vague.');
    }
} catch (Throwable $e) {
    $say('Could not finish: ' . $e->getMessage());
}
$finish();
