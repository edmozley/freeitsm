<?php
/**
 * Who Reply addresses on an email ticket (GH #120).
 *
 * Tickets -> Settings -> General -> "Reply goes to". Opinions differ, so it is a
 * setting, with what FreeITSM has always done as the default:
 *
 *   Message   - the person on the message you are reading (correspondentAddress()
 *               in assets/js/inbox.js). A ticket where requester A wrote and then
 *               B replied on the same thread answers B from B's message.
 *   Requester - always the ticket's requester, whichever message is open, as
 *               TopDesk does; anyone else is added by hand in To or Cc.
 *
 * The reply box is only pre-filled: the analyst can always change the address
 * before sending, so this decides a default, never who may be emailed.
 */

enum TicketReplyTo: string
{
    case Message   = 'message';
    case Requester = 'requester';
}

/**
 * The install's choice, or Message when it was never saved or holds a value
 * nobody chose (save_system_settings.php refuses those anyway).
 */
function ticketReplyTo(PDO $conn): TicketReplyTo
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_reply_to'");
        $st->execute();
        $v = $st->fetchColumn();
    } catch (Throwable $e) {
        return TicketReplyTo::Message;
    }
    return TicketReplyTo::tryFrom((string) $v) ?? TicketReplyTo::Message;
}
