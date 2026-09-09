<?php
/**
 * Outbound email log.
 *
 * The inbound half of this already existed (`mailbox_activity_log`, which records
 * every message that arrives and why it was imported or rejected). There was no
 * outbound equivalent: a successful send left a row in `emails`, and a FAILED send
 * left nothing anywhere except a line in the PHP error log on the server.
 *
 * That gap is how issue #67 stayed invisible. A workflow send failure was at least
 * visible in the workflow run history, but the ticket acknowledgement template was
 * failing on exactly the same call, every time, and said so only here:
 *
 *   [15-Aug-2026 13:30:51] Template email error (ticket_assigned, ticket 611):
 *   Graph API send failed: /me request is only valid with delegated authentication flow.
 *
 * Nobody reads a file on the server until they already suspect a problem, and the
 * whole difficulty with a silent send failure is that nothing makes you suspect one.
 *
 * ⚠️ EVERY outbound path must log, success and failure alike. A log that covers some
 * routes is worse than none, because "no failures" then reads as "nothing failed"
 * when it may only mean "that route was never instrumented". The routes are listed
 * in EMAIL_LOG_ROUTES below; add to it when a new way to send email appears.
 */

require_once __DIR__ . '/../config.php';

/**
 * Every way FreeITSM sends mail. Keep in step with the call sites — the UI uses
 * this for its filter, so a missing entry hides a whole route from the log.
 */
const EMAIL_LOG_ROUTES = [
    'reply'          => 'Analyst reply',
    'template'       => 'Ticket template',
    'workflow'       => 'Workflow action',
    'sla'            => 'SLA alert',
    'portal'         => 'Portal / system',
    'password_reset' => 'Password reset',
    'share_kb'       => 'Knowledge article shared',
    'share_change'   => 'Change record shared',
    // Training reminders. Its own route rather than sharing 'Portal / system':
    // reminders are the one route that sends to MANY people at once, so the
    // question "did the training chase go out, and to whom" is exactly the one
    // somebody brings to this log — and it is unanswerable if the rows are mixed
    // in with password resets and account verifications.
    'training'       => 'Training reminder',
];

/**
 * Record one send attempt. NEVER throws and never returns a failure: logging a send
 * must not be able to break the send it is logging, and must not turn a delivered
 * email into an error the caller reports as undelivered.
 *
 * $mailbox may be null — a send can fail before any mailbox is resolved, and that is
 * one of the more useful things to have recorded.
 */
function emailLogRecord(
    ?PDO $conn,
    ?array $mailbox,
    string $route,
    string $to,
    string $subject,
    $sent,                      // bool for sent/failed, or a status string
    ?string $error = null,
    ?int $ticketId = null
): void {
    try {
        if (!$conn) return;

        $stmt = $conn->prepare(
            "INSERT INTO email_send_log
               (mailbox_id, ticket_id, route, provider, auth_mode, to_address,
                subject, status, error_message, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $stmt->execute([
            isset($mailbox['id']) ? (int)$mailbox['id'] : null,
            $ticketId,
            substr($route, 0, 30),
            $mailbox ? substr((string)($mailbox['provider'] ?? 'microsoft'), 0, 20) : null,
            // auth_mode only means anything for Microsoft; for the others it would be
            // a misleading 'delegated' on a mailbox that has no such concept.
            ($mailbox && ($mailbox['provider'] ?? 'microsoft') === 'microsoft')
                ? substr((string)($mailbox['auth_mode'] ?? 'delegated'), 0, 20) : null,
            substr($to, 0, 255),
            substr($subject, 0, 500),
            is_string($sent) ? substr($sent, 0, 10) : ($sent ? 'sent' : 'failed'),
            $error !== null ? substr($error, 0, 2000) : null,

        ]);
    } catch (Throwable $e) {
        // Deliberately swallowed. If the log table is missing on a part-upgraded
        // install, sending must carry on working exactly as before.
        error_log('email_send_log write failed: ' . $e->getMessage());
    }
}

/** Convenience: a send that succeeded. */
function emailLogSent(?PDO $conn, ?array $mailbox, string $route, string $to, string $subject, ?int $ticketId = null): void {
    emailLogRecord($conn, $mailbox, $route, $to, $subject, true, null, $ticketId);
}

/** Convenience: a send that failed, with whatever the provider said about it. */
function emailLogFailed(?PDO $conn, ?array $mailbox, string $route, string $to, string $subject, string $error, ?int $ticketId = null): void {
    emailLogRecord($conn, $mailbox, $route, $to, $subject, false, $error, $ticketId);
}

/** Human label for a route key, falling back to the key itself for unknown ones. */
function emailLogRouteLabel(string $route): string {
    return EMAIL_LOG_ROUTES[$route] ?? $route;
}

/**
 * Convenience: an email that was deliberately NOT sent, and why.
 *
 * ⚠️ THIS IS THE ONE THAT MATTERS TWELVE MONTHS LATER. When email templates are
 * scoped to particular senders, a sender matching none of them gets no reply —
 * which is correct, and is also indistinguishable from a fault unless somebody
 * wrote it down. The scenario this exists for is a new customer domain being
 * onboarded long after the templates were set up, by people who never saw the
 * settings screen: without this row, the only evidence is an absence, and the
 * only way to understand it is to already suspect the cause.
 *
 * The mailbox may legitimately be null: not sending happens before a mailbox is
 * looked up, and the row is still worth having.
 */
function emailLogSkipped(?PDO $conn, ?array $mailbox, string $route, string $to, string $subject, string $reason, ?int $ticketId = null): void {
    emailLogRecord($conn, $mailbox, $route, $to, $subject, 'skipped', $reason, $ticketId);
}
