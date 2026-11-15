<?php
/**
 * System module page gate. Require it on every System page AFTER $current_page
 * is set and BEFORE anything is output.
 *
 * header.php makes the same two checks, but it is included in the middle of the
 * <body>, after the <head> has already been sent. By then header('Location')
 * can only raise "headers already sent" and the exit that follows leaves a
 * half-drawn page: a signed-out analyst saw a blank System page instead of the
 * login screen, and a non-admin saw one instead of being sent home. The checks
 * in header.php stay as a backstop; this is the one that can still redirect.
 *
 * Cosmetic like header.php's - the System APIs enforce admin themselves with
 * requireAdminJson().
 */
require_once __DIR__ . '/../../includes/functions.php';

require_once __DIR__ . '/page_access.php';   // systemPageAllowed()

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

// Preferences is every analyst's own page; the rest of System is admin-only.
// Asked of the database, not the session flag (3.5.0): an admin demoted or
// deactivated since signing in is turned away here too, not only by the APIs.
if (!systemPageAllowed(connectToDatabase(), (int)$_SESSION['analyst_id'], $current_page ?? '', $system_page_grant ?? null)) {
    header('Location: ' . BASE_URL);
    exit;
}
