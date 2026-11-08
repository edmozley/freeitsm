<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Tickets -> Settings -> General -> "Reply goes to" (GH #120).
 *
 * PHP: the setting belongs to Tickets' General tab, only its two values exist,
 * and the save endpoint validates it like the other enum settings.
 * Browser: the shipped replyAddress() and correspondentAddress() from
 * assets/js/inbox.js, on a bare page in headless Chrome, for the cases that
 * matter - a second person on the requester's thread, our own outbound
 * message, a requester with no address, the default. No database.
 */
$root = dirname(__DIR__);
require $root . '/includes/ticket_reply_to.php';
require_once $root . '/includes/settings_keys.php';

$php = [];
$owner = settingKeyOwner('ticket_reply_to');
$php[] = ['label' => 'the setting belongs to Tickets -> Settings -> General', 'pass' => ($owner['module'] ?? null) === 'tickets' && ($owner['tab'] ?? null) === 'general', 'detail' => $owner];
$php[] = ['label' => 'only "message" and "requester" exist', 'pass' => array_map(fn($c) => $c->value, TicketReplyTo::cases()) === ['message', 'requester']];
$php[] = ['label' => 'a value nobody chose is refused by the enum', 'pass' => TicketReplyTo::tryFrom('everyone') === null && TicketReplyTo::tryFrom('') === null];
$save = (string) file_get_contents($root . '/api/settings/save_system_settings.php');
$php[] = ['label' => 'the save endpoint validates it before writing', 'pass' => strpos($save, "'ticket_reply_to'       => ['../../includes/ticket_reply_to.php',  TicketReplyTo::class]") !== false];
$page = (string) file_get_contents($root . '/tickets/index.php');
$php[] = ['label' => 'the inbox is told the choice', 'pass' => strpos($page, 'window.TICKET_REPLY_TO') !== false && strpos($page, 'ticketReplyTo(connectToDatabase())') !== false];

foreach ($php as $c) echo ($c['pass'] ? '  PASS  ' : '  FAIL  ') . $c['label'] . ($c['pass'] ? '' : ' ' . json_encode($c['detail'] ?? null)) . "\n";
$phpFailed = count(array_filter($php, fn($c) => !$c['pass']));

require __DIR__ . '/support/ticket-workspace-browser.php';
$src = (string) file_get_contents($root . '/assets/js/inbox.js');
function replyToSource(string $src, string $start, string $end): string {
    $a = strpos($src, $start); $b = $a === false ? false : strpos($src, $end, $a);
    if ($a === false || $b === false) throw new RuntimeException('Not found in inbox.js: ' . $start);
    return substr($src, $a, $b - $a);
}
$code = replyToSource($src, 'function correspondentAddress(email) {', '/**')
      . replyToSource($src, 'function replyAddress(email, mode) {', '/** Is every address')
      . replyToSource($src, 'function outboundToStaffOnly(recipients) {', '/**');

$html = '<!doctype html><html><body><pre id="result">RUNNING</pre><script>'
    . 'const analystEmails = new Set(["sam@desk.example"]);' . $code . '</script>' . <<<'HTML'
<script>
const checks = [], check = (label, pass, detail) => checks.push({label, pass: !!pass, detail});
try {
    // Requester A raised it; B replied on the same thread; the analyst is reading B's message.
    const fromB = {direction: 'Inbound', from_address: 'b@customer.example', requester_email: 'a@customer.example'};
    check('Message (the default): Reply answers B, who wrote the message open', replyAddress(fromB, 'message') === 'b@customer.example', replyAddress(fromB, 'message'));
    check('Requester: Reply answers A, the ticket\'s requester', replyAddress(fromB, 'requester') === 'a@customer.example', replyAddress(fromB, 'requester'));
    check('a missing or unknown setting behaves as Message', replyAddress(fromB, undefined) === 'b@customer.example' && replyAddress(fromB, 'other') === 'b@customer.example');
    // Our own outbound message to the requester: both modes answer the requester, never the desk.
    const ours = {direction: 'Outbound', from_address: 'support@desk.example', to_recipients: 'a@customer.example', requester_email: 'a@customer.example'};
    check('on our own message both answer the requester, never the service desk',
        replyAddress(ours, 'message') === 'a@customer.example' && replyAddress(ours, 'requester') === 'a@customer.example');
    // A requester with no address: Requester falls back to the message's person rather than an empty box.
    const noMail = {direction: 'Inbound', from_address: 'b@customer.example', requester_email: ''};
    check('a requester with no address falls back to the person on the message', replyAddress(noMail, 'requester') === 'b@customer.example', replyAddress(noMail, 'requester'));
    // An outbound note to a colleague only (GH #244): both answer the requester.
    const toStaff = {direction: 'Outbound', from_address: 'support@desk.example', to_recipients: 'sam@desk.example', requester_email: 'a@customer.example'};
    check('a message sent only to an analyst still answers the requester (GH #244)', replyAddress(toStaff, 'message') === 'a@customer.example');
    check('no message at all gives an empty address', replyAddress(null, 'requester') === '');
} catch (e) {
    check('reply address code runs: ' + e.message, false);
}
document.getElementById('result').textContent = JSON.stringify(checks);
</script></body></html>
HTML;
$browser = ticketWorkspaceBrowser(['index.html' => $html]);
exit(($phpFailed || $browser) ? 1 : 0);
