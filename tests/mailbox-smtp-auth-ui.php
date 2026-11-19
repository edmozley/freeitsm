<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// php tests/mailbox-smtp-auth-ui.php: real SMTP controls and visibility handler, no database or mail.
require_once __DIR__ . '/support/ticket-workspace-browser.php';
$source = file_get_contents(__DIR__ . '/../tickets/settings/index.php');
$start = strpos($source, '                    <div class="form-group provider-imap" style="grid-column: span 2;">', strpos($source, 'id="mailboxSmtpEncryption"'));
$end = strpos($source, '<!-- Save a copy', $start);
if ($start === false || $end === false) throw new RuntimeException('SMTP controls not found.');
$markup = substr($source, $start, $end - $start);
$strings = require __DIR__ . '/../lang/en/tickets.php';
function t(string $key): string {
    global $strings;
    $value = $strings;
    foreach (array_slice(explode('.', $key), 1) as $part) $value = $value[$part];
    return $value;
}
ob_start(); eval('?>' . $markup); $markup = ob_get_clean();
preg_match('/        function toggleSmtpCredentials\(\) \{.*?\n        \}/s', $source, $handler);
preg_match('/        #mailboxSmtpAuthEnabled:focus-visible.*?\n        \}/s', $source, $focus);
if (!$handler || !$focus) throw new RuntimeException('SMTP handler or focus style not found.');
$html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<link rel="stylesheet" href="theme.css"><link rel="stylesheet" href="inbox.css"><style>' . $focus[0]
    . 'body{padding:24px}main{max-width:600px;margin:auto;padding:20px;background:var(--bg-secondary)}.form-group{margin-bottom:16px}</style>'
    . '<main><h2>SMTP authentication</h2><select id="mailboxProvider"><option>imap</option><option>microsoft</option><option>google</option></select>'
    . $markup . '</main><script>' . $handler[0] . '</script></html>';
exit(ticketWorkspaceBrowser(['index.html' => $html,
    'theme.css' => file_get_contents(__DIR__ . '/../assets/css/theme.css'),
    'inbox.css' => file_get_contents(__DIR__ . '/../assets/css/inbox.css')], ['verify' => function (string $ws): array {
    $checks = [];
    foreach (['light', 'dark'] as $theme) {
        cdpEvaluate($ws, "document.documentElement.setAttribute('data-theme', '$theme'); 'ok'", 10);
        $results = cdpEvaluate($ws, <<<'JS'
        (() => {
            const checks = [], check = (label, pass) => checks.push({label, pass});
            const auth = document.getElementById('mailboxSmtpAuthEnabled'), same = document.getElementById('mailboxSmtpSameAsImap');
            const visible = selector => !!document.querySelector(selector).getClientRects().length;
            document.getElementById('mailboxProvider').value = 'imap'; auth.checked = true; same.checked = true; toggleSmtpCredentials();
            check('authentication and IMAP login are the defaults', visible('.smtp-auth-creds') && !visible('.smtp-creds'));
            document.getElementById('mailboxSmtpAuthLabel').click();
            check('caption does not toggle authentication', auth.checked);
            same.click();
            check('separate login fields appear when selected', visible('.smtp-creds'));
            document.getElementById('mailboxSmtpUsername').value = 'sender@example.invalid';
            auth.closest('label').click();
            check('switch disables authentication and hides both login controls', !auth.checked && !visible('.smtp-auth-creds') && !visible('.smtp-creds'));
            auth.closest('label').click();
            check('re-enabling retains the separate login', auth.checked && visible('.smtp-creds') && document.getElementById('mailboxSmtpUsername').value === 'sender@example.invalid');
            for (const provider of ['microsoft', 'google']) {
                document.getElementById('mailboxProvider').value = provider; toggleSmtpCredentials();
                check(provider + ' hides credentials', !visible('.smtp-auth-creds') && !visible('.smtp-creds'));
            }
            document.getElementById('mailboxProvider').value = 'imap'; toggleSmtpCredentials(); auth.focus();
            return JSON.stringify(checks);
        })()
JS, 15);
        foreach (json_decode($results, true) as $check) { $check['label'] = $theme . ': ' . $check['label']; $checks[] = $check; }
        cdpEvaluate($ws, '', 10, ['method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'keyDown', 'key' => ' ', 'code' => 'Space', 'windowsVirtualKeyCode' => 32]]);
        cdpEvaluate($ws, '', 10, ['method' => 'Input.dispatchKeyEvent', 'params' => ['type' => 'keyUp', 'key' => ' ', 'code' => 'Space', 'windowsVirtualKeyCode' => 32]]);
        $checks[] = ['label' => $theme . ': keyboard Space toggles authentication', 'pass' => cdpEvaluate($ws, "String(!document.getElementById('mailboxSmtpAuthEnabled').checked)", 10) === 'true'];
        $checks[] = ['label' => $theme . ': keyboard focus has a visible outline', 'pass' => cdpEvaluate($ws, "String(getComputedStyle(document.getElementById('mailboxSmtpAuthEnabled').nextElementSibling).outlineStyle === 'solid')", 10) === 'true'];
        $ax = json_decode(cdpEvaluate($ws, '', 10, ['method' => 'Accessibility.getFullAXTree', 'params' => []]), true);
        $named = array_filter($ax['nodes'], fn($n) => ($n['role']['value'] ?? '') === 'checkbox' && ($n['name']['value'] ?? '') === 'Authenticate with the SMTP server');
        $checks[] = ['label' => $theme . ': switch has its accessible name', 'pass' => count($named) === 1];
        if (getenv('FREEITSM_TEST_SCREENSHOTS')) {
            $shot = json_decode(cdpEvaluate($ws, '', 10, ['method' => 'Page.captureScreenshot', 'params' => ['format' => 'png']]), true);
            file_put_contents(getenv('FREEITSM_TEST_SCREENSHOTS') . '/smtp-auth-' . $theme . '.png', base64_decode($shot['data']));
        }
    }
    return $checks;
}]));
