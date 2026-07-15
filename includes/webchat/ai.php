<?php
/**
 * Web chat AI answers — draft a reply from the Knowledge base for a visitor's message.
 *
 * Reuses the shared KB retrieval (includes/knowledge/kb_ai.php) + the shared AI client
 * (aiProviderChat) with the SAME provider/key the Knowledge module's AI chat uses
 * (namespace 'knowledge_ai'). The system prompt is webchat-specific: warm, short, and
 * strictly grounded in the retrieved articles — it must NOT invent answers, and it points
 * the visitor at a human when unsure (the widget renders the actual escalation buttons).
 */

require_once __DIR__ . '/../ai_settings.php';
require_once __DIR__ . '/../ai_provider.php';
require_once __DIR__ . '/../knowledge/kb_ai.php';

/** Is a Knowledge AI provider/key configured? (Cheap check before spending the budget.) */
function webchatAiConfigured(PDO $conn): bool
{
    try {
        $cfg = aiSettingsLoad($conn, 'knowledge_ai');
        return ($cfg['api_key'] ?? '') !== '';
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Draft an AI answer to $question, given the recent $history (array of ['sender','body']).
 * Returns ['ok'=>bool, 'answer'=>string, 'articles'=>[{id,title}], 'error'=>?string].
 */
function webchatAiReply(PDO $conn, string $question, array $history = []): array
{
    $cfg = aiSettingsLoad($conn, 'knowledge_ai');
    if (($cfg['api_key'] ?? '') === '') {
        return ['ok' => false, 'answer' => '', 'articles' => [], 'error' => 'AI not configured'];
    }

    $ret     = kbRetrieveArticles($conn, $question, 5);
    $context = kbBuildContext($ret['articles']);

    $system = "You are a friendly website support assistant. Answer the visitor's question using ONLY the "
        . "knowledge base articles below. If the answer is not clearly in them, say you're not certain and suggest "
        . "they speak to a person or raise a request — never invent details or use outside knowledge. Keep replies "
        . "short, warm and practical (2 to 4 sentences). Do not mention the words 'article' or 'knowledge base', "
        . "and do not add a sign-off.\n\nKNOWLEDGE BASE ARTICLES:\n" . $context;

    $userMsg = $question;
    if (!empty($history)) {
        $lines = '';
        foreach (array_slice($history, -6) as $m) {
            $who = ($m['sender'] ?? '') === 'visitor' ? 'Visitor' : 'Assistant';
            $lines .= $who . ': ' . trim((string) ($m['body'] ?? '')) . "\n";
        }
        $userMsg = "Conversation so far:\n" . $lines . "\nVisitor's latest message: " . $question;
    }

    try {
        $r = aiProviderChat($cfg, ['system' => $system, 'user' => $userMsg, 'max_tokens' => 600]);
        $answer = trim((string) ($r['content'] ?? ''));
    } catch (Exception $e) {
        return ['ok' => false, 'answer' => '', 'articles' => [], 'error' => $e->getMessage()];
    }

    if ($answer === '') {
        $answer = "I'm not certain about that one — would you like to speak to a person?";
    }
    $articles = array_map(function ($a) {
        return ['id' => (int) $a['id'], 'title' => $a['title']];
    }, $ret['articles']);

    return ['ok' => true, 'answer' => $answer, 'articles' => $articles, 'error' => null];
}
