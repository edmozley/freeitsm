<?php
/**
 * LMS API: the three AI authoring helpers.
 *
 *   mode=outline  — a course outline (lesson titles + a line on each) from a topic
 *   mode=lesson   — a lesson body, either from a knowledge article or from a title
 *   mode=quiz     — questions with an answer key, from a lesson's own text
 *
 * They share one endpoint because they share everything except the prompt: the
 * same config, the same JSON contract, the same error handling. Each returns a
 * DRAFT — nothing is written to the database here. The author reviews it in the
 * editor and presses Save, which goes through the normal validated endpoints, so
 * the AI cannot create a question with no correct answer or a course nobody
 * looked at.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/ai_settings.php';
require_once '../cmdb/_ai_helpers.php';   // parseClaudeJson()

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireCapabilityJson(Cap::LMS_MANAGE);

/** HTML (a knowledge article, a lesson body) down to the plain text the model should read. */
function lmsPlainText(?string $html, int $limit = 12000): string {
    $t = strip_tags((string)$html);
    $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
    $t = preg_replace('/\s+/', ' ', $t);
    return mb_substr(trim($t), 0, $limit);
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $mode  = $input['mode'] ?? '';
    $conn  = connectToDatabase();

    $cfg = aiSettingsLoad($conn, 'lms_ai');
    if (($cfg['api_key'] ?? '') === '') {
        throw new Exception('LMS AI is not configured. Set a provider and key in LMS → Settings.');
    }

    $house = "You are an experienced instructional designer writing internal IT training for a company's staff. "
           . "Write plainly and concretely, in British English. Prefer short paragraphs and specific examples over "
           . "generalities. Never invent product features, policies or facts that were not given to you.";

    // ---- Outline: a course's lesson list from a topic ----
    if ($mode === 'outline') {
        $topic = trim($input['topic'] ?? '');
        if ($topic === '') throw new Exception('Give the AI a topic to work from.');
        $count = min(12, max(2, (int)($input['lesson_count'] ?? 5)));

        $system = $house . " Respond ONLY as JSON: "
                . '{"title": "...", "description": "...", "lessons": [{"title": "...", "summary": "..."}]}. '
                . "Produce exactly {$count} lessons that build on each other in a sensible teaching order.";

        $resp  = aiProviderChat($cfg, ['system' => $system, 'user' => "Course topic: {$topic}", 'max_tokens' => 1500]);
        $draft = parseClaudeJson(trim((string)($resp['content'] ?? '')));
        if (!$draft || empty($draft['lessons'])) throw new Exception('The AI did not return a usable outline. Try rephrasing the topic.');

        echo json_encode(['success' => true, 'draft' => $draft]);
        exit;
    }

    // ---- Lesson: a body, ideally from a knowledge article ----
    if ($mode === 'lesson') {
        $title     = trim($input['title'] ?? '');
        $articleId = (int)($input['article_id'] ?? 0);
        $source    = '';

        if ($articleId) {
            $st = $conn->prepare("SELECT title, body FROM knowledge_articles
                                  WHERE id = ? AND is_published = 1 AND (is_archived = 0 OR is_archived IS NULL)");
            $st->execute([$articleId]);
            $article = $st->fetch(PDO::FETCH_ASSOC);
            if (!$article) throw new Exception('That knowledge article could not be found (it may be archived or unpublished).');

            if ($title === '') $title = $article['title'];
            $source = "Knowledge article — {$article['title']}\n\n" . lmsPlainText($article['body']);
            if (trim(lmsPlainText($article['body'])) === '') throw new Exception('That knowledge article has no text to teach from.');
        }

        if ($title === '' && $source === '') throw new Exception('Give the AI a lesson title or a knowledge article to work from.');

        // The two jobs are genuinely different: rewriting a known-good article into
        // teaching material is grounded and safe; writing from a bare title is not,
        // so it gets told explicitly to stay general rather than invent specifics.
        if ($source !== '') {
            $system = $house . " Rewrite the source material below as a single self-contained lesson that TEACHES it — "
                    . "lead with why it matters, then the steps or concepts, then what good looks like. Keep every fact "
                    . "from the source; add none of your own. Respond ONLY as JSON: {\"title\": \"...\", \"body\": \"<p>…HTML…</p>\"}. "
                    . "The body must be simple HTML: <p>, <h3>, <ul>/<li>, <ol>/<li>, <strong>, <em>, <code>. No <script>, no styles, no images.";
            $user = $source;
        } else {
            $system = $house . " Write a single self-contained lesson on the given title. Because you have no source material, "
                    . "stay at the level of general good practice and do NOT invent company-specific policies, names, systems or "
                    . "figures. Respond ONLY as JSON: {\"title\": \"...\", \"body\": \"<p>…HTML…</p>\"}. "
                    . "The body must be simple HTML: <p>, <h3>, <ul>/<li>, <ol>/<li>, <strong>, <em>, <code>. No <script>, no styles, no images.";
            $user = "Lesson title: {$title}";
        }

        $resp  = aiProviderChat($cfg, ['system' => $system, 'user' => $user, 'max_tokens' => 2500]);
        $draft = parseClaudeJson(trim((string)($resp['content'] ?? '')));
        if (!$draft || empty($draft['body'])) throw new Exception('The AI did not return a usable lesson.');

        echo json_encode(['success' => true, 'draft' => [
            'title' => $draft['title'] ?? $title,
            'body'  => $draft['body'],
        ]]);
        exit;
    }

    // ---- Quiz: questions from a lesson the author has already written ----
    if ($mode === 'quiz') {
        $lessonId = (int)($input['lesson_id'] ?? 0);
        if (!$lessonId) throw new Exception('Missing lesson_id');
        $count = min(10, max(1, (int)($input['question_count'] ?? 3)));

        $st = $conn->prepare("SELECT title, body FROM lms_lessons WHERE id = ?");
        $st->execute([$lessonId]);
        $lesson = $st->fetch(PDO::FETCH_ASSOC);
        if (!$lesson) throw new Exception('Lesson not found');

        $text = lmsPlainText($lesson['body']);
        if ($text === '') throw new Exception('Write the lesson first — there is nothing here to ask questions about.');

        // Grounding it in the lesson text is the whole trick: questions the lesson
        // does not answer are worse than no questions at all.
        $system = $house . " Write {$count} multiple-choice questions that test whether someone has UNDERSTOOD the lesson below — "
                . "not whether they can remember a phrase from it. Every question must be answerable from the lesson text alone. "
                . "Each needs 3 or 4 plausible options, where the wrong ones are believable mistakes rather than obvious filler. "
                . 'Respond ONLY as JSON: {"questions": [{"question_text": "...", "question_type": "single", '
                . '"explanation": "why the right answer is right", "answers": [{"answer_text": "...", "is_correct": true}]}]}. '
                . "question_type is \"single\" (exactly one correct), \"multiple\" (two or more correct) or \"truefalse\". "
                . "Every question MUST have at least one correct answer.";

        $resp  = aiProviderChat($cfg, ['system' => $system, 'user' => "Lesson: {$lesson['title']}\n\n{$text}", 'max_tokens' => 2500]);
        $draft = parseClaudeJson(trim((string)($resp['content'] ?? '')));
        if (!$draft || empty($draft['questions'])) throw new Exception('The AI did not return any usable questions.');

        // Drop anything malformed rather than handing the editor a question that the
        // save endpoint would reject anyway (no key, or a "single" with two answers).
        $clean = [];
        foreach ($draft['questions'] as $q) {
            $answers = array_values(array_filter((array)($q['answers'] ?? []), fn($a) => trim($a['answer_text'] ?? '') !== ''));
            $correct = count(array_filter($answers, fn($a) => !empty($a['is_correct'])));
            $type    = in_array($q['question_type'] ?? '', ['single', 'multiple', 'truefalse'], true) ? $q['question_type'] : 'single';

            if (count($answers) < 2 || $correct === 0) continue;
            if ($type !== 'multiple' && $correct > 1) $type = 'multiple';   // trust the key, fix the label

            $clean[] = [
                'question_text' => trim($q['question_text'] ?? ''),
                'question_type' => $type,
                'explanation'   => trim($q['explanation'] ?? ''),
                'answers'       => $answers,
            ];
        }
        if (!$clean) throw new Exception('The AI\'s questions came back malformed. Try again.');

        echo json_encode(['success' => true, 'draft' => ['questions' => $clean]]);
        exit;
    }

    throw new Exception('Unknown mode');
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
