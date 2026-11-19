<?php
/** Protected inline Knowledge image, for analyst and portal sessions. */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/services/knowledge_images.php';
require_once '../../includes/knowledge/portal_reader.php';

try {
    $conn = connectToDatabase();
    if (!moduleIsEnabled($conn, 'knowledge')) throw new RuntimeException('Unavailable');
    $lifecycle = 'live';
    if (!empty($_SESSION['analyst_id'])) {
        $ctx = ActorContext::fromSession($conn);
        KnowledgeTransferService::requireActor($conn, $ctx, false);
        $viewer = KnowledgeViewer::forAnalyst($conn, (int)$_SESSION['analyst_id']);
        $lifecycle = 'any';
    } elseif (!empty($_SESSION['ss_user_id'])) {
        $st = $conn->prepare('SELECT tenant_id FROM users WHERE id = ?');
        $st->execute([(int)$_SESSION['ss_user_id']]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new RuntimeException('Unavailable');
        // Match portalKnowledgeScope(), including its current principal model.
        $viewer = KnowledgeViewer::forPortalUser($conn, null, portalUserTenantId($conn, (int)$_SESSION['ss_user_id']));
    } else {
        // TRAP: nobody signed in = nothing served, not even an image from a
        // public article. Ed, 19 Nov 2026: "you should not be able to view content
        // of any sort if you are not authenticated in some way." Image ids are
        // sequential, so an anonymous reader would be a walk through 1, 2, 3...
        // The PR as sent served public, shared articles' images here.
        throw new RuntimeException('Unavailable');
    }
    $row = KnowledgeImageService::load($conn, $viewer, (int)($_GET['id'] ?? 0), $lifecycle);
    KnowledgeImageService::serve($row);
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo 'Not found';
}
