<?php
/**
 * The help assistant: the analyst's conversation, oldest first, plus the level
 * the install is at (wiki Help-Assistant-Design).
 *
 * GET -> {"success": true, "level": "search", "messages": [{role, body, kind, results, at}]}
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/help_assistant.php';

[$conn, $analystId] = helpAssistantRequireJson();

try {
    echo json_encode(['success' => true, 'level' => helpAssistantLevel($conn)['level'],
                      'messages' => helpAssistantHistory($conn, $analystId)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('help assistant history: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
}
