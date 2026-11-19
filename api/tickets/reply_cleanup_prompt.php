<?php
/**
 * API: the Reply Cleanup prompt and its history (GH #396).
 *
 *   GET                       the template in use, the built-in one, and every version
 *   GET  ?version=N           one version's text
 *   POST { action: 'save', text }     a new version from the editor
 *   POST { action: 'reset' }          a new version that goes back to the built-in prompt
 *   POST { action: 'restore', id }    a new version copying version N
 *
 * Every write ADDS a row (replyCleanupSavePromptVersion()); nothing is overwritten
 * or deleted, so any change - a Reset or a Restore included - can be undone the
 * same way. Same gate as the rest of the Reply Cleanup settings.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/reply_cleanup_prompt.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_REPLY_CLEANUP);

try {
    $conn = connectToDatabase();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        if (isset($_GET['version'])) {
            $text = replyCleanupPromptVersionText($conn, (int)$_GET['version']);
            if ($text === false) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'No such version']); exit; }
            echo json_encode(['success' => true, 'is_default' => $text === null, 'text' => $text ?? replyCleanupDefaultTemplate()]);
            exit;
        }
        $active = replyCleanupActiveTemplate($conn);
        echo json_encode([
            'success'      => true,
            'is_default'   => $active === null,
            'text'         => $active ?? replyCleanupDefaultTemplate(),
            'default_text' => replyCleanupDefaultTemplate(),
            'placeholders' => REPLY_CLEANUP_PLACEHOLDERS,
            'max'          => REPLY_CLEANUP_PROMPT_MAX,
            'versions'     => replyCleanupPromptVersions($conn),
        ]);
        exit;
    }

    // Changes data: never on a GET, which a link on another site can send (csrf.php).
    csrfRequireWriteMethod();
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $analystId = (int)$_SESSION['analyst_id'];
    $action = (string)($in['action'] ?? '');

    if ($action === 'save') {
        $id = replyCleanupSavePromptVersion($conn, (string)($in['text'] ?? ''), $analystId, 'edit');
    } elseif ($action === 'reset') {
        $id = replyCleanupSavePromptVersion($conn, null, $analystId, 'reset');
    } elseif ($action === 'restore') {
        $from = (int)($in['id'] ?? 0);
        $text = replyCleanupPromptVersionText($conn, $from);
        if ($text === false) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'No such version']); exit; }
        $id = replyCleanupSavePromptVersion($conn, $text, $analystId, 'restore', $from);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }
    echo json_encode(['success' => true, 'version_id' => $id, 'changed' => $id > 0]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('reply_cleanup_prompt: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'The prompt could not be saved. Run Database Verification if this is a new install.']);
}
