<?php
/**
 * War room — the chat analysts fall back to when Teams/Slack is unavailable.
 *
 * WHY THIS EXISTS. When the internet drops, the on-premise service desk is the
 * last thing still running on the LAN — and it already knows every analyst, who
 * is in which team, and what is currently on fire. So it is the natural place
 * for people to gather.
 *
 * 🔑 IT BEING A FALLBACK IS NOT A LICENCE TO MAKE IT THIN. The first cut had
 * team channels and nothing else, on the theory that a break-glass tool should
 * stay minimal. That was the wrong instinct: a tool nobody enjoys using in calm
 * conditions is not the one they reach for at 3am, and an outage is the worst
 * possible moment to discover you cannot start a side conversation or find what
 * somebody said forty minutes ago. So: real channels, direct messages, search
 * and attachments.
 *
 * WHAT WE KEPT FROM THE FIRST CUT, because these were right:
 *
 *   NO EXTERNAL DEPENDENCIES. No CDN asset, no cert, no token, no scheduled job.
 *   A tool for the day the internet is down must not have a dependency that can
 *   expire or fail to resolve while it is down — which is why retention is
 *   applied on write rather than by cron, and why the channels an analyst needs
 *   are created on demand rather than by an administrator setting them up in
 *   advance. The one part that DOES need the internet — the AI situation report
 *   — is strictly additive: it is a panel, and the chat neither calls it nor
 *   waits for it. When the provider is unreachable the report says so and the
 *   room carries on.
 *
 *   TEAM CHANNELS ARE STILL DERIVED. A team channel is keyed to its team and
 *   CASCADEs with it, so it cannot be renamed into a lie or orphaned. Custom
 *   channels and DMs are rows, and they do carry a lifecycle — that is the cost
 *   of the feature, paid deliberately rather than avoided.
 *
 * Service layer: the UI page and every API endpoint come through here, so the
 * access rule exists once. See [[Service-Layer-Architecture]].
 */

require_once __DIR__ . '/uploads.php';

/** How long since a poll before we stop calling someone "here". */
const WARROOM_PRESENCE_WINDOW_SECONDS = 90;

/** Hard cap on one message, so a paste of a log file can't wedge the page. */
const WARROOM_MAX_BODY = 4000;

/**
 * What Warbot is called on screen.
 *
 * Declared HERE rather than in warbot/warbot.php on purpose: the message reader
 * below needs it to label a bot message, and warroom.php must not depend on the
 * bot — the chat has to work with Warbot switched off entirely. warbot.php
 * requires this file, so it inherits the constant rather than owning it.
 */
const WARBOT_NAME = 'Warbot';

/** Pins per conversation (5.9.0). Past this a pin list stops being a shortlist. */
const WARROOM_MAX_PINS = 50;

/** Attachments per message. Enough for a screenshot set, not a file share. */
const WARROOM_MAX_ATTACHMENTS = 5;

/** Where attachment bytes live. Never served from here — see warRoomAttachmentPath. */
require_once __DIR__ . '/file_stores.php';
define('WARROOM_ATTACH_DIR', fileStoreDir('war-room'));   // the one place: includes/file_stores.php

/** The four kinds of channel. Stored as a string so db_verify stays simple. */
const WARROOM_KIND_ALL    = 'all';      // the one all-hands room
const WARROOM_KIND_TEAM   = 'team';     // one per team, derived, CASCADEs with it
const WARROOM_KIND_CUSTOM = 'custom';   // somebody made it
const WARROOM_KIND_DM     = 'dm';       // a pair of analysts

/* ══════════════════════════════════════════════════════════════════════════
   CHANNELS
   ══════════════════════════════════════════════════════════════════════════ */

/**
 * Make sure the channels this analyst is entitled to actually exist as rows.
 *
 * Called at list time rather than by an installer or a cron, so a team created
 * ten minutes ago has a channel the first time anybody looks — nothing to set
 * up in advance and nothing to run afterwards. Both inserts are idempotent:
 * the all-hands row is guarded by its unique kind, and team rows by the unique
 * on team_id, so concurrent pollers race harmlessly.
 */
function warRoomEnsureChannels(PDO $conn, int $analystId): void
{
    try {
        // The all-hands room. INSERT … SELECT WHERE NOT EXISTS rather than
        // INSERT IGNORE, because IGNORE would also swallow a real error.
        $conn->exec(
            "INSERT INTO warroom_channels (kind, name, created_datetime)
             SELECT '" . WARROOM_KIND_ALL . "', NULL, UTC_TIMESTAMP() FROM DUAL
              WHERE NOT EXISTS (SELECT 1 FROM warroom_channels WHERE kind = '" . WARROOM_KIND_ALL . "')"
        );

        // One channel per team this analyst belongs to.
        $stmt = $conn->prepare(
            "INSERT INTO warroom_channels (kind, team_id, created_datetime)
             SELECT '" . WARROOM_KIND_TEAM . "', t.id, UTC_TIMESTAMP()
               FROM teams t
               JOIN analyst_teams at ON at.team_id = t.id
              WHERE at.analyst_id = :aid
                AND (t.is_active IS NULL OR t.is_active = 1)
                AND NOT EXISTS (
                      SELECT 1 FROM warroom_channels c
                       WHERE c.kind = '" . WARROOM_KIND_TEAM . "' AND c.team_id = t.id)"
        );
        $stmt->execute([':aid' => $analystId]);
    } catch (Throwable) {
        // A channel we could not create just does not appear. Never let this
        // stop the page loading — the all-hands room is what matters.
    }
}

/**
 * Every channel this analyst can see, in display order, with unread counts.
 *
 * Order is deliberate and not alphabetical: all-hands, then teams, then custom
 * channels, then DMs. In an outage the room everyone shares should be the first
 * thing under the cursor, not the thing you scroll past your DMs to reach.
 *
 * @param bool $includeArchived  true to include archived channels as well
 * @return array<int,array{id:int,kind:string,name:string,topic:?string,
 *                         is_private:bool,archived:bool,unread:int,members:?int}>
 */
/**
 * Does warroom_messages have this column yet? (5.9.0)
 *
 * TRAP: every column added since 5.8.0 (quotes, reactions' re-send, translation,
 * threads, forwards) is read only after asking here. Code that reads a column
 * Database Verification has not made yet does not just lose that feature - it
 * failed the whole war room with a 500 (Ed, 21 Nov 2026). Without a column, its
 * feature quietly switches off and the chat keeps working; administrators see a
 * notice (warRoomSchemaBehind()).
 *
 * @param string $col  a warroom_messages column, e.g. 'thread_root_id'
 */
function warRoomHasCol(PDO $conn, string $col): bool
{
    static $cols = null;
    if ($cols === null) {
        try {
            $cols = array_flip($conn->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warroom_messages'"
            )->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) {
            $cols = [];
        }
    }
    return isset($cols[$col]);
}

/**
 * True when Database Verification has not yet made everything this version of the
 * war room uses - shown to administrators as a notice instead of anything failing.
 */
function warRoomSchemaBehind(PDO $conn): bool
{
    foreach (['quote_of_id', 'changed_datetime', 'lang', 'thread_root_id', 'also_in_channel', 'forwarded_from_id'] as $c) {
        if (!warRoomHasCol($conn, $c)) return true;
    }
    try {
        $have = (int) $conn->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
             ('warroom_reactions','warroom_pins','warroom_saved','warroom_translations','warroom_read_marks','warroom_file_links')"
        )->fetchColumn();
        return $have < 6;
    } catch (Throwable $e) {
        return false;
    }
}

function warRoomChannelList(PDO $conn, int $analystId, bool $includeArchived = false): array
{
    warRoomEnsureChannels($conn, $analystId);

    $archClause = $includeArchived ? '' : ' AND c.archived_datetime IS NULL';

    // One query for all four kinds. The visibility rule per kind is expressed in
    // the WHERE, so a channel the analyst may not see is never fetched and then
    // filtered — the same reason search scopes inside the query rather than after.
    $sql = "
        SELECT c.id, c.kind, c.name, c.topic, c.is_private, c.archived_datetime,
               t.name AS team_name,
               (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ')
                  FROM warroom_channel_members m2
                  JOIN analysts a2 ON a2.id = m2.analyst_id
                 WHERE m2.channel_id = c.id AND m2.analyst_id <> :me3) AS other_members,
               (SELECT COUNT(*) FROM warroom_channel_members m3 WHERE m3.channel_id = c.id) AS member_count,
               COALESCE(r.last_read_message_id, 0) AS last_read,
               (SELECT COUNT(*) FROM warroom_messages wm
                 WHERE wm.channel_id = c.id
                   AND wm.id > COALESCE(r.last_read_message_id, 0)
                   AND wm.analyst_id <> :me4"
                   . (warRoomHasCol($conn, 'thread_root_id') ? " AND (wm.thread_root_id IS NULL OR wm.also_in_channel = 1)" : '') . ") AS unread
          FROM warroom_channels c
          LEFT JOIN teams t ON t.id = c.team_id
          LEFT JOIN warroom_reads r ON r.channel_id = c.id AND r.analyst_id = :me1
         WHERE (
                   c.kind = '" . WARROOM_KIND_ALL . "'
                OR (c.kind = '" . WARROOM_KIND_TEAM . "' AND EXISTS (
                      SELECT 1 FROM analyst_teams at
                       WHERE at.team_id = c.team_id AND at.analyst_id = :me2))
                OR (c.kind = '" . WARROOM_KIND_CUSTOM . "' AND c.is_private = 0)
                OR (c.kind IN ('" . WARROOM_KIND_CUSTOM . "','" . WARROOM_KIND_DM . "') AND EXISTS (
                      SELECT 1 FROM warroom_channel_members m
                       WHERE m.channel_id = c.id AND m.analyst_id = :me5))
               )
               $archClause
         ORDER BY FIELD(c.kind, '" . WARROOM_KIND_ALL . "', '" . WARROOM_KIND_TEAM . "', '"
                              . WARROOM_KIND_CUSTOM . "', '" . WARROOM_KIND_DM . "'),
                  COALESCE(t.display_order, 0), COALESCE(t.name, c.name), c.id";

    $stmt = $conn->prepare($sql);
    foreach (['me1', 'me2', 'me3', 'me4', 'me5'] as $p) {
        $stmt->bindValue(':' . $p, $analystId, PDO::PARAM_INT);
    }
    $stmt->execute();

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id'         => (int) $r['id'],
            'kind'       => $r['kind'],
            'name'       => warRoomChannelName($r),
            'topic'      => $r['topic'] !== '' ? $r['topic'] : null,
            'is_private' => (bool) $r['is_private'],
            'archived'   => $r['archived_datetime'] !== null,
            'unread'     => (int) $r['unread'],
            'members'    => $r['member_count'] !== null ? (int) $r['member_count'] : null,
        ];
    }
    return $out;
}

/**
 * The label for a channel row.
 *
 * A team channel shows the team's CURRENT name and a DM the other person's, both
 * read live rather than copied at creation — so renaming a team or an analyst
 * renames the channel, and there is no stored name that can quietly become wrong.
 *
 * @param array $row  a channel row with kind, name, team_name and other_members (the other DM members' names)
 */
function warRoomChannelName(array $row): string
{
    switch ($row['kind']) {
        case WARROOM_KIND_ALL:
            return function_exists('t') ? t('war-room.channel.all_hands') : 'War room';
        case WARROOM_KIND_TEAM:
            return (string) ($row['team_name'] ?? '');
        case WARROOM_KIND_DM:
            // Everyone in the DM except the reader. Falls back rather than showing
            // an empty label if the other account has since been deleted.
            $others = trim((string) ($row['other_members'] ?? ''));
            if ($others !== '') return $others;
            return function_exists('t') ? t('war-room.former_analyst') : 'Former analyst';
        default:
            return (string) ($row['name'] ?? '');
    }
}

/**
 * One channel row, or null. Raw — callers must still check access.
 *
 * @param int $channelId  the warroom_channels id
 */
function warRoomChannel(PDO $conn, int $channelId): ?array
{
    $stmt = $conn->prepare(
        "SELECT c.*, t.name AS team_name,
                (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ')
                   FROM warroom_channel_members m2
                   JOIN analysts a2 ON a2.id = m2.analyst_id
                  WHERE m2.channel_id = c.id) AS other_members
           FROM warroom_channels c
           LEFT JOIN teams t ON t.id = c.team_id
          WHERE c.id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $channelId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * May this analyst read this channel?
 *
 * ⚠️ Enforced HERE, server-side, on every read and every write — never by only
 * rendering a shorter channel list. Anything unexpected fails CLOSED.
 *
 * @param int $channelId  the warroom_channels id
 */
function warRoomCanAccessChannel(PDO $conn, int $analystId, int $channelId): bool
{
    try {
        $ch = warRoomChannel($conn, $channelId);
        if ($ch === null) return false;

        switch ($ch['kind']) {
            case WARROOM_KIND_ALL:
                return true;                                   // every analyst
            case WARROOM_KIND_TEAM:
                $s = $conn->prepare("SELECT 1 FROM analyst_teams WHERE analyst_id = :a AND team_id = :t LIMIT 1");
                $s->execute([':a' => $analystId, ':t' => (int) $ch['team_id']]);
                return (bool) $s->fetchColumn();
            case WARROOM_KIND_CUSTOM:
                if (!$ch['is_private']) return true;           // open to all analysts
                return warRoomIsMember($conn, $analystId, $channelId);
            case WARROOM_KIND_DM:
                return warRoomIsMember($conn, $analystId, $channelId);
        }
        return false;
    } catch (Throwable) {
        return false;                                          // fail closed
    }
}

/**
 * True when the analyst is a listed member of the channel - what decides access to a private channel or a DM.
 *
 * @param int $channelId  the warroom_channels id
 */
function warRoomIsMember(PDO $conn, int $analystId, int $channelId): bool
{
    $s = $conn->prepare("SELECT 1 FROM warroom_channel_members WHERE channel_id = :c AND analyst_id = :a LIMIT 1");
    $s->execute([':c' => $channelId, ':a' => $analystId]);
    return (bool) $s->fetchColumn();
}

/**
 * May this analyst POST here? Read access, minus archived.
 *
 * Archiving deliberately leaves a channel readable: the point of archiving an
 * incident channel is to stop it collecting new chatter, not to hide what was
 * said during the incident.
 *
 * @param int $channelId  the warroom_channels id
 */
function warRoomCanPostChannel(PDO $conn, int $analystId, int $channelId): bool
{
    if (!warRoomCanAccessChannel($conn, $analystId, $channelId)) return false;
    $ch = warRoomChannel($conn, $channelId);
    return $ch !== null && $ch['archived_datetime'] === null;
}

/**
 * Create a custom channel. Any analyst may — during an incident, needing an
 * administrator to make you a room is exactly the wrong dependency.
 *
 * @param string $name  the channel name; blank throws 'name_required'; spaces collapsed, cut to 120 characters
 * @param string $topic  an optional topic; blank stores NULL; cut to 255 characters
 * @param bool $isPrivate  true for a members-only channel
 * @param int[] $memberIds ignored unless $isPrivate; the creator is always added
 * @return int the new channel id
 * @throws InvalidArgumentException with a message safe to show a user
 */
function warRoomCreateChannel(PDO $conn, int $analystId, string $name, string $topic = '', bool $isPrivate = false, array $memberIds = []): int
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '')             throw new InvalidArgumentException('name_required');
    if (mb_strlen($name) > 120)   $name = mb_substr($name, 0, 120);
    $topic = mb_substr(trim($topic), 0, 255);

    // Names are not unique — two people naming a channel "Exchange" during the
    // same incident is a nuisance, not a corruption, and blocking the second one
    // mid-incident is worse than allowing it.
    $stmt = $conn->prepare(
        "INSERT INTO warroom_channels (kind, name, topic, is_private, created_by, created_datetime)
         VALUES ('" . WARROOM_KIND_CUSTOM . "', :n, :t, :p, :by, UTC_TIMESTAMP())"
    );
    $stmt->bindValue(':n', $name);
    $stmt->bindValue(':t', $topic === '' ? null : $topic);
    $stmt->bindValue(':p', $isPrivate ? 1 : 0, PDO::PARAM_INT);
    $stmt->bindValue(':by', $analystId, PDO::PARAM_INT);
    $stmt->execute();

    // ⚠️ Read the id BEFORE anything else touches the connection — see warRoomSend.
    $id = (int) $conn->lastInsertId();
    if ($id <= 0) throw new RuntimeException('insert_failed');

    // The creator is a member even of a public channel, so "my channels" and the
    // members count mean the same thing whichever kind it is.
    $ids = array_unique(array_merge([$analystId], $isPrivate ? array_map('intval', $memberIds) : []));
    warRoomAddMembers($conn, $id, $ids);
    return $id;
}

/**
 * Add analysts to a channel. Idempotent — re-inviting somebody is not an error.
 *
 * @param int $channelId  the warroom_channels id
 * @param array $analystIds  analysts.id values to add; ids of 0 or less and inactive analysts are skipped
 */
function warRoomAddMembers(PDO $conn, int $channelId, array $analystIds): void
{
    $stmt = $conn->prepare(
        "INSERT INTO warroom_channel_members (channel_id, analyst_id, created_datetime)
         SELECT :c, a.id, UTC_TIMESTAMP() FROM analysts a
          WHERE a.id = :a AND (a.is_active IS NULL OR a.is_active = 1)
            AND NOT EXISTS (SELECT 1 FROM warroom_channel_members m
                             WHERE m.channel_id = :c2 AND m.analyst_id = :a2)"
    );
    foreach ($analystIds as $aid) {
        $aid = (int) $aid;
        if ($aid <= 0) continue;
        $stmt->execute([':c' => $channelId, ':a' => $aid, ':c2' => $channelId, ':a2' => $aid]);
    }
}

/**
 * Archive or restore a custom channel. Team and all-hands channels cannot be
 * archived — they are derived, so the way to remove one is to remove the team.
 *
 * @param int $channelId  the warroom_channels id; only custom and DM channels change
 * @param bool $archived  true to archive, false to restore
 */
function warRoomSetArchived(PDO $conn, int $channelId, bool $archived): bool
{
    $stmt = $conn->prepare(
        "UPDATE warroom_channels
            SET archived_datetime = " . ($archived ? 'UTC_TIMESTAMP()' : 'NULL') . "
          WHERE id = :id AND kind IN ('" . WARROOM_KIND_CUSTOM . "','" . WARROOM_KIND_DM . "')"
    );
    $stmt->execute([':id' => $channelId]);
    return $stmt->rowCount() > 0;
}

/**
 * Rename / re-topic a custom channel. Team and DM names are derived, never stored.
 *
 * @param int $channelId  the warroom_channels id; only a custom channel changes
 * The caller has already checked the channel is custom and the analyst may manage it.
 *
 * @param string $name  the new name; cut to 120 characters
 * @param string $topic  the new topic; blank clears it; cut to 255 characters
 * @return bool  true once saved - also when nothing changed
 * @throws InvalidArgumentException 'name_required' when the name is blank
 */
function warRoomUpdateChannel(PDO $conn, int $channelId, string $name, string $topic): bool
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    // TRAP: throw, never return false - the endpoint answered success whatever this returned,
    // so a blank name was silently ignored and the dialog closed as if it had saved.
    if ($name === '') throw new InvalidArgumentException('name_required');
    $stmt = $conn->prepare(
        "UPDATE warroom_channels SET name = :n, topic = :t
          WHERE id = :id AND kind = '" . WARROOM_KIND_CUSTOM . "'"
    );
    $stmt->execute([
        ':n'  => mb_substr($name, 0, 120),
        ':t'  => ($topic = mb_substr(trim($topic), 0, 255)) === '' ? null : $topic,
        ':id' => $channelId,
    ]);
    return true;   // rowCount() is 0 when nothing changed, which is still a success
}

/**
 * Find or create the DM between two analysts.
 *
 * 🔑 The pair is normalised into `dm_key` ("<lower>:<higher>") and that column is
 * UNIQUE. Without it, two people opening a DM with each other at the same moment
 * get two conversations and each sees half of it — which is precisely the failure
 * you would hit during an incident, when both are trying to reach the other.
 *
 * @param int $otherId  the other analyst's id; must be active and not the caller
 */
function warRoomOpenDm(PDO $conn, int $analystId, int $otherId): int
{
    if ($otherId <= 0 || $otherId === $analystId) throw new InvalidArgumentException('bad_recipient');

    $s = $conn->prepare("SELECT 1 FROM analysts WHERE id = :id AND (is_active IS NULL OR is_active = 1) LIMIT 1");
    $s->execute([':id' => $otherId]);
    if (!$s->fetchColumn()) throw new InvalidArgumentException('bad_recipient');

    $key = min($analystId, $otherId) . ':' . max($analystId, $otherId);

    $find = $conn->prepare("SELECT id FROM warroom_channels WHERE dm_key = :k LIMIT 1");
    $find->execute([':k' => $key]);
    $existing = (int) $find->fetchColumn();
    if ($existing > 0) return $existing;

    try {
        $ins = $conn->prepare(
            "INSERT INTO warroom_channels (kind, dm_key, is_private, created_by, created_datetime)
             VALUES ('" . WARROOM_KIND_DM . "', :k, 1, :by, UTC_TIMESTAMP())"
        );
        $ins->execute([':k' => $key, ':by' => $analystId]);
        $id = (int) $conn->lastInsertId();
    } catch (PDOException) {
        // Lost the race against the other person opening the same DM: theirs is
        // now the one that exists, so use it.
        $find->execute([':k' => $key]);
        $id = (int) $find->fetchColumn();
    }
    if ($id <= 0) throw new RuntimeException('insert_failed');

    warRoomAddMembers($conn, $id, [$analystId, $otherId]);
    return $id;
}

/**
 * Analysts this person could start a DM with. Active accounts only — offering to
 * message somebody who left is a dead end you only discover after typing.
 */
function warRoomDirectory(PDO $conn, int $analystId): array
{
    $stmt = $conn->prepare(
        "SELECT a.id, a.full_name,
                (SELECT 1 FROM warroom_presence p
                  WHERE p.analyst_id = a.id
                    AND p.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :win SECOND)) AS here
           FROM analysts a
          WHERE a.id <> :me AND (a.is_active IS NULL OR a.is_active = 1)
          ORDER BY a.full_name"
    );
    $stmt->bindValue(':win', WARROOM_PRESENCE_WINDOW_SECONDS, PDO::PARAM_INT);
    $stmt->bindValue(':me', $analystId, PDO::PARAM_INT);
    $stmt->execute();

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int) $r['id'], 'name' => $r['full_name'], 'here' => (bool) $r['here']];
    }
    return $out;
}

/* ══════════════════════════════════════════════════════════════════════════
   MESSAGES
   ══════════════════════════════════════════════════════════════════════════ */

/**
 * Messages in a channel, oldest first, each with its attachments.
 *
 * $sinceId drives the poll: the page asks for everything newer than the last id
 * it holds, so a quiet channel costs one indexed lookup that returns nothing.
 * On the first load $sinceId is 0 and we return the tail rather than the lot.
 *
 * A NULL analyst_id is a deleted account, rendered as "Former analyst" rather
 * than blank or "Unknown" so the reader can tell WHICH case it is.
 *
 * @param int $channelId  the warroom_channels id; the caller has already checked access
 * @param int $sinceId  the newest message id the page holds; 0 for the first load (the latest $limit)
 * @param int $limit  the most messages to return, 1-500
 */
function warRoomMessages(PDO $conn, int $channelId, int $sinceId = 0, int $limit = 200, int $viewerId = 0, int $aroundId = 0, string $viewerLang = '', int $threadRootId = 0): array
{
    $limit = max(1, min(500, $limit));

    // Threads (5.9.0): the conversation shows top-level messages and the thread
    // replies also sent to it; a thread ($threadRootId) is its first message and
    // every reply. Both are this one function, so reactions, quotes, pins and
    // translations work the same in either.
    $hasThreads = warRoomHasCol($conn, 'thread_root_id') && warRoomHasCol($conn, 'also_in_channel');
    $scope = !$hasThreads ? ($threadRootId > 0 ? " AND m.id = " . $threadRootId : '')   // before Database Verification
        : ($threadRootId > 0
            ? " AND (m.id = " . $threadRootId . " OR m.thread_root_id = " . $threadRootId . ")"
            : " AND (m.thread_root_id IS NULL OR m.also_in_channel = 1)");

    // ⚠️ `m.id > :since` cannot see an EDIT or a DELETE — both change a message the
    // caller already holds, so its id is below the watermark and it never comes
    // back. The extra clause re-sends anything changed since the caller last
    // polled; the page replaces the row it already has by id. Without this an
    // edited message stays as it was on everyone else's screen, which in an
    // incident is the one kind of stale that actually misleads.
    // changed_datetime (5.9.0) is the same idea for a reaction: it changes a message
    // the reader already holds without touching its text.
    $hasChanged = warRoomHasCol($conn, 'changed_datetime');
    $changedClause = $sinceId > 0
        ? "OR ((m.edited_datetime IS NOT NULL OR m.deleted_datetime IS NOT NULL" . ($hasChanged ? " OR m.changed_datetime IS NOT NULL" : '') . ")
               AND GREATEST(COALESCE(m.edited_datetime, '1970-01-01'),
                            COALESCE(m.deleted_datetime, '1970-01-01')" . ($hasChanged ? ",
                            COALESCE(m.changed_datetime, '1970-01-01')" : '') . ") >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 SECOND))"
        : '';

    $cols = "m.id, m.body, m.created_datetime, m.analyst_id, a.full_name,
             m.edited_datetime, m.deleted_datetime, d.full_name AS deleted_by_name,
             m.is_bot, m.reply_to_id"
           . (warRoomHasCol($conn, 'quote_of_id') ? ", m.quote_of_id" : '')
           . (warRoomHasCol($conn, 'lang') ? ", m.lang" : '')
           . ($hasThreads ? ", m.thread_root_id, m.also_in_channel" : '')
           . (warRoomHasCol($conn, 'forwarded_from_id') ? ", m.forwarded_from_id, m.fwd_author, m.fwd_channel, m.fwd_created" : '');
    $joins = "LEFT JOIN analysts a ON a.id = m.analyst_id
              LEFT JOIN analysts d ON d.id = m.deleted_by";

    if ($aroundId > 0) {
        // A permanent link (5.9.0): a few messages before the linked one, then
        // everything after it, oldest first - the poll carries on from the newest.
        $start = $conn->prepare(
            "SELECT MIN(id) FROM (SELECT id FROM warroom_messages
                                    WHERE channel_id = :cid AND id <= :aid ORDER BY id DESC LIMIT 31) AS b"
        );
        $start->execute([':cid' => $channelId, ':aid' => $aroundId]);
        $from = (int) $start->fetchColumn();
        $sql = "SELECT $cols
                  FROM warroom_messages m
                  $joins
                 WHERE m.channel_id = :cid AND m.id >= " . max(1, $from) . $scope . "
                 ORDER BY m.id ASC
                 LIMIT 500";
    } elseif ($sinceId > 0) {
        $sql = "SELECT $cols
                  FROM warroom_messages m
                  $joins
                 WHERE m.channel_id = :cid AND (m.id > :since $changedClause)$scope
                 ORDER BY m.id ASC
                 LIMIT $limit";
    } else {
        // First load: the most recent $limit, flipped back into reading order.
        $sql = "SELECT * FROM (
                    SELECT $cols
                      FROM warroom_messages m
                      $joins
                     WHERE m.channel_id = :cid$scope
                     ORDER BY m.id DESC
                     LIMIT $limit
                ) AS recent ORDER BY id ASC";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':cid', $channelId, PDO::PARAM_INT);
    if ($sinceId > 0) $stmt->bindValue(':since', $sinceId, PDO::PARAM_INT);
    $stmt->execute();

    $rows = [];
    $ids  = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id    = (int) $r['id'];
        $ids[] = $id;
        $rows[$id] = [
            'id'          => $id,
            'body'        => $r['deleted_datetime'] !== null ? '' : $r['body'],
            // ⚠️ analyst_id NULL means TWO different things, and telling them
            // apart is what is_bot is for: a deleted account, or Warbot, which
            // never had one. Without the flag every Warbot message would render
            // as "Former analyst".
            'author'      => !empty($r['is_bot'])
                ? WARBOT_NAME
                : ($r['full_name'] !== null
                    ? $r['full_name']
                    : (function_exists('t') ? t('war-room.former_analyst') : 'Former analyst')),
            'is_bot'      => !empty($r['is_bot']),
            'reply_to'    => $r['reply_to_id'] !== null ? (int) $r['reply_to_id'] : null,
            'analyst_id'  => $r['analyst_id'] !== null ? (int) $r['analyst_id'] : null,
            'created'     => $r['created_datetime'],
            'edited'      => $r['edited_datetime'] !== null,
            'deleted'     => $r['deleted_datetime'] !== null,
            'deleted_by'  => $r['deleted_by_name'],
            'attachments' => [],
            'reactions'   => [],
            'files'       => [],      // 5.9.0: Files cards - links to files in the Files module
            'lang'        => $r['lang'] ?? null,   // 5.9.0: the language it is written in, once detected
            'tr'          => null,    // 5.9.0: a stored translation into the viewer's language
            'thread_root' => isset($r['thread_root_id']) && $r['thread_root_id'] !== null ? (int) $r['thread_root_id'] : null,
            'in_channel'  => !empty($r['also_in_channel']),
            'thread'      => null,    // 5.9.0: on a first message: {count, last} of its replies
            'forward'     => !empty($r['forwarded_from_id']) && $r['deleted_datetime'] === null ? [   // 5.9.0
                'from'    => (int) $r['forwarded_from_id'],
                'author'  => (string) $r['fwd_author'],
                'channel' => (string) $r['fwd_channel'],
                'created' => $r['fwd_created'],
            ] : null,
            'pinned'      => null,    // 5.9.0: {by, at} when pinned for everyone
            'saved'       => false,   // 5.9.0: the viewer's own bookmark
            'quote'       => ($r['quote_of_id'] ?? null) !== null && $r['deleted_datetime'] === null
                ? ['id' => (int) $r['quote_of_id'], 'gone' => true] : null,
        ];
    }
    if ($ids) {
        // One query for the whole page's attachments rather than one per message.
        $in = implode(',', array_map('intval', $ids));
        $at = $conn->query(
            "SELECT id, message_id, original_name, size_bytes
               FROM warroom_attachments WHERE message_id IN ($in) ORDER BY id"
        );
        foreach ($at->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $mid = (int) $a['message_id'];
            if (!isset($rows[$mid])) continue;
            $rows[$mid]['attachments'][] = [
                'id'     => (int) $a['id'],
                'name'   => $a['original_name'],
                'size'   => (int) $a['size_bytes'],
                // Whether the browser may render it is OUR decision, taken from
                // the extension against our own map — never from anything the
                // uploader supplied. See includes/uploads.php (security F5).
                'inline' => attachmentServeRules((string) $a['original_name'])['inline'],
            ];
        }
        warRoomAttachReactions($conn, $rows, $in, $viewerId);
        warRoomAttachQuotes($conn, $rows, $channelId);
        warRoomAttachPinsAndSaved($conn, $rows, $in, $viewerId);
        warRoomAttachThreadCounts($conn, $rows, $in);
        warRoomAttachFileLinks($conn, $rows, $in, $viewerId);
        if ($viewerLang !== '') warRoomAttachTranslations($conn, $rows, $in, $viewerLang);
    }
    return array_values($rows);
}

/**
 * Each message's reactions, in the order they were first given: emoji, how many,
 * whether the viewer gave it, and who (names, for the hover).
 *
 * @param array $rows  warRoomMessages()' rows by id - filled in place
 * @param string $in   the same ids as a comma list (already integers)
 * @param int $viewerId  the analyst looking, for 'mine'; 0 = nobody
 */
function warRoomAttachReactions(PDO $conn, array &$rows, string $in, int $viewerId): void
{
    try {
        $q = $conn->query(
            "SELECT r.message_id, r.emoji, r.analyst_id, a.full_name
               FROM warroom_reactions r LEFT JOIN analysts a ON a.id = r.analyst_id
              WHERE r.message_id IN ($in) ORDER BY r.id"
        );
    } catch (Throwable $e) {
        return;   // before Database Verification: no reactions yet, nothing broken
    }
    $by = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mid = (int) $r['message_id'];
        $e = (string) $r['emoji'];
        if (!isset($by[$mid][$e])) $by[$mid][$e] = ['emoji' => $e, 'count' => 0, 'mine' => false, 'names' => []];
        $by[$mid][$e]['count']++;
        if ((int) $r['analyst_id'] === $viewerId) $by[$mid][$e]['mine'] = true;
        $by[$mid][$e]['names'][] = $r['full_name'] ?? (function_exists('t') ? t('war-room.former_analyst') : 'Former analyst');
    }
    foreach ($by as $mid => $list) {
        if (isset($rows[$mid])) $rows[$mid]['reactions'] = array_values($list);
    }
}

/**
 * Fill in what each quoting message quotes: its author, time and the start of its
 * text - or that it was deleted, or that it is no longer there at all (retention).
 * Only a message in the SAME channel is ever shown: a quote is how a reply points
 * back up the conversation, never a way to read another channel.
 *
 * @param array $rows  warRoomMessages()' rows by id - filled in place
 * @param int $channelId  the channel being read
 */
function warRoomAttachQuotes(PDO $conn, array &$rows, int $channelId): void
{
    $ids = [];
    foreach ($rows as $r) if ($r['quote'] !== null) $ids[] = (int) $r['quote']['id'];
    if (!$ids) return;
    $in = implode(',', array_unique($ids));
    $q = $conn->query(
        "SELECT m.id, m.channel_id, m.body, m.created_datetime, m.is_bot, m.deleted_datetime, a.full_name
           FROM warroom_messages m LEFT JOIN analysts a ON a.id = m.analyst_id
          WHERE m.id IN ($in)"
    );
    $found = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $o) {
        if ((int) $o['channel_id'] !== $channelId) continue;
        $found[(int) $o['id']] = $o;
    }
    foreach ($rows as $id => $r) {
        if ($r['quote'] === null) continue;
        $o = $found[$r['quote']['id']] ?? null;
        if ($o === null) continue;   // stays 'gone': removed by retention
        $rows[$id]['quote'] = [
            'id'      => (int) $o['id'],
            'gone'    => false,
            'deleted' => $o['deleted_datetime'] !== null,
            'author'  => !empty($o['is_bot']) ? WARBOT_NAME
                : ($o['full_name'] ?? (function_exists('t') ? t('war-room.former_analyst') : 'Former analyst')),
            'created' => $o['created_datetime'],
            'text'    => $o['deleted_datetime'] !== null ? '' : mb_substr(trim((string) $o['body']), 0, 200),
        ];
    }
}

/**
 * The emoji a reaction may be: exactly the ones the picker offers
 * (assets/js/war-room-emoji.json, built by scripts/gen_warroom_emoji.php). Checked
 * against the list, not a pattern, so the server and the picker cannot disagree
 * and nothing but an emoji is ever stored.
 */
function warRoomEmojiAllowed(string $emoji): bool
{
    static $set = null;
    if ($set === null) {
        $set = [];
        $data = json_decode((string) @file_get_contents(__DIR__ . '/../assets/js/war-room-emoji.json'), true);
        foreach ((array) $data as $g) foreach ((array) ($g['emoji'] ?? []) as $e) $set[(string) $e[0]] = true;
    }
    return isset($set[$emoji]);
}

/**
 * Add or take away the analyst's reaction to a message. Anyone who can post in the
 * channel may react - a reaction is a small message. Stamps changed_datetime so the
 * poll re-sends the message to everybody already looking at it.
 *
 * @param int $messageId  the warroom_messages id
 * @param string $emoji   one emoji from the picker's list
 * @param bool $on        true = add, false = take away
 * @return bool  false for no such message, a deleted one, a channel the analyst cannot post in, or an unknown emoji
 */
function warRoomReact(PDO $conn, int $analystId, int $messageId, string $emoji, bool $on): bool
{
    if (!warRoomEmojiAllowed($emoji)) return false;
    $row = warRoomMessageRow($conn, $messageId);
    if ($row === null || $row['deleted_datetime'] !== null) return false;
    if (!warRoomCanPostChannel($conn, $analystId, (int) $row['channel_id'])) return false;

    if ($on) {
        $conn->prepare("INSERT IGNORE INTO warroom_reactions (message_id, analyst_id, emoji, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$messageId, $analystId, $emoji]);
    } else {
        $conn->prepare("DELETE FROM warroom_reactions WHERE message_id = ? AND analyst_id = ? AND emoji = ?")
             ->execute([$messageId, $analystId, $emoji]);
    }
    if (warRoomHasCol($conn, 'changed_datetime')) $conn->prepare("UPDATE warroom_messages SET changed_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$messageId]);
    return true;
}

/**
 * Where a permanent link points: the message's channel, if the analyst may read it.
 * 0 when the message is gone or out of reach - one answer for both, so a link
 * cannot be used to find out which messages exist in channels you cannot read.
 *
 * @param int $messageId  the id from ?message=
 */
function warRoomChannelOfMessage(PDO $conn, int $analystId, int $messageId): int
{
    $row = warRoomMessageRow($conn, $messageId);
    if ($row === null || $row['channel_id'] === null) return 0;
    return warRoomCanAccessChannel($conn, $analystId, (int) $row['channel_id']) ? (int) $row['channel_id'] : 0;
}

/**
 * On each first message of a thread: how many replies it has and when the last came.
 *
 * @param array $rows  warRoomMessages()' rows by id - filled in place
 * @param string $in   the same ids as a comma list (already integers)
 */
function warRoomAttachThreadCounts(PDO $conn, array &$rows, string $in): void
{
    try {
        $q = $conn->query(
            "SELECT thread_root_id, COUNT(*) AS n, MAX(created_datetime) AS last
               FROM warroom_messages
              WHERE thread_root_id IN ($in) AND deleted_datetime IS NULL
              GROUP BY thread_root_id"
        );
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $rid = (int) $t['thread_root_id'];
            if (isset($rows[$rid])) $rows[$rid]['thread'] = ['count' => (int) $t['n'], 'last' => $t['last']];
        }
    } catch (Throwable $e) {
        // before Database Verification
    }
}

/**
 * The first message of the thread a message is in, or 0 when it is not a thread reply.
 * Used to open the thread when a link, a pin, a saved message or a mention points at a reply.
 *
 * @param int $messageId  the warroom_messages id
 */
function warRoomThreadRootOf(PDO $conn, int $messageId): int
{
    $row = warRoomMessageRow($conn, $messageId);
    return $row !== null && !empty($row['thread_root_id']) ? (int) $row['thread_root_id'] : 0;
}

/**
 * Forward a message to another conversation (5.9.0). The text comes WITH it - the
 * readers there may not be able to read the original - under "Forwarded from <author>
 * in <channel>", kept as they were. An optional comment is posted first, as the
 * forwarder's own message. Files are copied (war room files never change, and a copy
 * means deleting a message still really destroys its files). @names in the forwarded
 * text do NOT notify anybody again.
 *
 * @param int $messageId   the original - the forwarder must be able to read it
 * @param int $toChannel   the destination - the forwarder must be able to post there
 * @param string $comment  optional, posted before the forward
 * @return int  the forward's id, or 0 when not allowed
 */
function warRoomForward(PDO $conn, int $analystId, int $messageId, int $toChannel, string $comment = ''): int
{
    if (!warRoomHasCol($conn, 'forwarded_from_id')) return 0;   // before Database Verification
    $o = warRoomMessageRow($conn, $messageId);
    if ($o === null || $o['deleted_datetime'] !== null) return 0;
    if (!warRoomCanAccessChannel($conn, $analystId, (int) $o['channel_id'])) return 0;
    if (!warRoomCanPostChannel($conn, $analystId, $toChannel)) return 0;

    if (trim($comment) !== '') warRoomSend($conn, $analystId, $toChannel, $comment);

    // Who and where, as the forwarder sees them (a DM is named from their side).
    $author = !empty($o['is_bot']) ? WARBOT_NAME : '';
    if ($author === '' && $o['analyst_id'] !== null) {
        $a = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
        $a->execute([(int) $o['analyst_id']]);
        $author = (string) $a->fetchColumn();
    }
    if ($author === '') $author = function_exists('t') ? t('war-room.former_analyst') : 'Former analyst';
    $where = '';
    foreach (warRoomChannelList($conn, $analystId, true) as $c) if ((int) $c['id'] === (int) $o['channel_id']) $where = (string) $c['name'];

    $body = trim((string) $o['body']) === '' ? ' ' : (string) $o['body'];
    $conn->prepare(
        "INSERT INTO warroom_messages (channel_id, analyst_id, body, created_datetime, forwarded_from_id, fwd_author, fwd_channel, fwd_created)
         VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?)"
    )->execute([$toChannel, $analystId, $body, $messageId, mb_substr($author, 0, 150), mb_substr($where, 0, 150), $o['created_datetime']]);
    $id = (int) $conn->lastInsertId();   // TRAP (§8): read before anything else touches the connection

    // Files cards are forwarded as the same links - never copies (Ed, 21 Nov 2026).
    try {
        $conn->prepare("INSERT INTO warroom_file_links (message_id, file_item_id, name, created_datetime)
                        SELECT ?, file_item_id, name, UTC_TIMESTAMP() FROM warroom_file_links WHERE message_id = ?")
             ->execute([$id, $messageId]);
    } catch (Throwable $e) { /* before Database Verification */ }

    // Copy the files, each under a new random name.
    $at = $conn->prepare("SELECT stored_name, original_name, size_bytes FROM warroom_attachments WHERE message_id = ?");
    $at->execute([$messageId]);
    $ins = $conn->prepare("INSERT INTO warroom_attachments (message_id, stored_name, original_name, size_bytes, created_datetime) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())");
    $dir = rtrim(WARROOM_ATTACH_DIR, '/\\') . DIRECTORY_SEPARATOR;
    foreach ($at->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $src = $dir . basename((string) $f['stored_name']);
        if (!is_file($src)) continue;
        $ext = pathinfo((string) $f['stored_name'], PATHINFO_EXTENSION);
        $new = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
        if (@copy($src, $dir . $new)) $ins->execute([$id, $new, $f['original_name'], (int) $f['size_bytes']]);
    }
    return $id;
}

/** Most Files cards on one message. */
const WARROOM_MAX_FILE_LINKS = 5;

/**
 * Put Files cards on a message (5.9.0): links to files the SENDER can at least view -
 * nobody can share a file they cannot see. Each keeps the file's name as it was, for
 * readers who cannot open it. Returns how many were added.
 *
 * @param int $messageId  the message just sent
 * @param int[] $itemIds  files_items ids from the Files picker
 */
function warRoomAddFileLinks(PDO $conn, int $analystId, int $messageId, array $itemIds): int
{
    $itemIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $itemIds), fn($i) => $i > 0))), 0, WARROOM_MAX_FILE_LINKS);
    if (!$itemIds) return 0;
    require_once __DIR__ . '/services/files_attach.php';
    $n = 0;
    try {
        $ins = $conn->prepare("INSERT INTO warroom_file_links (message_id, file_item_id, name, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())");
        foreach ($itemIds as $item) {
            $v = FilesAttach::viewFor($conn, $analystId, $item);
            if (!$v['access']) continue;
            $ins->execute([$messageId, $item, mb_substr((string) $v['title'], 0, 255)]);
            $n++;
        }
    } catch (Throwable $e) {
        // before Database Verification: the message is sent, without its cards
    }
    return $n;
}

/**
 * The Files cards on each message, as THIS viewer may see them (5.9.0): the live name,
 * size and type when they can open it in Files; the name as shared when they cannot;
 * "gone" when it has been deleted, binned or purged. The Files module decides, through
 * FilesAttach::viewFor() - module access and the folder's permission.
 *
 * @param array $rows  warRoomMessages()' rows by id - filled in place
 * @param string $in   the same ids as a comma list (already integers)
 */
function warRoomAttachFileLinks(PDO $conn, array &$rows, string $in, int $viewerId): void
{
    try {
        $q = $conn->query("SELECT message_id, file_item_id, name FROM warroom_file_links WHERE message_id IN ($in) ORDER BY id");
        $links = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;   // before Database Verification
    }
    if (!$links) return;
    require_once __DIR__ . '/services/files_attach.php';
    $seen = [];
    $meta = $conn->prepare("SELECT size_bytes, mime_type FROM files_items WHERE id = ?");
    foreach ($links as $l) {
        $mid = (int) $l['message_id'];
        if (!isset($rows[$mid]) || $rows[$mid]['deleted']) continue;
        $item = (int) $l['file_item_id'];
        if (!isset($seen[$item])) {
            $v = $viewerId > 0 ? FilesAttach::viewFor($conn, $viewerId, $item) : ['access' => false, 'title' => null, 'gone' => false];
            $size = null; $mime = null;
            if ($v['access']) { $meta->execute([$item]); $m = $meta->fetch(PDO::FETCH_ASSOC) ?: []; $size = isset($m['size_bytes']) ? (int) $m['size_bytes'] : null; $mime = $m['mime_type'] ?? null; }
            $seen[$item] = ['access' => (bool) $v['access'], 'gone' => !empty($v['gone']), 'name' => $v['access'] ? (string) $v['title'] : null, 'size' => $size, 'mime' => $mime];
        }
        $s = $seen[$item];
        $rows[$mid]['files'][] = [
            'item_id' => $item,
            'name'    => $s['name'] ?? (string) $l['name'],
            'access'  => $s['access'],
            'gone'    => $s['gone'],
            'size'    => $s['size'],
        ];
    }
}

/**
 * Whether Seen by is on: War room -> Settings. On unless an administrator turned it off.
 *
 * @param bool $fresh  read the setting again rather than this request's cached answer (tests)
 */
function warRoomSeenByEnabled(PDO $conn, bool $fresh = false): bool
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'warroom_seen_by' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        return $cache = ($v === false || (string) $v !== '0');
    } catch (Throwable $e) {
        return $cache = false;
    }
}

/**
 * Who has read a message, and when: the first time each person's read marker passed
 * it. The author is left out. For a small conversation, who has not read it yet too.
 * A reply that lives only in a thread has no answer - reading the conversation does
 * not mean reading the thread.
 *
 * @param int $messageId  checked here: the analyst must be able to read its channel
 * @return array|null  {seen: [{name, at}], not_yet: [names] | null}, or null when not available
 */
function warRoomSeenBy(PDO $conn, int $analystId, int $messageId): ?array
{
    if (!warRoomSeenByEnabled($conn)) return null;
    $m = warRoomMessageRow($conn, $messageId);
    if ($m === null || $m['deleted_datetime'] !== null) return null;
    $channelId = (int) $m['channel_id'];
    if (!warRoomCanAccessChannel($conn, $analystId, $channelId)) return null;
    if (!empty($m['thread_root_id']) && empty($m['also_in_channel'])) return null;

    $st = $conn->prepare(
        "SELECT rm.analyst_id, a.full_name, MIN(rm.read_datetime) AS at
           FROM warroom_read_marks rm JOIN analysts a ON a.id = rm.analyst_id
          WHERE rm.channel_id = ? AND rm.up_to_message_id >= ?
          GROUP BY rm.analyst_id, a.full_name
          ORDER BY at"
    );
    $st->execute([$channelId, $messageId]);
    $author = $m['analyst_id'] !== null ? (int) $m['analyst_id'] : 0;
    $seen = []; $seenIds = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int) $r['analyst_id'] === $author) continue;
        $seen[] = ['name' => $r['full_name'], 'at' => $r['at']];
        $seenIds[(int) $r['analyst_id']] = true;
    }
    $notYet = null;
    $aud = warRoomChannelAudience($conn, $channelId);
    if (count($aud) <= 30) {
        $notYet = [];
        foreach ($aud as $pid => $name) {   // warRoomChannelAudience(): [analyst id => full name]
            if ((int) $pid === $author || isset($seenIds[(int) $pid])) continue;
            $notYet[] = (string) $name;
        }
        sort($notYet);
    }
    return ['seen' => $seen, 'not_yet' => $notYet];
}

/**
 * Whether each message is pinned (and by whom, when) and whether the viewer saved it.
 *
 * @param array $rows  warRoomMessages()' rows by id - filled in place
 * @param string $in   the same ids as a comma list (already integers)
 * @param int $viewerId  the analyst looking, for 'saved'
 */
function warRoomAttachPinsAndSaved(PDO $conn, array &$rows, string $in, int $viewerId): void
{
    try {
        $q = $conn->query(
            "SELECT p.message_id, p.pinned_datetime, a.full_name
               FROM warroom_pins p LEFT JOIN analysts a ON a.id = p.pinned_by
              WHERE p.message_id IN ($in)"
        );
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $mid = (int) $p['message_id'];
            if (isset($rows[$mid])) $rows[$mid]['pinned'] = [
                'by' => $p['full_name'] ?? (function_exists('t') ? t('war-room.former_analyst') : 'Former analyst'),
                'at' => $p['pinned_datetime'],
            ];
        }
        if ($viewerId > 0) {
            $s = $conn->prepare("SELECT message_id FROM warroom_saved WHERE analyst_id = ? AND message_id IN ($in)");
            $s->execute([$viewerId]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                if (isset($rows[(int) $mid])) $rows[(int) $mid]['saved'] = true;
            }
        }
    } catch (Throwable $e) {
        // before Database Verification: nothing pinned or saved yet
    }
}

/**
 * Pin a message for everyone in the conversation, or unpin it. Anyone who can post
 * there may do either - who pinned it is shown. Stamps changed_datetime so the poll
 * re-sends the message to everybody looking at it.
 *
 * @param int $messageId  the warroom_messages id
 * @param bool $on  true = pin, false = unpin
 * @return string 'ok', 'full' (WARROOM_MAX_PINS already), or 'no' (no such / deleted message, cannot post)
 */
function warRoomPin(PDO $conn, int $analystId, int $messageId, bool $on): string
{
    $row = warRoomMessageRow($conn, $messageId);
    if ($row === null || $row['deleted_datetime'] !== null) return 'no';
    $channelId = (int) $row['channel_id'];
    if (!warRoomCanPostChannel($conn, $analystId, $channelId)) return 'no';

    if ($on) {
        $have = $conn->prepare("SELECT COUNT(*) FROM warroom_pins WHERE channel_id = ? AND message_id <> ?");
        $have->execute([$channelId, $messageId]);
        if ((int) $have->fetchColumn() >= WARROOM_MAX_PINS) return 'full';
        $conn->prepare("INSERT IGNORE INTO warroom_pins (channel_id, message_id, pinned_by, pinned_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$channelId, $messageId, $analystId]);
    } else {
        $conn->prepare("DELETE FROM warroom_pins WHERE message_id = ?")->execute([$messageId]);
    }
    if (warRoomHasCol($conn, 'changed_datetime')) $conn->prepare("UPDATE warroom_messages SET changed_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$messageId]);
    return 'ok';
}

/** How many messages are pinned in a channel - the poll sends it for the pinned bar. */
function warRoomPinCount(PDO $conn, int $channelId): int
{
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM warroom_pins WHERE channel_id = ?");
        $st->execute([$channelId]);
        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * The pinned messages of a channel, newest pin first, for the pinned list.
 *
 * @param int $channelId  checked by the caller (warRoomCanAccessChannel)
 * @return array<int,array> id, author, created, text (the start), by, at
 */
function warRoomPins(PDO $conn, int $channelId): array
{
    $st = $conn->prepare(
        "SELECT m.id, m.body, m.created_datetime, m.is_bot, a.full_name, pb.full_name AS pinned_by_name, p.pinned_datetime
           FROM warroom_pins p
           JOIN warroom_messages m ON m.id = p.message_id
           LEFT JOIN analysts a ON a.id = m.analyst_id
           LEFT JOIN analysts pb ON pb.id = p.pinned_by
          WHERE p.channel_id = ? AND m.deleted_datetime IS NULL
          ORDER BY p.id DESC"
    );
    $st->execute([$channelId]);
    $former = function_exists('t') ? t('war-room.former_analyst') : 'Former analyst';
    return array_map(fn($r) => [
        'id'      => (int) $r['id'],
        'author'  => !empty($r['is_bot']) ? WARBOT_NAME : ($r['full_name'] ?? $former),
        'created' => $r['created_datetime'],
        'text'    => mb_substr(trim((string) $r['body']), 0, 200),
        'by'      => $r['pinned_by_name'] ?? $former,
        'at'      => $r['pinned_datetime'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Save a message to the analyst's own list, or take it off. Saving needs only that
 * they can READ the conversation - an archived channel's messages can be saved.
 *
 * @param int $messageId  the warroom_messages id
 * @param bool $on  true = save, false = remove
 */
function warRoomSave(PDO $conn, int $analystId, int $messageId, bool $on): bool
{
    $row = warRoomMessageRow($conn, $messageId);
    if ($row === null || $row['deleted_datetime'] !== null) return false;
    if (!warRoomCanAccessChannel($conn, $analystId, (int) $row['channel_id'])) return false;
    if ($on) {
        $conn->prepare("INSERT IGNORE INTO warroom_saved (analyst_id, message_id, saved_datetime) VALUES (?, ?, UTC_TIMESTAMP())")
             ->execute([$analystId, $messageId]);
    } else {
        $conn->prepare("DELETE FROM warroom_saved WHERE analyst_id = ? AND message_id = ?")->execute([$analystId, $messageId]);
    }
    return true;
}

/**
 * The analyst's saved messages, newest saved first - only from conversations they can
 * still read. Leaving a private channel hides what was saved from it, rather than
 * keeping a way in.
 *
 * @return array<int,array> id, channel_id, channel, author, created, text, saved
 */
function warRoomSavedList(PDO $conn, int $analystId, int $limit = 200): array
{
    $st = $conn->prepare(
        "SELECT m.id, m.channel_id, m.body, m.created_datetime, m.is_bot, a.full_name, s.saved_datetime
           FROM warroom_saved s
           JOIN warroom_messages m ON m.id = s.message_id
           LEFT JOIN analysts a ON a.id = m.analyst_id
          WHERE s.analyst_id = ? AND m.deleted_datetime IS NULL
          ORDER BY s.id DESC
          LIMIT " . max(1, min(500, $limit))
    );
    $st->execute([$analystId]);
    $former = function_exists('t') ? t('war-room.former_analyst') : 'Former analyst';
    // The reader's own channel list (archived included) names each channel from their
    // side - a DM is "Sarah", not "Ed, Sarah" - and is exactly the set they can read.
    $names = [];
    foreach (warRoomChannelList($conn, $analystId, true) as $c) $names[(int) $c['id']] = $c['name'];
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int) $r['channel_id'];
        if (!isset($names[$cid])) continue;
        $out[] = [
            'id'         => (int) $r['id'],
            'channel_id' => $cid,
            'channel'    => $names[$cid],
            'author'     => !empty($r['is_bot']) ? WARBOT_NAME : ($r['full_name'] ?? $former),
            'created'    => $r['created_datetime'],
            'text'       => mb_substr(trim((string) $r['body']), 0, 200),
            'saved'      => $r['saved_datetime'],
        ];
    }
    return $out;
}

/**
 * Mark a message as unread: the analyst's read marker for its channel moves back to
 * just before it, so the channel shows as unread from there. The one place the marker
 * moves backwards - warRoomMarkRead() only ever moves it forward.
 *
 * @param int $messageId  the first message to show as unread
 */
function warRoomMarkUnread(PDO $conn, int $analystId, int $messageId): bool
{
    $row = warRoomMessageRow($conn, $messageId);
    if ($row === null) return false;
    $channelId = (int) $row['channel_id'];
    if (!warRoomCanAccessChannel($conn, $analystId, $channelId)) return false;
    $prev = $conn->prepare("SELECT COALESCE(MAX(id), 0) FROM warroom_messages WHERE channel_id = ? AND id < ?");
    $prev->execute([$channelId, $messageId]);
    $to = (int) $prev->fetchColumn();
    $conn->prepare(
        "INSERT INTO warroom_reads (analyst_id, channel_id, last_read_message_id, updated_datetime)
         VALUES (?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE last_read_message_id = VALUES(last_read_message_id), updated_datetime = VALUES(updated_datetime)"
    )->execute([$analystId, $channelId, $to]);
    return true;
}

/* ── Translation (5.9.0) - wiki War-Room-Message-Actions-Design ─────────────────
   Every message is stored as typed; each reader can see it in their own FreeITSM
   language. A translation is stored once per message per LANGUAGE (not per person)
   and a message's own language is detected once, so nothing is translated twice
   and an English message is never sent anywhere for an English reader. Off unless
   an administrator switches it on (War room -> Settings -> AI), because it sends
   message text to the AI provider. Without the provider, or the internet, readers
   simply see the originals - the chat never waits for it. */

/** True when an administrator has switched translation on AND an AI provider is set up. */
function warRoomTranslateEnabled(PDO $conn): bool
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'warroom_translate' LIMIT 1");
        $st->execute();
        if ((string) $st->fetchColumn() !== '1') return false;
        require_once __DIR__ . '/ai_settings.php';
        require_once __DIR__ . '/ai_provider.php';
        return aiConfigured(aiSettingsLoad($conn, 'warroom_ai'));
    } catch (Throwable $e) {
        return false;
    }
}

/** The primary language subtag, lower-case: 'pt-BR' -> 'pt', 'FR' -> 'fr'. */
function warRoomLangKey(?string $code): string
{
    return strtolower(explode('-', str_replace('_', '-', trim((string) $code)))[0]);
}

/** Drop a message's translations and its detected language - after an edit or a delete. */
function warRoomForgetTranslations(PDO $conn, int $messageId): void
{
    try {
        $conn->prepare("DELETE FROM warroom_translations WHERE message_id = ?")->execute([$messageId]);
        $conn->prepare("UPDATE warroom_messages SET lang = NULL WHERE id = ?")->execute([$messageId]);
    } catch (Throwable $e) {
        // before Database Verification: nothing stored yet
    }
}

/**
 * Put each message's stored translation into the viewer's language on its row.
 *
 * @param array $rows  warRoomMessages()' rows by id - filled in place
 * @param string $in   the same ids as a comma list (already integers)
 * @param string $lang the viewer's FreeITSM language, e.g. 'de'
 */
function warRoomAttachTranslations(PDO $conn, array &$rows, string $in, string $lang): void
{
    try {
        $st = $conn->prepare("SELECT message_id, body FROM warroom_translations WHERE lang = ? AND message_id IN ($in)");
        $st->execute([warRoomLangKey($lang)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $mid = (int) $t['message_id'];
            if (isset($rows[$mid]) && !$rows[$mid]['deleted']) $rows[$mid]['tr'] = $t['body'];
        }
    } catch (Throwable $e) {
        // before Database Verification
    }
}

/**
 * Translate messages of one channel into a language, using and filling the store.
 *
 * Returns, for each message asked about that has text: ['lang' => its language,
 * 'text' => the translation, or null when it is already in that language]. Messages
 * it could not translate (the provider failed) are left out, so the page keeps the
 * original. Two readers asking at once for the same language wait on one lock, so
 * the second finds the first one's translations instead of paying again.
 *
 * @param int $channelId  checked here: the analyst must be able to read it
 * @param int[] $ids      message ids in that channel (at most 60 are looked at)
 * @param string $target  the reader's FreeITSM language, e.g. 'de'
 * @throws RuntimeException  when the AI provider cannot be reached
 */
function warRoomTranslate(PDO $conn, int $analystId, int $channelId, array $ids, string $target): array
{
    if (!warRoomCanAccessChannel($conn, $analystId, $channelId)) return [];
    $want = warRoomLangKey($target);
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0))), 0, 60);
    if (!$ids || $want === '') return [];

    /** The messages still to do, and the answers so far. */
    $load = function () use ($conn, $channelId, $ids, $want): array {
        $in = implode(',', $ids);
        $st = $conn->prepare(
            "SELECT m.id, m.body, m.lang, t.body AS tr
               FROM warroom_messages m
               LEFT JOIN warroom_translations t ON t.message_id = m.id AND t.lang = ?
              WHERE m.channel_id = ? AND m.id IN ($in) AND m.deleted_datetime IS NULL AND TRIM(m.body) <> ''"
        );
        $st->execute([$want, $channelId]);
        $done = []; $todo = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            if ($r['lang'] !== null && warRoomLangKey($r['lang']) === $want) $done[$id] = ['lang' => $r['lang'], 'text' => null];
            elseif ($r['tr'] !== null) $done[$id] = ['lang' => $r['lang'], 'text' => $r['tr']];
            else $todo[$id] = (string) $r['body'];
        }
        return [$done, $todo];
    };

    [$done, $todo] = $load();
    if (!$todo) return $done;

    $lock = 'wr_tr_' . $channelId . '_' . $want;
    $locked = (int) $conn->query("SELECT GET_LOCK(" . $conn->quote($lock) . ", 25)")->fetchColumn() === 1;
    try {
        if ($locked) [$done, $todo] = $load();   // somebody else may have just done them
        if (!$todo) return $done;

        require_once __DIR__ . '/ai_settings.php';
        require_once __DIR__ . '/ai_provider.php';
        $cfg = aiSettingsLoad($conn, 'warroom_ai');
        $setLang = $conn->prepare("UPDATE warroom_messages SET lang = ? WHERE id = ? AND lang IS NULL");
        $save = $conn->prepare("INSERT IGNORE INTO warroom_translations (message_id, lang, body, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())");

        foreach (array_chunk($todo, 20, true) as $batch) {
            foreach (warRoomTranslateBatch($cfg, $want, $batch) as $id => $res) {
                $src = $res['lang'];
                if ($src !== '') $setLang->execute([$src, $id]);
                if ($res['same'] || ($src !== '' && warRoomLangKey($src) === $want)) {
                    $done[$id] = ['lang' => $src ?: $want, 'text' => null];
                } elseif ($res['text'] !== '') {
                    $save->execute([$id, $want, $res['text']]);
                    $done[$id] = ['lang' => $src, 'text' => $res['text']];
                }
            }
        }
        return $done;
    } finally {
        if ($locked) $conn->query("SELECT RELEASE_LOCK(" . $conn->quote($lock) . ")");
    }
}

/**
 * One call to the AI provider: detect each message's language and translate the
 * ones that are not already in the target language.
 *
 * @param array $cfg     aiSettingsLoad($conn, 'warroom_ai')
 * @param string $want   the target language key, e.g. 'de'
 * @param array $batch   [message id => original text], at most 20
 * @return array [id => ['lang' => detected code, 'same' => bool, 'text' => translation or '']]
 */
function warRoomTranslateBatch(array $cfg, string $want, array $batch): array
{
    $name = class_exists('I18n') ? (I18n::getSupportedLocales()[$want] ?? '') : '';
    $items = [];
    $chars = 0;
    foreach ($batch as $id => $text) { $items[] = ['id' => $id, 'text' => $text]; $chars += mb_strlen($text); }
    $result = aiProviderChat($cfg, [
        'system' => 'You translate short messages from an IT team\'s incident chat. Reply with JSON only - no commentary, no code fences.',
        'user'   => warRoomTranslatePrompt($want, $name, $items),
        // Room for every translation, plus any thinking the model does first.
        'max_tokens' => min(16000, 1500 + $chars * 2),
    ]);
    $raw = (string) ($result['content'] ?? '');
    $a = strpos($raw, '['); $b = strrpos($raw, ']');
    $list = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
    $out = [];
    foreach ((array) $list as $r) {
        $id = (int) ($r['id'] ?? 0);
        if (!isset($batch[$id])) continue;   // only the messages we asked about
        $lang = preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i', (string) ($r['lang'] ?? '')) ? strtolower((string) $r['lang']) : '';
        $out[$id] = [
            'lang' => $lang,
            'same' => !empty($r['same']),
            'text' => mb_substr(trim((string) ($r['text'] ?? '')), 0, WARROOM_MAX_BODY * 2),
        ];
    }
    return $out;
}

/**
 * The translation request. Names, addresses and codes are kept as written - in an
 * incident chat "@Sarah restart srv-db01 (INC-1042)" must not come back as anything else.
 *
 * @param string $want  the target language code, e.g. 'de'
 * @param string $name  its own name, e.g. 'Deutsch' - helps the model
 * @param array $items  [['id' => int, 'text' => string], ...]
 */
function warRoomTranslatePrompt(string $want, string $name, array $items): string
{
    return "Target language: {$want}" . ($name !== '' ? " ({$name})" : '') . ".\n\n"
        . "For each message below:\n"
        . "- Detect the language it is written in, as an ISO 639-1 code (e.g. en, fr, de, es).\n"
        . "- If it is already in the target language, return \"same\": true and no text.\n"
        . "- Otherwise translate it into the target language, naturally, as a colleague would write it.\n\n"
        . "Keep exactly as written: @mentions and people's names, server and host names, IP addresses, URLs, "
        . "email addresses, file paths, ticket and change references (like INC-1042 or CHG-0037), error codes, "
        . "commands and anything that looks like code, numbers, and emoji. Keep line breaks. Do not add, explain or answer anything.\n\n"
        . "Reply with only a JSON array, one object per message, in the same order:\n"
        . "[{\"id\": 1, \"lang\": \"fr\", \"same\": false, \"text\": \"...\"}]\n\n"
        . "Messages:\n" . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Edit a message. The AUTHOR only — nobody else may put words in your mouth, not
 * even an administrator, who can delete but not rewrite.
 *
 * The edit is stamped rather than silent: this is the record of an incident, and a
 * message that changed after the fact has to say so. Mentions are re-resolved,
 * because an edit can add or remove a name.
 *
 * @param int $messageId  the warroom_messages id; only its author may edit it
 * @param string $body  the new text; blank changes nothing; cut to WARROOM_MAX_BODY
 */
function warRoomEditMessage(PDO $conn, int $analystId, int $messageId, string $body): bool
{
    $body = trim($body);
    if ($body === '') return false;
    if (mb_strlen($body) > WARROOM_MAX_BODY) $body = mb_substr($body, 0, WARROOM_MAX_BODY);

    $row = warRoomMessageRow($conn, $messageId);
    if ($row === null || $row['deleted_datetime'] !== null) return false;
    if ((int) $row['analyst_id'] !== $analystId) return false;
    if (!warRoomCanPostChannel($conn, $analystId, (int) $row['channel_id'])) return false;

    $stmt = $conn->prepare(
        "UPDATE warroom_messages SET body = :b, edited_datetime = UTC_TIMESTAMP()
          WHERE id = :id AND deleted_datetime IS NULL"
    );
    $stmt->execute([':b' => $body, ':id' => $messageId]);
    warRoomForgetTranslations($conn, $messageId);   // 5.9.0: the old translations say the old thing

    // Re-resolve from the NEW text. A name removed by the edit should stop being a
    // mention; one added should start being one.
    $conn->prepare("DELETE FROM warroom_mentions WHERE message_id = :m")->execute([':m' => $messageId]);
    warRoomStoreMentions($conn, $messageId, warRoomResolveMentions($conn, (int) $row['channel_id'], $analystId, $body));
    return true;
}

/**
 * Delete a message: the author, or somebody with war_room.manage.
 *
 * 🔑 THE CONTENT GOES, THE ROW STAYS. The body is overwritten and the attachments
 * are destroyed on disk — so a mistakenly pasted password really is gone — but a
 * tombstone remains saying who removed it and when. A silent gap in an incident
 * transcript is worse than a visible one: the reader cannot tell whether something
 * was removed or never said, and that is exactly the question a post-incident
 * review asks.
 *
 * @param int $messageId  the warroom_messages id
 * @param bool $mayManage  true when the caller holds war_room.manage and may delete anyone's message
 */
function warRoomDeleteMessage(PDO $conn, int $analystId, int $messageId, bool $mayManage = false): bool
{
    $row = warRoomMessageRow($conn, $messageId);
    if ($row === null || $row['deleted_datetime'] !== null) return false;
    if (!$mayManage && (int) $row['analyst_id'] !== $analystId) return false;
    if (!warRoomCanAccessChannel($conn, $analystId, (int) $row['channel_id'])) return false;

    // Files first: once the rows are gone the paths are unrecoverable, and the
    // hourly sweep would only tidy them up later.
    $at = $conn->prepare("SELECT stored_name FROM warroom_attachments WHERE message_id = :m");
    $at->execute([':m' => $messageId]);
    foreach ($at->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $path = rtrim(WARROOM_ATTACH_DIR, '/\\') . DIRECTORY_SEPARATOR . $a['stored_name'];
        if (is_file($path)) @unlink($path);
    }
    $conn->prepare("DELETE FROM warroom_attachments WHERE message_id = :m")->execute([':m' => $messageId]);
    $conn->prepare("DELETE FROM warroom_mentions WHERE message_id = :m")->execute([':m' => $messageId]);
    // Its content is gone, so a pin or a bookmark would point at a tombstone (5.9.0).
    warRoomForgetTranslations($conn, $messageId);
    try { $conn->prepare("DELETE FROM warroom_file_links WHERE message_id = ?")->execute([$messageId]); } catch (Throwable $e) {}
    try {
        $conn->prepare("DELETE FROM warroom_pins WHERE message_id = :m")->execute([':m' => $messageId]);
        $conn->prepare("DELETE FROM warroom_saved WHERE message_id = :m")->execute([':m' => $messageId]);
    } catch (Throwable $e) { /* before Database Verification */ }

    $stmt = $conn->prepare(
        "UPDATE warroom_messages
            SET body = '', deleted_datetime = UTC_TIMESTAMP(), deleted_by = :by
          WHERE id = :id"
    );
    $stmt->execute([':by' => $analystId, ':id' => $messageId]);
    return true;
}

/**
 * One message row, raw. Callers do their own access checks.
 *
 * @param int $messageId  the warroom_messages id
 */
function warRoomMessageRow(PDO $conn, int $messageId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM warroom_messages WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Post a message. Returns the new id, or 0 if the body was empty.
 *
 * @param int $channelId  the warroom_channels id; the caller has already checked it may post there
 * @param string $body  the message text as typed; blank posts nothing; cut to WARROOM_MAX_BODY
 */
function warRoomSend(PDO $conn, int $analystId, int $channelId, string $body, int $quoteOfId = 0, int $threadRootId = 0, bool $alsoInChannel = false): int
{
    $body = trim($body);
    if ($body === '') return 0;
    if (mb_strlen($body) > WARROOM_MAX_BODY) $body = mb_substr($body, 0, WARROOM_MAX_BODY);

    // A quote (5.9.0) must be a live message in THIS channel; anything else is
    // dropped, not refused - the reply itself is what matters.
    $quote = null;
    if ($quoteOfId > 0 && warRoomHasCol($conn, 'quote_of_id')) {
        $q = warRoomMessageRow($conn, $quoteOfId);
        if ($q !== null && (int) $q['channel_id'] === $channelId && $q['deleted_datetime'] === null) $quote = $quoteOfId;
    }

    // A thread reply (5.9.0) hangs off a live message in THIS channel. Replying to a
    // reply joins its thread - one level, as Slack and Teams do. Anything else is
    // posted as an ordinary message rather than refused.
    $root = null;
    if ($threadRootId > 0 && warRoomHasCol($conn, 'thread_root_id')) {
        $r = warRoomMessageRow($conn, $threadRootId);
        if ($r !== null && !empty($r['thread_root_id'])) $r = warRoomMessageRow($conn, (int) $r['thread_root_id']);
        if ($r !== null && (int) $r['channel_id'] === $channelId && $r['deleted_datetime'] === null) $root = (int) $r['id'];
    }

    $stmt = $conn->prepare(
        "INSERT INTO warroom_messages (channel_id, analyst_id, body, created_datetime"
        . ($quote !== null ? ", quote_of_id" : "") . ($root !== null ? ", thread_root_id, also_in_channel" : "") . ")
         VALUES (:cid, :aid, :body, UTC_TIMESTAMP()" . ($quote !== null ? ", :q" : "") . ($root !== null ? ", :root, :also" : "") . ")"
    );
    $stmt->bindValue(':cid', $channelId, PDO::PARAM_INT);
    $stmt->bindValue(':aid', $analystId, PDO::PARAM_INT);
    $stmt->bindValue(':body', $body);
    if ($quote !== null) $stmt->bindValue(':q', $quote, PDO::PARAM_INT);
    if ($root !== null) { $stmt->bindValue(':root', $root, PDO::PARAM_INT); $stmt->bindValue(':also', $alsoInChannel ? 1 : 0, PDO::PARAM_INT); }
    $stmt->execute();

    // ⚠️ Read the id BEFORE anything else touches the connection. MySQL resets
    // the insert id on any statement that doesn't generate one, so the SELECT
    // inside warRoomPrune() below would turn this into 0 — the message saves
    // fine and the caller is told it failed. Caught by testing the endpoint
    // rather than by reading the code, which looked perfectly reasonable.
    $id = (int) $conn->lastInsertId();

    // The first message's reply count changed, so everybody's poll re-sends it (5.9.0).
    // TRAP: after the id is read - this UPDATE would otherwise turn it into 0.
    if ($root !== null && warRoomHasCol($conn, 'changed_datetime')) {
        $conn->prepare("UPDATE warroom_messages SET changed_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$root]);
    }

    warRoomStoreMentions($conn, $id, warRoomResolveMentions($conn, $channelId, $analystId, $body));
    warRoomPrune($conn);
    return $id;
}

/* ══════════════════════════════════════════════════════════════════════════
   MENTIONS
   ══════════════════════════════════════════════════════════════════════════ */

/**
 * Work out who a message names, and record it.
 *
 * 🔑 RESOLVED FROM THE TEXT, SERVER-SIDE — the client sends no list of ids. That
 * is forced by how the composer is meant to work: you type `@`, pick a name, and
 * may then BACKSPACE down to just the first name. Anything the client resolved at
 * pick time is wrong the moment the text is edited, and a mention typed by hand
 * without ever touching the autocomplete would not resolve at all. The text is the
 * only thing that survives both, so the text is what we read.
 *
 * The body is stored EXACTLY AS TYPED. No `@[39]` tokens: the raw message has to
 * stay readable in search results and in the transcript the AI summarises, and a
 * stored token would leak into both.
 *
 * Matching is longest-first, so "@Sarah Williams" wins over "@Sarah". Where a bare
 * first name is genuinely ambiguous — two Sarahs — EVERY match is notified. During
 * an incident two people looking is a far better failure than nobody looking.
 *
 * ⚠️ Recipients are filtered to analysts who can actually READ the channel. Naming
 * somebody who cannot see a private channel would otherwise put its name and a
 * snippet of it into their notifications panel.
 *
 * @param int $channelId  the channel the message is in; only its readers can be mentioned
 * @param int $authorId  the analyst who wrote it, never notified of their own message
 * @param string $body  the message text as typed
 * @return int[] the analyst ids notified
 */
function warRoomResolveMentions(PDO $conn, int $channelId, int $authorId, string $body): array
{
    if (!str_contains($body, '@')) return [];

    $entitled = warRoomChannelAudience($conn, $channelId);
    if (!$entitled) return [];

    $hit = [];

    // `@everyone` — the amplifier an outage actually needs. There is no etiquette
    // problem to design around here the way a general chat tool has: this module
    // only exists during incidents.
    if (preg_match('/@everyone\b/iu', $body)) {
        $hit = array_keys($entitled);
    }

    // Candidate needles: full names first, then first names, longest first, so the
    // longest thing that matches at a given position wins.
    $needles = [];
    foreach ($entitled as $id => $name) {
        $needles[] = [mb_strtolower($name), $id];
        $first = mb_strtolower(preg_split('/\s+/u', trim($name))[0] ?? '');
        if ($first !== '' && $first !== mb_strtolower($name)) $needles[] = [$first, $id];
    }
    usort($needles, function ($a, $b) { return mb_strlen($b[0]) <=> mb_strlen($a[0]); });

    $lower = mb_strtolower($body);
    $len   = mb_strlen($lower);
    for ($i = 0; $i < $len; $i++) {
        if (mb_substr($lower, $i, 1) !== '@') continue;
        foreach ($needles as [$needle, $id]) {
            if (mb_substr($lower, $i + 1, mb_strlen($needle)) !== $needle) continue;
            // Must end at a word boundary, or "@Sam" would match inside "@Samantha"
            // and quietly notify the wrong person.
            $after = mb_substr($lower, $i + 1 + mb_strlen($needle), 1);
            if ($after !== '' && preg_match('/[\p{L}\p{N}]/u', $after)) continue;
            $hit[] = $id;
            break;                                  // longest match wins; stop here
        }
    }

    // You are never notified of your own message, even via @everyone.
    $hit = array_values(array_diff(array_unique(array_map('intval', $hit)), [$authorId]));
    return $hit;
}

/**
 * Every analyst who can read this channel, as id => full name.
 *
 * This is warRoomCanAccessChannel turned inside out, and the two must agree — a
 * name that resolves here but fails there would post a mention nobody can open.
 *
 * @param int $channelId  the warroom_channels id
 */
function warRoomChannelAudience(PDO $conn, int $channelId): array
{
    $ch = warRoomChannel($conn, $channelId);
    if ($ch === null) return [];

    switch ($ch['kind']) {
        case WARROOM_KIND_ALL:
            // Every analyst who can reach the module at all. Module access is not
            // resolvable in SQL here, so this is every active analyst; the panel
            // query re-checks channel access before showing anything.
            $sql = "SELECT id, full_name FROM analysts WHERE (is_active IS NULL OR is_active = 1)";
            $stmt = $conn->query($sql);
            break;
        case WARROOM_KIND_TEAM:
            $stmt = $conn->prepare(
                "SELECT a.id, a.full_name FROM analysts a
                   JOIN analyst_teams at ON at.analyst_id = a.id
                  WHERE at.team_id = :t AND (a.is_active IS NULL OR a.is_active = 1)"
            );
            $stmt->execute([':t' => (int) $ch['team_id']]);
            break;
        case WARROOM_KIND_CUSTOM:
            if (!$ch['is_private']) {
                $stmt = $conn->query("SELECT id, full_name FROM analysts WHERE (is_active IS NULL OR is_active = 1)");
                break;
            }
            // falls through to the member list
        default:
            $stmt = $conn->prepare(
                "SELECT a.id, a.full_name FROM analysts a
                   JOIN warroom_channel_members m ON m.analyst_id = a.id
                  WHERE m.channel_id = :c AND (a.is_active IS NULL OR a.is_active = 1)"
            );
            $stmt->execute([':c' => $channelId]);
    }

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int) $r['id']] = $r['full_name'];
    return $out;
}

/**
 * Record the mentions for a message. Idempotent via the UNIQUE on (message, analyst).
 *
 * @param int $messageId  the warroom_messages id
 * @param array $analystIds  the analysts.id values it names, from warRoomResolveMentions()
 */
function warRoomStoreMentions(PDO $conn, int $messageId, array $analystIds): void
{
    if (!$analystIds) return;
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO warroom_mentions (message_id, analyst_id, created_datetime)
         VALUES (:m, :a, UTC_TIMESTAMP())"
    );
    foreach ($analystIds as $id) $stmt->execute([':m' => $messageId, ':a' => (int) $id]);
}

/**
 * The notifications panel: messages naming this analyst that they have not read.
 *
 * "Unread" is derived from `warroom_reads`, not from a column here — so opening
 * the channel clears the mention, which is what a reader expects and what stops
 * the panel and the channel badge ever disagreeing.
 *
 * ⚠️ Channel access is re-checked in SQL, not assumed from the mention row. A
 * mention written before somebody was removed from a private channel must stop
 * being visible to them, not linger in their panel with a snippet attached.
 *
 * @param int $limit  the most to return, 1-50
 */
function warRoomMyMentions(PDO $conn, int $analystId, int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    $sql = "
        SELECT m.id, m.channel_id, m.body, m.created_datetime, a.full_name,
               c.kind, c.name, t.name AS team_name,
               (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ')
                  FROM warroom_channel_members m2
                  JOIN analysts a2 ON a2.id = m2.analyst_id
                 WHERE m2.channel_id = c.id AND m2.analyst_id <> :me4) AS other_members
          FROM warroom_mentions wm
          JOIN warroom_messages m ON m.id = wm.message_id
          JOIN warroom_channels c ON c.id = m.channel_id
          LEFT JOIN teams t     ON t.id = c.team_id
          LEFT JOIN analysts a  ON a.id = m.analyst_id
          LEFT JOIN warroom_reads r ON r.channel_id = m.channel_id AND r.analyst_id = :me5
         WHERE wm.analyst_id = :me1
           AND m.id > COALESCE(r.last_read_message_id, 0)
           AND (
                   c.kind = '" . WARROOM_KIND_ALL . "'
                OR (c.kind = '" . WARROOM_KIND_TEAM . "' AND EXISTS (
                      SELECT 1 FROM analyst_teams at
                       WHERE at.team_id = c.team_id AND at.analyst_id = :me2))
                OR (c.kind = '" . WARROOM_KIND_CUSTOM . "' AND c.is_private = 0)
                OR (c.kind IN ('" . WARROOM_KIND_CUSTOM . "','" . WARROOM_KIND_DM . "') AND EXISTS (
                      SELECT 1 FROM warroom_channel_members mm
                       WHERE mm.channel_id = c.id AND mm.analyst_id = :me3))
               )
         ORDER BY m.id DESC
         LIMIT $limit";

    $stmt = $conn->prepare($sql);
    foreach (['me1', 'me2', 'me3', 'me4', 'me5'] as $p) $stmt->bindValue(':' . $p, $analystId, PDO::PARAM_INT);
    $stmt->execute();

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id'         => (int) $r['id'],
            'channel_id' => (int) $r['channel_id'],
            'channel'    => warRoomChannelName($r),
            'author'     => $r['full_name'] ?? (function_exists('t') ? t('war-room.former_analyst') : 'Former analyst'),
            'created'    => $r['created_datetime'],
            'snippet'    => warRoomSnippet((string) $r['body'], [], 160),
        ];
    }
    return $out;
}

/**
 * Unread mentions per channel, so the channel list can mark a mention differently
 * from ordinary unread. Being named is a different event from having missed
 * something, and one badge for both would hide the one that needs you.
 *
 * @return array<int,int> channel_id => count
 */
function warRoomMentionCounts(PDO $conn, int $analystId): array
{
    $stmt = $conn->prepare(
        "SELECT m.channel_id, COUNT(*) AS n
           FROM warroom_mentions wm
           JOIN warroom_messages m ON m.id = wm.message_id
           LEFT JOIN warroom_reads r ON r.channel_id = m.channel_id AND r.analyst_id = :me2
          WHERE wm.analyst_id = :me1
            AND m.id > COALESCE(r.last_read_message_id, 0)
          GROUP BY m.channel_id"
    );
    $stmt->bindValue(':me1', $analystId, PDO::PARAM_INT);
    $stmt->bindValue(':me2', $analystId, PDO::PARAM_INT);
    $stmt->execute();

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int) $r['channel_id']] = (int) $r['n'];
    return $out;
}

/* ══════════════════════════════════════════════════════════════════════════
   ATTACHMENTS
   ══════════════════════════════════════════════════════════════════════════ */

/**
 * Store one uploaded file against a message.
 *
 * 🔒 Storage goes through uploadStoreFile() — the ONE home for the rules — so
 * this inherits all three defences: an extension AND mime whitelist, a random
 * stored filename drawn from our own list rather than the caller's string, and
 * an .htaccess that disables execution in the directory. Nothing here reimplements
 * any of that, and nothing here calls move_uploaded_file().
 *
 * ⚠️ NOTE WHAT IS *NOT* STORED: there is no content_type column. The type an
 * attachment is served as is derived from its extension against our own map at
 * serve time (security F5). Keeping the uploader's claim in the database would
 * only create something for a future endpoint to trust by mistake, so the
 * temptation is removed rather than documented.
 *
 * @param int $messageId  the warroom_messages id it belongs to
 * @param array $file  one entry from $_FILES - untrusted; uploadStoreFile() checks it
 */
function warRoomStoreAttachment(PDO $conn, int $messageId, array $file): array
{
    $stored = uploadStoreFile($file, WARROOM_ATTACH_DIR);

    $stmt = $conn->prepare(
        "INSERT INTO warroom_attachments (message_id, stored_name, original_name, size_bytes, created_datetime)
         VALUES (:m, :s, :o, :z, UTC_TIMESTAMP())"
    );
    $stmt->execute([
        ':m' => $messageId,
        ':s' => $stored['stored_name'],
        ':o' => $stored['original_name'],
        ':z' => (int) $stored['size'],
    ]);
    $id = (int) $conn->lastInsertId();

    return [
        'id'     => $id,
        'name'   => $stored['original_name'],
        'size'   => (int) $stored['size'],
        'inline' => attachmentServeRules($stored['original_name'])['inline'],
    ];
}

/**
 * The row for an attachment the analyst is allowed to fetch, or null.
 *
 * The channel check is done HERE rather than in the endpoint, so there is one
 * answer to "may I read this file" and it is the same answer as "may I read the
 * conversation it is in". Guessing an attachment id from another channel returns
 * nothing rather than a file.
 *
 * @param int $attachmentId  the warroom_attachments id asked for
 */
function warRoomAttachmentFor(PDO $conn, int $analystId, int $attachmentId): ?array
{
    $stmt = $conn->prepare(
        "SELECT at.id, at.stored_name, at.original_name, at.size_bytes, m.channel_id
           FROM warroom_attachments at
           JOIN warroom_messages m ON m.id = at.message_id
          WHERE at.id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $attachmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) return null;
    if (!warRoomCanAccessChannel($conn, $analystId, (int) $row['channel_id'])) return null;

    $row['path'] = rtrim(WARROOM_ATTACH_DIR, '/\\') . DIRECTORY_SEPARATOR . $row['stored_name'];
    return $row;
}

/* ══════════════════════════════════════════════════════════════════════════
   SEARCH
   ══════════════════════════════════════════════════════════════════════════ */

/**
 * Search the conversations this analyst can see.
 *
 * 🔑 DELIBERATELY `LIKE`, NOT THE FULL-TEXT CORPUS, for two reasons that both
 * bite exactly the strings people type into a war room.
 *
 *   1. MySQL's full-text index has a MINIMUM TOKEN SIZE — three characters by
 *      default — and anything shorter is not ranked low, it is never recorded at
 *      all. `P1`, `AD`, `DB`, `VM`, `DC`, `HQ` and any single octet of an IP
 *      address are all below it. The search returns nothing, with no error.
 *   2. Full text tokenises on non-word characters, so `10.0.0.5`, `SRV-01` and a
 *      UNC path are split into pieces and cannot be matched as one string
 *      without boolean-mode quoting the user has no reason to know about.
 *
 * A substring scan has neither problem. Chat volumes are small — retention bounds
 * the table, and it holds one row per message rather than per document — so the
 * scan is the right trade here even though it would be the wrong one for tickets.
 * ⚠️ If war room volumes ever stop being small, the fix is an index, NOT moving to
 * full text: that would silently lose case 1 again.
 *
 * Scoping is inside the query, not applied to the results afterwards: filtering
 * afterwards silently starves the result set when the matches are in channels you
 * cannot see.
 *
 * @param string $query  the words typed; up to six, all must appear (substring match)
 * @param ?int $channelId  limit to one channel; null searches every channel the analyst can read
 * @param int $limit  the most results, 1-200
 */
function warRoomSearch(PDO $conn, int $analystId, string $query, ?int $channelId = null, int $limit = 60): array
{
    $terms = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$terms) return [];
    $terms = array_slice($terms, 0, 6);

    $where  = [];
    $params = [];
    foreach ($terms as $i => $term) {
        // Escape the LIKE wildcards themselves, or a search for "50%" matches
        // everything and reads as the feature being broken.
        $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $where[] = "m.body LIKE :t$i ESCAPE '\\\\'";
        $params[":t$i"] = '%' . $esc . '%';
    }
    $termSql = implode(' AND ', $where);          // all terms must appear

    $chanSql = '';
    if ($channelId !== null) {
        $chanSql = ' AND m.channel_id = :cid';
        $params[':cid'] = $channelId;
    }

    $limit = max(1, min(200, $limit));
    $sql = "
        SELECT m.id, m.channel_id, m.body, m.created_datetime, a.full_name,
               c.kind, c.name, c.topic, t.name AS team_name,
               (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ')
                  FROM warroom_channel_members m2
                  JOIN analysts a2 ON a2.id = m2.analyst_id
                 WHERE m2.channel_id = c.id AND m2.analyst_id <> :me3) AS other_members
          FROM warroom_messages m
          JOIN warroom_channels c ON c.id = m.channel_id
          LEFT JOIN teams t   ON t.id = c.team_id
          LEFT JOIN analysts a ON a.id = m.analyst_id
         WHERE $termSql $chanSql
           AND (
                   c.kind = '" . WARROOM_KIND_ALL . "'
                OR (c.kind = '" . WARROOM_KIND_TEAM . "' AND EXISTS (
                      SELECT 1 FROM analyst_teams at
                       WHERE at.team_id = c.team_id AND at.analyst_id = :me1))
                OR (c.kind = '" . WARROOM_KIND_CUSTOM . "' AND c.is_private = 0)
                OR (c.kind IN ('" . WARROOM_KIND_CUSTOM . "','" . WARROOM_KIND_DM . "') AND EXISTS (
                      SELECT 1 FROM warroom_channel_members mm
                       WHERE mm.channel_id = c.id AND mm.analyst_id = :me2))
               )
         ORDER BY m.id DESC
         LIMIT $limit";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    foreach (['me1', 'me2', 'me3'] as $p) $stmt->bindValue(':' . $p, $analystId, PDO::PARAM_INT);
    $stmt->execute();

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = [
            'id'         => (int) $r['id'],
            'channel_id' => (int) $r['channel_id'],
            'channel'    => warRoomChannelName($r),
            'author'     => $r['full_name'] ?? (function_exists('t') ? t('war-room.former_analyst') : 'Former analyst'),
            'created'    => $r['created_datetime'],
            'snippet'    => warRoomSnippet((string) $r['body'], $terms),
        ];
    }
    return $out;
}

/**
 * A window of the message around the first term that matched, so a result is
 * readable without opening it. Plain text — the page inserts it with textContent
 * and highlights client-side, so nothing here can emit markup.
 *
 * @param string $body  the message text
 * @param array $terms  the search words; the window centres near the first one found ([] for the start)
 * @param int $len  the most characters to return, before the ellipses
 */
function warRoomSnippet(string $body, array $terms, int $len = 200): string
{
    $body = preg_replace('/\s+/u', ' ', trim($body));
    if (mb_strlen($body) <= $len) return $body;

    $pos = 0;
    foreach ($terms as $term) {
        $p = mb_stripos($body, $term);
        if ($p !== false) { $pos = $p; break; }
    }
    $start = max(0, $pos - 60);
    $out   = mb_substr($body, $start, $len);
    return ($start > 0 ? '…' : '') . $out . (($start + $len) < mb_strlen($body) ? '…' : '');
}

/* ══════════════════════════════════════════════════════════════════════════
   PRESENCE AND UNREAD
   ══════════════════════════════════════════════════════════════════════════ */

/**
 * Record that this analyst is here, and return who else is.
 *
 * The poll that fetches messages calls this, so presence costs no extra request
 * and there is no separate heartbeat to keep alive. The UPSERT onto the UNIQUE
 * analyst_id is what stops the table growing — one row per person, forever.
 *
 * @param int $channelId  the channel the analyst has open
 */
function warRoomTouchPresence(PDO $conn, int $analystId, int $channelId): void
{
    $stmt = $conn->prepare(
        "INSERT INTO warroom_presence (analyst_id, channel_id, last_seen)
         VALUES (:aid, :cid, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE channel_id = VALUES(channel_id), last_seen = VALUES(last_seen)"
    );
    $stmt->bindValue(':aid', $analystId, PDO::PARAM_INT);
    $stmt->bindValue(':cid', $channelId, PDO::PARAM_INT);
    $stmt->execute();
}

/**
 * Who has polled recently, and whether they are in THIS channel or elsewhere in
 * the war room. "Here" versus "around" is a genuinely useful distinction during an
 * incident — it is the difference between nobody is reading this and everybody is
 * in the other room.
 *
 * @param int $excludeAnalystId  the analyst asking, left out of both lists; 0 leaves nobody out
 * @param int $channelId  the channel that counts as "here"
 * @return array{here:string[],elsewhere:string[]}
 */
function warRoomPresent(PDO $conn, int $excludeAnalystId, int $channelId): array
{
    $stmt = $conn->prepare(
        "SELECT a.full_name, p.channel_id
           FROM warroom_presence p
           JOIN analysts a ON a.id = p.analyst_id
          WHERE p.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :win SECOND)
            AND p.analyst_id <> :me
          ORDER BY a.full_name"
    );
    $stmt->bindValue(':win', WARROOM_PRESENCE_WINDOW_SECONDS, PDO::PARAM_INT);
    $stmt->bindValue(':me', $excludeAnalystId, PDO::PARAM_INT);
    $stmt->execute();

    $here = $elsewhere = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ((int) $r['channel_id'] === $channelId) $here[] = $r['full_name'];
        else                                       $elsewhere[] = $r['full_name'];
    }
    return ['here' => $here, 'elsewhere' => $elsewhere];
}

/**
 * Remember how far this analyst has read, so the channel list can show what is
 * new. Only ever moves forward: an out-of-order poll must not un-read a channel.
 *
 * @param int $channelId  the warroom_channels id
 * @param int $messageId  the newest message id read; 0 or less does nothing
 */
function warRoomMarkRead(PDO $conn, int $analystId, int $channelId, int $messageId): void
{
    if ($messageId <= 0) return;
    // Seen by (5.9.0): note the moment the marker moves past new messages - only
    // while an administrator has Seen by switched on.
    if (warRoomSeenByEnabled($conn)) {
        try {
            $old = $conn->prepare("SELECT last_read_message_id FROM warroom_reads WHERE analyst_id = ? AND channel_id = ?");
            $old->execute([$analystId, $channelId]);
            if ($messageId > (int) $old->fetchColumn()) {
                $conn->prepare("INSERT INTO warroom_read_marks (analyst_id, channel_id, up_to_message_id, read_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
                     ->execute([$analystId, $channelId, $messageId]);
            }
        } catch (Throwable $e) { /* before Database Verification */ }
    }
    $stmt = $conn->prepare(
        "INSERT INTO warroom_reads (analyst_id, channel_id, last_read_message_id, updated_datetime)
         VALUES (:a, :c, :m, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),
            updated_datetime     = VALUES(updated_datetime)"
    );
    $stmt->execute([':a' => $analystId, ':c' => $channelId, ':m' => $messageId]);
}

/* ══════════════════════════════════════════════════════════════════════════
   RETENTION
   ══════════════════════════════════════════════════════════════════════════ */

/** Retention in days from settings. 0 (the default) means keep forever. */
function warRoomRetentionDays(PDO $conn): int
{
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'warroom_retention_days' LIMIT 1");
        $stmt->execute();
        $v = $stmt->fetchColumn();
        return $v === false ? 0 : max(0, (int) $v);
    } catch (Throwable) {
        return 0;                                 // never delete on an error
    }
}

/**
 * Apply the retention setting.
 *
 * Called from warRoomSend rather than from a cron job, deliberately: an emergency
 * tool must not depend on somebody having configured a scheduled task. The LIMIT
 * keeps any single send cheap — a long-overdue prune takes several sends to work
 * through instead of stalling one of them.
 *
 * ⚠️ ATTACHMENT FILES MUST GO WITH THE ROWS. The FK deletes the attachment record
 * when its message goes, but nothing deletes the bytes on disk, and a database
 * that says the file is gone while the file is still there is the worse half of
 * the two. So the paths are read BEFORE the delete and unlinked after it.
 */
function warRoomPrune(PDO $conn): void
{
    // ⚠️ RUNS EVEN WHEN RETENTION IS OFF, and must. Retention is not the only way
    // a message disappears: deleting a TEAM cascades its channel, its messages and
    // their attachment rows — and nothing in that chain touches the filesystem. So
    // "keep forever" would mean the rows go and the bytes stay, forever, with no
    // record that they exist. Found by clearing the test data and noticing two
    // files left in the folder afterwards.
    warRoomSweepOrphanFiles($conn);

    $days = warRoomRetentionDays($conn);
    if ($days <= 0) return;                       // keep forever

    try {
        $doomed = $conn->prepare(
            "SELECT at.stored_name
               FROM warroom_attachments at
               JOIN warroom_messages m ON m.id = at.message_id
              WHERE m.created_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY)
              LIMIT 2000"
        );
        $doomed->bindValue(':d', $days, PDO::PARAM_INT);
        $doomed->execute();
        $files = array_column($doomed->fetchAll(PDO::FETCH_ASSOC), 'stored_name');

        $stmt = $conn->prepare(
            "DELETE FROM warroom_messages
              WHERE created_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY)
              LIMIT 500"
        );
        $stmt->bindValue(':d', $days, PDO::PARAM_INT);
        $stmt->execute();

        // Only unlink files whose row really did go. A file still referenced by a
        // surviving message (the LIMIT above may not have reached it yet) stays.
        // Seen by's read marks go with the messages they describe (5.9.0).
        try {
            $marks = $conn->prepare("DELETE FROM warroom_read_marks WHERE read_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY) LIMIT 5000");
            $marks->bindValue(':d', $days, PDO::PARAM_INT);
            $marks->execute();
        } catch (Throwable $e) { /* before Database Verification */ }

        foreach ($files as $name) {
            $still = $conn->prepare("SELECT 1 FROM warroom_attachments WHERE stored_name = :s LIMIT 1");
            $still->execute([':s' => $name]);
            if ($still->fetchColumn()) continue;
            $path = rtrim(WARROOM_ATTACH_DIR, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) @unlink($path);
        }
    } catch (Throwable) {
        // Retention is housekeeping — never let it break posting a message.
    }
}

/**
 * Delete attachment files with no row left pointing at them.
 *
 * Rate-limited to once an hour by a timestamp in system_settings, because this
 * is the only thing in the module that lists a directory and it hangs off the
 * send path. An hour is generous: an orphan is wasted disk, not a live problem.
 *
 * Still no cron. A file that outlives its row by up to an hour is fine; a module
 * that needs a scheduled task set up before it cleans up after itself is not.
 */
function warRoomSweepOrphanFiles(PDO $conn): void
{
    try {
        $now  = time();
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'warroom_last_sweep' LIMIT 1");
        $stmt->execute();
        $last = (int) $stmt->fetchColumn();
        if ($now - $last < 3600) return;

        $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value) VALUES ('warroom_last_sweep', :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        )->execute([':v' => (string) $now]);

        $dir = rtrim(WARROOM_ATTACH_DIR, '/\\');
        if (!is_dir($dir)) return;

        $known = [];
        foreach ($conn->query("SELECT stored_name FROM warroom_attachments") as $r) {
            $known[$r['stored_name']] = true;
        }

        foreach ((array) scandir($dir) as $name) {
            if ($name === '.' || $name === '..') continue;
            // The directory's own guards are not uploads and must never be swept.
            if ($name === '.htaccess' || $name === 'web.config') continue;
            if (isset($known[$name])) continue;
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            // Give an in-flight upload a minute's grace: the file lands before its
            // row is written, and deleting it in that window would lose it.
            if (is_file($path) && ($now - (int) filemtime($path)) > 60) @unlink($path);
        }
    } catch (Throwable) {
        // Housekeeping. Never let it break posting a message.
    }
}

/* ══════════════════════════════════════════════════════════════════════════
   SITUATION REPORT (the AI summary)
   ══════════════════════════════════════════════════════════════════════════ */

/**
 * The transcript to summarise: everything said in channels this analyst can see
 * since a given moment.
 *
 * Scoped by the same rule as everything else, so a service delivery manager's
 * summary can never quote a conversation they are not entitled to read — which
 * would be a novel way to leak a DM.
 *
 * @param string $sinceUtc 'Y-m-d H:i:s' in UTC
 * @param ?int $channelId  limit to one channel; null takes every channel the analyst can read
 * @param int $maxMessages  the most messages to include, oldest first, 1-1000
 * @return array{lines:string[],messages:int,channels:int}
 */
function warRoomTranscriptSince(PDO $conn, int $analystId, string $sinceUtc, ?int $channelId = null, int $maxMessages = 400): array
{
    $params = [':since' => $sinceUtc];
    $chanSql = '';
    if ($channelId !== null) {
        $chanSql = ' AND m.channel_id = :cid';
        $params[':cid'] = $channelId;
    }
    $maxMessages = max(1, min(1000, $maxMessages));

    $sql = "
        SELECT m.channel_id, m.body, m.created_datetime, a.full_name,
               c.kind, c.name, t.name AS team_name,
               (SELECT GROUP_CONCAT(a2.full_name ORDER BY a2.full_name SEPARATOR ', ')
                  FROM warroom_channel_members m2
                  JOIN analysts a2 ON a2.id = m2.analyst_id
                 WHERE m2.channel_id = c.id AND m2.analyst_id <> :me3) AS other_members
          FROM warroom_messages m
          JOIN warroom_channels c ON c.id = m.channel_id
          LEFT JOIN teams t    ON t.id = c.team_id
          LEFT JOIN analysts a ON a.id = m.analyst_id
         WHERE m.created_datetime >= :since $chanSql
           AND (
                   c.kind = '" . WARROOM_KIND_ALL . "'
                OR (c.kind = '" . WARROOM_KIND_TEAM . "' AND EXISTS (
                      SELECT 1 FROM analyst_teams at
                       WHERE at.team_id = c.team_id AND at.analyst_id = :me1))
                OR (c.kind = '" . WARROOM_KIND_CUSTOM . "' AND c.is_private = 0)
                OR (c.kind IN ('" . WARROOM_KIND_CUSTOM . "','" . WARROOM_KIND_DM . "') AND EXISTS (
                      SELECT 1 FROM warroom_channel_members mm
                       WHERE mm.channel_id = c.id AND mm.analyst_id = :me2))
               )
         ORDER BY m.id ASC
         LIMIT $maxMessages";

    $stmt = $conn->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    foreach (['me1', 'me2', 'me3'] as $p) $stmt->bindValue(':' . $p, $analystId, PDO::PARAM_INT);
    $stmt->execute();

    $lines    = [];
    $channels = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $chan = warRoomChannelName($r);
        $channels[$chan] = true;
        $who  = $r['full_name'] ?? 'Former analyst';
        $when = substr((string) $r['created_datetime'], 11, 5) . ' UTC';
        $lines[] = "[$when] #$chan — $who: " . preg_replace('/\s+/u', ' ', (string) $r['body']);
    }

    return ['lines' => $lines, 'messages' => count($lines), 'channels' => count($channels)];
}

/**
 * The prompt for the situation report.
 *
 * 🔑 Written for ONE reader: the service delivery manager who has to send the
 * next update to the business in ten minutes and cannot read four hundred
 * messages first. That is why it asks for what changed, what is still unknown and
 * what the business would want told — and why it is told to say when the chat
 * does not support a conclusion. A summary that invents a cause reads exactly like
 * one that found a cause, and it goes out to the whole company under somebody's
 * name.
 *
 * @param array $lines  the transcript lines from warRoomTranscriptSince()
 * @param string $sinceLabel  how far back the transcript goes, in words, e.g. '30 minutes ago'
 */
function warRoomSitrepPrompt(array $lines, string $sinceLabel): string
{
    $transcript = implode("\n", $lines);

    return <<<PROMPT
You are helping a service delivery manager write an update to the business during
an IT incident. Below is the chat between analysts in the war room since {$sinceLabel}.

Write a briefing with these headings, using only what the transcript supports:

**Where things stand** — two or three sentences a non-technical executive can read.
**What changed since {$sinceLabel}** — bullets, newest development first.
**Who is doing what** — bullets naming the analyst, only where the chat says so.
**Still unknown** — the open questions, including anything being guessed at.
**Suggested wording for the business** — one short paragraph they could send as-is.

Rules:
- Use ONLY the transcript. If it does not say something, do not supply it.
- Where analysts are speculating, say they are speculating. Do not promote a
  guess to a cause, and do not offer an ETA the chat has not given.
- If the conversation is too thin to brief on, say exactly that in one line under
  "Where things stand" and leave the other sections empty rather than padding them.
- Plain British English. No preamble, no sign-off, no restating these rules.

TRANSCRIPT
----------
{$transcript}
PROMPT;
}
