<?php
/**
 * API: AI-draft a root cause + workaround for a problem from its linked incidents.
 * Does not save — returns a draft for the analyst to review. Uses the problem_ai key.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ai_settings.php';
require_once '../../api/cmdb/_ai_helpers.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }

try {
    $d = json_decode(file_get_contents('php://input'), true);
    $problemId = (int) ($d['problem_id'] ?? 0);
    if ($problemId <= 0) throw new Exception('Problem ID is required');
    $conn = connectToDatabase();
    if (!analystCanAccessProblem($conn, (int) $_SESSION['analyst_id'], $problemId)) throw new Exception('Problem not found');

    $cfg = aiSettingsLoad($conn, 'problem_ai');
    if (($cfg['api_key'] ?? '') === '') throw new Exception('Problem AI is not configured. Set a provider + key in Problem Management → Settings.');

    // Problem context + its linked incidents.
    $p = $conn->prepare("SELECT title, description FROM problems WHERE id = ?");
    $p->execute([$problemId]);
    $prob = $p->fetch(PDO::FETCH_ASSOC);

    $inc = $conn->prepare(
        "SELECT t.ticket_number, t.subject,
                (SELECT body_content FROM emails e WHERE e.ticket_id = t.id AND e.direction = 'Inbound'
                 ORDER BY e.is_initial DESC, e.id ASC LIMIT 1) AS body
         FROM problem_tickets pt JOIN tickets t ON t.id = pt.ticket_id
         WHERE pt.problem_id = ? AND t.deleted_datetime IS NULL LIMIT 20"
    );
    $inc->execute([$problemId]);
    $rows = $inc->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) throw new Exception('Link some incidents to this problem first, then I can analyse them.');

    $lines = ["Problem: " . ($prob['title'] ?? ''), ($prob['description'] ? 'Notes: ' . $prob['description'] : ''), '', 'Linked incidents:'];
    foreach ($rows as $r) {
        $body = trim(strip_tags((string) $r['body']));
        $body = function_exists('mb_substr') ? mb_substr($body, 0, 600) : substr($body, 0, 600);
        $lines[] = "- [{$r['ticket_number']}] {$r['subject']}\n  $body";
    }
    $context = implode("\n", array_filter($lines, fn($l) => $l !== ''));

    $system = "You are an experienced ITIL problem manager. Given a set of related incidents, "
        . "identify the single most likely underlying ROOT CAUSE and a practical interim WORKAROUND. "
        . "Be concrete and concise; do not invent specifics not supported by the incidents. "
        . "Respond ONLY as JSON: {\"root_cause\": \"...\", \"workaround\": \"...\"}.";

    $resp = aiProviderChat($cfg, ['system' => $system, 'user' => $context, 'max_tokens' => 700]);
    $text = trim((string) ($resp['content'] ?? ''));
    $rootCause = ''; $workaround = '';
    try {
        $j = parseClaudeJson($text);
        $rootCause = trim((string) ($j['root_cause'] ?? ''));
        $workaround = trim((string) ($j['workaround'] ?? ''));
    } catch (Exception $e) { /* fall back to raw text below */ }

    $draft = ($rootCause !== '' || $workaround !== '')
        ? "Root cause:\n$rootCause\n\nWorkaround:\n$workaround"
        : $text;

    echo json_encode(['success' => true, 'draft' => $draft, 'root_cause' => $rootCause, 'workaround' => $workaround]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
