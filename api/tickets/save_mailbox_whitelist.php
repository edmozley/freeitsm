<?php
/**
 * API Endpoint: Save whitelist entries for a mailbox
 * POST: { mailbox_id, entries: [{ entry_type, entry_value }] }
 * Replaces all existing entries for the mailbox.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$mailboxId = $data['mailbox_id'] ?? null;
$entries = $data['entries'] ?? [];

if (!$mailboxId) {
    echo json_encode(['success' => false, 'error' => 'Mailbox ID is required']);
    exit;
}

$allowedTypes = ['domain', 'email'];

try {
    $conn = connectToDatabase();
    $conn->beginTransaction();

    // Delete existing entries
    $delStmt = $conn->prepare("DELETE FROM mailbox_email_whitelist WHERE mailbox_id = ?");
    $delStmt->execute([$mailboxId]);

    // Insert new entries
    if (!empty($entries)) {
        $insStmt = $conn->prepare("INSERT INTO mailbox_email_whitelist (mailbox_id, entry_type, entry_value) VALUES (?, ?, ?)");

        foreach ($entries as $entry) {
            $type = $entry['entry_type'] ?? '';
            $value = strtolower(trim($entry['entry_value'] ?? ''));

            if (!in_array($type, $allowedTypes) || empty($value)) {
                continue;
            }

            $insStmt->execute([$mailboxId, $type, $value]);
        }
    }

    $conn->commit();

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
