<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * knowledgeArticlePreview() - the one plain-text preview of an article body
 * (PR #255) - and the four places that show one all using it. No database.
 *
 * On the old analyst list (strip_tags() alone) the entity checks fail: "&auml;"
 * came through as the literal text. The caller checks fail if any of the four
 * goes back to its own strip_tags().
 */
$root = dirname(__DIR__);
require $root . '/includes/knowledge/article_preview.php';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, $detail = null): void {
    global $pass, $fail;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === null ? '' : ' ' . json_encode($detail, JSON_UNESCAPED_UNICODE)) . "\n";
    $ok ? $pass++ : $fail++;
}

$p = knowledgeArticlePreview('<p>&Auml;nderung f&uuml;r &Ouml;l &amp; Gas, 5 &euro; &#8364; &#x20AC;</p>');
check('named, numeric and hex entities are decoded', $p === 'Änderung für Öl & Gas, 5 € € €', $p);

$p = knowledgeArticlePreview('<p>Type &lt;b&gt;bold&lt;/b&gt; here</p>');
check('escaped markup becomes plain text, to be escaped again on output', $p === 'Type <b>bold</b> here', $p);

$p = knowledgeArticlePreview('<h3>Password Reset</h3><p>You can</p>');
check('words either side of a tag stay apart', $p === 'Password Reset You can', $p);

$p = knowledgeArticlePreview('<p>Sign in.</p><script>alert(1)</script><style>p{color:red}</style><p>Done</p>');
check('script and style contents are dropped', $p === 'Sign in. Done', $p);

$p = knowledgeArticlePreview('<p>Settings for the mail &au', 300, true);
check('a body cut inside an entity drops the stub (and the last, possibly cut, word)', $p === 'Settings for the…', $p);

$p = knowledgeArticlePreview('<p>one two three four five six</p>', 12);
check('a long preview is cut on a whole word with an ellipsis', $p === 'one two…', $p);

$p = knowledgeArticlePreview('<p>Close the ot', 300, true);
check('a SQL-cut body loses its half-word and gains an ellipsis', $p === 'Close the…', $p);

check('null gives an empty string', knowledgeArticlePreview(null) === '');

// The four places that show a preview all go through the helper.
$callers = [
    'api/knowledge/knowledge_articles.php'  => 'knowledgeArticlePreview(',
    'includes/record_preview.php'           => 'knowledgeArticlePreview(',
    'api/v1/resources/knowledge.php'        => 'knowledgeArticlePreview(',
    'includes/knowledge/portal_reader.php'  => 'return knowledgeArticlePreview(',
];
foreach ($callers as $file => $needle) {
    $src = (string) file_get_contents($root . '/' . $file);
    check("$file uses the shared helper", strpos($src, $needle) !== false);
}
$list = (string) file_get_contents($root . '/api/knowledge/knowledge_articles.php');
check('the analyst list tells the helper when the body was cut', strpos($list, 'CHAR_LENGTH(a.body) > 300 AS preview_cut') !== false
    && strpos($list, "unset(\$article['preview_cut'])") !== false);

echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
