<?php
/**
 * Has this install ever been provisioned?
 *
 * setup/index.php and api/system/db_verify.php both have to answer that before
 * letting an anonymous visitor near schema changes, because there is a genuine
 * bootstrap problem: on a brand-new install no analyst exists yet, so the
 * endpoint that BUILDS the table they will log in against cannot itself
 * require a login.
 *
 * That used to be solved with a session flag which setup/index.php handed to
 * anyone who loaded the page:
 *
 *     $_SESSION['setup_access'] = true;      // no authentication of any kind
 *
 * so `GET /setup/` followed by `POST /api/system/db_verify.php` on the same
 * cookie ran migrations against a live database with no credentials at any
 * point, and the flag was never cleared. Reported privately against f7f1e9dd.
 *
 * The flag is gone. Both callers now ask the database the real question, so
 * there is no longer a token to forge — the bootstrap path exists only while
 * the condition that justifies it is actually true.
 */

require_once __DIR__ . '/db_errors.php';

/**
 * True only when no analyst account exists, i.e. nobody can possibly log in
 * and an unauthenticated bootstrap is the only way to build the schema.
 *
 * Fails CLOSED. A missing `analysts` table is the one error that really does
 * mean "fresh install"; everything else — connection dropped, lock timeout,
 * permission denied — returns false and the caller demands an administrator.
 * Defaulting the other way would turn any transient database blip into an
 * unauthenticated migration endpoint, which is the same fail-open shape this
 * whole change exists to remove.
 */
function installIsUnprovisioned(PDO $conn): bool
{
    try {
        return (int)$conn->query("SELECT COUNT(*) FROM analysts")->fetchColumn() === 0;
    } catch (PDOException $e) {
        return dbErrorIsMissingTable($e);
    }
}
