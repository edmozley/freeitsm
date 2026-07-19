<?php
/**
 * What the requester may see of their OWN ticket in the self-service portal.
 *
 * THE PROBLEM
 * -----------
 * A ticket's thread is not always a conversation with the requester. When an
 * analyst forwards a ticket to a supplier, or CCs a colleague, that message is
 * written onto the SAME ticket — and the supplier's reply lands there too. The
 * portal showed the whole thread, so correspondence *about* the requester was
 * visible *to* the requester, occasionally with another customer's document
 * attached.
 *
 * "Forward" is not recorded anywhere: a forward and an ordinary reply look
 * identical in `emails`. So the rule is not "was this a forward" but the
 * question that actually matters, which also covers CCs and third-party
 * replies, and works on existing history with no migration:
 *
 *     Was this message TO or FROM the person who raised the ticket?
 *
 * WHERE THIS MUST BE APPLIED
 * --------------------------
 * BOTH the thread listing AND the attachment download endpoint. Hiding a
 * message in the list while its attachment URL still works is decoration, not
 * enforcement — the id is guessable and the file is the sensitive part.
 *
 * FAILING TOWARDS VISIBILITY
 * --------------------------
 * Every ambiguous case resolves to VISIBLE, deliberately:
 *
 *   - Non-email channels (WhatsApp, web chat) are addressed by phone number or
 *     visitor id, so a requester's EMAIL matches nothing on them. Comparing
 *     addresses there would hide the customer's entire conversation from their
 *     own portal. There is also no "forward" on those channels — the exchange is
 *     between the visitor and the desk by definition. Always visible.
 *   - A message with no recipients recorded cannot be judged. Visible.
 *   - A requester whose address is simply UNKNOWN to us ('') cannot be judged.
 *     Visible.
 *
 * Hiding an analyst's genuine reply is a worse failure than showing a forward:
 * the customer is left believing nobody answered them. The strict direction is
 * the one an admin opts into per-message-type, not one we guess at.
 *
 * ⚠️ THE ONE CASE THAT IS NOT AMBIGUOUS
 * -------------------------------------
 * A requester who has NO MAILBOX AT ALL (`$requesterEmail === null`) is a
 * different thing entirely from one whose address we don't know. Directory
 * users may genuinely have none (GitHub #47). No email can ever have been
 * addressed to them, so every email-channel message on their ticket is
 * third-party by definition and the policy APPLIES — it is not ambiguous and
 * must not fail open. Their own messages arrive by the portal and are matched
 * on `direction` well before any address comparison.
 */

require_once __DIR__ . '/functions.php';

/** Third-party messages are hidden from the portal entirely (the default). */
const PORTAL_THIRD_PARTY_HIDE = 'hide';
/** The message shows, but its attachments are withheld. */
const PORTAL_THIRD_PARTY_NO_ATTACHMENTS = 'no_attachments';
/** Everything on the ticket is shown — the behaviour before this setting existed. */
const PORTAL_THIRD_PARTY_SHOW = 'show';

/**
 * The configured policy: Tickets → Settings → Privacy.
 *
 * Defaults to HIDE when never saved. Ed's explicit call: showing a supplier
 * forward to the customer is treated as the bug it is, so a fresh or upgrading
 * install is protected without anyone having to find the setting first.
 */
function portalThirdPartyPolicy(PDO $conn): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    $valid = [PORTAL_THIRD_PARTY_HIDE, PORTAL_THIRD_PARTY_NO_ATTACHMENTS, PORTAL_THIRD_PARTY_SHOW];
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'portal_third_party_visibility'");
        $stmt->execute();
        $val = $stmt->fetchColumn();
        $cached = ($val !== false && in_array($val, $valid, true)) ? $val : PORTAL_THIRD_PARTY_HIDE;
    } catch (Exception $e) {
        $cached = PORTAL_THIRD_PARTY_HIDE;   // unreadable settings → protect.
    }
    return $cached;
}

/**
 * Is this message part of the requester's own correspondence?
 *
 * @param array  $email           needs: channel, direction, from_address,
 *                                to_recipients, cc_recipients
 * @param ?string $requesterEmail the ticket owner's address. NULL = they have
 *                                NO mailbox (a directory user); '' = unknown.
 *                                The two mean opposite things here.
 * @return bool true = the requester's own conversation (always portal-visible)
 */
function portalEmailInvolvesRequester(array $email, ?string $requesterEmail): bool {
    // Non-email channels: addressed by phone number / visitor id, never by the
    // requester's email. Judging them by address would hide their own chat.
    $channel = strtolower(trim((string)($email['channel'] ?? 'email')));
    if ($channel !== '' && $channel !== 'email') return true;

    // The requester typed it in the portal themselves.
    if (strtolower(trim((string)($email['direction'] ?? ''))) === 'portal') return true;

    // 🔴 "This person HAS no address" is not the same as "we don't know their
    // address", and conflating the two silently defeats the whole privacy
    // policy. NULL means the former; '' means the latter.
    //
    // Directory users may genuinely have no mailbox (GitHub #47). For them an
    // EMAIL-channel message cannot possibly be to or from them — there is no
    // address it could have been sent to. So every email on their ticket is
    // third-party correspondence by definition and the configured policy must
    // apply. Treating it as "involves them", which the old `$needle === ''`
    // branch did, would show a mailbox-less requester every forward to a
    // supplier and every copied-in colleague's reply — precisely the disclosure
    // #892 exists to prevent, aimed at the people least likely to notice.
    //
    // Their own conversation is already covered above: what they typed returns
    // at the `direction === 'portal'` check, and anything an analyst chooses to
    // share with them is a NOTE, which never reaches this function.
    if ($requesterEmail === null) return false;

    $needle = strtolower(trim($requesterEmail));
    if ($needle === '' || strpos($needle, '@') === false) return true;   // can't judge → visible

    $to = trim((string)($email['to_recipients'] ?? ''));
    $cc = trim((string)($email['cc_recipients'] ?? ''));
    $from = trim((string)($email['from_address'] ?? ''));

    // Nothing recorded to compare against → visible rather than wrongly hidden.
    if ($to === '' && $cc === '' && $from === '') return true;

    $haystack = strtolower($from . ' ' . $to . ' ' . $cc);
    return strpos($haystack, $needle) !== false;
}

/**
 * Apply the policy to one message.
 *
 * @return array{visible: bool, attachments: bool}
 *         visible     — show the message in the thread at all
 *         attachments — allow its attachments to be listed AND downloaded
 */
function portalEmailVisibility(array $email, ?string $requesterEmail, string $policy): array {
    if (portalEmailInvolvesRequester($email, $requesterEmail)) {
        return ['visible' => true, 'attachments' => true];
    }

    switch ($policy) {
        case PORTAL_THIRD_PARTY_SHOW:
            return ['visible' => true,  'attachments' => true];
        case PORTAL_THIRD_PARTY_NO_ATTACHMENTS:
            return ['visible' => true,  'attachments' => false];
        case PORTAL_THIRD_PARTY_HIDE:
        default:
            return ['visible' => false, 'attachments' => false];
    }
}
