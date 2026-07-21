<?php
/**
 * API Endpoint: create or update a reply template.
 *
 * POST { id?, name, body, scope: 'shared'|'mine', is_active?, display_order? }
 *
 * TWO DIFFERENT PERMISSION RULES IN ONE ENDPOINT
 * ----------------------------------------------
 *   scope 'mine'   — anybody with the Tickets module. It is one analyst's own text,
 *                    saved from the reply box, and nobody else will ever see it.
 *   scope 'shared' — Cap::TICKETS_REPLY_TEMPLATES. This is settings: it puts words in
 *                    every colleague's picker, under the company's name.
 *
 * The case worth staring at is the SEAM between them. A private template is yours to
 * edit — but flipping one to shared is not an edit, it is publishing, so the
 * capability is checked against the scope being SAVED, not the scope it had. Without
 * that, every analyst could promote their own draft into the team list and the
 * settings permission would mean nothing.
 *
 * ON NOT SANITISING THE BODY
 * --------------------------
 * Analyst-authored HTML is stored as written, deliberately. sanitiseUserHtml() is for
 * CUSTOMER markup and its allow-list drops colours and styling — which is exactly the
 * rich formatting a template is for. And it would buy nothing: an analyst can already
 * put arbitrary HTML in an outbound reply via send_email.php, so a template grants no
 * capability they lack. The genuinely untrusted input here is the merge VALUES
 * (a requester's name comes from a stranger's From header) and those are escaped at
 * render time in includes/reply_templates.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/reply_templates.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id           = !empty($data['id']) ? (int)$data['id'] : null;
    $name         = trim($data['name'] ?? '');
    $body         = trim($data['body'] ?? '');
    $scope        = ($data['scope'] ?? 'mine') === 'shared' ? 'shared' : 'mine';
    $isActive     = isset($data['is_active']) ? (int)!empty($data['is_active']) : 1;
    $displayOrder = (int)($data['display_order'] ?? 0);

    if ($name === '')                       throw new Exception('Name is required');
    if (trim(strip_tags($body)) === '')      throw new Exception('Template text is required');
    if (mb_strlen($name) > 100)              throw new Exception('Name is too long');

    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    // Publishing to the team — or keeping something published there — is the settings
    // action. Checked on the TARGET scope, so promotion is covered by the same line.
    if ($scope === 'shared') {
        requireCapabilityJson(Cap::TICKETS_REPLY_TEMPLATES);
    }

    if ($id) {
        $current = replyTemplateWriteScope($conn, $analystId, $id);
        if ($current === null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Template not found']);
            exit;
        }
        // Editing something already shared is a settings action even when the save is
        // trying to demote it to private — otherwise "make it mine" would be a way to
        // quietly delete a team template you had no permission to touch.
        if ($current === 'shared') {
            requireCapabilityJson(Cap::TICKETS_REPLY_TEMPLATES);
        }
    }

    // Which company a SHARED template belongs to, following the ticket_types rule
    // (design §7): the MSP/Default context authors global defaults (tenant_id NULL);
    // a client context authors that company's own. A PRIVATE template is never
    // tenant-scoped — it is one person's text and follows them everywhere.
    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $isDefaultCtx = (!$multi || $activeId === getDefaultTenantId($conn));
    $tenantId     = ($scope === 'shared' && !$isDefaultCtx) ? $activeId : null;
    $ownerId      = ($scope === 'mine') ? $analystId : null;

    if ($id) {
        $stmt = $conn->prepare(
            "UPDATE ticket_reply_templates
                SET name = ?, body = ?, analyst_id = ?, tenant_id = ?,
                    is_active = ?, display_order = ?, updated_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        );
        $stmt->execute([$name, $body, $ownerId, $tenantId, $isActive, $displayOrder, $id]);
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO ticket_reply_templates
                 (name, body, analyst_id, tenant_id, is_active, display_order)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$name, $body, $ownerId, $tenantId, $isActive, $displayOrder]);
        $id = (int)$conn->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
