<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The ticket AI writes in the analyst's language (GH #396): the summary, "Read this
 * ticket for me" and the merge briefing. No database, no AI call - it reads the prompts.
 *
 *   php tests/ticket-ai-language.php
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

require_once 'includes/i18n.php';
require_once 'includes/rfp_ai.php';      // the merge endpoint loads both; they must not clash
require_once 'includes/ticket_ai.php';
require_once 'includes/help_ask.php';

echo "English stays exactly as it was\n";
check('no language rule for English', ticketAiLanguageRule('en') === '');
check('the summary prompt for English has no language line', !str_contains(ticketAiSummarySystemPrompt('en'), 'Write everything'));
check('an unknown locale is treated as English', ticketAiLanguageRule('xx') === '');

echo "Another language\n";
$el = ticketAiSummarySystemPrompt('el');
check('the summary asks for Greek', str_contains($el, 'Write everything in Greek'));
check('and for the headings to be translated', str_contains($el, 'including the headings'));
check('names and reference numbers stay as written', str_contains($el, 'reference numbers and quoted error messages stay exactly as written'));
check('"Read this ticket" asks for German', str_contains(ticketAiReadSystemPrompt('de'), 'Write everything in German'));
check('the language rule is the last rule, after the others', str_ends_with(ticketAiReadSystemPrompt('fr'), 'stay exactly as written.'));
check('the merge endpoint appends the same rule', str_contains((string)file_get_contents('api/tickets/ai_merge_summary.php'), '. ticketAiLanguageRule();'));

echo "Whose language\n";
I18n::setLocale('pl');
check('with no locale given, the current request\'s locale is used', str_contains(ticketAiSummarySystemPrompt(), 'Write everything in Polish'));
foreach (['api/tickets/ai_summary.php', 'api/tickets/ai_read.php', 'api/tickets/ai_merge_summary.php'] as $f) {
    check("$f sets the analyst's locale first", str_contains((string)file_get_contents($f), 'I18n::initFromSession();'));
}

echo "One map\n";
check('the help assistant still names languages', helpAskLanguageName('uk') === 'Ukrainian' && helpAskLanguageName('zz') === 'English');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
