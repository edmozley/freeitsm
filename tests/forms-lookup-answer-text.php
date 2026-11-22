<?php
/* NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * GH #420: a lookup answer is stored as {"id":2,"label":"User Test"}, and both
 * flatteners that hand answers to "what happens next" and workflows imploded the
 * pair into "2, User Test" - a value no rule could use. They must say the label.
 *
 * No database: both functions are pure for a lookup answer.
 *   php tests/forms-lookup-answer-text.php
 */
chdir(dirname(__DIR__));
require_once 'includes/services/forms.php';
require_once 'includes/catalogue_approvals.php';

$fails = 0;
$check = function (string $what, $got, $want) use (&$fails) {
    $ok = $got === $want;
    if (!$ok) $fails++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $what . ($ok ? '' : "  (got " . var_export($got, true) . ", want " . var_export($want, true) . ")") . "\n";
};

$raw = '{"id":2,"label":"User Test"}';
$check('FormsService::lookupAnswerText says the label', FormsService::lookupAnswerText($raw), 'User Test');
$check('catalogueAnswerText says the label for a lookup', catalogueAnswerText($raw, 'lookup'), 'User Test');
$check('a label with a comma survives whole', FormsService::lookupAnswerText('{"id":7,"label":"Smith, Jane"}'), 'Smith, Jane');
$check('an empty answer stays empty', FormsService::lookupAnswerText(''), '');
$check('a non-JSON answer passes through', catalogueAnswerText('plain text', 'lookup'), 'plain text');
// Unchanged neighbours: checkboxes still join, a checkbox still says Yes.
$check('checkboxes still join with a comma', catalogueAnswerText('["Red","Blue"]', 'checkboxes'), 'Red, Blue');
$check('a ticked checkbox still says Yes', catalogueAnswerText('1', 'checkbox'), 'Yes');

echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
