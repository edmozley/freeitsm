<?php
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;

    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();

    $check = $conn->prepare("SELECT id FROM contracts WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetchColumn()) {
        throw new Exception('Contract not found');
    }

    // Delete children/referrers explicitly rather than relying on FK rules —
    // the contracts domain historically had no foreign keys at all (they were
    // only added in July 2026 and installs grown via Database Verify may still
    // be missing them), so a bare DELETE orphaned contract_term_values rows.
    $conn->beginTransaction();
    try {
        $conn->prepare("DELETE FROM contract_term_values WHERE contract_id = ?")->execute([$id]);
        foreach (['tasks', 'calendar_events', 'rfps'] as $t) {
            try {
                $conn->prepare("UPDATE `$t` SET contract_id = NULL WHERE contract_id = ?")->execute([$id]);
            } catch (Exception $e) { /* table absent on a minimal install */ }
        }
        $conn->prepare("DELETE FROM contracts WHERE id = ?")->execute([$id]);
        $conn->commit();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
