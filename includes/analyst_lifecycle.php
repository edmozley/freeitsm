<?php
/**
 * When an analyst leaves: Deactivate, Anonymise or Delete (GH #178).
 *
 * Everything in the database that refers to an analyst falls into one of these:
 *
 *   personal  their own settings and memberships - dashboard widgets, saved
 *             views, preferences, team and module membership, folder grants.
 *             Useless without them. Removed on Delete and Anonymise.
 *   secret    sign-in material - SSO links, trusted devices, reset tokens, API
 *             keys. Removed on Delete and Anonymise. (Deactivate already stops
 *             all of it working: sign-in and the REST API both refuse an
 *             inactive analyst.)
 *   live      open work assigned to them - open tickets, problems, changes and
 *             tasks, articles, projects, contracts and domains they own. Offered
 *             for reassignment through each module's own service, so the history,
 *             notifications and workflow triggers are exactly a manual reassign's.
 *   setup     something routed to them that a person has to rethink - a form's
 *             approver, an SLA rule that notifies them, a CAB seat on an open
 *             change, future rota shifts, equipment still assigned to them.
 *             Listed, never changed automatically: clearing a form's approver
 *             could let approvals through unchecked.
 *   history   who did what - notes, time, audit trails, "created by". Kept, and
 *             still credited to them. This is why most leavers are DEACTIVATED:
 *             deleting would leave a hole in the record.
 *
 * Delete is only possible when nothing in `history` or `setup` refers to them
 * (once their live work has been moved): an account made by mistake, a test
 * login. Otherwise the answer is Deactivate (reversible, names kept) or
 * Anonymise (irreversible: the records stay, the person becomes "Former
 * analyst #N").
 *
 * TRAP: every column that refers to an analyst must be in analystReferenceRegistry().
 *   tests/analyst-lifecycle.php fails on any it does not know, because a missing one
 *   is either a delete that errors (fresh installs, with foreign keys) or a dangling
 *   reference (installs without them).
 *
 * @wiki Analysts-Leaving
 */

require_once __DIR__ . '/service_context.php';

/** The placeholder an anonymised analyst becomes, e.g. "Former analyst #42". */
function analystFormerName(int $id): string {
    return 'Former analyst #' . $id;
}

/**
 * Every column that refers to an analyst, and what kind of reference it is.
 * Each row: [table, column, kind] or [table, column, kind, extraWhere] where
 * extraWhere narrows a polymorphic or partly-live column (trusted SQL, no
 * placeholders). See the file header for the kinds.
 *
 * @return array<int,array{0:string,1:string,2:string,3?:string}>
 */
function analystReferenceRegistry(): array {
    $openTicket  = "status_id NOT IN (SELECT id FROM ticket_statuses WHERE is_closed = 1) AND deleted_datetime IS NULL";
    $openChange  = "status_id NOT IN (SELECT id FROM change_statuses WHERE is_closed = 1)";
    $changeOpen  = "change_id IN (SELECT c.id FROM changes c WHERE c.status_id NOT IN (SELECT id FROM change_statuses WHERE is_closed = 1))";
    $changeShut  = "change_id NOT IN (SELECT c.id FROM changes c WHERE c.status_id NOT IN (SELECT id FROM change_statuses WHERE is_closed = 1))";
    return [
        // ── personal: their own settings, memberships and grants ─────────────
        ['analyst_teams', 'analyst_id', 'personal'],
        ['analyst_modules', 'analyst_id', 'personal'],
        ['analyst_tenant_access', 'analyst_id', 'personal'],
        ['rbac_analyst_roles', 'analyst_id', 'personal'],
        ['rbac_roles', 'managed_for_analyst_id', 'personal'],
        ['user_preferences', 'analyst_id', 'personal'],
        ['ticket_presence', 'analyst_id', 'personal'],
        ['ticket_reply_templates', 'analyst_id', 'personal'],
        ['analyst_dashboard_widgets', 'analyst_id', 'personal'],
        ['analyst_ticket_dashboard_widgets', 'analyst_id', 'personal'],
        ['analyst_software_dashboard_widgets', 'analyst_id', 'personal'],
        ['analyst_signatures', 'analyst_id', 'personal'],
        ['analyst_recent_trail', 'analyst_id', 'personal'],
        ['calendar_enrolments', 'analyst_id', 'personal'],
        ['calendar_sync_events', 'analyst_id', 'personal'],
        ['change_notifications', 'analyst_id', 'personal'],
        ['notifications', 'analyst_id', 'personal'],
        ['feature_bingo_dismissed', 'dismissed_by_analyst_id', 'personal'],
        ['watchtower_items', 'analyst_id', 'personal'],
        ['watchtower_item_members', 'analyst_id', 'personal'],
        ['table_views', 'owner_id', 'personal'],
        ['photo_album', 'analyst_id', 'personal'],
        ['warroom_channel_members', 'analyst_id', 'personal'],
        ['warroom_mentions', 'analyst_id', 'personal'],
        ['warroom_reads', 'analyst_id', 'personal'],
        ['warroom_presence', 'analyst_id', 'personal'],
        ['task_collaborators', 'analyst_id', 'personal'],
        ['project_members', 'analyst_id', 'personal'],
        ['project_ai_messages', 'analyst_id', 'personal'],
        ['project_ai_threads', 'analyst_id', 'personal'],
        ['lms_learning_group_members', 'analyst_id', 'personal'],
        ['files_shortcuts', 'analyst_id', 'personal'],
        ['files_uploads', 'analyst_id', 'personal'],
        ['form_drafts', 'owner_id', 'personal', "owner_kind = 'analyst'"],
        ['knowledge_acl', 'principal_id', 'personal', "principal_type = 'analyst'"],
        ['knowledge_user_group_members', 'member_id', 'personal', "member_type = 'analyst'"],
        ['form_audiences', 'principal_id', 'personal', "principal_type = 'analyst'"],
        ['report_pack_shares', 'target_id', 'personal', "target_type = 'analyst'"],
        ['files_permissions', 'principal_id', 'personal', "principal_type = 'analyst'"],
        ['lms_course_assignments', 'group_id', 'personal', "target_type = 'analyst'"],

        // ── secret: sign-in material ─────────────────────────────────────────
        ['analyst_sso_identities', 'analyst_id', 'secret'],
        ['trusted_devices', 'analyst_id', 'secret'],
        ['password_reset_tokens', 'analyst_id', 'secret'],
        ['api_keys', 'analyst_id', 'secret'],
        ['apikeys', 'analyst_id', 'secret'],

        // ── live: open work (moved by analystLiveGroups(); closed rows are history)
        ['tickets', 'assigned_analyst_id', 'live', $openTicket],
        ['tickets', 'owner_id', 'live', $openTicket],
        ['problems', 'assigned_analyst_id', 'live', "status_id NOT IN (SELECT id FROM problem_statuses WHERE is_closed = 1)"],
        ['changes', 'assigned_to_id', 'live', $openChange],
        ['tasks', 'assigned_analyst_id', 'live', "status_id NOT IN (SELECT id FROM task_statuses WHERE is_closed = 1)"],
        ['knowledge_articles', 'owner_id', 'live', "is_archived = 0"],
        ['projects', 'owner_analyst_id', 'live', "closed_datetime IS NULL AND COALESCE(status, '') NOT IN ('closed', 'cancelled')"],
        ['contracts', 'contract_owner_id', 'live', "COALESCE(is_active, 1) = 1"],
        ['domains', 'owner_analyst_id', 'live'],
        ['domains', 'tech_analyst_id', 'live'],
        ['jml_cases', 'owner_analyst_id', 'live', "status = 'open'"],   // Starters & leavers (4.6.0)
        // ...and the same columns once the item is closed: who had it is history.
        ['tickets', 'assigned_analyst_id', 'history', "NOT ($openTicket)"],
        ['tickets', 'owner_id', 'history', "NOT ($openTicket)"],
        ['problems', 'assigned_analyst_id', 'history', "status_id IN (SELECT id FROM problem_statuses WHERE is_closed = 1)"],
        ['changes', 'assigned_to_id', 'history', "NOT ($openChange)"],
        ['tasks', 'assigned_analyst_id', 'history', "status_id IN (SELECT id FROM task_statuses WHERE is_closed = 1)"],
        ['knowledge_articles', 'owner_id', 'history', "is_archived = 1"],
        ['projects', 'owner_analyst_id', 'history', "NOT (closed_datetime IS NULL AND COALESCE(status, '') NOT IN ('closed', 'cancelled'))"],
        ['contracts', 'contract_owner_id', 'history', "COALESCE(is_active, 1) = 0"],
        ['jml_cases', 'owner_analyst_id', 'history', "status <> 'open'"],

        // ── setup: routed to them; a person must decide ─────────────────────
        ['forms', 'approver_id', 'setup'],
        ['form_submissions', 'approver_id', 'setup', "approval_status = 'pending'"],
        ['sla_notification_rules', 'notify_analyst_id', 'setup'],
        ['changes', 'approver_id', 'setup', $openChange],
        ['change_cab_members', 'analyst_id', 'setup', $changeOpen],
        ['ticket_rota_entries', 'analyst_id', 'setup', "rota_date >= CURDATE()"],
        ['users_assets', 'analyst_id', 'setup'],
        ['knowledge_folders', 'owner_id', 'setup'],
        ['report_packs', 'owner_id', 'setup'],
        ['domain_registrar_accounts', 'owner_analyst_id', 'setup'],
        ['project_raid', 'owner_analyst_id', 'setup', "closed_datetime IS NULL"],
        ['project_benefits', 'owner_analyst_id', 'setup'],
        // The service account an inventory integration writes as (#194): a leaver's source
        // name has to be handed to whoever runs that integration now.
        ['asset_inventory_source_owners', 'owner_analyst_id', 'setup'],
        ['project_gate_items', 'analyst_id', 'setup'],
        // A template step routed to them: every future case would give them that step.
        ['jml_template_steps', 'assigned_analyst_id', 'setup'],

        // ── history: who did what; kept and credited ────────────────────────
        ['form_submissions', 'approver_id', 'history', "approval_status <> 'pending'"],
        ['changes', 'approver_id', 'history', "NOT ($openChange)"],
        ['change_cab_members', 'analyst_id', 'history', $changeShut],
        ['ticket_rota_entries', 'analyst_id', 'history', "rota_date < CURDATE()"],
        ['project_raid', 'owner_analyst_id', 'history', "closed_datetime IS NOT NULL"],
        ['ticket_audit', 'analyst_id', 'history'],
        ['jml_cases', 'created_by', 'history'],
        ['jml_audit', 'analyst_id', 'history'],
        ['ticket_notes', 'analyst_id', 'history'],
        ['ticket_time_entries', 'analyst_id', 'history'],
        ['tickets', 'deleted_by', 'history'],
        ['emails', 'assigned_analyst_id', 'history'],
        ['ticket_ai_summaries', 'generated_by', 'history'],
        ['ticket_merges', 'merged_by_id', 'history'],
        ['ticket_splits', 'split_by_id', 'history'],
        ['ticket_csat_responses', 'analyst_id', 'history'],
        ['ticket_cmdb_objects', 'created_by_analyst_id', 'history'],
        ['ticket_assets', 'created_by_analyst_id', 'history'],
        ['ticket_domains', 'created_by_analyst_id', 'history'],
        ['ticket_links', 'created_by_id', 'history'],
        ['ticket_checklists', 'created_by_id', 'history'],
        ['ticket_checklist_items', 'completed_by_id', 'history'],
        ['users_assets', 'assigned_by_analyst_id', 'history'],
        ['asset_history', 'analyst_id', 'history'],
        ['asset_inventory_sections', 'imported_by_id', 'history'],
        ['asset_connections', 'confirmed_by_id', 'history'],
        ['asset_connections', 'disconnected_by_id', 'history'],
        ['asset_review_items', 'resolved_by_id', 'history'],
        ['asset_merges', 'merged_by_id', 'history'],
        ['asset_protected_fields', 'protected_by_id', 'history'],
        ['asset_name_mappings', 'created_by_id', 'history'],
        ['asset_field_set_assets', 'created_by_analyst_id', 'history'],
        ['asset_checkout_log', 'analyst_id', 'history'],
        ['asset_disk_hide_rules', 'created_by_analyst_id', 'history'],
        ['asset_import_profiles', 'created_by_analyst_id', 'history'],
        ['asset_import_runs', 'triggered_by_analyst_id', 'history'],
        ['changes', 'requester_id', 'history'],
        ['changes', 'created_by_id', 'history'],
        ['change_attachments', 'uploaded_by_id', 'history'],
        ['change_audit', 'analyst_id', 'history'],
        ['change_comments', 'analyst_id', 'history'],
        ['change_text_revisions', 'editor_id', 'history'],
        ['change_approvals', 'opened_by_id', 'history'],
        ['change_approvals', 'decided_by_id', 'history'],
        ['change_approval_votes', 'analyst_id', 'history'],
        ['change_cab_members', 'added_by_id', 'history'],
        ['change_checklist_items', 'completed_by_id', 'history'],
        ['change_relations', 'created_by_id', 'history'],
        ['change_templates', 'created_by_id', 'history'],
        ['change_template_versions', 'created_by_id', 'history'],
        ['change_template_versions', 'approved_by_id', 'history'],
        ['change_tickets', 'created_by_id', 'history'],
        ['problems', 'created_by_id', 'history'],
        ['problem_tickets', 'created_by_id', 'history'],
        ['problem_audit', 'analyst_id', 'history'],
        ['problem_notes', 'analyst_id', 'history'],
        ['tasks', 'created_by_id', 'history'],
        ['tasks', 'completed_by_id', 'history'],
        ['task_events', 'analyst_id', 'history'],
        ['task_recurrence_versions', 'analyst_id', 'history'],
        ['task_forms', 'added_by_id', 'history'],
        ['task_procedures', 'added_by_id', 'history'],
        ['task_follow_ups', 'created_by_id', 'history'],
        ['task_checklist_item_events', 'analyst_id', 'history'],
        ['checklist_template_versions', 'analyst_id', 'history'],
        ['job_runs', 'analyst_id', 'history'],
        ['task_audit', 'analyst_id', 'history'],
        ['task_time_entries', 'analyst_id', 'history'],
        ['task_comments', 'analyst_id', 'history'],
        ['task_collaborators', 'added_by_id', 'history'],
        ['task_recurrences', 'created_by_id', 'history'],
        ['task_dependencies', 'created_by_id', 'history'],
        ['task_checklists', 'created_by_id', 'history'],
        ['task_checklist_items', 'completed_by_id', 'history'],
        ['checklist_templates', 'created_by_id', 'history'],
        ['knowledge_articles', 'author_id', 'history'],
        ['knowledge_articles', 'archived_by_id', 'history'],
        ['knowledge_article_versions', 'saved_by_id', 'history'],
        ['knowledge_folders', 'created_by_id', 'history'],
        ['knowledge_acl', 'created_by_id', 'history'],
        ['knowledge_shortcuts', 'created_by_id', 'history'],
        ['knowledge_user_groups', 'created_by_id', 'history'],
        ['knowledge_audit', 'analyst_id', 'history'],
        ['knowledge_gap_clusters', 'dismissed_by_id', 'history'],
        ['workflows', 'created_by', 'history'],
        ['software_inventory_apps', 'created_by', 'history'],
        ['software_licences', 'created_by', 'history'],
        ['api_keys', 'created_by', 'history'],
        ['forms', 'created_by', 'history'],
        ['forms', 'modified_by', 'history'],
        ['form_collections', 'created_by', 'history'],
        ['form_collections', 'closed_by', 'history'],
        ['form_submissions', 'approval_decided_by_id', 'history'],
        ['rfps', 'created_by_analyst_id', 'history'],
        ['rfp_conflicts', 'resolved_by_analyst_id', 'history'],
        ['rfp_scores', 'analyst_id', 'history'],
        ['contract_assets', 'linked_by_id', 'history'],
        ['report_packs', 'updated_by', 'history'],
        ['network_diagrams', 'created_by_analyst_id', 'history'],
        ['warroom_channels', 'created_by', 'history'],
        ['warroom_messages', 'analyst_id', 'history'],
        ['warroom_messages', 'deleted_by', 'history'],
        ['domains', 'created_by', 'history'],
        ['domain_audit', 'analyst_id', 'history'],
        ['domain_registrar_accounts', 'created_by', 'history'],
        ['domain_cmdb_objects', 'created_by_analyst_id', 'history'],
        ['domain_status_services', 'created_by_analyst_id', 'history'],
        ['domain_knowledge_articles', 'created_by_analyst_id', 'history'],
        ['projects', 'created_by_id', 'history'],
        ['projects', 'approval_by_id', 'history'],
        ['project_assets', 'created_by_analyst_id', 'history'],
        ['project_changes', 'created_by_analyst_id', 'history'],
        ['project_tickets', 'created_by_analyst_id', 'history'],
        ['project_contracts', 'created_by_analyst_id', 'history'],
        ['project_cmdb_objects', 'created_by_analyst_id', 'history'],
        ['project_knowledge_articles', 'created_by_analyst_id', 'history'],
        ['project_problems', 'created_by_analyst_id', 'history'],
        ['project_members', 'created_by_analyst_id', 'history'],
        ['project_raid', 'escalated_by_id', 'history'],
        ['project_raid', 'raised_by_id', 'history'],
        ['project_asset_targets', 'created_by_analyst_id', 'history'],
        ['project_templates', 'created_by_analyst_id', 'history'],
        ['project_milestones', 'done_by_analyst_id', 'history'],
        ['project_milestones', 'created_by_analyst_id', 'history'],
        ['project_baselines', 'created_by_id', 'history'],
        ['project_change_requests', 'raised_by_id', 'history'],
        ['project_change_requests', 'decided_by_id', 'history'],
        ['project_gate_items', 'done_by_id', 'history'],
        ['project_gate_items', 'created_by_id', 'history'],
        ['project_benefits', 'created_by_id', 'history'],
        ['project_benefit_measures', 'recorded_by_id', 'history'],
        ['project_stages', 'gate_decided_by', 'history'],
        ['project_audit', 'analyst_id', 'history'],
        ['project_budget_lines', 'created_by_id', 'history'],
        ['project_reports', 'created_by_id', 'history'],
        ['project_reports', 'updated_by_id', 'history'],
        ['project_reports', 'approved_by_id', 'history'],
        ['project_labour_rates', 'created_by_id', 'history'],
        ['rbac_roles', 'created_by_id', 'history'],
        ['manager_grants', 'created_by_analyst_id', 'history'],
        ['integration_connections', 'created_by', 'history'],
        ['integration_links', 'created_by', 'history'],
        ['directory_sync_runs', 'triggered_by_analyst_id', 'history'],
        ['carddav_write_log', 'triggered_by_analyst_id', 'history'],
        ['documents', 'uploaded_by_id', 'history'],
        ['document_links', 'linked_by_id', 'history'],
        ['document_access_log', 'analyst_id', 'history'],
        ['calendar_events', 'created_by', 'history'],
        ['calendar_connections', 'created_by', 'history'],
        ['system_logs', 'analyst_id', 'history'],
        ['lms_courses', 'created_by_id', 'history'],
        ['lms_learning_groups', 'created_by_id', 'history'],
        ['lms_progress', 'analyst_id', 'history'],
        ['lms_ct_questions', 'created_by_analyst_id', 'history'],
        ['lms_ct_tests', 'created_by_analyst_id', 'history'],
        ['lms_ct_sittings', 'created_by_analyst_id', 'history'],
        ['processes', 'created_by', 'history'],
        ['status_incidents', 'created_by_id', 'history'],
        ['status_incident_updates', 'created_by_id', 'history'],
        ['status_planned', 'created_by_id', 'history'],
        ['leases', 'created_by', 'history'],
        ['lease_assets', 'created_by', 'history'],
        ['files_folders', 'created_by', 'history'],
        ['files_folders', 'updated_by', 'history'],
        ['files_folders', 'deleted_by', 'history'],
        ['files_items', 'created_by', 'history'],
        ['files_items', 'updated_by', 'history'],
        ['files_items', 'deleted_by', 'history'],
        ['files_versions', 'uploaded_by', 'history'],
        ['files_permissions', 'granted_by', 'history'],
        ['files_audit', 'analyst_id', 'history'],
        ['files_shortcuts', 'created_by', 'history'],
    ];
}

/**
 * Text columns that hold a COPY of an analyst's name next to their id. Anonymise
 * replaces the copy where the id says it is theirs. [table, nameColumn, idColumn].
 * Plus the four audit tables' old/new values, replaced where the WHOLE value is
 * the old name (an assignment change records names, not ids) - see
 * analystAnonymise(). Names typed into free text (notes, emails) are not found.
 */
function analystNameCopies(): array {
    return [
        ['ticket_checklist_items', 'completed_by_name', 'completed_by_id'],
        ['task_checklist_items', 'completed_by_name', 'completed_by_id'],
        ['files_audit', 'analyst_name', 'analyst_id'],
    ];
}

/** Audit tables whose old_value / new_value record assignment changes by NAME. */
function analystNameAuditTables(): array {
    return ['ticket_audit', 'task_audit', 'problem_audit', 'change_audit'];
}

/**
 * Does this table.column exist on this install? Installs differ (a module's
 * tables arrive with Database Verification), so every query checks first.
 *
 * @param string $table  a table name from the registry (trusted)
 * @param string $col  a column of it (trusted)
 */
function analystLcColumnExists(PDO $conn, string $table, string $col): bool {
    static $cache = [];
    $k = "$table.$col";
    if (!array_key_exists($k, $cache)) {
        try {
            $cache[$k] = (bool) $conn->query("SHOW COLUMNS FROM `$table` LIKE " . $conn->quote($col))->fetch();
        } catch (Throwable) {
            $cache[$k] = false;
        }
    }
    return $cache[$k];
}

/**
 * How many rows of one registry entry refer to this analyst.
 *
 * @param array $ref  one analystReferenceRegistry() row
 * @param int $analystId  analysts.id
 */
function analystLcCount(PDO $conn, array $ref, int $analystId): int {
    [$table, $col] = $ref;
    if (!analystLcColumnExists($conn, $table, $col)) return 0;
    $where = "`$col` = ?" . (isset($ref[3]) ? " AND ({$ref[3]})" : '');
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM `$table` WHERE $where");
        $st->execute([$analystId]);
        return (int) $st->fetchColumn();
    } catch (Throwable) {
        return 0;   // a column the extraWhere needs is absent on this install
    }
}

/**
 * The open work that can be handed on, one group per kind of thing, each moved
 * through its module's own service. 'count' is how many rows the group holds.
 *
 * @return array<string,array{label:string,count:int}>
 */
function analystLiveGroups(PDO $conn, int $analystId): array {
    $groups = [
        'tickets'   => ['Open tickets', [['tickets', 'assigned_analyst_id'], ['tickets', 'owner_id']]],
        'problems'  => ['Open problems', [['problems', 'assigned_analyst_id']]],
        'changes'   => ['Open changes', [['changes', 'assigned_to_id']]],
        'tasks'     => ['Open tasks', [['tasks', 'assigned_analyst_id']]],
        'articles'  => ['Knowledge articles they own', [['knowledge_articles', 'owner_id']]],
        'projects'  => ['Projects they manage', [['projects', 'owner_analyst_id']]],
        'contracts' => ['Contracts they own', [['contracts', 'contract_owner_id']]],
        'domains'   => ['Domains they own or look after', [['domains', 'owner_analyst_id'], ['domains', 'tech_analyst_id']]],
        'starters'  => ['Starters & leavers cases they own', [['jml_cases', 'owner_analyst_id']]],
    ];
    $out = [];
    foreach ($groups as $key => [$label, $cols]) {
        $ids = [];
        foreach ($cols as [$t, $c]) {
            foreach (analystReferenceRegistry() as $ref) {
                if ($ref[0] === $t && $ref[1] === $c && $ref[2] === 'live') {
                    foreach (analystLcIds($conn, $ref, $analystId) as $id) $ids[$id] = true;
                }
            }
        }
        if ($ids) $out[$key] = ['label' => $label, 'count' => count($ids), 'ids' => array_keys($ids)];
    }
    return $out;
}

/**
 * The ids of the rows one registry entry matches (for the live groups).
 *
 * @param array $ref  one analystReferenceRegistry() row
 * @param int $analystId  analysts.id
 * @return int[]
 */
function analystLcIds(PDO $conn, array $ref, int $analystId): array {
    [$table, $col] = $ref;
    if (!analystLcColumnExists($conn, $table, $col)) return [];
    $where = "`$col` = ?" . (isset($ref[3]) ? " AND ({$ref[3]})" : '');
    try {
        $st = $conn->prepare("SELECT id FROM `$table` WHERE $where");
        $st->execute([$analystId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        return [];
    }
}

/** Plain-English names for the setup items, by table. */
function analystSetupLabels(): array {
    return [
        'forms'                     => 'Forms that send approvals to them',
        'form_submissions'          => 'Form requests waiting for their approval',
        'sla_notification_rules'    => 'SLA alerts that notify them',
        'changes'                   => 'Open changes waiting for their approval',
        'change_cab_members'        => 'CAB seats on open changes',
        'ticket_rota_entries'       => 'Rota shifts from today on',
        'users_assets'              => 'Equipment still assigned to them',
        'knowledge_folders'         => 'Knowledge folders they own',
        'report_packs'              => 'Report packs they own',
        'domain_registrar_accounts' => 'Registrar accounts they own',
        'project_raid'              => 'Open RAID items they own',
        'project_benefits'          => 'Project benefits they own',
        'project_gate_items'        => 'Project gate checklist items assigned to them',
    ];
}

/**
 * Everything the screen needs to explain what Deactivate, Anonymise and Delete
 * would do to this analyst.
 *
 * @return array{analyst:array,live:array,setup:array,history:int,history_top:array,personal:int,secret:int,can_delete:bool,is_last_admin:bool}
 */
function analystFootprint(PDO $conn, int $analystId): array {
    $st = $conn->prepare("SELECT id, username, full_name, email, is_active, is_admin FROM analysts WHERE id = ?");
    $st->execute([$analystId]);
    $analyst = $st->fetch(PDO::FETCH_ASSOC);
    if (!$analyst) throw new ServiceError('not_found', 'not_found', 'Analyst not found.');

    $setup = []; $history = 0; $byTable = []; $personal = 0; $secret = 0;
    $labels = analystSetupLabels();
    foreach (analystReferenceRegistry() as $ref) {
        $kind = $ref[2];
        if ($kind === 'live') continue;
        $n = analystLcCount($conn, $ref, $analystId);
        if (!$n) continue;
        if ($kind === 'setup') {
            $label = $labels[$ref[0]] ?? $ref[0];
            $setup[$label] = ($setup[$label] ?? 0) + $n;
        } elseif ($kind === 'history') {
            $history += $n;
            $byTable[$ref[0]] = ($byTable[$ref[0]] ?? 0) + $n;
        } elseif ($kind === 'personal') {
            $personal += $n;
        } elseif ($kind === 'secret') {
            $secret += $n;
        }
    }
    arsort($byTable);
    $live = analystLiveGroups($conn, $analystId);
    foreach ($live as &$g) unset($g['ids']);
    unset($g);

    $isLastAdmin = (int) $analyst['is_admin'] === 1
        && (int) $conn->query("SELECT COUNT(*) FROM analysts WHERE is_admin = 1 AND is_active = 1 AND id <> " . (int) $analystId)->fetchColumn() === 0;

    return [
        'analyst'       => ['id' => (int) $analyst['id'], 'name' => $analyst['full_name'], 'is_active' => (int) $analyst['is_active'] === 1],
        'live'          => $live,
        'setup'         => $setup,
        'history'       => $history,
        'history_top'   => array_slice($byTable, 0, 6, true),
        'personal'      => $personal,
        'secret'        => $secret,
        // Live work is moved first, so it never blocks; history and setup do.
        'can_delete'    => $history === 0 && !$setup,
        'is_last_admin' => $isLastAdmin,
    ];
}

/**
 * Hand an analyst's open work to another analyst, or to nobody (null), through
 * each module's own service. Per item, so one refusal (a project the acting
 * analyst may not change, say) does not stop the rest. Returns what moved and
 * what did not.
 *
 * @param ActorContext $ctx  the administrator doing it
 * @param int $fromId  the analyst who is leaving
 * @param ?int $toId  the analyst taking the work; null leaves it unassigned
 * @param ?array $only  group keys to move (from analystLiveGroups()); null = all
 * @return array{moved:array<string,int>,failed:array<int,string>}
 */
function analystReassignLive(PDO $conn, ActorContext $ctx, int $fromId, ?int $toId, ?array $only = null): array {
    if ($toId !== null) {
        if ($toId === $fromId) throw new ServiceError('validation', 'same_analyst', 'Choose somebody else to take the work.');
        $t = $conn->prepare("SELECT 1 FROM analysts WHERE id = ? AND is_active = 1");
        $t->execute([$toId]);
        if (!$t->fetchColumn()) throw new ServiceError('validation', 'bad_analyst', 'The analyst taking the work must be active.');
    }
    $base = dirname(__DIR__) . '/includes/services/';
    $moved = []; $failed = [];
    foreach (analystLiveGroups($conn, $fromId) as $key => $g) {
        if ($only !== null && !in_array($key, $only, true)) continue;
        foreach ($g['ids'] as $id) {
            try {
                switch ($key) {
                    case 'tickets':
                        require_once $base . 'tickets.php';
                        TicketsService::updateTicket($conn, $ctx, $id, ['assigned_analyst_id' => $toId], true);
                        break;
                    case 'problems':
                        require_once $base . 'problems.php';
                        ProblemsService::updateProblem($conn, $ctx, $id, ['assigned_analyst_id' => $toId]);
                        break;
                    case 'changes':
                        require_once $base . 'changes.php';
                        ChangesService::updateChange($conn, $ctx, $id, ['assigned_to_id' => $toId]);
                        break;
                    case 'tasks':
                        require_once $base . 'tasks.php';
                        TasksService::saveTask($conn, $ctx, ['id' => $id, 'assigned_analyst_id' => $toId]);
                        break;
                    case 'articles':
                        require_once $base . 'knowledge.php';
                        KnowledgeService::saveArticle($conn, $ctx, ['id' => $id, 'owner_id' => $toId]);
                        break;
                    case 'projects':
                        require_once $base . 'projects.php';
                        ProjectsService::updateProject($conn, $ctx, $id, ['owner_analyst_id' => $toId]);
                        break;
                    case 'contracts':
                        // Contracts has no partial update and no history table to keep.
                        $conn->prepare("UPDATE contracts SET contract_owner_id = ? WHERE id = ? AND contract_owner_id = ?")
                             ->execute([$toId, $id, $fromId]);
                        break;
                    case 'domains':
                        require_once $base . 'domains.php';
                        $d = $conn->prepare("SELECT owner_analyst_id, tech_analyst_id FROM domains WHERE id = ?");
                        $d->execute([$id]);
                        $row = $d->fetch(PDO::FETCH_ASSOC) ?: [];
                        $in = [];
                        if ((int) ($row['owner_analyst_id'] ?? 0) === $fromId) $in['owner_analyst_id'] = $toId;
                        if ((int) ($row['tech_analyst_id'] ?? 0) === $fromId)  $in['tech_analyst_id']  = $toId;
                        if ($in) DomainsService::updateDomain($conn, $ctx, $id, $in);
                        break;
                    case 'starters':
                        require_once dirname(__DIR__) . '/includes/starters/service.php';
                        StartersService::setOwner($conn, $ctx, $id, $toId);
                        break;
                }
                $moved[$key] = ($moved[$key] ?? 0) + 1;
            } catch (Throwable $e) {
                $failed[] = $g['label'] . " #$id: " . $e->getMessage();
            }
        }
    }
    return ['moved' => $moved, 'failed' => $failed];
}

/**
 * Remove everything of kind personal and secret for this analyst.
 *
 * @param int $analystId  analysts.id
 * @return int rows removed
 */
function analystRemovePersonal(PDO $conn, int $analystId): int {
    $n = 0;
    foreach (analystReferenceRegistry() as $ref) {
        if ($ref[2] !== 'personal' && $ref[2] !== 'secret') continue;
        [$table, $col] = $ref;
        if (!analystLcColumnExists($conn, $table, $col)) continue;
        $where = "`$col` = ?" . (isset($ref[3]) ? " AND ({$ref[3]})" : '');
        $st = $conn->prepare("DELETE FROM `$table` WHERE $where");
        $st->execute([$analystId]);
        $n += $st->rowCount();
    }
    return $n;
}

/**
 * Deactivate: no sign-in, no access, API keys refused - and every name kept.
 * Reversible by ticking Active again. Moving their open work is a separate,
 * optional step (analystReassignLive()), done first by the screen.
 *
 * @param ActorContext $ctx  the administrator doing it
 * @param int $analystId  the analyst leaving
 */
function analystDeactivate(PDO $conn, ActorContext $ctx, int $analystId): void {
    analystLcAssertActionable($conn, $ctx, $analystId);
    $conn->prepare("UPDATE analysts SET is_active = 0, last_modified_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$analystId]);
    analystLcLog($conn, $ctx, $analystId, 'deactivated');
}

/**
 * Anonymise: everything Deactivate does, then the person behind the records is
 * removed - personal settings and sign-in material deleted, the account's name
 * and contact details replaced, and copies of the name next to their id
 * replaced. The records themselves stay, credited to "Former analyst #N".
 * Irreversible.
 *
 * @param ActorContext $ctx  the administrator doing it
 * @param int $analystId  the analyst to anonymise
 */
function analystAnonymise(PDO $conn, ActorContext $ctx, int $analystId): void {
    analystLcAssertActionable($conn, $ctx, $analystId);
    $st = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
    $st->execute([$analystId]);
    $oldName = (string) $st->fetchColumn();
    $former  = analystFormerName($analystId);

    $conn->beginTransaction();
    try {
        analystRemovePersonal($conn, $analystId);
        $conn->prepare(
            "UPDATE analysts SET username = ?, full_name = ?, email = ?, job_title = NULL, department = NULL,
                    phone = NULL, mobile = NULL, password_hash = ?, totp_secret = NULL, totp_enabled = 0,
                    trust_device_enabled = 0, auth_provider_id = NULL, auth_follow_team = 0, is_active = 0,
                    is_admin = 0, can_access_all_tenants = 0, can_access_all_modules = 0, must_change_password = 0,
                    last_login_datetime = NULL, last_modified_datetime = UTC_TIMESTAMP()
              WHERE id = ?"
        )->execute([
            'former-analyst-' . $analystId, $former, 'former-analyst-' . $analystId . '@invalid',
            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),   // nobody knows it, ever
            $analystId,
        ]);
        foreach (analystNameCopies() as [$table, $nameCol, $idCol]) {
            if (!analystLcColumnExists($conn, $table, $nameCol)) continue;
            $conn->prepare("UPDATE `$table` SET `$nameCol` = ? WHERE `$idCol` = ?")->execute([$former, $analystId]);
        }
        // Assignment changes are recorded by NAME. Only a value that IS the old name
        // exactly is replaced - never a substring of a longer text.
        if ($oldName !== '') {
            foreach (analystNameAuditTables() as $table) {
                foreach (['old_value', 'new_value'] as $c) {
                    if (!analystLcColumnExists($conn, $table, $c)) continue;
                    $conn->prepare("UPDATE `$table` SET `$c` = ? WHERE `$c` = ?")->execute([$former, $oldName]);
                }
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    analystLcLog($conn, $ctx, $analystId, 'anonymised');
}

/**
 * Delete: only for an analyst nothing in history or setup refers to (once their
 * open work has been moved). Personal settings and sign-in material go with
 * them, in one transaction. Anything else refers to them -> refused, with the
 * reason, so the screen can offer Deactivate or Anonymise instead.
 *
 * @param ActorContext $ctx  the administrator doing it
 * @param int $analystId  the analyst to delete
 */
function analystDelete(PDO $conn, ActorContext $ctx, int $analystId): void {
    analystLcAssertActionable($conn, $ctx, $analystId);
    $f = analystFootprint($conn, $analystId);
    if ($f['live']) {
        throw new ServiceError('conflict', 'has_live_work', 'Move their open work first.');
    }
    if (!$f['can_delete']) {
        throw new ServiceError('conflict', 'has_history', 'Their name is on records that must keep it. Deactivate or anonymise them instead.');
    }
    $conn->beginTransaction();
    try {
        analystRemovePersonal($conn, $analystId);
        $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$analystId]);
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        throw $e;
    }
    analystLcLog($conn, $ctx, $analystId, 'deleted');
}

/**
 * The checks every action shares: the analyst exists, it is not you, and it is
 * not the last active administrator.
 *
 * @param ActorContext $ctx  the administrator doing it
 * @param int $analystId  the analyst being acted on
 */
function analystLcAssertActionable(PDO $conn, ActorContext $ctx, int $analystId): void {
    if ($analystId === $ctx->actorId) {
        throw new ServiceError('validation', 'self', 'You cannot do this to your own account.');
    }
    $st = $conn->prepare("SELECT is_admin, is_active FROM analysts WHERE id = ?");
    $st->execute([$analystId]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new ServiceError('not_found', 'not_found', 'Analyst not found.');
    if ((int) $a['is_admin'] === 1) {
        $others = (int) $conn->query("SELECT COUNT(*) FROM analysts WHERE is_admin = 1 AND is_active = 1 AND id <> " . (int) $analystId)->fetchColumn();
        if ($others === 0) {
            throw new ServiceError('conflict', 'last_admin', 'This is the last active administrator. Make another analyst an administrator first.');
        }
    }
}

/**
 * Record the action in the system log - by id only. Writing the name here would
 * keep, in the log of an anonymisation, the very thing it removed.
 *
 * @param ActorContext $ctx  the administrator doing it
 * @param int $analystId  the analyst acted on
 * @param string $what  'deactivated' | 'anonymised' | 'deleted'
 */
function analystLcLog(PDO $conn, ActorContext $ctx, int $analystId, string $what): void {
    try {
        // The same shape as every other writer of system_logs (e.g. api/auth/reset_password.php).
        $conn->prepare("INSERT INTO system_logs (log_type, analyst_id, details, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute(['analyst_' . $what, $ctx->actorId ?: null,
                        json_encode(['analyst_id' => $analystId, 'action' => $what])]);
    } catch (Throwable $e) {
        error_log('[analyst_lifecycle] log: ' . $e->getMessage());
    }
}
