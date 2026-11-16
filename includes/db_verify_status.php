<?php
/**
 * Is the database behind the code? (GH #369)
 *
 * An upgrade replaces the files; the database only catches up when an
 * administrator runs System -> Database Verification. Until then any query that
 * reaches a new table or column fails. In #369 that was the ticket list for every
 * analyst in a team (ticketScopeFilter() reads ticket_watchers, added in 5.1.0),
 * so they saw an empty inbox and a raw SQL error, while administrators - who skip
 * that filter - saw nothing wrong.
 *
 * Database Verification now records the version it last brought the database up
 * to (dbVerifyRecordRun()), and:
 *   - every page tells an administrator when the code is newer than that
 *     (dbVerifyBehind(), shown by renderWaffleMenuJS() in waffle-menu.php);
 *   - an endpoint that hits a missing table or column says so in words
 *     (dbErrorForPeople()) instead of passing the SQL error on.
 *
 * TRAP: an install that has never recorded a version counts as behind. That is
 * deliberate - it covers every install upgraded before this existed - and one run
 * of Database Verification clears it.
 *
 * @wiki Issue-369-Inbox-Empty-Until-Database-Verification
 */

require_once __DIR__ . '/version.php';

/** The system_settings key Database Verification writes. */
const DB_VERIFIED_VERSION_KEY = 'db_verified_version';

/**
 * Record that Database Verification has brought the database up to this
 * version. Called only after a run with no errors.
 */
function dbVerifyRecordRun(PDO $conn): void
{
    $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
         ->execute([DB_VERIFIED_VERSION_KEY, FREEITSM_VERSION]);
}

/**
 * The code's version and the one the database was last verified for, when they
 * differ; null when the database is up to date (or the check itself fails - a
 * notice must never break a page).
 *
 * @return array{code: string, verified: ?string}|null
 */
function dbVerifyBehind(PDO $conn): ?array
{
    try {
        $v = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $v->execute([DB_VERIFIED_VERSION_KEY]);
        $verified = $v->fetchColumn();
        $verified = $verified === false || $verified === null || $verified === '' ? null : (string)$verified;
    } catch (Throwable) {
        return null;
    }
    return $verified === FREEITSM_VERSION ? null : ['code' => FREEITSM_VERSION, 'verified' => $verified];
}

/**
 * Is this the error a database that is behind the code gives? A missing table
 * (SQLSTATE 42S02) or a missing column (42S22).
 */
function dbErrorIsSchemaBehind(Throwable $e): bool
{
    if (!$e instanceof PDOException) return false;
    $state = (string)($e->errorInfo[0] ?? $e->getCode());
    return $state === '42S02' || $state === '42S22';
}

/**
 * The message to show for a database error: for a missing table or column, words
 * saying what to do (and the detail logged for the administrator); otherwise the
 * error as it always was.
 */
function dbErrorForPeople(Throwable $e): string
{
    if (!dbErrorIsSchemaBehind($e)) return $e->getMessage();
    error_log('FreeITSM: the database is behind the code (' . FREEITSM_VERSION . '); run Database Verification. ' . $e->getMessage());
    $en = 'The database needs updating for this version of FreeITSM. Ask an administrator to run System → Database Verification.';
    if (!function_exists('t')) return $en;
    $msg = t('common.db_behind.error');
    return ($msg === '' || $msg === 'common.db_behind.error') ? $en : $msg;
}
