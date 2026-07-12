<?php
/**
 * LMS API: the courses assigned to the signed-in analyst — the My Courses feed.
 *
 * Learner-facing: needs the 'lms' module, nothing more. It only ever returns the
 * caller's OWN assigned courses (the query is scoped to their group memberships),
 * so there's nothing here a learner shouldn't see. All the real logic lives in
 * lmsMyCourses() so the page and this endpoint can't disagree.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/lms_access.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('lms');

try {
    $conn = connectToDatabase();
    echo json_encode(['success' => true, 'data' => lmsMyCourses($conn, (int)$_SESSION['analyst_id'])]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
