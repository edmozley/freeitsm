<?php
/**
 * API Endpoint: fetch a document.
 *
 * ⚠️ THIS IS THE BOUNDARY. Everything else in the feature decides what to LIST;
 * this decides what somebody may HAVE. It must never assume the caller found the
 * id through a filtered list — `download.php?id=12345` with a guessed integer is
 * the attack, and integers are easy to guess.
 *
 * So the check here is the full one: documentCanView() walks every link and asks
 * whether the caller can see any parent. It is deliberately the expensive form,
 * because N is 1 and being right matters more than being quick.
 *
 * Every successful fetch is recorded. This is the only route to a document, so
 * it is the only place that can honestly claim to have seen every access.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/uploads.php';
require_once '../../includes/documents.php';

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not authenticated\n");
}

$analystId  = (int) $_SESSION['analyst_id'];
$allowed    = analystAllowedModulesNow();   // the grants now, not at sign-in
$documentId = (int) ($_GET['id'] ?? 0);

/**
 * One refusal for every reason. "No such document" and "not yours" must look
 * identical, or the difference between them maps out what exists.
 */
function documentDenied(): void {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found\n");
}

if ($documentId <= 0) documentDenied();

try {
    $conn = connectToDatabase();

    // ---- THE CHECK, before anything is read from disk --------------------
    if (!documentCanView($conn, $analystId, $allowed, $documentId)) {
        documentDenied();
    }

    $st = $conn->prepare(
        "SELECT id, kind, title, storage_key, original_name, mime_type, size_bytes, external_url, files_item_id
           FROM documents WHERE id = ? AND deleted_datetime IS NULL"
    );
    $st->execute([$documentId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) documentDenied();

    // Record the access before serving. A log written afterwards is a log that
    // misses exactly the requests that went wrong.
    try {
        $conn->prepare(
            "INSERT INTO document_access_log (document_id, analyst_id, action, ip_address)
             VALUES (?,?,?,?)"
        )->execute([
            $documentId, $analystId,
            $doc['kind'] === DOCUMENT_KIND_LINK ? 'follow' : 'download',
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
    } catch (Throwable $e) { /* never fail a download because the log did */ }

    // GH #303: a file in Files is opened THROUGH Files, so its folder permissions, View
    // only and the watermark still apply. Someone who cannot open that folder gets the
    // same 404 as for a document that does not exist. A redirect only ever to our own
    // files/?item=, never to anything the document holds.
    if ($doc['kind'] === DOCUMENT_KIND_FILES) {
        require_once '../../includes/services/files_attach.php';
        $view = FilesAttach::viewFor($conn, $analystId, (int)$doc['files_item_id']);
        if (!$view['access']) documentDenied();
        header('Location: ' . $view['open_url'], true, 302);
        exit;
    }

    // A link is not ours to serve — hand back where it lives and let the caller
    // decide. Deliberately NOT a redirect: this endpoint would then become an
    // open redirector for anyone who can attach a document.
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

    // Reuse the shared serving rules rather than writing headers here. They already
    // decide what may be shown INLINE and what must always be downloaded — an HTML
    // or SVG rendered inline on our own origin runs as us — and they strip the
    // quotes and newlines a filename could otherwise use to break out of the
    // Content-Disposition header. `inline` is the file type's call, not the
    // caller's: an `?inline=1` on a .html must not be honoured.
    $name = (string) ($doc['original_name'] ?: $doc['title']);
    attachmentSendHeaders($name, (int) filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);

} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Could not serve that document.\n";
}
