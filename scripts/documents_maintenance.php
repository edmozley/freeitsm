<?php
/**
 * Documents maintenance — extraction and tidy-up (discussion #76).
 *
 * Two jobs that both want a clock rather than a page load:
 *
 *   1. EXTRACT the text from uploaded files so their CONTENTS are searchable.
 *      Uploading runs one file with a four-second budget, which is right for a
 *      page load and useless for somebody who has just attached fifty warranty
 *      PDFs. Without this they sit `pending` until the next upload happens to
 *      nudge the queue.
 *
 *   2. COLLECT ORPHANS. document_links.parent_id is polymorphic, so no foreign
 *      key can protect it: delete a contract and its links survive. The document
 *      is invisible from that moment — every permission check verifies the parent
 *      still exists — but it is never collected, so the file stays on disk.
 *
 * ⚠️ Neither is required for the feature to be CORRECT. Nothing leaks and nothing
 * is lost without it. What you get is documents that become searchable promptly
 * and disk that does not fill with files nobody can reach.
 *
 * Usage:
 *   php scripts/documents_maintenance.php                 both jobs, default limits
 *   php scripts/documents_maintenance.php --extract=25    read up to 25 files
 *   php scripts/documents_maintenance.php --no-collect    extraction only
 *   php scripts/documents_maintenance.php --quiet         for cron
 *
 * Suggested schedule: every 5 minutes. It is safe to run concurrently — the
 * extractor claims rows before working on them, and returns claims a dead worker
 * abandoned.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This script is for the command line.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/documents.php';
require_once __DIR__ . '/../includes/search/documents_index.php';
require_once __DIR__ . '/../includes/job_runs.php';   // run history (#182)

$opts    = getopt('', ['extract::', 'collect::', 'no-collect', 'no-extract', 'quiet']);
$quiet   = isset($opts['quiet']);
$extract = isset($opts['no-extract']) ? 0 : (int) ($opts['extract'] ?? 25);
$collect = isset($opts['no-collect']) ? 0 : (int) ($opts['collect'] ?? 500);

function docsSay(string $line): void { global $quiet; if (!$quiet) echo $line . "\n"; }

try {
    $conn = connectToDatabase();
} catch (Throwable $e) {
    fwrite(STDERR, "Database unavailable: " . $e->getMessage() . "\n");
    exit(1);
}

$exit = 0;
$trigger = JobRun::scheduledTrigger();
// The extractor's claims make two runs safe, but the tidy-up is not worth doing twice (#233).
if (!JobRun::lock($conn, 'documents_maintenance', $trigger)) exit(0);
// #182: its two halves are its two sources, so a stuck queue or a failing tidy-up
// is named on the health page instead of only in an exit code nobody reads.
$runId = JobRun::start($conn, 'documents_maintenance', $trigger);

if ($extract > 0) {
    $depth = documentTextQueueDepth($conn);
    docsSay("Extraction: {$depth} document(s) waiting.");
    if ($depth > 0) {
        // No wall-clock deadline here: this is a cron job, not a page load, and
        // stopping halfway through a file it has already asked Tika for would
        // waste the request. The drain stops itself if the extractor goes away.
        $res = documentTextDrain($conn, $extract);
        docsSay("  read {$res['done']}, {$res['still_pending']} still waiting"
              . ($res['skipped_reason'] ? " ({$res['skipped_reason']})" : ''));
        // A queue that will not drain is worth a non-zero exit, so a monitored
        // cron notices rather than reporting success for ever.
        if ($res['done'] === 0 && $res['still_pending'] > 0) $exit = 2;
        JobRun::source($conn, $runId, 'documents_maintenance', 'part:extract', 'Reading document text', $exit !== 2,
            $exit === 2 ? 'Nothing cleared: the extraction service is probably unreachable.' : "Read {$res['done']}, {$res['still_pending']} waiting.");
    } else {
        JobRun::source($conn, $runId, 'documents_maintenance', 'part:extract', 'Reading document text', true, 'Nothing waiting.');
    }
}

if ($collect > 0) {
    $res = documentsCollectOrphans($conn, $collect);
    docsSay("Tidy-up: removed {$res['links_removed']} dead link(s), "
          . "collected {$res['documents_orphaned']} document(s), "
          . "deleted {$res['files_removed']} file(s).");
    // ⚠️ A sweep that failed and a sweep with nothing to do both report zeroes.
    // Say which, and exit non-zero, or a broken tidy-up looks like a clean one
    // for ever — which is precisely how the first version of this hid a syntax
    // error behind "0 removed".
    if (!empty($res['errors'])) {
        foreach ($res['errors'] as $err) fwrite(STDERR, "  tidy-up error: {$err}\n");
        $exit = 3;
    }
    JobRun::source($conn, $runId, 'documents_maintenance', 'part:tidy', 'Tidy-up', empty($res['errors']),
        empty($res['errors']) ? null : implode('; ', array_slice($res['errors'], 0, 3)));
}

JobRun::finish($conn, $runId);

exit($exit);
