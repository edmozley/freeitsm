<?php
/**
 * API: the CMDB assistant (GH #313). Every rule is in includes/cmdb/assistant.php;
 * this only routes.
 *
 * The company is always the analyst's active one (the top-bar switcher); the
 * conversation is theirs alone, what it learned is the company's.
 *
 * GET                       {success, ready, ai_ready, can_set_up, company: {id, name, multi},
 *                            topics, coverage, messages, remembers, facts}
 *                           Reads only - never creates a conversation.
 * GET ?action=preview&message_id=&index=
 *                           {success, type: schema | objects, status, summary, resolved, ...}
 *                           one proposal re-resolved against the live CMDB, for the Review
 *                           dialog (schema: +can {relationship_types}, multi_company). Reads only.
 * POST {action, ...}        each answers {success, messages, remembers, facts, coverage}
 *      open                 opening the panel: a greeting, a "since we last spoke", or nothing
 *      send   {text}        a message; the answer may carry Noted facts (and, later, proposals)
 *      undo   {message_id, fact_id}             undo one Noted chip
 *      apply / dismiss {message_id, items:[i], selection?:[line]}   proposals - +{proposals}
 *                           (selection: the lines ticked in the preview; none = every
 *                           creatable line). Each item comes back applied (with result:
 *                           created ids and counts) or failed (rolled back, with error).
 *      clear                Start again: the conversation goes, the learning stays
 *      fact_save {fact_id, topic, subject, statement}   edit a fact (Learned view)
 *      fact_delete {fact_id}
 *      forget_all           forget everything learned about this company
 * An AI problem on open/send answers {success: true, ai_error: 'not_configured' | 'unreachable', detail}.
 *
 * Needs CMDB module access and Cap::CMDB_CLASSES (designing the CI schema); no new
 * capability. Cap::CMDB_AI only decides whether the "Set up AI" link shows. Applying
 * checks again per line: a schema's relationship types need Cap::CMDB_RELATIONSHIP_TYPES;
 * CIs are created through CmdbService, as the analyst, in their active company.
 *
 * TRAP: parses on PHP 7.4 (no match, nullsafe or named arguments here).
 *
 * @wiki CMDB-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/service_context.php';   // ServiceError, thrown below
require_once '../../includes/rbac.php';
require_once '../../includes/i18n.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/cmdb/assistant.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('cmdb');
I18n::initFromSession();   // the assistant answers in the analyst's language

/**
 * Send a JSON answer and stop.
 *
 * @param array $data  merged after success: true
 */
function cmdbAssistOut(array $data): void
{
    echo json_encode(['success' => true] + $data);
    exit;
}

try {
    $conn = connectToDatabase();
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($isPost) {
        // Changes data: never on a GET, which a link on another site can send (csrf.php).
        csrfRequireWriteMethod();
    }
    requireCapabilityJson(Cap::CMDB_CLASSES, $conn);
    $analystId = (int)$_SESSION['analyst_id'];
    $company = cmdbAssistCompany($conn, $analystId);
    $ready = cmdbAssistReady($conn);

    if (!$isPost && ($_GET['action'] ?? '') === 'preview') {
        if (!$ready) throw new ServiceError('validation', 'not_ready', t('cmdb.assistant.not_ready'));
        cmdbAssistOut(cmdbAssistPreview($conn, ActorContext::fromSession($conn), $company, (int)($_GET['message_id'] ?? 0), (int)($_GET['index'] ?? -1)));
    }
    if (!$isPost) {
        $out = [
            'ready'      => $ready,
            'ai_ready'   => cmdbAssistAiReady($conn),
            'can_set_up' => analystHasCapability($conn, $analystId, Cap::CMDB_AI),
            'company'    => $company,
            'topics'     => cmdbAssistTopics(),
        ];
        if ($ready) $out += cmdbAssistConversation($conn, (int)$company['id'], $analystId);
        cmdbAssistOut($out);
    }

    if (!$ready) throw new ServiceError('validation', 'not_ready', t('cmdb.assistant.not_ready'));
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    $ctx = ActorContext::fromSession($conn);
    $tid = (int)$company['id'];
    $action = (string)($in['action'] ?? '');

    if ($action === 'open' || $action === 'send') {
        $cfg = [];
        try {
            $cfg = aiSettingsLoad($conn, 'cmdb_ai');
            cmdbAssistOut($action === 'open'
                ? cmdbAssistOpen($conn, $ctx, $company)
                : cmdbAssistTurn($conn, $ctx, $company, 'chat', (string)($in['text'] ?? '')));
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) throw $e;
            if ($e->getMessage() === 'not_configured') cmdbAssistOut(['ai_error' => 'not_configured']);
            error_log('cmdb assistant: ' . $e->getMessage());
            cmdbAssistOut(['ai_error' => 'unreachable', 'detail' => mb_substr(aiExplainFailure($cfg, $e->getMessage()), 0, 400)]);
        }
    }
    switch ($action) {
        case 'undo':
            cmdbAssistUndo($conn, $analystId, $company, (int)($in['message_id'] ?? 0), (int)($in['fact_id'] ?? 0));
            break;
        case 'apply':
        case 'dismiss':
            $sel = isset($in['selection']) && is_array($in['selection']) ? array_values(array_filter($in['selection'], 'is_string')) : null;
            $props = cmdbAssistApply($conn, $ctx, $company, (int)($in['message_id'] ?? 0), (array)($in['items'] ?? []), $action === 'dismiss', $sel);
            // What each became, for the panel - without the arguments.
            $props = array_map(function ($p) {
                return ['type' => $p['type'] ?? '', 'summary' => $p['summary'] ?? '', 'status' => $p['status'] ?? '', 'error' => $p['error'] ?? null, 'result' => $p['result'] ?? null];
            }, $props);
            cmdbAssistOut(['proposals' => $props] + cmdbAssistConversation($conn, $tid, $analystId));
            break;
        case 'clear':
            cmdbAssistClear($conn, $analystId, $company);
            break;
        case 'fact_save':
            cmdbAssistFactSave($conn, $tid, $analystId, (int)($in['fact_id'] ?? 0), $in);
            break;
        case 'fact_delete':
            if (!cmdbAssistForget($conn, $tid, (int)($in['fact_id'] ?? 0))) throw new ServiceError('not_found', 'not_found', t('cmdb.assistant.err_fact_gone'));
            break;
        case 'forget_all':
            cmdbAssistForgetAll($conn, $tid);
            break;
        default:
            throw new ServiceError('validation', 'invalid_field', t('cmdb.assistant.err_action'));
    }
    cmdbAssistOut(cmdbAssistConversation($conn, $tid, $analystId));
} catch (ServiceError $e) {
    if ($e->kind === 'forbidden') http_response_code(403);
    echo json_encode(['success' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    error_log('cmdb assistant: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => t('cmdb.assistant.err_general')]);
}
