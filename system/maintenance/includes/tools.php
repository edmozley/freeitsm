<?php
/**
 * Maintenance tools registry - single source of truth (GH #263).
 *
 * System -> Maintenance gathers the tools that find data left behind - a ticket
 * in a department that was deleted, a person whose directory source was deleted
 * - and put it right. Each entry is one card on system/maintenance/index.php.
 *
 * A tool keeps its own page and URL (Orphaned tickets is still
 * system/orphaned-tickets/): 'url' is relative to system/, like an area's in
 * getSystemAreas(), so its help page keeps the same slug and old links work.
 *
 * Each entry:
 *   url, icon      the tool's page (relative to system/) and a systemAreaIcon() key
 *   title, desc,   i18n keys, as an area's - the System search and the help
 *   keywords       landing read them too
 *   count          optional fn(PDO): int - how many items the tool has found,
 *                  shown on its card. ONE cheap query: the page runs every
 *                  count each time it opens.
 *
 * ADDING A TOOL: add its entry here and give it a help page (system/help/,
 * keyed by the same slug in _registry.php). The card, the System search and
 * the help card follow from the entry.
 *
 * @wiki System
 */

/**
 * Every Maintenance tool, in display order.
 *
 * @return array<int,array<string,mixed>> */
function getMaintenanceTools(): array
{
    return [
        [
            'url'      => 'orphaned-tickets/',
            'icon'     => 'orphaned',
            'title'    => 'system.landing.orphaned_title',
            'desc'     => 'system.landing.orphaned_desc',
            'keywords' => 'system.landing.orphaned_keywords',
            'count'    => 'maintenanceCountOrphanedTickets',
        ],
        // Imported people (#261): counts the people whose directory source was
        // deleted - the list that needs a decision whatever the sources are.
        [
            'url'      => 'directory-maintenance/',
            'icon'     => 'imported_people',
            'title'    => 'system.landing.imported_people_title',
            'desc'     => 'system.landing.imported_people_desc',
            'keywords' => 'system.landing.imported_people_keywords',
            'count'    => 'maintenanceCountImportedPeople',
        ],
        // Assets whose Intune user differs (GH #335): a holder here, while a
        // linked device names somebody else as its primary user in Intune.
        [
            'url'      => 'intune-user-mismatch/',
            'icon'     => 'intune_mismatch',
            'title'    => 'system.landing.intune_mismatch_title',
            'desc'     => 'system.landing.intune_mismatch_desc',
            'keywords' => 'system.landing.intune_mismatch_keywords',
            'count'    => 'maintenanceCountIntuneMismatch',
        ],
    ];
}

/**
 * Assets whose Intune user differs: one COUNT, the rule the page lists by
 * (intuneAssetsWithDifferentUser()).
 */
function maintenanceCountIntuneMismatch(PDO $conn): int
{
    require_once __DIR__ . '/../../../includes/intune_user_history.php';
    return intuneCountAssetsWithDifferentUser($conn);
}

/**
 * How many items a tool has found, or null when it has no count or it could not
 * be asked (a table Database Verification has not made yet, say) - the card
 * then shows none, rather than the page failing.
 *
 * @param array $tool  one entry of getMaintenanceTools()
 */
function maintenanceToolCount(PDO $conn, array $tool): ?int
{
    $fn = $tool['count'] ?? null;
    if (!$fn || !is_callable($fn)) return null;
    try {
        return (int)$fn($conn);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Imported people: still flagged as imported by a directory source that no
 * longer exists - DirectoryMaintenance's orphans, across every company.
 */
function maintenanceCountImportedPeople(PDO $conn): int
{
    require_once __DIR__ . '/../../../includes/services/directory_maintenance.php';
    return DirectoryMaintenance::orphanCount($conn);
}

/**
 * Orphaned tickets: a department_id with no department behind it, binned
 * tickets aside - the same rule as api/system/get_orphaned_tickets.php.
 */
function maintenanceCountOrphanedTickets(PDO $conn): int
{
    return (int)$conn->query(
        "SELECT COUNT(*) FROM tickets t
           LEFT JOIN departments d ON d.id = t.department_id
          WHERE t.department_id IS NOT NULL AND d.id IS NULL
            AND t.deleted_datetime IS NULL"
    )->fetchColumn();
}
