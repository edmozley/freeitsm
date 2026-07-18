<?php
/**
 * API: set "who can see this" on several articles at once.
 * POST { ids: [1,2,3], audience: 'internal'|'customer'|'public' }
 *
 * WHY THIS EXISTS
 * ---------------
 * Every article defaults to `internal` — deliberately, so that adding the
 * audience column (#868) could never start disclosing anything nobody had
 * chosen to publish. The consequence is that the moment the self-service Help
 * Centre shipped, EVERY install had an empty one: publishing a knowledge base
 * meant opening articles one at a time.
 *
 * IMPLEMENTATION
 * --------------
 * A thin loop over KnowledgeService::saveArticle(), which already does exactly
 * what is needed and nothing more: it updates `audience` ONLY when that key is
 * present, it REJECTS an invalid audience outright rather than normalising it
 * down to internal (so nobody saves as internal while believing they published),
 * and it refuses to touch an article belonging to a company the actor cannot
 * reach. Hand-rolling an UPDATE here would have bypassed all three.
 *
 * PARTIAL SUCCESS IS REPORTED, NOT SWALLOWED. Selecting fifty articles and
 * having three silently skipped — because they belong to another company — is
 * how someone ends up believing a document is published when it is not. The
 * response names what failed.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/knowledge.php';
require_once '../../includes/knowledge/audience.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('knowledge');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['ids']) || !is_array($input['ids'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$audience = (string)($input['audience'] ?? '');
if (!Audience::isValid($audience)) {
    echo json_encode([
        'success' => false,
        'error'   => "Audience must be one of: " . implode(', ', Audience::all()),
    ]);
    exit;
}

$ids = array_values(array_unique(array_filter(array_map('intval', $input['ids']))));
if (!$ids) {
    echo json_encode(['success' => false, 'error' => 'No articles selected']);
    exit;
}
// A sane ceiling: this is a UI action, not a migration tool.
if (count($ids) > 500) {
    echo json_encode(['success' => false, 'error' => 'Too many articles selected (maximum 500)']);
    exit;
}

try {
    $conn = connectToDatabase();
    $ctx  = ActorContext::fromSession($conn);

    $updated = 0;
    $failed  = [];

    foreach ($ids as $id) {
        try {
            KnowledgeService::saveArticle($conn, $ctx, ['id' => $id, 'audience' => $audience]);
            $updated++;
        } catch (Exception $e) {
            // Keep going: one inaccessible article shouldn't abandon the rest.
            $failed[] = ['id' => $id, 'error' => $e->getMessage()];
        }
    }

    echo json_encode([
        'success' => true,
        'updated' => $updated,
        'failed'  => $failed,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
