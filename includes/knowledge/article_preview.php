<?php
/**
 * One way to turn a Knowledge article body into a short plain-text preview,
 * used by every place that shows one: the analyst article list, the portal
 * (Help Centre, home page, New ticket suggestions), the record preview panel
 * and the REST API (GH PR #255).
 *
 * TRAP: the result is PLAIN TEXT with entities DECODED ("&auml;" -> "ä",
 * "&lt;b&gt;" -> "<b>"), so every caller must escape it when it draws it.
 * Each caller does today: knowledge.js escapeHtml(), the portal pages
 * htmlspecialchars(), record-preview.js esc(), and the REST API is JSON.
 *
 * It lived in portal_reader.php as portalArticlePreview(), and the other three
 * places each had their own strip_tags() - which left entities as literal
 * "&auml;" (PR #255, Dr4inder) and glued words together across tags. One
 * function now, so those fixes reach everywhere at once.
 */

/**
 * Turn an article body into a short plain-text preview.
 *
 * strip_tags() removes TAGS but keeps their CONTENTS, so a <script> or <style>
 * block spills its source into the preview as readable text ("…sign in.
 * alert(1)"). Those elements are dropped whole first. Not a security control —
 * the preview is escaped on render — just correctness.
 *
 * strip_tags() also leaves NO separator where a tag was, so "<h3>Password
 * Reset</h3><p>You can…" comes out as "Password ResetYou can…". A space is
 * pushed in front of every tag first, then the run is collapsed — which keeps
 * strip_tags() (and its tolerance of malformed markup) rather than swapping in
 * a hand-rolled tag regex.
 *
 * A caller that cuts the body in SQL (LEFT(body, 400)) cuts in HTML characters,
 * so the plain text left over is usually far SHORTER than the limit and the
 * length check below never fires: previews just stopped mid-word ("Close ot")
 * with nothing to say there was more. $wasCut is the caller telling us the body
 * really was longer; the half-word (and any half-entity) at the end is then
 * dropped and an ellipsis added.
 *
 * @param ?string $body  the article body HTML; null gives ''
 * @param int $length  the most characters kept before an ellipsis is added
 * @param bool $wasCut  true when $body is the start of a longer article (CHAR_LENGTH(body) > the LEFT() taken)
 */
function knowledgeArticlePreview(?string $body, int $length = 400, bool $wasCut = false): string {
    $raw = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string) $body);
    $raw = str_replace('<', ' <', (string) $raw);
    // strip_tags() leaves ENTITIES alone, and every caller escapes the preview
    // as it draws it - so "Settings &gt; Mail" reached the page as the literal
    // text "&gt;". Decode to plain text here; the escaping on output is what
    // keeps it safe. A cut body can end inside an entity ("&am"); drop that
    // stub before decoding.
    if ($wasCut) $raw = preg_replace('/&#?\w*$/', '', (string) $raw);
    $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text));

    if (function_exists('mb_substr') && mb_strlen($text) > $length) {
        $text   = mb_substr($text, 0, $length);
        $wasCut = true;
    }
    if ($wasCut && $text !== '') {
        // End on a whole word: the last one is almost certainly cut in half.
        // Kept as-is when there is only one word, rather than emptying it.
        $trimmed = preg_replace('/\s+\S*$/u', '', $text);
        $text = rtrim($trimmed !== '' ? $trimmed : $text, " \t,;:.-–—") . '…';
    }
    return $text;
}
