<?php
/**
 * Serve a file attached to a SHARED ticket note, to the requester who raised
 * that ticket.
 *
 * Notes could carry files from the moment note attachments arrived (discussion
 * #69), but only INTERNAL notes could: a shared note had to drop them, because
 * the portal had no way to hand a document back and offering one would have been
 * a promise to the requester that was never kept. That limitation is what this
 * endpoint removes (discussion #103).
 *
 * This is the portal twin of api/documents/download.php, exactly as
 * api/self-service/get_attachment.php is the portal twin of the analyst-side
 * attachment route. The difference is the authorisation model: the analyst
 * version asks "may this analyst reach this document", this one asks "did this
 * requester raise the ticket the document hangs off, and is the note it hangs
 * off one they are allowed to read".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  THE RULE THAT MAKES THIS SAFE
 * ─────────────────────────────────────────────────────────────────────────────
 * Both halves are ONE join, not two checks:
 *
 *     documents -> document_links (parent_type='ticket_note')
 *               -> ticket_notes  (is_internal = 0)
 *               -> tickets       (user_id = this requester)
 *
 * An internal note's files are therefore unreachable BY CONSTRUCTION rather than
 * by a separate condition somebody could later forget, reorder, or short-circuit.
 * A miss is a 404 whatever the reason, so a wrong id and somebody else's id are
 * indistinguishable from outside and ids cannot be enumerated into another
 * customer's files.
 *
 * Only `parent_type = 'ticket_note'` is served. Documents hanging off assets,
 * contracts, changes or anything else are NOT portal material and must not
 * become reachable because they happen to share a table.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/documents.php';   // documentStoragePath(), DOCUMENT_KIND_LINK
require_once '../../includes/uploads.php';     // attachmentSendHeaders()

if (!isset($_SESSION['ss_user_id'])) {
    http_response_code(401);
    exit('Not authenticated');
}

$userId     = (int) $_SESSION['ss_user_id'];
$documentId = (int) ($_GET['id'] ?? 0);

if (!$documentId) {
    http_response_code(400);
    exit('Document ID required');
}

try {
    $conn = connectToDatabase();

    // One query, joined all the way to the ticket. See the note above: the
    // is_internal = 0 condition lives HERE, in the same join that establishes
    // ownership, so an internal note's file can never be served by this route.
    $stmt = $conn->prepare(
        "SELECT d.id, d.kind, d.title, d.storage_key, d.original_name, d.external_url, t.id AS ticket_id
           FROM documents d
           JOIN document_links dl ON dl.document_id = d.id
                                 AND dl.parent_type = 'ticket_note'
           JOIN ticket_notes n    ON n.id = dl.parent_id
                                 AND n.is_internal = 0
           JOIN tickets t         ON t.id = n.ticket_id
          WHERE d.id = ?
            AND d.deleted_datetime IS NULL
            AND d.kind <> 'files'   -- GH #303: a Files link is for analysts, under the Files permissions
            AND t.deleted_datetime IS NULL"
    );
    $stmt->execute([$documentId]);

    // Served if ANY ticket it is shared on is one this person may see - by the
    // SAME rule as the ticket page (includes/managers.php), and never through a
    // confidential stub (#62). Every row is checked, not just the first: one
    // document can be linked to notes on several tickets.
    require_once __DIR__ . '/../../includes/managers.php';
    $doc = false;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $access = portalTicketAccess($conn, $userId, (int)$row['ticket_id']);
        if ($access && !$access['stub']) { $doc = $row; break; }
    }

    if (!$doc) {
        http_response_code(404);
        exit('Document not found');
    }

    // A DMS entry is a link out, not a file we hold. Hand the URL back rather
    // than pretending to stream something — matching the analyst-side route.
    if ($doc['kind'] === DOCUMENT_KIND_LINK) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'kind' => 'link', 'external_url' => $doc['external_url']]);
        exit;
    }

    $path = documentStoragePath((string) $doc['storage_key']);
    if (!is_file($path)) {
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        exit("That file is recorded but missing from storage.\n");
    }

    // Shared serving rules rather than headers written here: they already decide
    // what may be shown inline and what must always be downloaded — an HTML or
    // SVG rendered inline on our own origin runs as us — and they strip the
    // quotes and newlines a filename could otherwise use to break out of the
    // Content-Disposition header.
    $name = (string) ($doc['original_name'] ?: $doc['title']);
    attachmentSendHeaders($name, (int) filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);

} catch (Exception $e) {
    http_response_code(500);
    exit('Could not retrieve the document');
}
