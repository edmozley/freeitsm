<?php
/**
 * Database Verify — table → module grouping.
 *
 * Powers the module filter and card colours on the DB Verify page. The mapping
 * is DERIVED from table-name prefixes (ticket_*, knowledge_*, sla_* …), so a
 * newly-added table usually lands in the right group with no edit here. Only
 * genuinely irregular names need a line in $DB_VERIFY_TABLE_OVERRIDES. Anything
 * unmatched falls to 'other' — always visible, never hidden — which is the
 * signal that a new prefix rule (or override) is worth adding.
 *
 * Module keys and colours reuse includes/module-colors.php so the cards match
 * the rest of the app. Groups with no colour of their own (rfp, other) get a
 * neutral grey. This file holds NO schema truth — it only labels tables — so it
 * can never drift the actual verification; a wrong guess just mis-files a card.
 */

require_once __DIR__ . '/module-colors.php';

/**
 * Exact-name overrides, checked before the prefix rules. Keep this short — reach
 * for it only when a table's name doesn't carry its module in the prefix.
 */
$DB_VERIFY_TABLE_OVERRIDES = [
    'users'          => 'tickets',   // requesters — a Tickets concept, not "users_*"
    'contacts'       => 'tickets',
    'freemail_domains' => 'tickets',
    'servers'        => 'assets',
    // Documents attach to everything, so they belong to no one module. System is
    // where they are administered. NOT prefixed, because "document_*" would also
    // swallow directory_sync's tables if that prefix rule ever loosened.
    'documents'      => 'system',
    // Not a table: the .htaccess repair across the upload folders (GH #115).
    'upload folders' => 'system',
    'document_links' => 'system',
    // Feature Bingo's "Not for us" list - administered in System.
    'feature_bingo_dismissed' => 'system',
    // The self-service portal's people and their bell sit with the requesters
    // (`users` => tickets), not in "other" (#62).
    'manager_grants'       => 'tickets',
    'portal_notifications' => 'tickets',
];

/**
 * Ordered prefix → module. The FIRST prefix that matches (as a leading string)
 * wins, so more specific prefixes must come before broader ones (users_assets
 * before users). Insertion order is the match order.
 */
$DB_VERIFY_MODULE_PREFIXES = [
    // --- System / security / access management ---
    'analyst'          => 'system',
    'rbac_'            => 'system',
    'api_key'          => 'system',
    'apikeys'          => 'system',
    'api_rate'         => 'system',
    'auth_providers'   => 'system',
    'tenant'           => 'system',
    'team'             => 'system',
    'department'       => 'system',
    'system_'          => 'system',
    'trusted_devices'  => 'system',
    'password_reset'   => 'system',
    'ip_login_bans'    => 'system',
    'user_preferences' => 'system',
    'user_sso'         => 'system',
    'cost_centre'      => 'system',   // GH #160 - System -> Cost Centres
    'photo_album'      => 'system',   // System -> Photo Album
    // --- Tickets (and everything that feeds the inbox) ---
    'users_assets'     => 'assets',   // must precede the 'users' fallthrough
    'ticket'           => 'tickets',
    'emails'           => 'tickets',
    'email_'           => 'tickets',
    'messaging_'       => 'tickets',
    'mailbox_'         => 'tickets',
    'target_mailboxes' => 'tickets',
    'webchat_'         => 'tickets',
    'sla_'             => 'tickets',
    'rota_'            => 'tickets',
    'users'            => 'tickets',
    // --- Changes / Problems ---
    'change'           => 'changes',
    'problem'          => 'problems',
    // --- Assets / device sync ---
    'asset'            => 'assets',
    'intune_'          => 'assets',
    // --- CMDB ---
    'cmdb_'            => 'cmdb',
    // --- Knowledge / Learning / Wiki ---
    'knowledge_'       => 'knowledge',
    'lms_'             => 'lms',
    'wiki_'            => 'wiki',
    // --- Projects (3.2.0) - before 'process', which it does not overlap but sits beside ---
    'project'          => 'projects',
    // --- Mappers ---
    'process'          => 'process-mapper',
    'network_'         => 'network-mapper',
    // --- Tasks / Software / Forms / Contracts ---
    'task'             => 'tasks',
    'software_'        => 'software',
    'form'             => 'forms',
    'contract'         => 'contracts',
    'payment_schedules'=> 'contracts',
    'supplier'         => 'contracts',
    'domain_'          => 'domains',    // domains, domain_statuses, domain_audit… (tenant_domains is caught by 'tenant' first)
    'domains'          => 'domains',
    // --- Automation ---
    'workflow'         => 'workflow',
    'webhook_'         => 'workflow',
    // --- Calendar / Service status ---
    'calendar_'        => 'calendar',
    'status_'          => 'service-status',
    'service_'         => 'service-status',
    // --- RFP builder (internal-only; no module colour of its own) ---
    // --- War room (fallback chat) ---
    'warroom_'         => 'war-room',
    // --- RFP builder (internal-only; no module colour of its own) ---
    'rfp'              => 'rfp',
];

/** Human labels + a neutral fallback colour for groups outside module-colors. */
function dbVerifyModuleLabels(): array {
    return [
        'tickets'        => 'Tickets',
        'assets'         => 'Assets',
        'knowledge'      => 'Knowledge',
        'changes'        => 'Changes',
        'problems'       => 'Problems',
        'calendar'       => 'Calendar',
        'service-status' => 'Service status',
        'war-room'       => 'War room',
        'software'       => 'Software',
        'forms'          => 'Forms',
        'contracts'      => 'Contracts',
        'domains'        => 'Domains',
        'wiki'           => 'Wiki',
        'lms'            => 'Learning',
        'process-mapper' => 'Process mapper',
        'tasks'          => 'Tasks',
        'projects'       => 'Projects',
        'cmdb'           => 'CMDB',
        'network-mapper' => 'Network mapper',
        'workflow'       => 'Workflows',
        'system'         => 'System',
        'rfp'            => 'RFP builder',
        'other'          => 'Other',
    ];
}

/** Which module a table belongs to. Never throws; unknown tables → 'other'. */
function dbVerifyModuleForTable(string $table): string {
    global $DB_VERIFY_TABLE_OVERRIDES, $DB_VERIFY_MODULE_PREFIXES;
    if (isset($DB_VERIFY_TABLE_OVERRIDES[$table])) {
        return $DB_VERIFY_TABLE_OVERRIDES[$table];
    }
    foreach ($DB_VERIFY_MODULE_PREFIXES as $prefix => $module) {
        if (strncmp($table, $prefix, strlen($prefix)) === 0) {
            return $module;
        }
    }
    return 'other';
}

/**
 * Metadata for every known module group: key => {label, color, colorHover}.
 * The front end filters this down to the groups that actually have a card.
 */
function dbVerifyModuleMeta(): array {
    $labels  = dbVerifyModuleLabels();
    $colors  = function_exists('getModuleColors') ? getModuleColors() : [];
    $fallback = ['#64748b', '#475569'];   // slate — for rfp / other
    $out = [];
    foreach ($labels as $key => $label) {
        $c = $colors[$key] ?? $fallback;
        $out[$key] = [
            'label'      => $label,
            'color'      => $c[0],
            'colorHover' => $c[1] ?? $c[0],
        ];
    }
    return $out;
}
