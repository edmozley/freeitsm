<?php
/**
 * The help assistant: Reset - the analyst's conversation is deleted (wiki
 * Help-Assistant-Design).
 *
 * POST -> {"success": true, "removed": 12}
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/help_assistant.php';

[$conn, $analystId] = helpAssistantRequireJson();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('POST only');
    echo json_encode(['success' => true, 'removed' => helpAssistantReset($conn, $analystId)]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('help assistant reset: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
}
