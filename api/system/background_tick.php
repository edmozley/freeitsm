<?php
/**
 * API: the background tick - POST, once a minute, from every open analyst tab.
 *
 * The notification bell calls this alongside its unread-count poll. It is the one
 * place FreeITSM can notice that background work is due when no scheduled task
 * runs it: today, a directory import whose "Import automatically" interval has
 * passed (dsyncKickDue() in includes/directory_sync_auto.php), started as a
 * separate process and never inside this request.
 *
 * 🔴 A POST, not part of the bell's GET poll. Claiming a due import is a write
 * (one UPDATE), and a GET must never change anything: a SameSite=Lax cookie is
 * sent on a top-level GET from any other site (see includes/csrf.php). As a POST
 * it gets the central origin and token checks like every other writer.
 *
 * Answers the same whatever it did, so a page learns nothing from it, and never
 * fails the caller: a problem goes to the error log, not to somebody's tab.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    require_once '../../includes/directory_sync_auto.php';
    dsyncKickDue($conn);   // never throws; logs its own faults
} catch (Throwable $e) {
    error_log('[background_tick] ' . $e->getMessage());
}
echo json_encode(['success' => true]);
