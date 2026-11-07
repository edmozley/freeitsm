<?php
/**
 * Mailbox health — everything that could be quietly wrong with a mailbox.
 *
 * Built for #79, where a mailbox collected mail perfectly well but stamped no
 * origin on the tickets it opened. Nothing was broken enough to error, so
 * nothing said anything: the mailbox list showed a green "Authenticated" badge
 * and the fault only surfaced weeks later, on the tickets.
 *
 * So the rule here is that a mailbox can be CONNECTED and still not be doing
 * what you think it is. Each check names one specific thing and says what the
 * consequence is, because "there is a problem" that doesn't say which problem
 * is barely better than silence.
 *
 * Severity:
 *   'error'   — mail is not being collected, or is being collected wrongly.
 *   'warning' — mail is collected, but something downstream is not set up.
 *
 * Pure function of a row already loaded by get_mailboxes.php (plus a couple of
 * looked-up extras), so it can't itself be the thing that breaks the list.
 */

/**
 * Every warning here can be dismissed, because every one of them can legitimately
 * be somebody's deliberate choice: a mailbox that genuinely should record no
 * origin, a receive-only IMAP mailbox with no outgoing server, a low-traffic
 * mailbox nobody minds being checked rarely. Dismissing says "I know", and the
 * item drops out of the ! while staying visible, and reversible, inside it.
 *
 * Errors cannot be dismissed. Reading the wrong inbox or holding credentials
 * that no longer decrypt is not a configuration choice, it is a fault, and
 * letting somebody silence it would be the one case where this whole feature
 * makes things worse than saying nothing.
 *
 * @param array $mb      A mailbox row as get_mailboxes.php has prepared it
 *                       (decrypted, typed, with auth_status already computed).
 * @param array $context 'origin_names' => [id => name] of origins that exist
 * @return array<int, array{key:string, severity:string, title:string, detail:string, dismissible:bool, dismissed:bool}>
 */
function mailboxHealthProblems(array $mb, array $context = []): array
{
    $problems    = [];
    $originNames = $context['origin_names'] ?? [];

    // Keys the admin has already acknowledged on THIS mailbox.
    $dismissed = json_decode((string)($mb['health_dismissed'] ?? ''), true);
    if (!is_array($dismissed)) $dismissed = [];
    $dismissed = array_flip(array_map('strval', $dismissed));

    $add = static function (&$out, $key, $severity, $title, $detail) use ($dismissed) {
        $isWarning = ($severity === 'warning');
        $out[] = [
            'key'         => $key,
            'severity'    => $severity,
            'title'       => $title,
            'detail'      => $detail,
            'dismissible' => $isWarning,
            // An error is never treated as dismissed even if a stale key names it
            // — e.g. a warning that was dismissed and has since become an error.
            'dismissed'   => $isWarning && isset($dismissed[$key]),
        ];
    };

    // --- Is it even reading? ------------------------------------------------

    if (!empty($mb['decrypt_error'])) {
        $add($problems, 'decrypt', 'error',
            'Stored credentials cannot be read',
            'The saved settings for this mailbox could not be decrypted, so it cannot sign in. This usually means the encryption key changed or was lost. Re-enter the credentials.');
    }

    // Deliberately NOT flagged: a mailbox being inactive, or being shared intake
    // rather than pinned to a company. Both are choices an admin makes on purpose,
    // both already show as a badge on the row, and a mark that can never be
    // cleared is one people stop reading — which would cost us the marks that
    // actually matter.

    $authStatus = $mb['auth_status'] ?? '';
    if ($authStatus === 'mismatch') {
        $add($problems, 'wrong_account', 'error',
            'Reading the wrong mailbox',
            'This mailbox is set to collect from ' . (string)($mb['target_mailbox'] ?? '?')
            . ' but the account that signed in is ' . (string)($mb['authenticated_as'] ?? '?')
            . '. Mail is being collected from the wrong inbox. Sign out and authenticate as the correct account.');
    } elseif ($authStatus === 'unauthenticated') {
        $add($problems, 'not_authenticated', 'error',
            'Never signed in',
            'This mailbox has no saved sign-in, so nothing is being collected from it yet. Use the authenticate button on the row.');
    } elseif ($authStatus === 'unverified') {
        $add($problems, 'unverified', 'warning',
            'Cannot confirm which account is signed in',
            'This mailbox signed in before FreeITSM started recording which account it was, so it cannot be checked against the address above. Signing out and back in confirms it.');
    }

    // Collected, but never actually run. A mailbox that has never been checked
    // is the normal state for thirty seconds and a fault after that, and the
    // list already shows "Never" without saying whether that matters.
    if (empty($mb['last_checked_datetime'])) {
        if (!empty($mb['is_active']) && $authStatus !== 'unauthenticated') {
            $add($problems, 'never_checked', 'warning',
                'Never checked for mail',
                'This mailbox is set up but has not been checked once. Mail is collected while somebody has the ticket inbox open, so nothing arrives from here until then.');
        }
    } else {
        // 24h is deliberately generous — this is meant to catch "nobody has had
        // the inbox open" (there is no mail cron yet: Mail-Collection-Developer-Guide),
        // not to nag an install that checks a low-traffic mailbox twice a day.
        $age = time() - strtotime((string)$mb['last_checked_datetime']);
        if ($age > 86400 && !empty($mb['is_active'])) {
            $days = max(1, (int) floor($age / 86400));
            $add($problems, 'stale', 'warning',
                'Not checked for ' . $days . ' day' . ($days === 1 ? '' : 's'),
                'The last check was ' . date('j M Y H:i', strtotime((string)$mb['last_checked_datetime']))
                . '. Mail is collected only while somebody has the ticket inbox open, so if mail is expected more often than that, check the inbox is in use.');
        }
    }

    // 🔴 THE CHECK THAT WOULD HAVE SAVED EIGHTEEN HOURS. A mailbox whose last
    // check failed used to look identical to one working perfectly: green badge,
    // "Authenticated", recent timestamp. The reason was written down nowhere and
    // the only way to see it was to click Check and read the response.
    //
    // Never dismissible. Mail not arriving is a fault, not a preference — and it
    // clears itself the moment a check succeeds, so it cannot nag once fixed.
    if (!empty($mb['last_error'])) {
        $when = !empty($mb['last_error_datetime'])
            ? ' (' . date('j M Y H:i', strtotime((string)$mb['last_error_datetime'])) . ')'
            : '';
        $add($problems, 'last_check_failed', 'error',
            'The last check for mail failed',
            'Mail is not being collected from this mailbox' . $when . '. '
            . 'The mail provider said: "' . trim((string)$mb['last_error']) . '"');
    }

    // --- Collected fine, but the tickets are wrong -------------------------

    // The #79 case. Not an error: a ticket without an origin is a working
    // ticket. It just cannot be reported on by where it came from, and that
    // fact is invisible until somebody tries.
    $originId = $mb['default_origin_id'] ?? null;
    if ($originId === null || $originId === '') {
        $add($problems, 'no_origin', 'warning',
            'No ticket origin set',
            'Tickets opened by this mailbox will not record where they came from, so they cannot be reported on by source. Set "Default ticket origin" in this mailbox\'s settings — Email for a helpdesk address, or something like Monitoring for an alerting one.');
    } elseif (!isset($originNames[(int)$originId])) {
        // The FK is ON DELETE SET NULL so this should not happen, but a restored
        // or hand-edited database can carry an id that points at nothing.
        $add($problems, 'origin_missing', 'error',
            'The ticket origin no longer exists',
            'This mailbox points at a ticket origin that has since been deleted, so no origin will be recorded. Pick a new one.');
    }

    // --- Set up half-way ---------------------------------------------------

    if (($mb['imported_action'] ?? '') === 'move_to_folder' && trim((string)($mb['imported_folder'] ?? '')) === '') {
        $add($problems, 'no_imported_folder', 'warning',
            'No folder chosen to move imported mail into',
            'This mailbox is set to move mail to a folder once imported, but no folder is named. Imported mail will be left where it is.');
    }

    if (($mb['provider'] ?? '') === 'imap' && trim((string)($mb['smtp_server'] ?? '')) === '') {
        $add($problems, 'no_smtp', 'warning',
            'No outgoing (SMTP) server',
            'Mail can be collected from this mailbox but replies cannot be sent from it.');
    }

    // A port and an encryption setting that do not belong together (4.11.0). The
    // classic is STARTTLS on 465: the mailbox looks fine, collects mail, and every
    // reply fails. Flagged here so it shows BEFORE the first reply bounces. Only
    // a warning, and dismissible: a provider can run something unusual on a port.
    if (($mb['provider'] ?? '') === 'imap') {
        require_once __DIR__ . '/mail_ports.php';
        $smtpHint = mailPortMismatch('smtp', (int)($mb['smtp_port'] ?? 0), (string)($mb['smtp_encryption'] ?? ''));
        if ($smtpHint !== null && trim((string)($mb['smtp_server'] ?? '')) !== '') {
            $add($problems, 'smtp_port_encryption', 'warning',
                'Sending will fail: the SMTP port and encryption do not match',
                $smtpHint . ' Use Test in the mailbox settings to check.');
        }
        $imapHint = mailPortMismatch('imap', (int)($mb['imap_port'] ?? 0), (string)($mb['imap_encryption'] ?? ''));
        if ($imapHint !== null) {
            $add($problems, 'imap_port_encryption', 'warning',
                'Collecting mail will fail: the IMAP port and encryption do not match',
                $imapHint);
        }
    }

    return $problems;
}
