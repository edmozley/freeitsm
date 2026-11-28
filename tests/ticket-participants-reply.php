<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Ticket participants (GitHub #432): Reply pre-fills them in Cc, Forward does not.
 *
 *   php tests/ticket-participants-reply.php        (CHROME_BIN if Chrome is not found)
 *
 * The shipped assets/js/ticket-participants.js, with the shipped openReplyModal(),
 * openForwardModal() and the reply-address functions from assets/js/inbox.js, on a bare
 * page in headless Chrome. The Reply and Forward dialogs' To and Cc are read back after
 * each one opens. No database.
 */
$root = dirname(__DIR__);

$php = [];
$page = (string) file_get_contents($root . '/tickets/index.php');
$php[] = ['label' => 'the ticket page loads ticket-participants.js', 'pass' => preg_match('~assets/js/ticket-participants\.js\?v=\d+~', $page) === 1];
foreach ($php as $c) echo ($c['pass'] ? '  PASS  ' : '  FAIL  ') . $c['label'] . "\n";
$phpFailed = count(array_filter($php, fn($c) => !$c['pass']));

require __DIR__ . '/support/ticket-workspace-browser.php';
$src = str_replace("\r\n", "\n", (string) file_get_contents($root . '/assets/js/inbox.js'));
/** A top-level function from inbox.js, whole: from its declaration to the closing brace at the start of a line. */
function participantsReplyFunction(string $src, string $name): string {
    $a = strpos($src, "\nfunction $name(");
    $b = $a === false ? false : strpos($src, "\n}", $a + 1);
    if ($a === false || $b === false) throw new RuntimeException("Not found in inbox.js: function $name");
    return substr($src, $a, $b + 2 - $a) . "\n";
}
$code = '';
foreach (['correspondentAddress', 'replyAddress', 'outboundToStaffOnly', 'composeSubject', 'openReplyModal', 'openForwardModal'] as $fn) {
    $code .= participantsReplyFunction($src, $fn);
}

$html = '<!doctype html><html><body>'
    . '<input id="emailTo"><input id="emailCc"><input id="emailSubject"><div id="emailModal"></div><pre id="result">RUNNING</pre>'
    . '<script>'
    // inbox.js's globals and the helpers these functions call, as quiet stand-ins.
    . 'let composeMode = "new", analystEmails = new Set(["sam@desk.example"]), currentEmail = null, currentTicketChannel = "email", emailEditor = null;'
    . 'const applyDefaultSignature = () => {}, setReplyCleanupVisibility = () => {}, syncReplyFromFiles = () => {},'
    . ' setPresenceComposing = () => {}, renderComposerCollisionWarning = () => {}, showToast = () => {};'
    . $code . '</script><script src="ticket-participants.js"></script>' . <<<'HTML'
<script>
const checks = [], check = (label, pass, detail) => checks.push({label, pass: !!pass, detail});
const to = () => document.getElementById('emailTo').value, cc = () => document.getElementById('emailCc').value;
try {
    currentEmail = {ticket_id: 1, ticket_number: 'TKT-1', subject: 'Printer', direction: 'Inbound',
                    from_address: 'req@customer.example', requester_email: 'req@customer.example', sensitivity: 'normal'};
    window.currentTicketRequesterAddresses = ['req@customer.example', 'req.alt@customer.example'];

    window.currentTicketParticipants = [];
    openReplyModal(null);
    check('no participants: Reply is unchanged (To the requester, Cc empty)', to() === 'req@customer.example' && cc() === '', [to(), cc()]);

    window.currentTicketParticipants = [
        {user_id: 11, email: 'a@customer.example', addresses: ['a@customer.example', 'a.alt@customer.example'], is_active: true},
        {user_id: 12, email: 'B@Customer.example', addresses: ['b@customer.example'], is_active: true},
        {user_id: 13, email: 'left@customer.example', addresses: ['left@customer.example'], is_active: false},
        {user_id: 14, email: null, addresses: [], is_active: true},
    ];
    openReplyModal(null);
    check('Reply pre-fills the active participants in Cc, once each (not one who has left, nor one with no address)',
        to() === 'req@customer.example' && cc() === 'a@customer.example; b@customer.example', [to(), cc()]);

    openForwardModal(null);
    check('Forward does not: To and Cc start empty', to() === '' && cc() === '', [to(), cc()]);
    openForwardModal({direction: 'Inbound', from_address: 'a@customer.example', subject: 'Printer'});
    check('...nor when forwarding a participant\'s own message', to() === '' && cc() === '', [to(), cc()]);

    openReplyModal({direction: 'Inbound', from_address: 'a.alt@customer.example', subject: 'RE: Printer'});
    check('Reply to a participant\'s message: To them, the requester and the others in Cc, and not them again under their main address',
        to() === 'a.alt@customer.example' && cc() === 'req@customer.example; b@customer.example', [to(), cc()]);

    openReplyModal({direction: 'Inbound', from_address: 'req.alt@customer.example', subject: 'RE: Printer'});
    check('Reply to the requester writing from a linked address: the requester is not added to Cc again',
        to() === 'req.alt@customer.example' && cc() === 'a@customer.example; b@customer.example', [to(), cc()]);

    openReplyModal(null);
    document.getElementById('emailCc').value = 'b@customer.example';   // the analyst takes A off this one message
    openReplyModal(null);
    check('taking someone off Cc is for that message only: the next Reply pre-fills them again',
        cc() === 'a@customer.example; b@customer.example' && window.currentTicketParticipants.length === 4, cc());

    currentEmail = Object.assign({}, currentEmail, {sensitivity: 'confidential'});
    openReplyModal(null);
    check('a ticket that has just become confidential pre-fills nobody, even before the list reloads', to() === 'req@customer.example' && cc() === '', [to(), cc()]);
} catch (e) {
    check('the Reply and Forward code runs: ' + e.message, false);
}
document.getElementById('result').textContent = JSON.stringify(checks);
</script></body></html>
HTML;
$browser = ticketWorkspaceBrowser([
    'index.html' => $html,
    'ticket-participants.js' => (string) file_get_contents($root . '/assets/js/ticket-participants.js'),
]);
exit(($phpFailed || $browser) ? 1 : 0);
