<?php
/**
 * API: CMDB schema plans (GH #313) - preview and apply.
 *
 * GET  ?plan=starter                        {success, plan, resolved, can: {classes, relationship_types}, multi_company}
 *                                           Reads only - resolves the plan against the schema as it is now.
 * POST {plan: 'starter', selection: [line]} {success, created: {relationship_types, classes, properties},
 *                                            counts, skipped: [{line, reason}], existing}
 *
 * Needs CMDB module access and Cap::CMDB_CLASSES (the button lives on the Classes
 * tab). On apply each line is checked again by the plan engine: classes and
 * properties need Cap::CMDB_CLASSES, relationship types Cap::CMDB_RELATIONSHIP_TYPES.
 *
 * Only the Starter classes plan for now; the CMDB AI assistant will send its own.
 *
 * @wiki CMDB-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/i18n.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/cmdb/schema_plan.php';
require_once '../../includes/cmdb/starter_classes.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('cmdb');
I18n::initFromSession();

try {
    $conn = connectToDatabase();
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($isPost) {
        // Changes data: never on a GET, which a link on another site can send (csrf.php).
        csrfRequireWriteMethod();
    }
    requireCapabilityJson(Cap::CMDB_CLASSES, $conn);

    $in = $isPost ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    if (($in['plan'] ?? '') !== 'starter') {
        echo json_encode(['success' => false, 'error' => t('cmdb.plan.unknown_plan')]);
        exit;
    }
    $plan = cmdbStarterPlan();
    $ctx = ActorContext::fromSession($conn);

    if ($isPost) {
        $selection = array_values(array_filter((array)($in['selection'] ?? []), 'is_string'));
        $res = cmdbPlanApply($conn, $ctx, $plan, $selection);
        echo json_encode(['success' => true] + $res);
        exit;
    }

    echo json_encode([
        'success' => true,
        'plan' => $plan,
        'resolved' => cmdbPlanResolve($conn, $plan),
        'can' => [
            'classes' => true,
            'relationship_types' => analystHasCapability($conn, $ctx->actorId, Cap::CMDB_RELATIONSHIP_TYPES),
        ],
        'multi_company' => isMultiTenant($conn),
    ]);
} catch (ServiceError $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('cmdb schema_plan: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Something went wrong - nothing was changed.']);
}
