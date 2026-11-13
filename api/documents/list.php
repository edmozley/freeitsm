<?php
/**
 * API Endpoint: the documents attached to one record.
 *
 * GET ?parent_type=contract&parent_id=42[&offset=0&limit=50]
 *
 * Paged from the start. "Show all attachments" is the obvious first
 * implementation and it is fine right up until somebody attaches three thousand
 * photographs to an asset, at which point it is a page that never renders.
 *
 * Each row also reports what ELSE it is attached to — limited to parents the
 * caller can see, because "also on Contract 42" would otherwise disclose the
 * existence of a contract they have no access to. That list is what makes the
 * widening rule visible: attach a document somewhere public and everyone there
 * can read it, so the UI has to say where it already is.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/documents.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$analystId  = (int) $_SESSION['analyst_id'];
$allowed    = analystAllowedModulesNow();   // the grants now, not at sign-in
$parentType = trim((string) ($_GET['parent_type'] ?? ''));
$parentId   = (int) ($_GET['parent_id'] ?? 0);
$offset     = max(0, (int) ($_GET['offset'] ?? 0));
$limit      = min(200, max(1, (int) ($_GET['limit'] ?? 50)));

if (!documentEntityDef($parentType) || $parentId <= 0) {
    echo json_encode(['success' => false, 'error' => 'A valid parent_type and parent_id are required.']);
    exit;
}

try {
    $conn = connectToDatabase();

    if (!documentCanViewParent($conn, $analystId, $allowed, $parentType, $parentId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You do not have access to that record.']);
        exit;
    }

    // Opportunistic tidy-up, bounded and cheap. Links whose parent was deleted are
    // invisible either way, so this is housekeeping rather than a safety measure —
    // but doing it where documents are being looked at means an install with no
    // cron still collects its litter. See documentsCollectOrphans().
    try { documentsCollectOrphans($conn, 25); } catch (Throwable $e) { /* never fail a list */ }

    $countSt = $conn->prepare(
        "SELECT COUNT(*) FROM document_links dl
           JOIN documents d ON d.id = dl.document_id
          WHERE dl.parent_type = ? AND dl.parent_id = ? AND d.deleted_datetime IS NULL"
    );
    $countSt->execute([$parentType, $parentId]);
    $total = (int) $countSt->fetchColumn();

    // On a knowledge article, whether each one is shown in the portal's Help
    // Centre (GH #177). Only once Database Verification has added the column.
    $isArticle  = $parentType === 'knowledge_article';
    $helpCentre = $isArticle && documentHelpCentreReady($conn);
    $hcCol      = $helpCentre ? ', dl.show_in_help_centre' : '';
    require_once '../../includes/services/documents.php';
    $filesCol   = DocumentsService::filesLinkReady($conn) ? ', d.files_item_id' : '';   // GH #303

    $st = $conn->prepare(
        "SELECT d.id, d.kind, d.title, d.description, d.original_name, d.mime_type,
                d.size_bytes, d.external_url, d.created_datetime,
                a.full_name AS uploaded_by_name$hcCol$filesCol
           FROM document_links dl
           JOIN documents d ON d.id = dl.document_id
      LEFT JOIN analysts a ON a.id = d.uploaded_by_id
          WHERE dl.parent_type = ? AND dl.parent_id = ? AND d.deleted_datetime IS NULL
       ORDER BY d.created_datetime DESC, d.id DESC
          LIMIT $limit OFFSET $offset"
    );
    $st->execute([$parentType, $parentId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Where else each one lives — only the places this caller may see.
    $otherSt = $conn->prepare(
        "SELECT parent_type, parent_id FROM document_links
          WHERE document_id = ? AND NOT (parent_type = ? AND parent_id = ?)"
    );
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['size_bytes'] = $r['size_bytes'] !== null ? (int) $r['size_bytes'] : null;
        if ($helpCentre) $r['show_in_help_centre'] = (int) $r['show_in_help_centre'] === 1;
        // GH #303: a file in Files - its name and a way in only for someone who can open
        // its folder; for anyone else, only that one exists (Files' "hidden means not found").
        if ($r['kind'] === DOCUMENT_KIND_FILES) {
            require_once '../../includes/services/files_attach.php';
            $fv = FilesAttach::viewFor($conn, $analystId, (int) ($r['files_item_id'] ?? 0));
            $r['files_access'] = $fv['access'];
            $r['files_gone']   = $fv['gone'];
            $r['open_url']     = $fv['open_url'];
            if (!$fv['access']) { $r['title'] = ''; $r['description'] = null; }
        }
        unset($r['files_item_id']);
        $r['also_on']    = [];
        $otherSt->execute([$r['id'], $parentType, $parentId]);
        foreach ($otherSt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            if (!documentCanViewParent($conn, $analystId, $allowed, (string) $o['parent_type'], (int) $o['parent_id'])) {
                continue;   // never name a record the caller cannot see
            }
            $def  = documentEntityDef((string) $o['parent_type']);
            $name = documentParentName($conn, (string) $o['parent_type'], (int) $o['parent_id']);
            $r['also_on'][] = [
                'parent_type' => $o['parent_type'],
                'parent_id'   => (int) $o['parent_id'],
                'label'       => $def['label'],
                'name'        => $name,
            ];
        }
    }
    unset($r);

    $out = [
        'success'   => true,
        'total'     => $total,
        'offset'    => $offset,
        'limit'     => $limit,
        'documents' => $rows,
        // GH #303: whether this person can use Save to Files at all (the folder is checked on save)
        'can_save_to_files' => analystCanAccessModule($conn, $analystId, 'files'),
    ];
    // What the panel says about the Help Centre, for the WHOLE article rather
    // than this page of it: can customers read the article at all, and how many
    // of its documents are shown. 'ready' false = verification not run yet.
    if ($isArticle) {
        require_once '../../includes/knowledge/help_centre_documents.php';
        $shown = 0;
        if ($helpCentre) {
            $c = $conn->prepare(
                "SELECT COUNT(*) FROM document_links dl
                   JOIN documents d ON d.id = dl.document_id AND d.deleted_datetime IS NULL
                  WHERE dl.parent_type = 'knowledge_article' AND dl.parent_id = ? AND dl.show_in_help_centre = 1"
            );
            $c->execute([$parentId]);
            $shown = (int) $c->fetchColumn();
        }
        $out['help_centre'] = [
            'ready'           => $helpCentre,
            'customer_facing' => helpCentreArticleIsCustomerFacing($conn, $parentId),
            'shown'           => $shown,
        ];
    }
    echo json_encode($out);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
