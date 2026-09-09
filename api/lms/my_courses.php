<?php
/**
 * LMS API: the courses assigned to whoever is signed in — the My Courses feed,
 * and the portal's Training page.
 *
 * Learner-facing: it only ever returns the caller's OWN assigned courses (the
 * query is scoped to what reaches them), so there's nothing here a learner
 * shouldn't see. All the real logic lives in lmsMyCourses() so the two pages and
 * this endpoint can't disagree.
 *
 * 🔑 THE MODULE GATE IS ANALYST-ONLY. `lms` is a module in the analyst app's
 * access model; a portal user has no module list and asking about theirs would
 * refuse every one of them. What entitles a portal learner is the assignment
 * itself, which lmsMyCourses() has already applied — so for them the gate is the
 * data, not a permission lookup.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/lms_access.php';
header('Content-Type: application/json');

$learner = LmsLearner::fromSession();
if (!$learner) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
if ($learner->isAnalyst()) {
    requireModuleAccessJson('lms');
}

try {
    $conn = connectToDatabase();
    echo json_encode(['success' => true, 'data' => lmsMyCourses($conn, $learner)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
