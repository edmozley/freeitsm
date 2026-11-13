<?php
/**
 * API Endpoint: find an existing document to attach somewhere else.
 *
 * GET ?q=warranty&parent_type=asset&parent_id=900
 *
 * 🔑 THIS IS WHAT THE JOIN TABLE WAS FOR. Until now every upload made a NEW
 * document, so one warranty covering eleven laptops meant eleven copies — the
 * exact thing document_links exists to avoid. This is the door to the other half.
 *
 * ⚠️ You may only attach a document you can ALREADY see, to a record you can
 * already see. Both halves matter: without the first, this endpoint would let
 * somebody attach a document they have no access to onto a record they do have
 * access to — and then read it, because visibility is inherited. That is
 * privilege escalation with two clicks, and it is why the search below runs
 * through the same visibility clause as everything else.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/documents.php';
require_once '../../includes/services/documents.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId  = (int) $_SESSION['analyst_id'];
$allowed    = analystAllowedModulesNow();   // the grants now, not at sign-in
$q          = trim((string) ($_GET['q'] ?? ''));
$parentType = trim((string) ($_GET['parent_type'] ?? ''));
$parentId   = (int) ($_GET['parent_id'] ?? 0);

if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'documents' => []]);
    exit;
}
if (!documentEntityDef($parentType) || $parentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'A valid parent_type and parent_id are required.']);
    exit;
}

try {
    $conn = connectToDatabase();

    // You must be able to see where you are attaching TO.
    if (!documentCanViewParent($conn, $analystId, $allowed, $parentType, $parentId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have access to that record.']);
        exit;
    }

    // ...and only documents you can already see come back.
    list($visSql, $visParams) = documentVisibilityClause($conn, $analystId, $allowed, 'd');

    $like = '%' . $q . '%';
    $sql = "SELECT d.id, d.kind, d.title, d.original_name, d.mime_type, d.size_bytes, d.external_url
              FROM documents d
             WHERE d.deleted_datetime IS NULL
               -- GH #303: a Files link name is for Files to show; it is found under In Files.
               AND d.kind <> 'files'
               AND (d.title LIKE ? OR d.original_name LIKE ? OR d.description LIKE ?)
               -- Already on this record: offering it again would only produce a
               -- duplicate-key error the person cannot act on.
               AND NOT EXISTS (SELECT 1 FROM document_links dl
                                WHERE dl.document_id = d.id
                                  AND dl.parent_type = ? AND dl.parent_id = ?)"
             . $visSql . "
          ORDER BY d.created_datetime DESC
             LIMIT 15";

    $st = $conn->prepare($sql);
    $st->execute(array_merge([$like, $like, $like, $parentType, $parentId], $visParams));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['size_bytes'] = $r['size_bytes'] !== null ? (int) $r['size_bytes'] : null;
        // Where it already lives, so somebody attaching it can see they are
        // WIDENING who can read it — the consequence of the inheritance rule.
        $r['also_on'] = documentVisibleParents($conn, $analystId, $allowed, $r['id']);
    }
    unset($r);

    // GH #303: files in Files the person can open, to attach as a link or a copy
    // (Files -> Settings -> Sharing says which).
    require_once '../../includes/services/files_attach.php';
    // Always searched: only attaching as a LINK needs documents.files_item_id, and attach()
    // says so then. Hiding the results until Database Verification ran looked like
    // "no files match" with no reason given (Ed, 2026-11-13).
    $files = FilesAttach::search($conn, $analystId, $q);
    echo json_encode(['success' => true, 'documents' => $rows, 'files' => $files, 'files_mode' => FilesAttach::mode($conn)]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
