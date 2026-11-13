<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. Needs no database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Transforms in placeholders - {First name|initial|upper} (GH #317): every
 * transform, chaining left to right, the examples from the issue (ORG-JSMITH,
 * a username, an email address), accents, and that a typo is left as typed.
 * Also through StartersService::fillAnswers(), where step text is filled.
 *
 * Run: php tests/text-transforms.php
 */

require_once __DIR__ . '/../includes/text_transforms.php';

$pass = 0; $fail = 0;
function eq(string $what, $got, $want): void {
    global $pass, $fail;
    if ($got === $want) { $pass++; echo "  PASS  {$what}\n"; }
    else { $fail++; echo "  FAIL  {$what}  <- got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n"; }
}

echo "\nEach transform\n";
eq('initial', textTransform('John Paul', ['initial']), 'J');
eq('initial ignores leading spaces', textTransform('  john', ['initial']), 'j');
eq('first', textTransform('John Paul', ['first']), 'John');
eq('last', textTransform('Mary Ann Smith', ['last']), 'Smith');
eq('upper', textTransform('John Paul', ['upper']), 'JOHN PAUL');
eq('lower', textTransform('John Paul', ['lower']), 'john paul');
eq('nospace', textTransform('Van der Berg', ['nospace']), 'VanderBerg');
eq('slug', textTransform('John Paul', ['slug']), 'john.paul');
eq('slug folds accents and punctuation', textTransform("José O'Brien-Núñez", ['slug']), 'jose.o.brien.nunez');
eq('ascii', textTransform('José Núñez', ['ascii']), 'Jose Nunez');
eq('ascii folds letters that are not accents (Ł)', textTransform('Łukasz', ['ascii']), 'Lukasz');
eq('a multi-byte initial', textTransform('Émile', ['initial']), 'É');
eq('names are case- and space-insensitive', textTransform('john', [' Upper ']), 'JOHN');
eq('an unknown transform answers null', textTransform('john', ['upper', 'uper']), null);
eq('no transforms leaves the value', textTransform('John', []), 'John');

echo "\nPlaceholders\n";
$map = ['first name' => 'John Paul', 'last name' => 'Smith', 'department' => 'Dispute Resolution'];
eq('the PC name from the issue: ORG-JSMITH', textFillPlaceholders('ORG-{First name|initial|upper}{Last name|upper|nospace}', $map), 'ORG-JSMITH');
eq('an email address', textFillPlaceholders('{First name|first|lower}.{Last name|lower|nospace}@example.org', $map), 'john.smith@example.org');
eq('a username', textFillPlaceholders('{First name|initial|lower}{Last name|lower}', $map), 'jsmith');
eq('plain placeholders still work', textFillPlaceholders('For {First name} {Last name}, {Department}', $map), 'For John Paul Smith, Dispute Resolution');
eq('the name is matched case-insensitively', textFillPlaceholders('{FIRST NAME|first}', $map), 'John');
eq('a typo in a transform is left exactly as typed', textFillPlaceholders('ORG-{Last name|uper}', $map), 'ORG-{Last name|uper}');
eq('an unknown question is left as typed', textFillPlaceholders('{Middle name|upper}', $map), '{Middle name|upper}');
eq('an empty answer gives empty text', textFillPlaceholders('[{Department|initial}]', ['department' => '']), '[]');
eq('text with no braces is untouched', textFillPlaceholders('No placeholders here', $map), 'No placeholders here');

echo "\nThrough Starters & leavers step text\n";
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/starters/service.php';
$answers = [['label' => 'First name', 'value' => 'José'], ['label' => 'Last name', 'value' => 'Núñez García']];
$case = ['person_name' => 'José Núñez García', 'person_email' => 'jose@example.org', 'job_title' => 'Analyst', 'effective_date' => '2026-12-01'];
eq('fillAnswers works out a username with accents folded', StartersService::fillAnswers('{First name|ascii|lower}.{Last name|first|ascii|lower}', $answers, $case), 'jose.nunez');
eq('fillAnswers keeps {name} and {date}', StartersService::fillAnswers('{Name|upper} on {Date}', $answers, $case), 'JOSÉ NÚÑEZ GARCÍA on 1 Dec 2026');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
