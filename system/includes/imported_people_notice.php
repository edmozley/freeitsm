<?php
/**
 * One line under the Run buttons of the LDAP, CardDAV and Entra pages: how many of
 * the people this source imported need a look, and the way to Imported people
 * (System -> Maintenance), already on this source (#261).
 *
 * "Need a look" is the list Imported people opens on: the people this source has
 * not found for DirectoryMaintenance::STALE_DEFAULT days - removed from the
 * directory, moved out of what is imported, or the import has stopped. Counted in
 * the companies the reader can access, as that list is.
 */
require_once __DIR__ . '/../../includes/services/directory_maintenance.php';

/**
 * The line, as HTML.
 *
 * @param array $provider  the auth_providers row
 */
function importedPeopleNotice(PDO $conn, array $provider): string
{
    $e = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $id = (int)$provider['id'];
    $link = '<a href="../directory-maintenance/?source=' . $id . '">' . $e(t('system.sso.maint_source_link')) . '</a>';
    $n = 0;
    try {
        $n = DirectoryMaintenance::staleCount($conn, ActorContext::fromSession($conn), $id, DirectoryMaintenance::STALE_DEFAULT);
    } catch (Throwable $ex) {
        // A table Database Verification has not made yet: the link alone, as before.
        error_log('[sso] imported people count: ' . $ex->getMessage());
    }
    if ($n === 0) return '<div class="hint" style="margin-top:8px;">' . $link . '</div>';
    return '<div class="hint" style="margin-top:8px;color:var(--warning-text,#92400e);">'
         . $e(t('system.sso.maint_source_need_look', ['n' => $n, 'days' => DirectoryMaintenance::STALE_DEFAULT])) . ' ' . $link . '</div>';
}
