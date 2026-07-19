<?php
/**
 * Claiming pending screen recordings onto a ticket — in ONE place.
 *
 * WHY THIS EXISTS
 * ---------------
 * A recording must be uploaded BEFORE the thing it belongs to exists: the user
 * may record, watch it back, and re-record several times before they are happy,
 * and each attempt has to leave the browser. So uploads land as PENDING
 * (`ticket_id IS NULL`, file under `recordings/pending/{uuid}.{ext}`) and are
 * CLAIMED when the ticket or the reply is finally written —
 * see api/self-service/upload_recording.php.
 *
 * That claim was written for raising a ticket. Letting people record while
 * REPLYING needed exactly the same dance — same ownership guard, same file move,
 * same fallback — so it moved here rather than being copied. The ownership guard
 * in particular is the kind of thing that must never exist in two versions.
 *
 * 🔑 THE GUARD: a row is only claimable while `ticket_id IS NULL` AND it was
 * uploaded by the SAME user. Both halves matter — the first stops a recording
 * being stolen off an existing ticket, the second stops one user claiming
 * another's pending upload. Recording ids are sequential and guessable, so this
 * is the only thing standing between them.
 */

/**
 * Move pending recordings onto a ticket and (optionally) a specific message.
 *
 * File renames happen outside any transaction — filesystem operations are not
 * transactional anyway, and a failed move must not cost the user their reply.
 *
 * @param array $recordingIds ids the client claims to own (untrusted)
 * @param int   $ticketId     the ticket they now belong to
 * @param int   $userId       the portal user doing the claiming
 * @param ?int  $emailId      the message they were recorded with; NULL means the
 *                            ticket's opening message, which is how every
 *                            recording behaved before replies could carry one
 * @return int how many rows were actually claimed
 */
function claimPendingRecordings(PDO $conn, array $recordingIds, int $ticketId, int $userId, ?int $emailId = null): int
{
    $recordingIds = array_values(array_filter(array_map('intval', $recordingIds)));
    if (empty($recordingIds)) {
        return 0;
    }

    $appRoot   = realpath(__DIR__ . '/../');
    $ticketDir = $appRoot . DIRECTORY_SEPARATOR . 'recordings' . DIRECTORY_SEPARATOR . $ticketId;
    if (!is_dir($ticketDir)) {
        @mkdir($ticketDir, 0755, true);
    }

    // See THE GUARD above. Applied in the query, so an id belonging to somebody
    // else simply selects nothing — there is no separate "denied" path to get
    // wrong, and no way to learn whether that id exists.
    $placeholders = implode(',', array_fill(0, count($recordingIds), '?'));
    $selStmt = $conn->prepare(
        "SELECT id, filename, file_path FROM ticket_recordings
         WHERE id IN ($placeholders) AND ticket_id IS NULL AND recorded_by_user_id = ?"
    );
    $selStmt->execute(array_merge($recordingIds, [$userId]));
    $pending = $selStmt->fetchAll(PDO::FETCH_ASSOC);

    $updStmt = $conn->prepare(
        "UPDATE ticket_recordings SET ticket_id = ?, email_id = ?, file_path = ? WHERE id = ?"
    );

    $claimed = 0;
    foreach ($pending as $rec) {
        $srcAbs = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rec['file_path']);
        $dstAbs = $ticketDir . DIRECTORY_SEPARATOR . $rec['filename'];
        $dstRel = 'recordings/' . $ticketId . '/' . $rec['filename'];

        if (is_file($srcAbs) && @rename($srcAbs, $dstAbs)) {
            $updStmt->execute([$ticketId, $emailId, $dstRel, (int) $rec['id']]);
        } else {
            // The move failed, but still claim the row: associating a broken
            // recording with its ticket is recoverable and visible, whereas
            // leaving it orphaned in pending/ means nobody ever finds it.
            $updStmt->execute([$ticketId, $emailId, $rec['file_path'], (int) $rec['id']]);
            error_log('claimPendingRecordings: failed to move recording ' . $rec['id'] . ' from ' . $srcAbs);
        }
        $claimed++;
    }

    return $claimed;
}
