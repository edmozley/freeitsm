<?php
/**
 * API: scan recent open incidents in the active company and propose candidate
 * problems (groups of incidents that likely share a root cause). Suggestion only —
 * the analyst confirms before anything is created. Uses the problem_ai key.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ai_settings.php';
require_once '../../api/cmdb/_ai_helpers.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('problems');

try {
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    $cfg = aiSettingsLoad($conn, 'problem_ai');
    if (($cfg['api_key'] ?? '') === '') throw new Exception('Problem AI is not configured. Set a provider + key in Problem Management → Settings.');

    [$tf, $tp] = ticketTenantFilter($conn, $analystId, 't');
    $sql = "SELECT t.ticket_number, t.subject
            FROM tickets t
            WHERE t.deleted_datetime IS NULL AND t.closed_datetime IS NULL" . $tf . "
            ORDER BY t.created_datetime DESC LIMIT 50";
    $stmt = $conn->prepare($sql);
    $stmt->execute($tp);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) < 3) throw new Exception('Not enough open incidents to look for patterns yet.');

    $list = implode("\n", array_map(fn($r) => "[{$r['ticket_number']}] {$r['subject']}", $rows));
    $system = "You are an ITIL problem manager looking for recurring incidents that likely share a "
        . "common root cause. From the incident list, propose candidate PROBLEMS — but only where there "
        . "is a genuine common theme (2+ incidents). It's fine to return an empty list. For each, give a "
        . "short problem title, the ticket numbers, and a one-line rationale. Respond ONLY as JSON: "
        . "{\"suggestions\":[{\"title\":\"...\",\"ticket_numbers\":[\"...\"],\"rationale\":\"...\"}]}.";

    $resp = aiProviderChat($cfg, ['system' => $system, 'user' => "Open incidents:\n" . $list, 'max_tokens' => 900]);
    $text = trim((string) ($resp['content'] ?? ''));
    $suggestions = [];
    try {
        $j = parseClaudeJson($text);
        $suggestions = is_array($j['suggestions'] ?? null) ? $j['suggestions'] : [];
    } catch (Exception $e) { /* none parseable */ }

    echo json_encode(['success' => true, 'suggestions' => $suggestions, 'scanned' => count($rows)]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
