<?php
/**
 * Attachment size limits, and what happens to an inbound email that breaks them
 * (GH #170). The ONE place the numbers and the rules are read and applied.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHERE EACH SETTING LIVES (Ed: "split by job, with signposting")
 * ─────────────────────────────────────────────────────────────────────────────
 *   System -> Security -> Attachments      the LIMITS, for everything:
 *       attachment_max_mb         largest single attachment (default 10, as before)
 *       attachment_max_total_mb   largest total per inbound email (0 = no limit)
 *     The per-attachment limit applies to every attachment path that does not set
 *     its own: inbound email, the self-service portal, chat channels, analyst
 *     replies, attached documents and change attachments. The total applies to
 *     inbound email, where a message arrives as one thing.
 *
 *   Tickets -> Settings -> Mailboxes       what an OVERSIZED EMAIL does:
 *       oversize_mode             strip (keep the email, remove what is too big,
 *                                 leave a note in its place) | reject (no ticket)
 *       oversize_strip_order      when the TOTAL is over: largest (remove the
 *                                 biggest until the rest fit) | all | order (keep
 *                                 in order until the next would not fit)
 *       oversize_alert / _alert_to        email these addresses about it
 *       oversize_autoreply / _autoreply_text   tell the sender
 *
 *   Each mailbox may override all of it, limits included (target_mailboxes.
 *   oversize_policy, JSON with the same names; NULL = the defaults above) - a
 *   supplier mailbox that receives drawings is the case Ed had in mind.
 *
 * ⚠️ PHP's own limits (upload_max_filesize, post_max_size) still cap anything
 * uploaded through a BROWSER - FreeITSM never sees a file PHP refused. Inbound
 * email does not go through them (it is fetched, not uploaded), but it is held in
 * memory while it is processed, so memory_limit matters for very large mail.
 * phpUploadLimits() reports all three for the settings screen.
 */

const ATTACHMENT_DEFAULT_MAX_MB = 10;

/**
 * One setting, cached per request.
 *
 * @param string $key  the system_settings key to read
 * @param string $default  returned when the row is missing, empty or unreadable; the first answer per key is cached
 */
function attachmentSetting(?PDO $conn, string $key, string $default): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $v = null;
    try {
        $conn = $conn ?: (function_exists('connectToDatabase') ? connectToDatabase() : null);
        if ($conn) {
            $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $st->execute([$key]);
            $r = $st->fetchColumn();
            $v = ($r === false || $r === null || $r === '') ? null : (string)$r;
        }
    } catch (Throwable) { /* no table yet: defaults */ }
    return $cache[$key] = ($v ?? $default);
}

/**
 * A whole number of MB from a setting, clamped to something sane (0 allowed only where it means "no limit").
 *
 * @param mixed $v  the raw setting or posted value, in MB (string, int or null)
 * @param int $default  returned when $v is empty or not numeric, and for 0 or less unless $zeroMeansNone
 * @param bool $zeroMeansNone  true when 0 means "no limit" (the per-email total); 0 or less then returns 0
 */
function attachmentMb($v, int $default, bool $zeroMeansNone): int
{
    if ($v === null || $v === '' || !is_numeric($v)) return $default;
    $n = (int)$v;
    if ($n <= 0) return $zeroMeansNone ? 0 : $default;
    return min($n, 102400);   // 100 GB: past this the number is a typo
}

/** The install-wide largest single attachment, in bytes. What uploads.php uses when a caller sets none. */
function attachmentMaxBytes(?PDO $conn = null): int
{
    return attachmentMb(attachmentSetting($conn, 'attachment_max_mb', (string)ATTACHMENT_DEFAULT_MAX_MB), ATTACHMENT_DEFAULT_MAX_MB, false) * 1024 * 1024;
}

/** The default text of the auto-reply. {subject}, {limit} and {total_limit} are filled in. */
function oversizeDefaultReplyText(): string
{
    return "Your email \"{subject}\" was larger than this mailbox accepts. No attachment can be larger than {limit}, and an email's attachments together no larger than {total_limit}.\n\nPlease send large files another way - for example a link to a shared folder - or split them across several emails.";
}

/**
 * The rules for one mailbox: the install defaults, overridden by the mailbox's
 * own oversize_policy when it has one. Limits in BYTES (total 0 = no limit).
 *
 * @param ?array $mailbox  the target_mailboxes row, whose oversize_policy JSON overrides the defaults; null for defaults only
 */
function oversizeRules(PDO $conn, ?array $mailbox = null): array
{
    $rules = [
        'max_bytes'       => attachmentMaxBytes($conn),
        'max_total_bytes' => attachmentMb(attachmentSetting($conn, 'attachment_max_total_mb', '0'), 0, true) * 1024 * 1024,
        'mode'            => attachmentSetting($conn, 'oversize_mode', 'strip') === 'reject' ? 'reject' : 'strip',
        'strip_order'     => in_array($o = attachmentSetting($conn, 'oversize_strip_order', 'largest'), ['largest', 'all', 'order'], true) ? $o : 'largest',
        'alert'           => attachmentSetting($conn, 'oversize_alert', '0') === '1',
        'alert_to'        => attachmentSetting($conn, 'oversize_alert_to', ''),
        'autoreply'       => attachmentSetting($conn, 'oversize_autoreply', '0') === '1',
        'autoreply_text'  => attachmentSetting($conn, 'oversize_autoreply_text', '') ?: oversizeDefaultReplyText(),
        'source'          => 'defaults',
    ];
    $own = $mailbox && !empty($mailbox['oversize_policy']) ? json_decode((string)$mailbox['oversize_policy'], true) : null;
    if (is_array($own)) {
        $rules = array_merge($rules, oversizeNormalisePolicy($own, $rules));
        $rules['source'] = 'mailbox';
    }
    return $rules;
}

/**
 * A policy as posted by a settings screen (MB, strings), made safe. Used for the
 * mailbox override on save and on read, so a hand-edited row cannot break a check.
 *
 * @param array $p  the policy as posted or stored - max_mb, max_total_mb, mode, strip_order, alert, alert_to, autoreply...
 * @param ?array $fallback  rules to take max_bytes and max_total_bytes from when $p has none; null uses 10 MB and no total
 */
function oversizeNormalisePolicy(array $p, ?array $fallback = null): array
{
    $fb = $fallback ?? ['max_bytes' => ATTACHMENT_DEFAULT_MAX_MB * 1048576, 'max_total_bytes' => 0];
    $maxMb   = attachmentMb($p['max_mb'] ?? null, (int)round($fb['max_bytes'] / 1048576), false);
    $totalMb = attachmentMb($p['max_total_mb'] ?? null, (int)round($fb['max_total_bytes'] / 1048576), true);
    $text = trim((string)($p['autoreply_text'] ?? ''));
    return [
        'max_bytes'       => $maxMb * 1048576,
        'max_total_bytes' => $totalMb * 1048576,
        'mode'            => ($p['mode'] ?? '') === 'reject' ? 'reject' : 'strip',
        'strip_order'     => in_array($p['strip_order'] ?? '', ['largest', 'all', 'order'], true) ? $p['strip_order'] : 'largest',
        'alert'           => !empty($p['alert']),
        'alert_to'        => oversizeCleanAddresses((string)($p['alert_to'] ?? '')),
        'autoreply'       => !empty($p['autoreply']),
        'autoreply_text'  => $text !== '' ? mb_substr($text, 0, 4000) : oversizeDefaultReplyText(),
    ];
}

/**
 * "a@x.com; b@y.com, junk" -> "a@x.com, b@y.com" - valid addresses only.
 *
 * @param string $raw  addresses as typed, separated by commas, semicolons or spaces
 */
function oversizeCleanAddresses(string $raw): string
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', $raw) as $a) {
        $a = trim($a);
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) $out[strtolower($a)] = $a;
    }
    return implode(', ', array_values($out));
}

/**
 * Decide what to do with one email's attachments.
 *
 * @param array $atts  [['name' => string, 'size' => int bytes], ...] in message order
 * @param array $rules  the mailbox's rules from oversizeRules() - max_bytes, max_total_bytes, mode, strip_order
 * @return array [
 *   'over'   => bool          anything breaks a limit
 *   'reject' => bool          the whole email is to be refused (mode = reject)
 *   'strip'  => [index => reason]   attachments to leave out (mode = strip)
 *   'total'  => int           total bytes of all attachments
 * ]
 * An attachment over the per-attachment limit is always removed. Then, if what is
 * left still comes to more than the total limit, strip_order decides what else goes.
 */
function oversizePlan(array $atts, array $rules): array
{
    $total = 0;
    foreach ($atts as $a) $total += (int)$a['size'];
    $strip = [];
    $limitWords = oversizeMbWords($rules['max_bytes']);
    foreach ($atts as $i => $a) {
        if ((int)$a['size'] > $rules['max_bytes']) $strip[$i] = 'it is larger than the ' . $limitWords . ' limit for one attachment';
    }
    $max = (int)$rules['max_total_bytes'];
    if ($max > 0) {
        $left = 0;
        $keep = [];
        foreach ($atts as $i => $a) if (!isset($strip[$i])) { $keep[$i] = (int)$a['size']; $left += (int)$a['size']; }
        if ($left > $max) {
            $why = 'the attachments came to more than the ' . oversizeMbWords($max) . ' limit for one email';
            if ($rules['strip_order'] === 'all') {
                foreach ($keep as $i => $_) $strip[$i] = $why;
            } elseif ($rules['strip_order'] === 'order') {
                $running = 0;
                foreach ($keep as $i => $sz) {
                    if ($running + $sz > $max) { $strip[$i] = $why; continue; }
                    $running += $sz;
                }
            } else {   // largest first, until what is left fits
                arsort($keep);
                foreach ($keep as $i => $sz) {
                    if ($left <= $max) break;
                    $strip[$i] = $why;
                    $left -= $sz;
                }
            }
        }
    }
    ksort($strip);
    $over = !empty($strip);
    return ['over' => $over, 'reject' => $over && $rules['mode'] === 'reject', 'strip' => $rules['mode'] === 'reject' ? [] : $strip, 'total' => $total, 'reasons' => $strip];
}

/**
 * Formats a byte count as megabytes for messages - whole numbers without a decimal ("10 MB"), otherwise one place ("2.5 MB").
 *
 * @param int $bytes  the size in bytes
 */
function oversizeMbWords(int $bytes): string
{
    $mb = $bytes / 1048576;
    return (floor($mb) == $mb ? (int)$mb : round($mb, 1)) . ' MB';
}

/** What PHP on this server will accept, for the settings screen. */
function phpUploadLimits(): array
{
    $toBytes = function (string $v): int {
        $v = trim($v);
        if ($v === '' || $v === '-1') return -1;
        $n = (float)$v;
        switch (strtolower(substr($v, -1))) {
            case 'g': $n *= 1024;
            case 'm': $n *= 1024;
            case 'k': $n *= 1024;
        }
        return (int)$n;
    };
    return [
        'upload_max_filesize' => $toBytes((string)ini_get('upload_max_filesize')),
        'post_max_size'       => $toBytes((string)ini_get('post_max_size')),
        'memory_limit'        => $toBytes((string)ini_get('memory_limit')),
    ];
}

/**
 * Tell people about an oversized email: the alert to the configured addresses,
 * and the reply to the sender. Returns processing-log steps for the inbound log.
 *
 * @param array  $mailbox      the target_mailboxes row the email arrived at - id, name and target_mailbox are read
 * @param array  $rules        the mailbox's rules from oversizeRules()
 * @param string $fromAddress  the sender's address, from the email (untrusted) - the auto-reply goes here
 * @param string $fromName     the sender's display name, shown in the alert
 * @param string $subject      the email's subject, shown in the alert and the auto-reply
 * @param string $outcome  'rejected' (no ticket) | 'stripped' (ticket kept, files removed)
 * @param array  $atts     [['name','size'], ...]   $plan from oversizePlan()
 * @param array  $plan         the decision from oversizePlan() for these attachments - its 'reasons' are listed
 *
 * ⚠️ THE AUTO-REPLY MUST NOT BE ABLE TO LOOP. Two systems that each answer an
 * oversized email with an email can bounce forever, so the reply is never sent to
 * an automated sender (no-reply, mailer-daemon, postmaster, bounce...), never to
 * the mailbox itself, and at most once an hour to the same sender from the same
 * mailbox - checked against the inbound log, which already records every reply.
 * Nor to automated mail ($autoReply, GH #432: an out-of-office, a system
 * notification): a machine answering an auto-reply is exactly that loop.
 */
function oversizeNotify(PDO $conn, array $mailbox, array $rules, string $fromAddress, string $fromName, string $subject,
                        array $atts, array $plan, string $outcome, ?int $ticketId = null, bool $autoReply = false): array
{
    require_once __DIR__ . '/mailbox_send.php';
    $steps = [];
    $mbName = trim(($mailbox['name'] ?? '') . ' <' . ($mailbox['target_mailbox'] ?? '') . '>');
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $limit = oversizeMbWords((int)$rules['max_bytes']);
    $totalLimit = $rules['max_total_bytes'] > 0 ? oversizeMbWords((int)$rules['max_total_bytes']) : 'no limit';

    if ($rules['alert'] && $rules['alert_to'] !== '') {
        $ticketNo = null;
        if ($ticketId) {
            $st = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ?");
            $st->execute([$ticketId]);
            $ticketNo = $st->fetchColumn() ?: null;
        }
        $rows = '';
        foreach ($atts as $i => $a) {
            $status = isset($plan['reasons'][$i])
                ? ($outcome === 'rejected' ? 'Too large: ' . $plan['reasons'][$i] : 'Removed: ' . $plan['reasons'][$i])
                : ($outcome === 'rejected' ? 'Within the limits' : 'Kept');
            $rows .= '<tr><td style="padding:4px 10px;border-bottom:1px solid #eee">' . $h($a['name']) . '</td><td style="padding:4px 10px;border-bottom:1px solid #eee;text-align:right">'
                   . $h(oversizeMbWords((int)$a['size'])) . '</td><td style="padding:4px 10px;border-bottom:1px solid #eee">' . $h($status) . '</td></tr>';
        }
        $what = $outcome === 'rejected'
            ? 'The email was <strong>rejected</strong> - no ticket was created - because its attachments break the size limits.'
            : 'The email was accepted' . ($ticketNo ? ' as ticket <strong>' . $h($ticketNo) . '</strong>' : '') . ', but the attachments that break the size limits were <strong>removed</strong>. A note on the ticket lists them.';
        $body = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#222">'
              . '<p>An email to <strong>' . $h($mbName) . '</strong> was larger than FreeITSM accepts.</p>'
              . '<p><strong>From:</strong> ' . $h(trim($fromName . ' <' . $fromAddress . '>')) . '<br><strong>Subject:</strong> ' . $h($subject)
              . '<br><strong>Limits:</strong> ' . $h($limit) . ' per attachment, ' . $h($totalLimit) . ' per email in all ('
              . ($rules['source'] === 'mailbox' ? 'this mailbox\'s own limits' : 'the install-wide limits') . ')</p>'
              . '<p>' . $what . '</p>'
              . '<table style="border-collapse:collapse;font-size:13px"><tr><th style="text-align:left;padding:4px 10px">Attachment</th><th style="text-align:right;padding:4px 10px">Size</th><th style="text-align:left;padding:4px 10px">Result</th></tr>' . $rows . '</table>'
              . '<p style="color:#666;font-size:12px">Sent by FreeITSM because Tickets -> Settings -> Mailboxes asks for an alert when this happens.</p></div>';
        $subj = 'Oversized email ' . ($outcome === 'rejected' ? 'rejected' : 'received') . ': ' . mb_substr($subject, 0, 120);
        foreach (array_map('trim', explode(',', $rules['alert_to'])) as $to) {
            if ($to === '') continue;
            $err = mailboxSendSimple($conn, $mailbox, $to, $subj, $body, 'oversize_alert', $ticketId);
            $steps[] = ['step' => 'oversize_alert', 'to' => $to, 'result' => $err === null ? 'sent' : 'error', 'error' => $err];
        }
    }

    if ($rules['autoreply']) {
        $skip = oversizeReplySkipReason($conn, (int)$mailbox['id'], $fromAddress, (string)($mailbox['target_mailbox'] ?? ''), $autoReply);
        if ($skip !== null) {
            $steps[] = ['step' => 'oversize_reply', 'to' => $fromAddress, 'result' => 'skipped', 'reason' => $skip];
        } else {
            $text = strtr($rules['autoreply_text'], ['{subject}' => $subject, '{limit}' => $limit, '{total_limit}' => $totalLimit]);
            $body = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#222">' . nl2br($h($text)) . '</div>';
            $err = mailboxSendSimple($conn, $mailbox, $fromAddress, 'Re: ' . mb_substr($subject, 0, 150), $body, 'oversize_reply', $ticketId);
            $steps[] = ['step' => 'oversize_reply', 'to' => $fromAddress, 'result' => $err === null ? 'sent' : 'error', 'error' => $err];
        }
    }
    return $steps;
}

/**
 * Why NOT to auto-reply to this sender, or null to go ahead.
 *
 * @param int $mailboxId  the target_mailboxes id the email arrived at
 * @param string $from  the sender's address, from the email (untrusted)
 * @param string $mailboxAddress  the mailbox's own address, so it never replies to itself
 */
function oversizeReplySkipReason(PDO $conn, int $mailboxId, string $from, string $mailboxAddress, bool $autoReply = false): ?string
{
    $from = strtolower(trim($from));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return 'no valid sender address';
    if ($autoReply) return 'an automatic reply';
    if ($from === strtolower(trim($mailboxAddress))) return 'the sender is this mailbox';
    $local = explode('@', $from)[0];
    if (preg_match('/^(no-?reply|do-?not-?reply|donotreply|mailer-daemon|postmaster|bounces?|notifications?|auto-?reply)([+._-]|$)/i', $local)) {
        return 'an automated sender address';
    }
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM mailbox_activity_log
                               WHERE mailbox_id = ? AND from_address = ? AND created_datetime > UTC_TIMESTAMP() - INTERVAL 1 HOUR
                                 AND processing_log LIKE '%\"step\":\"oversize_reply\"%\"result\":\"sent\"%'");
        $st->execute([$mailboxId, $from]);
        if ((int)$st->fetchColumn() > 0) return 'already replied to this sender in the last hour';
    } catch (Throwable) { /* no log table yet: allow */ }
    return null;
}

/**
 * A normalised policy in the shape stored on a mailbox (MB, not bytes) and read back by oversizeNormalisePolicy().
 *
 * @param array $p  a policy as returned by oversizeNormalisePolicy() - limits in bytes
 */
function oversizePolicyForStorage(array $p): array
{
    return [
        'max_mb'         => (int)round($p['max_bytes'] / 1048576),
        'max_total_mb'   => (int)round($p['max_total_bytes'] / 1048576),
        'mode'           => $p['mode'],
        'strip_order'    => $p['strip_order'],
        'alert'          => (bool)$p['alert'],
        'alert_to'       => $p['alert_to'],
        'autoreply'      => (bool)$p['autoreply'],
        'autoreply_text' => $p['autoreply_text'],
    ];
}
