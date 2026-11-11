<?php
/**
 * The help assistant: answer a question (wiki Help-Assistant-Design).
 *
 * POST {"question": "...", "module": "tickets"}  ->  {"success": true, "reply": {kind, text, results}}
 *
 * Step 3 is the search level: the best help sections for the question, in the
 * asker's language, from modules they can open, with a canned line chosen by how
 * good the match is. Both the question and the reply are kept in their
 * conversation until they click Reset.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/help_assistant.php';

[$conn, $analystId] = helpAssistantRequireJson();
I18n::initFromSession();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('POST only');
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $question = trim(preg_replace('/\s+/u', ' ', (string)($in['question'] ?? '')));
    if ($question === '') throw new InvalidArgumentException('Ask a question first.');
    $question = mb_substr($question, 0, HELP_ASSISTANT_MAX_QUESTION);
    // The module the asker is in - only a tie-breaker, so anything unrecognised is ignored.
    $module = preg_match('/^[a-z0-9_-]{1,40}$/', (string)($in['module'] ?? '')) ? (string)$in['module'] : null;

    $reply = helpAssistantAnswer($conn, $analystId, $question, I18n::getLocale(), $module);
    helpAssistantSave($conn, $analystId, 'user', $question);
    helpAssistantSave($conn, $analystId, 'assistant', $reply['text'], ['kind' => $reply['kind'], 'results' => $reply['results']]);
    // Nothing found, or only weak matches: keep it for the admins (step 6) - the
    // asker's name only if an admin chose to record it - and ring their bell.
    helpAssistantRecordUnanswered($conn, $analystId, $question, I18n::getLocale(), $module, $reply);
    echo json_encode(['success' => true, 'reply' => $reply], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('help assistant ask: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Something went wrong.']);
}
