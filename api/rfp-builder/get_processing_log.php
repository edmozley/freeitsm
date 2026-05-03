<?php
/**
 * Aggregate AI activity for one RFP — totals across runs, plus a slice
 * of the most recent log rows. Powers the "AI activity" panel on the
 * RFP overview page so the user sees what each run cost in tokens.
 *
 * The `details` column is JSON for success rows (item_count, duration_ms,
 * cache_read, cache_write, model) and a plain error string for failures.
 * We surface duration_ms / cache_read in the recent rows so the analyst
 * can see prompt-cache hits saving them money.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $rfp_id = isset($_GET['rfp_id']) ? (int)$_GET['rfp_id'] : 0;
    if ($rfp_id <= 0) {
        throw new Exception('Missing or invalid rfp_id');
    }
    $limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 25;

    $conn = connectToDatabase();

    $stats = $conn->prepare(
        "SELECT
            COUNT(*)                                                   AS run_count,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END)        AS success_count,
            SUM(CASE WHEN status = 'error'   THEN 1 ELSE 0 END)        AS error_count,
            COALESCE(SUM(tokens_in),  0)                               AS total_tokens_in,
            COALESCE(SUM(tokens_out), 0)                               AS total_tokens_out,
            MAX(created_datetime)                                      AS last_run
         FROM rfp_processing_log
         WHERE rfp_id = ?"
    );
    $stats->execute([$rfp_id]);
    $totals = $stats->fetch(PDO::FETCH_ASSOC) ?: [];

    $rows = $conn->prepare(
        "SELECT l.id, l.action, l.status, l.details, l.tokens_in, l.tokens_out,
                l.document_id, l.section_id, l.created_datetime,
                d.original_filename AS document_filename,
                s.section_title    AS section_title
           FROM rfp_processing_log l
      LEFT JOIN rfp_documents       d ON l.document_id = d.id
      LEFT JOIN rfp_output_sections s ON l.section_id  = s.id
          WHERE l.rfp_id = ?
       ORDER BY l.created_datetime DESC, l.id DESC
          LIMIT $limit"
    );
    $rows->execute([$rfp_id]);
    $entries = $rows->fetchAll(PDO::FETCH_ASSOC);

    // Cached input tokens are buried in the success detail JSON. Walk
    // the recent rows to extract them and a per-row duration_ms; also
    // tally cache_read AND tokens_in across the same recent window so
    // the panel can show the proportion of input tokens served from
    // cache without mixing a recent-window numerator with an all-time
    // denominator.
    $cacheReadRecent = 0;
    $tokensInRecent  = 0;
    foreach ($entries as &$e) {
        if ($e['tokens_in'] !== null) {
            $tokensInRecent += (int)$e['tokens_in'];
        }
        $e['tokens_in']  = $e['tokens_in']  !== null ? (int)$e['tokens_in']  : null;
        $e['tokens_out'] = $e['tokens_out'] !== null ? (int)$e['tokens_out'] : null;
        $e['cache_read']  = null;
        $e['cache_write'] = null;
        $e['duration_ms'] = null;
        $e['model']       = null;
        $e['error']       = null;

        if ($e['status'] === 'success' && !empty($e['details'])) {
            $parsed = json_decode($e['details'], true);
            if (is_array($parsed)) {
                $e['cache_read']  = isset($parsed['cache_read'])  ? (int)$parsed['cache_read']  : null;
                $e['cache_write'] = isset($parsed['cache_write']) ? (int)$parsed['cache_write'] : null;
                $e['duration_ms'] = isset($parsed['duration_ms']) ? (int)$parsed['duration_ms'] : null;
                $e['model']       = $parsed['model'] ?? null;
                if ($e['cache_read'] !== null) {
                    $cacheReadRecent += $e['cache_read'];
                }
            }
        } elseif ($e['status'] === 'error') {
            $e['error'] = $e['details'];
        }
        unset($e['details']);
    }
    unset($e);

    echo json_encode([
        'success' => true,
        'totals'  => [
            'run_count'        => (int)($totals['run_count']        ?? 0),
            'success_count'    => (int)($totals['success_count']    ?? 0),
            'error_count'      => (int)($totals['error_count']      ?? 0),
            'total_tokens_in'  => (int)($totals['total_tokens_in']  ?? 0),
            'total_tokens_out' => (int)($totals['total_tokens_out'] ?? 0),
            'cache_read_recent' => $cacheReadRecent,
            'tokens_in_recent'  => $tokensInRecent,
            'last_run'         => $totals['last_run'] ?? null,
        ],
        'entries' => $entries,
        'limit'   => $limit,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
