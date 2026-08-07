<?php
/**
 * Telling one database error from another.
 *
 * Several places in the app need to tolerate a schema that hasn't caught up
 * yet — a table Database Verify hasn't created, a column an older install is
 * still missing — while NOT tolerating anything else. Written as a bare
 * `catch (Exception $e)` that assumption quietly widens: a lock-wait timeout,
 * a dropped connection or a permissions error all look identical to "not
 * migrated yet", and whatever the catch block does for a fresh install it now
 * also does during a database hiccup on a live one.
 *
 * That is fail-open by accident, and it is how includes/tenancy.php came to
 * grant cross-tenant access on any transient error. These helpers exist so a
 * catch block can say precisely which error it is willing to absorb.
 *
 * Codes are MySQL's, matched on SQLSTATE first (portable, and always present
 * on a PDOException) with the driver-specific code as a second opinion.
 */

/**
 * "That table doesn't exist." MySQL: SQLSTATE 42S02, driver code 1146.
 */
function dbErrorIsMissingTable(PDOException $e): bool
{
    if ($e->getCode() === '42S02') return true;
    return (int)($e->errorInfo[1] ?? 0) === 1146;
}

/**
 * "That column doesn't exist." MySQL: SQLSTATE 42S22, driver code 1054.
 */
function dbErrorIsUnknownColumn(PDOException $e): bool
{
    if ($e->getCode() === '42S22') return true;
    return (int)($e->errorInfo[1] ?? 0) === 1054;
}

/**
 * Either of the above — the two shapes a part-migrated install actually takes.
 */
function dbErrorIsMissingSchema(PDOException $e): bool
{
    return dbErrorIsMissingTable($e) || dbErrorIsUnknownColumn($e);
}
