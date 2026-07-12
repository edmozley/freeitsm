<?php
/**
 * LMS API: the knowledge articles an author can turn into a lesson.
 *
 * A deliberately thin list (id + title only) rather than reusing the knowledge
 * module's own endpoint, because that one is gated on the 'knowledge' module and
 * an LMS author may not have it. Bodies are never returned here — ai_author.php
 * reads the body server-side when it actually needs it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireCapabilityJson('lms.manage');

try {
    $conn = connectToDatabase();
    $stmt = $conn->query("SELECT id, title FROM knowledge_articles
                          WHERE is_published = 1 AND (is_archived = 0 OR is_archived IS NULL)
                          ORDER BY title");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    // The knowledge module may not exist on this install — an empty list is the
    // right answer, not an error the author has to think about.
    echo json_encode(['success' => true, 'data' => []]);
}
