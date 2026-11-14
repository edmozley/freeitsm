<?php
/**
 * Tickets whose requester gets no automatic emails (GitHub #316).
 *
 * A Starters & leavers case can raise tickets for the teams that do the work. Their
 * requester is whoever asked for the case - a manager, usually - and the template
 * decides whether that person hears about them: "Email the requester about these
 * tickets", off by default. Off, the ticket sends its requester no automatic email
 * (logged, assigned, closed, a shared note) and no satisfaction survey. An analyst
 * can still reply by email by hand - that is not automatic.
 *
 * The answer is kept on the link row (jml_case_tickets.mute_requester) when the
 * ticket is raised, so changing the template later does not change tickets already
 * out. Two places ask: sendTemplateEmail() for requester emails, csatAutoOnClose()
 * for the survey.
 *
 * @wiki Starters-Steps-As-Tickets-Developer-Guide
 */

/** True when automatic emails to this ticket's requester are switched off. Never throws. */
function ticketRequesterEmailsMuted(PDO $conn, int $ticketId): bool
{
    static $cache = [];
    if (array_key_exists($ticketId, $cache)) return $cache[$ticketId];
    try {
        $st = $conn->prepare("SELECT 1 FROM jml_case_tickets WHERE ticket_id = ? AND mute_requester = 1 LIMIT 1");
        $st->execute([$ticketId]);
        return $cache[$ticketId] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$ticketId] = false;   // no table before Database Verification: nothing is muted
    }
}
