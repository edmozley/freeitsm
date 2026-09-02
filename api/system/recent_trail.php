<?php
/**
 * API: this analyst's recent trail, grouped into headings (discussion #124).
 * GET — no parameters. Always the caller's own trail; there is nothing to ask for.
 *
 * ⚠️ NOT GATED ON ONE MODULE, for the same reason record_preview.php is not:
 * eight kinds of record are reachable from here and each needs a different gate,
 * so the gating lives in entityRecentTrail() — module gate first, then the
 * record gate, per type. Naming a single module here would either refuse an
 * analyst their own trail or wave through a type that module has nothing to do
 * with. Being signed in is the whole of the gate at this level, and it is enough:
 * the only thing this endpoint can ever return is the caller's own history,
 * re-filtered through today's permissions.
 *
 * 🔴 THE FILTERING IS THE POINT, not a formality. The trail is written when a
 * record is opened and read possibly weeks later, so it is the ONLY list in the
 * product guaranteed to hold references that have since been deleted or put out
 * of reach. Rows that no longer resolve are dropped in silence — a "you can no
 * longer see this" row would confirm the record exists, which is the fact the
 * check withholds.
 *
 * ⚠️ LAZY BY DESIGN. The waffle drawer is on all 91 screens; resolving a trail
 * into every page render would put this work on every page load in the product
 * to serve a pane most of them never open. So the drawer ships empty markup and
 * calls this the first time the Recent tab is opened.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/recent_trail.php';
I18n::initFromSession();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn   = connectToDatabase();
    $groups = entityRecentTrail($conn, (int)$_SESSION['analyst_id']);

    // ⚠️ Timestamps leave as UTC with an explicit Z. Every date in this product
    // is stored UTC at rest (#1444-#1446) and turned into the reader's own clock
    // in the browser by tz.js — formatting here would hand back the SERVER's idea
    // of "10:42", which is the exact class of bug that work existed to remove.
    foreach ($groups as &$g) {
        $g['started'] = recentTrailIso($g['started']);
        $g['latest']  = recentTrailIso($g['latest']);
        foreach ($g['records'] as &$r) {
            $r['visited'] = recentTrailIso($r['visited']);
        }
        unset($r);
    }
    unset($g);

    echo json_encode(['success' => true, 'groups' => $groups]);
} catch (Throwable $e) {
    // An install that has not run Database Verification since upgrading has no
    // trail table. That is an empty drawer with a hint, not an error page.
    echo json_encode(['success' => true, 'groups' => [], 'unavailable' => true]);
}

/** 'YYYY-MM-DD HH:MM:SS' as stored (UTC) → an ISO instant the browser can read. */
function recentTrailIso(string $stored): string
{
    return str_replace(' ', 'T', trim($stored)) . 'Z';
}
