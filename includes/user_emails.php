<?php
/**
 * A person's other email addresses, their history, and merging a duplicate into them (GitHub #308).
 *
 * People often write from a private address - exactly when they cannot sign in (an MFA
 * problem, a locked account). Until 5.2.0 that made a second person for the private
 * address, whose tickets never showed in the real person's portal. A person can now have
 * LINKED addresses: mail from one belongs to them.
 *
 * THE RULES
 *   - One owner per address: an address is linked to one person (unique), and is never
 *     another person's main address (users.email). userLinkEmail() checks both.
 *   - Linked addresses are for MAIL ONLY. Signing in, password resets and single sign-on
 *     stay on the main address (Ed, 2026-11-13) - a compromised private mailbox must not be
 *     a way into the account. So this file is used where an address is matched to a
 *     person for mail, never by the sign-in code.
 *   - A directory wins: when LDAP, Entra, CardDAV or single sign-on makes an address
 *     somebody's MAIN address, userReleaseEmailForMain() takes it off whoever had it
 *     linked, and their history says so.
 *   - Before Database Verification the tables are not there: everything here answers as
 *     if nobody had a linked address (userEmailsReady()).
 *
 * @wiki Linked-Email-Addresses-Developer-Guide
 */

require_once __DIR__ . '/tenancy.php';
require_once __DIR__ . '/users.php';   // userManagedSql()

/** An address as stored: trimmed, lower case. */
function userEmailNorm(?string $email): string
{
    return strtolower(trim((string)$email));
}

/** Are the GH #308 tables there (Database Verification)? Cached per request. */
function userEmailsReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $conn->query("SELECT 1 FROM user_email_addresses LIMIT 0");
        $conn->query("SELECT 1 FROM user_audit LIMIT 0");
        return $ready = true;
    } catch (Throwable $e) {
        return $ready = false;
    }
}

/**
 * The person an address belongs to, for MAIL: their main address first, then a linked one.
 * Null when nobody has it. Not for signing in - see the header.
 */
function userIdForEmail(PDO $conn, ?string $email): ?int
{
    $owner = userEmailOwner($conn, $email);
    return $owner ? $owner['user_id'] : null;
}

/**
 * Who has an address, and how: ['user_id' => int, 'kind' => 'main'|'linked'], or null.
 *
 * @param ?string $email  any case, any spaces
 */
function userEmailOwner(PDO $conn, ?string $email): ?array
{
    $e = userEmailNorm($email);
    if ($e === '') return null;
    // users.email is case-insensitive (utf8mb4_unicode_ci) - a plain = uses its index; LOWER() would not.
    $st = $conn->prepare("SELECT id FROM users WHERE email = ? ORDER BY id LIMIT 1");
    $st->execute([$e]);
    if ($id = $st->fetchColumn()) return ['user_id' => (int)$id, 'kind' => 'main'];
    if (!userEmailsReady($conn)) return null;
    $st = $conn->prepare("SELECT user_id FROM user_email_addresses WHERE email = ? LIMIT 1");
    $st->execute([$e]);
    if ($id = $st->fetchColumn()) return ['user_id' => (int)$id, 'kind' => 'linked'];
    return null;
}

/** A person's linked addresses, oldest first, with how and by whom each was added. */
function userLinkedEmails(PDO $conn, int $userId): array
{
    if (!userEmailsReady($conn)) return [];
    $st = $conn->prepare(
        "SELECT e.email, e.added_via, e.created_datetime, a.full_name AS added_by
           FROM user_email_addresses e LEFT JOIN analysts a ON a.id = e.added_by_analyst_id
          WHERE e.user_id = ? ORDER BY e.id");
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * One line of a person's history.
 *
 * @param ?int $analystId  the analyst, or null for the person themselves (portal) or the system
 */
function userAudit(PDO $conn, int $userId, ?int $analystId, string $field, ?string $old, ?string $new): void
{
    if (!userEmailsReady($conn)) return;
    try {
        $conn->prepare("INSERT INTO user_audit (user_id, analyst_id, field_name, old_value, new_value) VALUES (?, ?, ?, ?, ?)")
             ->execute([$userId, $analystId ?: null, $field, $old !== null ? mb_substr($old, 0, 1000) : null, $new !== null ? mb_substr($new, 0, 1000) : null]);
    } catch (Throwable $e) {
        error_log('user_audit: ' . $e->getMessage());
    }
}

/** A person's history, newest first. */
function userHistory(PDO $conn, int $userId, int $limit = 100): array
{
    if (!userEmailsReady($conn)) return [];
    $st = $conn->prepare(
        "SELECT h.field_name, h.old_value, h.new_value, h.created_datetime, a.full_name AS analyst_name
           FROM user_audit h LEFT JOIN analysts a ON a.id = h.analyst_id
          WHERE h.user_id = ? ORDER BY h.created_datetime DESC, h.id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Link an address to a person.
 *
 * @param ?int $analystId  who did it (they vouch for it); null = the person, after confirming in the portal
 * @param string $via  analyst | portal | merge | requester
 * @return array{ok:bool, reason?:string, other_user_id?:int}
 *   reasons: invalid, own_main, already_yours, linked_elsewhere, main_elsewhere (another person's
 *   main address - the caller offers to merge that person, see userMergePreview()), not_ready
 */
function userLinkEmail(PDO $conn, ?int $analystId, int $userId, string $email, string $via): array
{
    if (!userEmailsReady($conn)) return ['ok' => false, 'reason' => 'not_ready'];
    $e = userEmailNorm($email);
    if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL) || mb_strlen($e) > 255) return ['ok' => false, 'reason' => 'invalid'];
    $owner = userEmailOwner($conn, $e);
    if ($owner) {
        if ($owner['user_id'] === $userId) return ['ok' => false, 'reason' => $owner['kind'] === 'main' ? 'own_main' : 'already_yours'];
        return ['ok' => false, 'reason' => $owner['kind'] === 'main' ? 'main_elsewhere' : 'linked_elsewhere', 'other_user_id' => $owner['user_id']];
    }
    try {
        $conn->prepare("INSERT INTO user_email_addresses (user_id, email, added_via, added_by_analyst_id) VALUES (?, ?, ?, ?)")
             ->execute([$userId, $e, in_array($via, ['analyst', 'portal', 'merge', 'requester'], true) ? $via : 'analyst', $analystId ?: null]);
    } catch (PDOException $x) {
        if ((string)$x->getCode() === '23000') return ['ok' => false, 'reason' => 'linked_elsewhere'];   // a race: someone linked it first
        throw $x;
    }
    userAudit($conn, $userId, $analystId, 'email_linked', $via, $e);
    return ['ok' => true];
}

/** Remove a linked address. Tickets already raised from it stay where they are. True if it was linked. */
function userUnlinkEmail(PDO $conn, ?int $analystId, int $userId, string $email): bool
{
    if (!userEmailsReady($conn)) return false;
    $e = userEmailNorm($email);
    $st = $conn->prepare("DELETE FROM user_email_addresses WHERE user_id = ? AND email = ?");
    $st->execute([$userId, $e]);
    if ($st->rowCount() < 1) return false;
    userAudit($conn, $userId, $analystId, 'email_unlinked', $e, null);
    return true;
}

/**
 * A directory (LDAP, Entra, CardDAV) or single sign-on is about to make $email somebody's
 * MAIN address: the directory is the authority on work addresses, so whoever has it linked
 * loses the link, and their history says why. Call it before creating or re-addressing the
 * person. Never throws.
 *
 * @param string $source  what took it, for the history - 'directory', 'ldap', 'sso'
 * @param ?int $forUserId  the person it is becoming the main address of, if known - not released from them
 */
function userReleaseEmailForMain(PDO $conn, ?string $email, string $source, ?int $forUserId = null): void
{
    if (!userEmailsReady($conn)) return;
    $e = userEmailNorm($email);
    if ($e === '') return;
    try {
        $st = $conn->prepare("SELECT user_id FROM user_email_addresses WHERE email = ?");
        $st->execute([$e]);
        $holder = $st->fetchColumn();
        if ($holder === false) return;
        $conn->prepare("DELETE FROM user_email_addresses WHERE email = ?")->execute([$e]);
        if ((int)$holder !== (int)$forUserId) userAudit($conn, (int)$holder, null, 'email_released', $e, $source);
    } catch (Throwable $x) {
        error_log('userReleaseEmailForMain: ' . $x->getMessage());
    }
}

/** Does this person sign in - a portal password, a directory or identity provider, a single sign-on identity? */
function userHasLogin(PDO $conn, int $userId): bool
{
    $st = $conn->prepare("SELECT password_hash, auth_provider_id FROM users WHERE id = ?");
    $st->execute([$userId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;
    if (!empty($u['password_hash']) || !empty($u['auth_provider_id'])) return true;
    try {
        $s = $conn->prepare("SELECT 1 FROM user_sso_identities WHERE user_id = ? LIMIT 1");
        $s->execute([$userId]);
        return (bool)$s->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Everything (other than tickets) that still points at a person: [table.column => rows].
 * Every column with a foreign key to users, and - because not every reference has a key -
 * every column named user_id, *_user_id or manager_id. Over-counting is the safe side: a
 * person is only deleted when this is empty, otherwise switched off.
 */
function userReferences(PDO $conn, int $userId): array
{
    $skip = ['users.id', 'tickets.user_id', 'user_email_addresses.user_id', 'user_email_claims.user_id', 'user_audit.user_id',
             // A VARCHAR holding Entra's object id, not users.id: compared with a number it can match by accident.
             'intune_devices.user_id'];
    // The columns, found once per request: the directory maintenance tools ask this for
    // hundreds of people at a time (userDeleteBlockedByRecords()).
    static $found = null;
    $cols = $found ?? [];
    if ($found === null) try {
        foreach ($conn->query("SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                                WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'users'")->fetchAll(PDO::FETCH_NUM) as [$t, $c]) {
            $cols["$t.$c"] = [$t, $c];
        }
        foreach ($conn->query("SELECT c.TABLE_NAME, c.COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS c
                                 JOIN INFORMATION_SCHEMA.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
                                WHERE c.TABLE_SCHEMA = DATABASE()
                                  AND (c.COLUMN_NAME = 'user_id' OR c.COLUMN_NAME LIKE '%\\_user\\_id' OR (c.TABLE_NAME = 'users' AND c.COLUMN_NAME = 'manager_id'))")->fetchAll(PDO::FETCH_NUM) as [$t, $c]) {
            $cols["$t.$c"] = [$t, $c];
        }
        $found = $cols;
    } catch (Throwable $e) {
        return ['(could not be checked)' => 1];   // unknown means "do not delete"
    }
    $out = [];
    foreach ($cols as $key => [$t, $c]) {
        if (in_array($key, $skip, true)) continue;
        try {
            $st = $conn->prepare("SELECT COUNT(*) FROM `" . str_replace('`', '', $t) . "` WHERE `" . str_replace('`', '', $c) . "` = ?");
            $st->execute([$userId]);
            if ($n = (int)$st->fetchColumn()) $out[$key] = $n;
        } catch (Throwable $e) { /* a view or an odd table - skip */ }
    }
    return $out;
}

/**
 * What merging $fromId into $toId would do, or why it cannot be done.
 *
 * @return array{can:bool, reason:?string, from:array, to:array, tickets:int, delete:bool, references:array}
 *   reasons: not_found, same, other_company, has_login, directory
 */
function userMergePreview(PDO $conn, int $analystId, int $fromId, int $toId): array
{
    $load = function (int $id) use ($conn): ?array {
        // Managed only while the source exists (userManagedSql()): a deleted source's people merge like anybody.
        $st = $conn->prepare("SELECT id, email, display_name, tenant_id, " . userManagedSql('users') . " AS is_managed FROM users WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    $from = $load($fromId); $to = $load($toId);
    $out = ['can' => false, 'reason' => null, 'from' => [], 'to' => [], 'tickets' => 0, 'delete' => false, 'references' => []];
    if (!$from || !$to || ($analystId > 0 && (!analystCanAccessUser($conn, $analystId, $fromId) || !analystCanAccessUser($conn, $analystId, $toId)))) {
        $out['reason'] = 'not_found';
        return $out;
    }
    $shape = fn(array $u) => ['id' => (int)$u['id'], 'name' => $u['display_name'] ?: $u['email'], 'email' => $u['email']];
    $out['from'] = $shape($from); $out['to'] = $shape($to);
    if ($fromId === $toId) { $out['reason'] = 'same'; return $out; }
    $def = getDefaultTenantId($conn);
    $tf = $from['tenant_id'] !== null ? (int)$from['tenant_id'] : $def;
    $tt = $to['tenant_id'] !== null ? (int)$to['tenant_id'] : $def;
    if (isMultiTenant($conn) && $tf !== $tt) { $out['reason'] = 'other_company'; return $out; }
    // Someone who signs in is a real account, never merged away automatically.
    if (userHasLogin($conn, $fromId)) { $out['reason'] = 'has_login'; return $out; }
    // A directory keeps this person up to date: removing them would only bring them back.
    if (!empty($from['is_managed'])) { $out['reason'] = 'directory'; return $out; }
    $st = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE user_id = ?");
    $st->execute([$fromId]);
    $out['tickets'] = (int)$st->fetchColumn();
    $out['references'] = userReferences($conn, $fromId);
    $out['delete'] = !$out['references'];
    $out['can'] = true;
    return $out;
}

/**
 * Merge a duplicate person into the real one: their tickets move (each ticket's history
 * says so), their address becomes a linked address of the real person, and the duplicate
 * is deleted - or, when something else still points at them, switched off with no address
 * (so the address has one owner). The history of both says what happened.
 *
 * @return array  userMergePreview()'s answer, plus moved (tickets) and outcome ('deleted' | 'switched_off')
 * @throws RuntimeException  when the preview says it cannot be done
 */
function userMergeInto(PDO $conn, int $analystId, int $fromId, int $toId): array
{
    $p = userMergePreview($conn, $analystId, $fromId, $toId);
    if (!$p['can']) throw new RuntimeException($p['reason'] ?? 'cannot_merge');
    $fromEmail = userEmailNorm($p['from']['email'] ?? '');
    $own = !$conn->inTransaction();
    if ($own) $conn->beginTransaction();
    try {
        $ids = $conn->prepare("SELECT id FROM tickets WHERE user_id = ?");
        $ids->execute([$fromId]);
        $ticketIds = $ids->fetchAll(PDO::FETCH_COLUMN);
        if ($ticketIds) {
            $conn->prepare("UPDATE tickets SET user_id = ? WHERE user_id = ?")->execute([$toId, $fromId]);
            $aud = $conn->prepare("INSERT INTO ticket_audit (ticket_id, analyst_id, field_name, old_value, new_value, created_datetime) VALUES (?, ?, 'Requester', ?, ?, UTC_TIMESTAMP())");
            foreach ($ticketIds as $tid) $aud->execute([(int)$tid, $analystId ?: null, mb_substr((string)$p['from']['name'], 0, 500), mb_substr((string)$p['to']['name'], 0, 500)]);
        }
        // The duplicate's own linked addresses come along too.
        if (userEmailsReady($conn)) {
            $conn->prepare("UPDATE user_email_addresses SET user_id = ? WHERE user_id = ?")->execute([$toId, $fromId]);
        }
        if ($p['delete']) {
            $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$fromId]);
            $outcome = 'deleted';
        } else {
            // Kept for what still points at them, but no longer the owner of the address.
            $conn->prepare("UPDATE users SET email = NULL, is_active = 0, deactivated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$fromId]);
            userAudit($conn, $fromId, $analystId, 'merged_into', $fromEmail, (string)$p['to']['name']);
            $outcome = 'switched_off';
        }
        if ($fromEmail !== '' && userEmailsReady($conn)) {
            $conn->prepare("INSERT IGNORE INTO user_email_addresses (user_id, email, added_via, added_by_analyst_id) VALUES (?, ?, 'merge', ?)")
                 ->execute([$toId, $fromEmail, $analystId ?: null]);
        }
        userAudit($conn, $toId, $analystId, 'merged', (string)$p['from']['name'] . ($fromEmail !== '' ? " <$fromEmail>" : ''), (string)count($ticketIds));
        if ($own) $conn->commit();
    } catch (Throwable $e) {
        if ($own && $conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    return $p + ['moved' => count($ticketIds), 'outcome' => $outcome];
}
