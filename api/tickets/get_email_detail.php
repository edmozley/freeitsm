<?php
/**
 * API Endpoint: Get email details
 * Returns full email content for display in reading pane
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get email ID or ticket ID from request
$emailId = $_GET['id'] ?? null;
$ticketId = $_GET['ticket_id'] ?? null;

if (!$emailId && !$ticketId) {
    echo json_encode(['success' => false, 'error' => 'Email ID or Ticket ID required']);
    exit;
}

try {
    // Connect to database
    $conn = connectToDatabase();

    // Query full email details with ticket information
    $sql = "SELECT
                e.id,
                e.exchange_message_id,
                e.from_address,
                e.from_name,
                e.to_recipients,
                e.cc_recipients,
                e.received_datetime,
                e.body_preview,
                e.body_content,
                e.body_type,
                e.has_attachments,
                e.importance,
                e.is_read,
                e.ticket_id,
                e.is_initial,
                e.direction,
                t.ticket_number,
                t.subject,
                ts.name AS status,
                tp.name AS priority,
                t.priority_id,
                t.department_id,
                t.ticket_type_id,
                t.assigned_analyst_id,
                t.origin_id,
                t.first_time_fix,
                t.it_training_provided,
                t.owner_id,
                t.work_start_datetime,
                t.tenant_id,
                t.user_id,
                u.email AS requester_email,
                t.created_datetime as ticket_created,
                t.updated_datetime as ticket_updated,
                t.deleted_datetime
            FROM emails e
            INNER JOIN tickets t ON e.ticket_id = t.id
            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
            LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
            LEFT JOIN users u ON u.id = t.user_id
            WHERE ";

    // Look up by email ID or by ticket ID (gets the initial email for the ticket)
    if ($emailId) {
        $sql .= "e.id = ?";
        $param = $emailId;
    } else {
        $sql .= "e.ticket_id = ? AND e.is_initial = 1";
        $param = $ticketId;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([$param]);
    $email = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$email) {
        echo json_encode(['success' => false, 'error' => 'Email not found']);
        exit;
    }

    // Multi-tenancy: don't reveal a ticket in a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], (int)$email['ticket_id'])) {
        echo json_encode(['success' => false, 'error' => 'Email not found']);
        exit;
    }

    // Clean body_content of control characters and Unicode replacement characters
    if ($email['body_content']) {
        // Remove control characters (0x00-0x1F except tab, newline, carriage return, and 0x7F)
        $email['body_content'] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $email['body_content']);
        // Remove Unicode replacement character (U+FFFD = 0xEF 0xBF 0xBD in UTF-8)
        $email['body_content'] = str_replace("\xEF\xBF\xBD", '', $email['body_content']);
    }

    // Format date for display
    if ($email['received_datetime']) {
        $email['received_datetime'] = date('Y-m-d\TH:i:s', strtotime($email['received_datetime']));
    }

    // Convert bit fields to boolean
    $email['is_read'] = (bool)$email['is_read'];
    $email['has_attachments'] = (bool)$email['has_attachments'];
    $email['first_time_fix'] = $email['first_time_fix'] === null ? null : (bool)$email['first_time_fix'];
    $email['it_training_provided'] = $email['it_training_provided'] === null ? null : (bool)$email['it_training_provided'];

    // Multi-tenancy: the ticket's company, and a soft wrong-company suggestion. All of
    // this stays null on a single-company install (isMultiTenant false), so the reading
    // pane shows nothing extra at N=1.
    $email['tenant_id'] = $email['tenant_id'] !== null ? (int)$email['tenant_id'] : null;
    $email['company_name'] = null;
    $email['company_warning'] = null;
    if (isMultiTenant($conn)) {
        $effectiveTenant = $email['tenant_id'] ?? getDefaultTenantId($conn);
        $cur = getTenantById($conn, (int)$effectiveTenant);
        $email['company_name'] = $cur['name'] ?? null;

        // Where does the requester's address say this should go? If that's a real
        // company and differs from where the ticket currently sits, flag it (soft).
        $reqEmail = trim((string)($email['requester_email'] ?? ''));
        if ($reqEmail === '') {
            // Fall back to the inbound sender address if there's no linked user.
            $reqEmail = trim((string)($email['from_address'] ?? ''));
        }
        if ($reqEmail !== '') {
            $suggested = resolveTenantIdForAddress($conn, $reqEmail);
            if ($suggested !== null && (int)$suggested !== (int)$effectiveTenant) {
                $sug = getTenantById($conn, (int)$suggested);
                if ($sug) {
                    $email['company_warning'] = [
                        'suggested_id'   => (int)$suggested,
                        'suggested_name' => $sug['name'],
                        'requester'      => $reqEmail,
                    ];
                }
            }
        }
    }

    // Mark email as read if not already
    if (!$email['is_read']) {
        $updateSql = "UPDATE emails SET is_read = 1 WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$emailId]);
    }

    // Problem Management: problems this incident is linked to (badge + link in the
    // reading pane). Degrades to [] if the module's tables aren't present yet.
    $email['problems'] = [];
    try {
        $pq = $conn->prepare(
            "SELECT p.id, p.problem_number, p.title, ps.name AS status
             FROM problem_tickets pt
             JOIN problems p ON p.id = pt.problem_id
             LEFT JOIN problem_statuses ps ON ps.id = p.status_id
             WHERE pt.ticket_id = ? ORDER BY p.created_datetime DESC"
        );
        $pq->execute([(int) $email['ticket_id']]);
        $email['problems'] = $pq->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* module not installed yet */ }

    // Change Management: changes this incident is linked to (badge + link in the
    // reading pane). Degrades to [] if the module's tables aren't present yet.
    // change_number is derived (CHG-####) from the id, so only id/title/status here.
    $email['changes'] = [];
    try {
        $cq = $conn->prepare(
            "SELECT c.id, c.title, cs.name AS status
             FROM change_tickets ct
             JOIN changes c ON c.id = ct.change_id
             LEFT JOIN change_statuses cs ON cs.id = c.status_id
             WHERE ct.ticket_id = ? ORDER BY c.created_datetime DESC"
        );
        $cq->execute([(int) $email['ticket_id']]);
        $email['changes'] = $cq->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* module not installed yet */ }

    // Screen recordings attached to the ticket
    $recordings = [];
    try {
        $recStmt = $conn->prepare(
            "SELECT id, original_filename, content_type, file_size, duration_seconds, has_audio, created_at
             FROM ticket_recordings WHERE ticket_id = ? ORDER BY created_at ASC"
        );
        $recStmt->execute([(int)$email['ticket_id']]);
        $recordings = $recStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table may not exist on installs that haven't run db_verify yet
    }

    echo json_encode([
        'success' => true,
        'email' => $email,
        'recordings' => $recordings
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
