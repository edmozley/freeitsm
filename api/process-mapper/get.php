<?php
/**
 * Get a single process with its steps and connectors
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing process ID']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->prepare("SELECT * FROM processes WHERE id = ?");
    $stmt->execute([$id]);
    $process = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$process) {
        echo json_encode(['success' => false, 'error' => 'Process not found']);
        exit;
    }

    $stepsStmt = $conn->prepare("SELECT * FROM process_steps WHERE process_id = ?");
    $stepsStmt->execute([$id]);
    $steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

    $connStmt = $conn->prepare("SELECT * FROM process_connectors WHERE process_id = ?");
    $connStmt->execute([$id]);
    $connectors = $connStmt->fetchAll(PDO::FETCH_ASSOC);

    // process_groups + process_lanes tables may not exist on older installs that
    // haven't run db_verify since those features shipped — fall back to empty array.
    $groups = [];
    try {
        $grpStmt = $conn->prepare("SELECT * FROM process_groups WHERE process_id = ?");
        $grpStmt->execute([$id]);
        $groups = $grpStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $lanes = [];
    try {
        $laneStmt = $conn->prepare("SELECT * FROM process_lanes WHERE process_id = ? ORDER BY display_order, id");
        $laneStmt->execute([$id]);
        $lanes = $laneStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    // process_annotations shipped in #334 — same defensive fallback so older
    // installs that haven't run db_verify don't error out.
    $annotations = [];
    try {
        $annStmt = $conn->prepare("SELECT * FROM process_annotations WHERE process_id = ?");
        $annStmt->execute([$id]);
        $annotations = $annStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $process['steps']       = $steps;
    $process['connectors']  = $connectors;
    $process['groups']      = $groups;
    $process['lanes']       = $lanes;
    $process['annotations'] = $annotations;

    echo json_encode(['success' => true, 'data' => $process]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
