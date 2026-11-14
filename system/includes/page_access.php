<?php
/**
 * Who may open a System page - the one rule, used by page_gate.php and header.php (GH #263).
 */
require_once __DIR__ . '/../../includes/functions.php';

/**
 * May this analyst open this System page?
 *
 * An administrator may open any; every analyst may open Preferences, their own.
 * A page that moved here from a module keeps the people who could use it there
 * (GH #263): it sets $system_page_grant = ['module' => 'assets', 'cap' => Cap::ASSETS_INTUNE]
 * before requiring this file, and an analyst with that module and capability may
 * open it too. Its endpoints check the same capability, as they always did.
 *
 * @param ?array $grant  ['module' => string, 'cap' => Cap] or null - the page's own exception
 */
function systemPageAllowed(PDO $conn, int $analystId, string $page, ?array $grant): bool
{
    if ($page === 'preferences') return true;
    if (analystIsAdmin($conn, $analystId)) return true;
    if ($grant === null || empty($grant['module']) || !isset($grant['cap'])) return false;
    require_once __DIR__ . '/../../includes/rbac.php';
    try {
        return analystCanAccessModule($conn, $analystId, (string)$grant['module'])
            && analystHasCapability($conn, $analystId, $grant['cap']);
    } catch (Throwable $e) {
        return false;   // fail closed
    }
}
