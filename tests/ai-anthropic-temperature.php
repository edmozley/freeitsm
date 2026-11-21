<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Which Anthropic models are sent `temperature` (includes/ai_provider.php,
 * aiAnthropicAcceptsTemperature). The newer models refuse it with a 400, which
 * failed every AI feature; the older ones accept it.
 *
 *   php tests/ai-anthropic-temperature.php
 *
 * No network, no database.
 */
require_once __DIR__ . '/../includes/ai_provider.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; } else { $fail++; echo "  FAIL  {$what}\n"; }
}
echo "\nAnthropic temperature\n" . str_repeat('=', 70) . "\n";

$accepts = ['claude-opus-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5', 'claude-sonnet-4-5-20250929',
            'claude-opus-4-1', 'claude-opus-4-20250514', 'claude-sonnet-4-0', 'claude-3-5-haiku-20241022',
            'claude-3-opus-20240229', 'claude-2.1', 'claude-instant-1.2', 'claude-opus-4'];
$refuses = ['claude-haiku-5-5', 'claude-sonnet-5-5', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5',
            'claude-opus-4-7', 'claude-opus-4-8', 'claude-fable-5-2', 'claude-fable-5', 'claude-mythos-5-2',
            'claude-haiku-6', 'claude-something-new'];
foreach ($accepts as $m) ok("$m is sent temperature", aiAnthropicAcceptsTemperature($m));
foreach ($refuses as $m) ok("$m is not", !aiAnthropicAcceptsTemperature($m));

echo str_repeat('-', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
