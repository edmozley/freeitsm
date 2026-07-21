<?php
/**
 * API Endpoint: the reply templates this analyst may insert.
 *
 * GET  ?all=1  include inactive ones too (the settings tab wants them; the reply-box
 *              picker does not — an inactive template is one an admin has retired).
 *
 * Deliberately NOT capability-gated. Inserting a canned response is the everyday job,
 * like replying at all; gating it would not tighten anything, it would just mean most
 * of the service desk sees an empty picker. The capability guards WRITING a shared
 * template, which is where the settings power actually is.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/reply_templates.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
// Layer 1 only — see the note above about why no CAPABILITY guards this read.
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $includeInactive = isset($_GET['all']) && $_GET['all'] === '1';

    $templates = replyTemplatesVisibleTo($conn, $analystId, !$includeInactive);

    echo json_encode([
        'success'     => true,
        'templates'   => $templates,
        'merge_codes' => replyTemplateMergeCodes(),
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
