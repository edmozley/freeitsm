<?php
/**
 * API Endpoint: Get notes for a ticket
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$ticket_id = $_GET['ticket_id'] ?? null;

if (!$ticket_id) {
    echo json_encode(['success' => false, 'error' => 'Ticket ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Multi-tenancy: don't reveal a ticket in a company this analyst can't access.
    if (!analystCanAccessTicket($conn, (int)$_SESSION['analyst_id'], $ticket_id)) {
        echo json_encode(['success' => false, 'error' => 'Ticket not found']);
        exit;
    }

    // ⚠️ LEFT JOIN, not JOIN. A note imported from an external issue tracker has
    // no FreeITSM author (analyst_id NULL - 0 on rows from before #281), and an inner join silently dropped it —
    // which presents as "Jira comments never arrive" with nothing in any log.
    $sql = "SELECT
                n.id,
                n.ticket_id,
                n.analyst_id,
                n.note_text,
                n.is_internal,
                n.created_datetime,
                a.full_name as analyst_name
            FROM ticket_notes n
            LEFT JOIN analysts a ON n.analyst_id = a.id
            WHERE n.ticket_id = ?
            ORDER BY n.created_datetime DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$ticket_id]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attribute imported notes to the tracker they came from, so the header says
    // "Jira" rather than nothing. Deliberately a second query behind a schema
    // gate: an install that has not run Database Verification since V1 has no
    // comment map, and that must read as "no tracker notes", never as an error.
    $trackerNotes = [];
    require_once '../../includes/integrations/integrations.php';
    if ($notes && integrationsCommentSchemaReady($conn)) {
        try {
            $ids = array_column($notes, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $tStmt = $conn->prepare(
                "SELECT m.local_note_id, c.name AS connection_name, c.provider
                 FROM integration_comment_map m
                 JOIN integration_links l       ON l.id = m.link_id
                 JOIN integration_connections c ON c.id = l.connection_id
                 WHERE m.direction = 'in' AND m.local_note_id IN ($in)"
            );
            $tStmt->execute($ids);
            foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $trackerNotes[(int)$t['local_note_id']] = $t;
            }
        } catch (Exception $e) {
            // Display sugar only — never fail the notes list over it.
        }
    }

    // Files attached to these notes (discussion #69). ONE query for the whole
    // list rather than one per note — a busy ticket has plenty of both.
    //
    // 🔑 NO PERMISSION FILTER HERE, and that is deliberate rather than an
    // omission. A document is visible iff you can see something it is attached
    // to, and the parent here is this ticket, whose access was already checked
    // at the top of this file. Re-deriving it per document would be a second,
    // divergeable answer to a question already settled.
    //
    // Behind a schema gate for the same reason the tracker map above is: an
    // install that has not run Database Verification since documents shipped has
    // no such tables, and that must read as "no attachments", never as an error.
    $noteDocs = [];
    if ($notes) {
        try {
            $ids = array_column($notes, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $dStmt = $conn->prepare(
                "SELECT dl.parent_id AS note_id, d.id, d.kind, d.title,
                        d.original_name, d.mime_type, d.size_bytes, d.external_url
                   FROM document_links dl
                   JOIN documents d ON d.id = dl.document_id
                  WHERE dl.parent_type = 'ticket_note'
                    AND dl.parent_id IN ($in)
                    AND d.deleted_datetime IS NULL
                  ORDER BY d.id"
            );
            $dStmt->execute($ids);
            foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $nid = (int) $d['note_id'];
                unset($d['note_id']);
                $d['id']          = (int) $d['id'];
                $d['size_bytes']  = $d['size_bytes'] === null ? null : (int) $d['size_bytes'];
                $noteDocs[$nid][] = $d;
            }
        } catch (Exception $e) {
            // No documents tables on this install, or a query that failed. Notes
            // still list; they simply show no files.
        }
    }

    foreach ($notes as &$note) {
        $note['is_internal'] = (bool)$note['is_internal'];
        $note['documents']   = $noteDocs[(int)$note['id']] ?? [];
        if ($note['created_datetime']) {
            $note['created_datetime'] = date('Y-m-d\TH:i:s', strtotime($note['created_datetime']));
        }
        $tracker = $trackerNotes[(int)$note['id']] ?? null;
        $note['source'] = $tracker ? (string)$tracker['provider'] : null;

        // Who to credit when the analyst join found nothing. The three cases are
        // genuinely different and lumping them together as "Unknown" throws away
        // information we actually hold:
        //
        //   tracker  — imported from Jira; the header names the connection, and
        //              the note text names the person who wrote it there
        //   former   — a real analyst_id with no row left. Deleting an analyst is
        //              a hard DELETE that reassigns nothing, so this is the normal
        //              state of every note someone wrote before they left
        //   system   — analyst_id NULL (or 0, before #281) and no tracker behind it: written by the app
        //
        // The label itself is resolved in the browser, which is where the
        // translations live; this endpoint only says which case it is.
        if ($note['analyst_name']) {
            $note['author_kind'] = 'analyst';
        } elseif ($tracker) {
            $note['author_kind'] = 'tracker';
            $note['analyst_name'] = (string)$tracker['connection_name'];
        } elseif ((int)$note['analyst_id'] > 0) {
            $note['author_kind'] = 'former';
        } else {
            $note['author_kind'] = 'system';
        }
    }
    unset($note);

    echo json_encode([
        'success' => true,
        'notes' => $notes
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
