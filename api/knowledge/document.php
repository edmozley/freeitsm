<?php
/**
 * A document on a Knowledge article, for a signed-in analyst or portal user (GH #416).
 *
 *   api/knowledge/document.php?article_id=A&id=D
 *
 * The address the REST API gives as a document's `url`, so an article's HTML can link
 * to its PDF the way a hand-written one does. The same address works in the app and in
 * the Help Centre, as api/knowledge/image.php does for pictures.
 *
 * 🔑 THE ARTICLE IS PART OF THE QUESTION. The reader must be able to read article A now,
 * and the document must be on A. Unlike api/documents/download.php, a grant through
 * some other record never opens this address: losing access to A closes A's links at
 * once, whatever else the document hangs off.
 *
 *   - an analyst: the Knowledge module, and the article as their own reading of it
 *     (company, audience, access list; drafts and archived articles too, as the app).
 *     Logged in document_access_log, as every analyst download is.
 *   - a portal user: an article they may read in the Help Centre (portalCanReadArticle:
 *     published, customer-facing, their company, the access list), and only a document
 *     shown there (show_in_help_centre) - a hidden one stays hidden even when the
 *     article's HTML links to it.
 *
 * Every refusal is the same 404, so ids cannot be used to learn what exists.
 *
 * TRAP: nobody signed in gets nothing, even for a public article - Ed, 19 Nov 2026,
 * on pictures: "you should not be able to view content of any sort if you are not
 * authenticated in some way". Ids are sequential.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/uploads.php';     // attachmentSendHeaders()
require_once '../../includes/services/knowledge_transfer.php';
require_once '../../includes/knowledge/help_centre_documents.php';   // documents.php, portal_reader.php

/** One refusal for every reason. */
function knowledgeDocumentDenied(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    exit("Not found\n");
}

$articleId  = (int)($_GET['article_id'] ?? 0);
$documentId = (int)($_GET['id'] ?? 0);
if ($articleId <= 0 || $documentId <= 0) knowledgeDocumentDenied();

try {
    $conn = connectToDatabase();
    if (!moduleIsEnabled($conn, 'knowledge')) knowledgeDocumentDenied();

    $sql = "SELECT d.id, d.kind, d.title, d.storage_key, d.original_name, d.external_url
              FROM document_links dl
              JOIN documents d ON d.id = dl.document_id AND d.deleted_datetime IS NULL
             WHERE dl.document_id = ? AND dl.parent_type = 'knowledge_article' AND dl.parent_id = ?";
    $analystId = 0;
    if (!empty($_SESSION['analyst_id'])) {
        $analystId = (int)$_SESSION['analyst_id'];
        KnowledgeTransferService::requireActor($conn, ActorContext::fromSession($conn), false);
        // The grants now, not at sign-in; the article as this analyst reads it.
        if (!documentCanViewParent($conn, $analystId, analystAllowedModulesNow(), 'knowledge_article', $articleId)) {
            knowledgeDocumentDenied();
        }
    } elseif (!empty($_SESSION['ss_user_id'])) {
        $userId = (int)$_SESSION['ss_user_id'];
        // A requester marked as left keeps nothing (self-service/includes/auth.php).
        if (!requesterIsActive($conn, $userId) || !documentHelpCentreReady($conn)
            || !portalCanReadArticle($conn, $userId, $articleId)) {
            knowledgeDocumentDenied();
        }
        $sql .= ' AND dl.show_in_help_centre = 1';
    } else {
        knowledgeDocumentDenied();
    }

    $st = $conn->prepare($sql);
    $st->execute([$documentId, $articleId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    // GH #303: a file in Files opens through Files, never from here.
    if (!$doc || $doc['kind'] === DOCUMENT_KIND_FILES) knowledgeDocumentDenied();

    if ($analystId > 0) {
        // Recorded before serving: a log written afterwards misses the requests that went wrong.
        try {
            $conn->prepare("INSERT INTO document_access_log (document_id, analyst_id, action, ip_address) VALUES (?,?,?,?)")
                 ->execute([$documentId, $analystId, $doc['kind'] === DOCUMENT_KIND_LINK ? 'follow' : 'download',
                            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]);
        } catch (Throwable $e) { /* never fail a download because the log did */ }
    }

    // A link is not ours to serve - where it lives, as JSON. Deliberately NOT a
    // redirect: this address would otherwise be an open redirector.
    if ($doc['kind'] === DOCUMENT_KIND_LINK) {
        header('Content-Type: application/json');
        header('Cache-Control: private, no-store');
        echo json_encode(['success' => true, 'kind' => 'link', 'external_url' => $doc['external_url']]);
        exit;
    }

    $path = documentStoragePath((string)$doc['storage_key']);
    if (!is_file($path)) {
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        exit("That file is recorded but missing from storage.\n");
    }
    // The shared serving rules decide inline-versus-download (an HTML or SVG shown
    // inline on our own origin would run as us) and clean the file name.
    attachmentSendHeaders((string)($doc['original_name'] ?: $doc['title']), (int)filesize($path));
    // No conditional caching: revoking article access must also revoke its documents.
    header('Cache-Control: private, no-store');
    readfile($path);
} catch (Throwable $e) {
    knowledgeDocumentDenied();
}
