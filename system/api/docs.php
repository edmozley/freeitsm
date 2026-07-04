<?php
/**
 * System - API documentation + interactive tester.
 *
 * Documents every REST API v1 endpoint (data-driven from the SPEC array
 * below — keep it in step with api/v1/index.php's route table) and lets an
 * admin fire real requests against this install and inspect the response.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

require_once '../../includes/functions.php';

$current_page = 'api';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$apiBaseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'api/v1';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - API documentation</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .docs-container { height: calc(100vh - 48px); overflow-y: auto; padding: 30px 20px; }
        .page-title { font-size: 22px; font-weight: 600; color: #333; margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: #888; margin: 0 0 20px 0; }
        .page-subtitle a { color: #546e7a; }

        .settings-card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin-bottom: 24px; }
        .settings-card h3 { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 4px 0; }
        .settings-card .card-desc { font-size: 13px; color: #888; margin: 0 0 16px 0; line-height: 1.5; }

        .key-bar { display: flex; align-items: center; gap: 10px; }
        .key-bar input { flex: 1; padding: 9px 11px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; font-family: Consolas, Monaco, monospace; }
        .key-bar input:focus { outline: none; border-color: #546e7a; }
        .key-hint { font-size: 12px; color: #999; margin-top: 8px; }
        .key-hint a { color: #546e7a; }

        .section-title { font-size: 14px; font-weight: 700; color: #546e7a; text-transform: uppercase; letter-spacing: 0.5px; margin: 26px 0 10px 0; }

        .endpoint { border: 1px solid #e8e8e8; border-radius: 8px; background: #fff; margin-bottom: 10px; overflow: hidden; }
        .ep-head { display: flex; align-items: center; gap: 12px; padding: 12px 16px; cursor: pointer; }
        .ep-head:hover { background: #fafbfc; }
        .method { flex: none; width: 62px; text-align: center; padding: 4px 0; border-radius: 5px; font-size: 11px; font-weight: 700; color: #fff; font-family: Consolas, Monaco, monospace; }
        .method.GET { background: #2e7d32; }
        .method.POST { background: #1565c0; }
        .method.PATCH { background: #e65100; }
        .method.DELETE { background: #c62828; }
        .ep-path { font-family: Consolas, Monaco, monospace; font-size: 13px; color: #333; }
        .ep-summary { font-size: 12.5px; color: #888; margin-left: auto; text-align: right; }
        .ep-perm { flex: none; font-size: 11px; background: #e3f2fd; color: #1565c0; border-radius: 10px; padding: 2px 8px; white-space: nowrap; }

        .ep-body { display: none; border-top: 1px solid #eee; padding: 16px; }
        .endpoint.open .ep-body { display: block; }
        .ep-desc { font-size: 13px; color: #555; line-height: 1.6; margin: 0 0 14px 0; }

        table.params { width: 100%; border-collapse: collapse; font-size: 12.5px; margin-bottom: 14px; }
        table.params th { text-align: left; color: #888; font-weight: 600; font-size: 11.5px; padding: 6px 8px; border-bottom: 1px solid #eee; }
        table.params td { padding: 6px 8px; border-bottom: 1px solid #f5f5f5; color: #444; vertical-align: top; }
        table.params code { background: #f5f7fa; padding: 1px 5px; border-radius: 3px; font-size: 12px; }
        .param-req { color: #c62828; font-weight: 600; font-size: 11px; }

        .tryit { background: #f8fafb; border: 1px solid #e8eef1; border-radius: 6px; padding: 14px; }
        .tryit h4 { margin: 0 0 10px 0; font-size: 13px; color: #37474f; }
        .try-row { display: flex; gap: 8px; margin-bottom: 8px; align-items: center; flex-wrap: wrap; }
        .try-row label { font-size: 12px; color: #666; width: 150px; flex: none; }
        .try-row input { flex: 1; min-width: 160px; padding: 7px 9px; border: 1px solid #ddd; border-radius: 4px; font-size: 12.5px; font-family: Consolas, Monaco, monospace; }
        .try-body { width: 100%; min-height: 110px; padding: 9px; border: 1px solid #ddd; border-radius: 4px; font-size: 12.5px; font-family: Consolas, Monaco, monospace; box-sizing: border-box; resize: vertical; }
        .send-btn { padding: 8px 22px; background: #546e7a; color: #fff; border: none; border-radius: 5px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .send-btn:hover { background: #455a64; }
        .send-btn:disabled { opacity: 0.6; cursor: wait; }

        .resp { margin-top: 12px; display: none; }
        .resp.show { display: block; }
        .resp-status { font-size: 12.5px; font-weight: 700; margin-bottom: 6px; }
        .resp-status.ok { color: #2e7d32; }
        .resp-status.err { color: #c62828; }
        .resp pre { background: #263238; color: #eceff1; border-radius: 6px; padding: 14px; font-size: 12px; line-height: 1.5; overflow-x: auto; max-height: 420px; overflow-y: auto; margin: 0; }

        .curl-line { margin-top: 10px; font-size: 11.5px; color: #999; }
        .curl-line code { display: block; background: #f5f7fa; border: 1px solid #e8e8e8; border-radius: 4px; padding: 8px 10px; margin-top: 4px; font-size: 11.5px; color: #555; overflow-x: auto; white-space: pre; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="docs-container">
        <h1 class="page-title">API documentation</h1>
        <p class="page-subtitle">
            REST API v1 — base URL <code id="baseUrl"><?php echo htmlspecialchars($apiBaseUrl); ?></code>.
            Manage keys on the <a href="index.php">API keys page</a>.
        </p>

        <div class="settings-card">
            <h3>Test key</h3>
            <p class="card-desc">Paste an API key to use the "Try it" panels below. It is kept in this browser only (localStorage) and sent as <code>Authorization: Bearer &lt;key&gt;</code>.</p>
            <div class="key-bar">
                <input type="password" id="testKey" placeholder="fitsm_…" autocomplete="off">
                <button class="send-btn" id="pingBtn">Test</button>
            </div>
            <div class="key-hint" id="pingResult">Requests honour the key's permissions, company scope and rate limit — exactly like a real integration.</div>
        </div>

        <div id="docs"></div>
    </div>

    <script>
    const BASE = document.getElementById('baseUrl').textContent;

    // --- Endpoint specification (keep in step with api/v1/index.php) --------
    const P = (name, where, desc, req) => ({name, in: where, desc, req: !!req});
    const SPEC = [
    {section: 'Getting started', items: [
        {m: 'GET', p: '/ping', perm: 'none', s: 'Check a key works',
         d: 'Verifies authentication and returns what the key is allowed to do: its permissions, company scope and expiry. No permission required — any valid key can call it.',
         params: []},
        {m: 'GET', p: '/', perm: 'none', s: 'List all endpoints',
         d: 'Returns the API version and the full endpoint index.',
         params: []},
    ]},
    {section: 'Tickets', items: [
        {m: 'GET', p: '/tickets', perm: 'tickets.read', s: 'List / search tickets',
         d: 'Lists tickets with filtering, search, sorting and pagination. Trash is excluded unless deleted=true. Results are limited to the key\'s company scope.',
         params: [
            P('state', 'query', '"open", "closed" or "all" (default all)'),
            P('status', 'query', 'Filter by status name, e.g. "In Progress" (or use status_id)'),
            P('priority', 'query', 'Filter by priority name (or use priority_id)'),
            P('assigned_analyst_id', 'query', 'Filter by assignee; unassigned=true finds unassigned tickets'),
            P('requester_email', 'query', 'Filter by the requester\'s email (or use user_id)'),
            P('ticket_type_id / origin_id / department_id / company_id', 'query', 'More id filters'),
            P('q', 'query', 'Search in subject and ticket number'),
            P('created_since / created_before / updated_since / closed_since', 'query', 'ISO 8601 date-time filters'),
            P('deleted', 'query', 'true lists the trash instead'),
            P('sort', 'query', 'created_at, updated_at, closed_at, id, subject, ticket_number, priority, status — prefix with "-" for descending (default -created_at)'),
            P('page / per_page', 'query', 'Pagination (default 1 / 25, max per_page 100)'),
         ]},
        {m: 'POST', p: '/tickets', perm: 'tickets.create', s: 'Create a ticket',
         d: 'Creates a ticket exactly like the UI: the requester is found or created by email, the description becomes the ticket\'s initial message, an audit entry is written and the workflow engine fires ticket.created. Defaults: status Open, priority Normal, unassigned, the key\'s default company.',
         params: [
            P('subject', 'body', 'Ticket subject', true),
            P('requester_email', 'body', 'Requester\'s email — found or created automatically', true),
            P('requester_name', 'body', 'Requester\'s display name (used if the requester is new)'),
            P('description', 'body', 'The request text (plain text; becomes the ticket body)'),
            P('status / status_id', 'body', 'Status by name or id (default Open)'),
            P('priority / priority_id', 'body', 'Priority by name or id (default Normal)'),
            P('ticket_type_id / origin_id / department_id', 'body', 'Optional classification ids'),
            P('assigned_analyst_id', 'body', 'Assign on creation'),
            P('company_id', 'body', 'File under a specific company (must be in the key\'s scope)'),
            P('mailbox_id', 'body', 'Send-from mailbox for later replies'),
         ],
         body: {subject: 'Printer on floor 2 is jammed', requester_email: 'jane@example.com', requester_name: 'Jane Smith', description: 'Paper jam error, tray 2.', priority: 'High'}},
        {m: 'GET', p: '/tickets/{id}', perm: 'tickets.read', s: 'Get one ticket',
         d: 'Full ticket detail including the original request body (description_html).',
         params: [P('id', 'path', 'Ticket id', true)]},
        {m: 'PATCH', p: '/tickets/{id}', perm: 'tickets.update', s: 'Update a ticket',
         d: 'Updates any combination of fields. Behaves exactly like the UI: closing/reopening maintains closed_datetime, assignment keeps owner in sync and sends the assignment email, closing sends the closure email and can auto-trigger CSAT, every change is written to the audit log, and the workflow engine fires status/priority/assignment events. Send only the fields you want to change; explicit null clears a nullable field.',
         params: [
            P('id', 'path', 'Ticket id', true),
            P('subject', 'body', 'New subject'),
            P('status / status_id', 'body', 'New status by name or id'),
            P('priority / priority_id', 'body', 'New priority (null clears)'),
            P('ticket_type_id / origin_id / department_id', 'body', 'New classification (null clears)'),
            P('assigned_analyst_id', 'body', 'Reassign (null unassigns)'),
            P('first_time_fix / it_training_provided', 'body', 'Booleans'),
            P('work_start_at', 'body', 'Scheduled work start, ISO 8601 (null clears)'),
            P('company_id', 'body', 'Move the ticket to another company (key must be scoped to both)'),
         ],
         body: {status: 'In Progress', priority: 'High', assigned_analyst_id: 1}},
        {m: 'DELETE', p: '/tickets/{id}', perm: 'tickets.delete', s: 'Trash a ticket',
         d: 'Soft-deletes the ticket (moves it to the trash, same as the UI). Restore it with POST /tickets/{id}/restore.',
         params: [P('id', 'path', 'Ticket id', true)]},
        {m: 'POST', p: '/tickets/{id}/restore', perm: 'tickets.restore', s: 'Restore from trash',
         d: 'Restores a trashed ticket.',
         params: [P('id', 'path', 'Ticket id', true)]},
    ]},
    {section: 'Ticket notes, conversation & history', items: [
        {m: 'GET', p: '/tickets/{id}/notes', perm: 'ticket_notes.read', s: 'List notes',
         d: 'All notes on the ticket, oldest first, with is_internal marking private analyst notes.',
         params: [P('id', 'path', 'Ticket id', true)]},
        {m: 'POST', p: '/tickets/{id}/notes', perm: 'ticket_notes.create', s: 'Add a note',
         d: 'Adds a note attributed to the analyst the key acts as. Defaults to internal (private).',
         params: [
            P('id', 'path', 'Ticket id', true),
            P('text', 'body', 'The note text', true),
            P('is_internal', 'body', 'false makes it a public note (default true)'),
         ],
         body: {text: 'Chased the supplier — replacement part due Friday.', is_internal: true}},
        {m: 'GET', p: '/tickets/{id}/thread', perm: 'ticket_thread.read', s: 'Get the conversation',
         d: 'The full message thread — inbound emails/messages and outbound replies — oldest first, with HTML bodies.',
         params: [P('id', 'path', 'Ticket id', true)]},
        {m: 'GET', p: '/tickets/{id}/audit', perm: 'ticket_audit.read', s: 'Get the change history',
         d: 'Every audited change (status, priority, owner, …) with old/new values and who made it.',
         params: [P('id', 'path', 'Ticket id', true)]},
        {m: 'GET', p: '/tickets/{id}/sla', perm: 'ticket_sla.read', s: 'Get live SLA state',
         d: 'The live SLA computation for the ticket: response/resolution targets, elapsed and remaining business minutes, percentages and breach flags — the same engine the UI uses.',
         params: [P('id', 'path', 'Ticket id', true)]},
    ]},
    {section: 'Time tracking', items: [
        {m: 'GET', p: '/tickets/{id}/time-entries', perm: 'ticket_time_entries.read', s: 'List time entries',
         d: 'Active time entries logged against the ticket.',
         params: [P('id', 'path', 'Ticket id', true)]},
        {m: 'POST', p: '/tickets/{id}/time-entries', perm: 'ticket_time_entries.create', s: 'Log time',
         d: 'Logs time against the ticket, attributed to the analyst the key acts as.',
         params: [
            P('id', 'path', 'Ticket id', true),
            P('minutes', 'body', 'Minutes spent (positive integer)', true),
            P('notes', 'body', 'What the time was spent on'),
            P('entry_at', 'body', 'When the work happened, ISO 8601 (default now)'),
         ],
         body: {minutes: 30, notes: 'Remote session with the user'}},
        {m: 'DELETE', p: '/tickets/{id}/time-entries/{entry_id}', perm: 'ticket_time_entries.delete', s: 'Remove a time entry',
         d: 'Soft-deletes a time entry (same as the UI).',
         params: [P('id', 'path', 'Ticket id', true), P('entry_id', 'path', 'Time entry id', true)]},
    ]},
    {section: 'Assets', items: [
        {m: 'GET', p: '/assets', perm: 'assets.read', s: 'List / search assets',
         d: 'Lists assets with hardware, classification and lifecycle filters. Assets are install-wide (they have no company), so company scope does not apply.',
         params: [
            P('q', 'query', 'Search hostname, service tag, model and manufacturer'),
            P('hostname / service_tag', 'query', 'Exact lookups'),
            P('asset_type_id / asset_status_id / location_id / supplier_id', 'query', 'Classification filters'),
            P('assigned_user_id', 'query', 'Assets assigned to a requester; unassigned=true finds unassigned assets'),
            P('warranty_within_days', 'query', 'Warranty expiring within N days (the dashboard\'s "expiring soon" shape)'),
            P('warranty_expired', 'query', 'true = warranty already expired'),
            P('not_seen_days', 'query', 'Not reported by the inventory agent for N days (or never)'),
            P('sort', 'query', 'hostname, id, first_seen, last_seen, warranty_expiry, purchase_date, model — prefix "-" for descending (default hostname)'),
            P('page / per_page', 'query', 'Pagination (default 1 / 25, max per_page 100)'),
         ]},
        {m: 'POST', p: '/assets', perm: 'assets.create', s: 'Create an asset',
         d: 'Creates an asset record. hostname is the identity the inventory agent and Intune sync upsert on, so a duplicate hostname is refused (409) — PATCH the existing asset instead. All other fields are optional and match PATCH below.',
         params: [
            P('hostname', 'body', 'Unique hostname (max 50 chars)', true),
            P('asset_type_id / asset_status_id / location_id', 'body', 'Classification'),
            P('purchase_date / purchase_cost / supplier_id / order_number / warranty_expiry', 'body', 'Lifecycle (dates YYYY-MM-DD)'),
            P('manufacturer / model / service_tag / memory / operating_system / cpu_name / …', 'body', 'Hardware & OS fields'),
         ],
         body: {hostname: 'LT-0042', asset_type_id: 1, service_tag: 'ABC1234', manufacturer: 'Dell', model: 'Latitude 5440', warranty_expiry: '2028-06-30'}},
        {m: 'GET', p: '/assets/{id}', perm: 'assets.read', s: 'Get one asset',
         d: 'Full asset detail — hardware, OS, network, lifecycle, location path — plus the currently assigned users inline.',
         params: [P('id', 'path', 'Asset id', true)]},
        {m: 'PATCH', p: '/assets/{id}', perm: 'assets.update', s: 'Update an asset',
         d: 'Updates any combination of fields. Every change is written to the asset history with the same field keys the UI uses; changing warranty_expiry re-syncs the calendar\'s warranty events. Send only what changes; null clears a field. Unknown lookup ids are a 422.',
         params: [
            P('id', 'path', 'Asset id', true),
            P('asset_type_id / asset_status_id / location_id / supplier_id', 'body', 'Classification (null clears)'),
            P('purchase_date / purchase_cost / order_number / warranty_expiry', 'body', 'Lifecycle'),
            P('hostname', 'body', 'Rename — uniqueness enforced (409 on clash)'),
            P('manufacturer / model / service_tag / memory / operating_system / …', 'body', 'Hardware & OS fields'),
         ],
         body: {asset_status_id: 2, location_id: 3, warranty_expiry: '2028-06-30'}},
    ]},
    {section: 'Asset assignments, history & inventory', items: [
        {m: 'GET', p: '/assets/{id}/assignments', perm: 'asset_assignments.read', s: 'Who has it',
         d: 'The requesters this asset is currently assigned to, with dates, due-back and who assigned it.',
         params: [P('id', 'path', 'Asset id', true)]},
        {m: 'POST', p: '/assets/{id}/assignments', perm: 'asset_assignments.create', s: 'Assign (check out)',
         d: 'Assigns the asset to an existing requester — exactly like the UI: the custody log records a check-out and the history records the assignment. The requester must already exist (create one with POST /users).',
         params: [
            P('id', 'path', 'Asset id', true),
            P('user_id', 'body', 'Requester id (or use user_email)'),
            P('user_email', 'body', 'Requester email (exact match)'),
            P('notes', 'body', 'Assignment notes'),
            P('expected_return_date', 'body', 'Due back, YYYY-MM-DD'),
         ],
         body: {user_email: 'jane@example.com', notes: 'Loan laptop', expected_return_date: '2026-08-01'}},
        {m: 'DELETE', p: '/assets/{id}/assignments/{user_id}', perm: 'asset_assignments.delete', s: 'Unassign (check in)',
         d: 'Removes the assignment; the custody log records a check-in and the history records the return.',
         params: [P('id', 'path', 'Asset id', true), P('user_id', 'path', 'Requester id', true)]},
        {m: 'GET', p: '/assets/{id}/history', perm: 'asset_history.read', s: 'Change history',
         d: 'Every audited change (type, status, location, warranty, assignments, …) with old/new values, newest first.',
         params: [P('id', 'path', 'Asset id', true)]},
        {m: 'GET', p: '/assets/{id}/custody', perm: 'asset_history.read', s: 'Custody log',
         d: 'The check-out / check-in trail: who had the asset, when, expected return, and which analyst processed it.',
         params: [P('id', 'path', 'Asset id', true)]},
        {m: 'GET', p: '/assets/{id}/disks', perm: 'asset_inventory.read', s: 'Disks',
         d: 'Agent-collected disks with size, free space and used percent.',
         params: [P('id', 'path', 'Asset id', true)]},
        {m: 'GET', p: '/assets/{id}/network-adapters', perm: 'asset_inventory.read', s: 'Network adapters',
         d: 'Agent-collected adapters with MAC, IP, subnet, gateway and DHCP flag.',
         params: [P('id', 'path', 'Asset id', true)]},
        {m: 'GET', p: '/assets/{id}/devices', perm: 'asset_inventory.read', s: 'Devices',
         d: 'Agent-collected device-manager entries (class, name, status, driver).',
         params: [P('id', 'path', 'Asset id', true)]},
        {m: 'GET', p: '/assets/{id}/software', perm: 'asset_inventory.read', s: 'Installed software',
         d: 'Agent-collected installed software. System components are excluded unless include_components=true.',
         params: [P('id', 'path', 'Asset id', true), P('include_components', 'query', 'true includes system components')]},
    ]},
    {section: 'Problems', items: [
        {m: 'GET', p: '/problems', perm: 'problems.read', s: 'List / search problems',
         d: 'Lists problems with filters, search, sorting and pagination. Problems are company-scoped: results are limited to the key\'s companies.',
         params: [
            P('state', 'query', '"open", "closed" or "all" (default all)'),
            P('status / status_id', 'query', 'Filter by problem status name or id'),
            P('priority / priority_id', 'query', 'Filter by problem priority name or id'),
            P('assigned_analyst_id', 'query', 'Filter by assignee'),
            P('is_known_error', 'query', 'true / false — known-error database filter'),
            P('company_id', 'query', 'One company (must be in the key\'s scope)'),
            P('q', 'query', 'Search title and problem number (PRB-…)'),
            P('created_since / updated_since', 'query', 'ISO 8601 date-time filters'),
            P('sort', 'query', 'created_at, updated_at, closed_at, id, title, problem_number, priority, status — prefix "-" for descending (default -created_at)'),
            P('page / per_page', 'query', 'Pagination (default 1 / 25, max per_page 100)'),
         ]},
        {m: 'POST', p: '/problems', perm: 'problems.create', s: 'Create a problem',
         d: 'Creates a problem exactly like the UI: PRB-##### number stamped automatically, default status applied if none given, a "created" entry written to the audit trail. Defaults to the key\'s default company.',
         params: [
            P('title', 'body', 'Problem title', true),
            P('description', 'body', 'What\'s the problem?'),
            P('status / status_id', 'body', 'By name or id (default: the module\'s default status)'),
            P('priority / priority_id', 'body', 'By name or id'),
            P('assigned_analyst_id', 'body', 'Assign on creation'),
            P('root_cause / workaround', 'body', 'RCA fields'),
            P('is_known_error', 'body', 'Boolean — flag as a known error'),
            P('company_id', 'body', 'File under a specific company (must be in the key\'s scope)'),
         ],
         body: {title: 'Recurring VPN drops on London office link', description: 'Multiple users report VPN disconnects since 28 June.', priority: 'High'}},
        {m: 'GET', p: '/problems/{id}', perm: 'problems.read', s: 'Get one problem',
         d: 'Full problem detail including the linked incidents (tickets) and linked changes inline.',
         params: [P('id', 'path', 'Problem id', true)]},
        {m: 'PATCH', p: '/problems/{id}', perm: 'problems.update', s: 'Update a problem',
         d: 'Updates any combination of fields. Every change lands in the problem audit trail with the same field keys the UI writes; moving into a closed status sets closed_at (and reopening clears it). Send only what changes; null clears nullable fields.',
         params: [
            P('id', 'path', 'Problem id', true),
            P('title / description', 'body', 'Text fields'),
            P('status / status_id', 'body', 'New status by name or id'),
            P('priority / priority_id', 'body', 'New priority (null clears)'),
            P('assigned_analyst_id', 'body', 'Reassign (null unassigns)'),
            P('root_cause / workaround', 'body', 'RCA fields (null clears)'),
            P('is_known_error', 'body', 'Boolean'),
         ],
         body: {status: 'Root Cause Identified', root_cause: 'Faulty SFP on the primary switch uplink.', workaround: 'Failover to secondary link.'}},
        {m: 'DELETE', p: '/problems/{id}', perm: 'problems.delete', s: 'Delete a problem',
         d: 'Permanently deletes the problem and everything attached to it (links, notes, audit trail) — same as the UI\'s delete. This is NOT a soft delete; there is no restore.',
         params: [P('id', 'path', 'Problem id', true)]},
    ]},
    {section: 'Problem notes, history & links', items: [
        {m: 'GET', p: '/problems/{id}/notes', perm: 'problem_notes.read', s: 'Read the journal',
         d: 'The problem\'s append-only journal, newest first.',
         params: [P('id', 'path', 'Problem id', true)]},
        {m: 'POST', p: '/problems/{id}/notes', perm: 'problem_notes.create', s: 'Add a journal note',
         d: 'Appends a note attributed to the analyst the key acts as. Notes are immutable — no edit or delete, same as the UI.',
         params: [P('id', 'path', 'Problem id', true), P('note', 'body', 'The note text', true)],
         body: {note: 'Vendor confirmed firmware bug; fix scheduled in change #42.'}},
        {m: 'GET', p: '/problems/{id}/audit', perm: 'problem_audit.read', s: 'Change history',
         d: 'Every audited change (created / modified rows with field, old/new values and analyst), newest first.',
         params: [P('id', 'path', 'Problem id', true)]},
        {m: 'POST', p: '/problems/{id}/tickets', perm: 'problem_links.create', s: 'Link an incident',
         d: 'Links a ticket to the problem. The ticket must belong to the same company as the problem (multi-company installs) and be visible to the key. Already linked → 409.',
         params: [P('id', 'path', 'Problem id', true), P('ticket_id', 'body', 'Ticket id to link', true)],
         body: {ticket_id: 1}},
        {m: 'DELETE', p: '/problems/{id}/tickets/{ticket_id}', perm: 'problem_links.delete', s: 'Unlink an incident',
         d: 'Removes the incident link.',
         params: [P('id', 'path', 'Problem id', true), P('ticket_id', 'path', 'Linked ticket id', true)]},
        {m: 'POST', p: '/problems/{id}/changes', perm: 'problem_links.create', s: 'Link a change',
         d: 'Links a change (the fix) to the problem via the shared change-relations mechanism.',
         params: [P('id', 'path', 'Problem id', true), P('change_id', 'body', 'Change id to link', true)],
         body: {change_id: 1}},
        {m: 'DELETE', p: '/problems/{id}/changes/{change_id}', perm: 'problem_links.delete', s: 'Unlink a change',
         d: 'Removes the change link.',
         params: [P('id', 'path', 'Problem id', true), P('change_id', 'path', 'Linked change id', true)]},
    ]},
    {section: 'Changes', items: [
        {m: 'GET', p: '/changes', perm: 'changes.read', s: 'List / search changes',
         d: 'Lists changes with filters, search, sorting and pagination. Changes are install-wide (not company-scoped), matching the UI.',
         params: [
            P('state', 'query', '"open", "closed" or "all" (default all)'),
            P('status / status_id', 'query', 'Filter by status name (e.g. "Pending Approval") or id'),
            P('change_type / change_type_id', 'query', 'Standard / Normal / Emergency, by name or id'),
            P('priority / priority_id · impact / impact_id', 'query', 'By name or id'),
            P('category_id / requester_id / assigned_to_id / approver_id', 'query', 'More id filters'),
            P('cab_required', 'query', 'true / false'),
            P('risk_level', 'query', 'Low, Medium, High, Very High, Critical'),
            P('q', 'query', 'Search title; "CHG-0042" finds a change by its reference'),
            P('work_start_from / work_start_to', 'query', 'Scheduled-work window (ISO 8601) — the calendar shape'),
            P('created_since / modified_since', 'query', 'ISO 8601 date-time filters'),
            P('sort', 'query', 'created_at, modified_at, id, title, work_start_at, risk_score, priority, status — prefix "-" for descending (default -created_at)'),
            P('page / per_page', 'query', 'Pagination (default 1 / 25, max per_page 100)'),
         ]},
        {m: 'POST', p: '/changes', perm: 'changes.create', s: 'Create a change',
         d: 'Creates a change exactly like the UI: defaults Normal / Draft / Medium / Medium, risk score and level computed server-side from likelihood × impact (1-5 each), a creation entry written to the audit trail. The CHG-#### reference is derived from the id.',
         params: [
            P('title', 'body', 'Change title', true),
            P('change_type / change_type_id · status / status_id · priority / priority_id · impact / impact_id', 'body', 'Lookups by name or id (defaults applied when omitted)'),
            P('category_id', 'body', 'Change category'),
            P('requester_id / assigned_to_id / approver_id', 'body', 'Analyst ids'),
            P('work_start_at / work_end_at / outage_start_at / outage_end_at', 'body', 'Schedule, ISO 8601'),
            P('description / reason_for_change / risk_evaluation / test_plan / rollback_plan', 'body', 'The plan bodies'),
            P('risk_likelihood / risk_impact_score', 'body', '1-5 each — score and level are computed'),
            P('cab_required / cab_approval_type', 'body', 'Boolean + "all" or "majority"'),
         ],
         body: {title: 'Replace SFP on core switch uplink', change_type: 'Normal', priority: 'High', reason_for_change: 'Fixes PRB-00005 (recurring VPN drops).', risk_likelihood: 2, risk_impact_score: 4, cab_required: true}},
        {m: 'GET', p: '/changes/{id}', perm: 'changes.read', s: 'Get one change',
         d: 'Full detail: all plan bodies, risk evaluation, PIR block, the attachments list and any linked problems.',
         params: [P('id', 'path', 'Change id', true)]},
        {m: 'PATCH', p: '/changes/{id}', perm: 'changes.update', s: 'Update a change',
         d: 'Updates any combination of fields with full audit parity: human field labels, display names for lookups, status changes marked as such, risk score/level recomputed when likelihood/impact change, and the change.approved workflow event on a genuine transition into Approved. Plan bodies update silently (the UI does not audit long text). Send only what changes.',
         params: [
            P('id', 'path', 'Change id', true),
            P('status / status_id', 'body', 'Move through the lifecycle (Draft → Pending Approval → Approved → Scheduled → In Progress → Completed/Failed)'),
            P('…all POST fields…', 'body', 'Everything creatable is also patchable; null clears nullable fields'),
            P('pir_was_successful / pir_actual_start_at / pir_actual_end_at / pir_lessons_learned / pir_follow_up', 'body', 'Post-implementation review'),
         ],
         body: {status: 'Pending Approval'}},
        {m: 'DELETE', p: '/changes/{id}', perm: 'changes.delete', s: 'Delete a change',
         d: 'Permanently deletes the change, its attachment files, and all children (comments, CAB, audit) — same as the UI. No restore.',
         params: [P('id', 'path', 'Change id', true)]},
    ]},
    {section: 'Change comments, history & CAB', items: [
        {m: 'GET', p: '/changes/{id}/comments', perm: 'change_comments.read', s: 'List comments',
         d: 'Comments newest first.', params: [P('id', 'path', 'Change id', true)]},
        {m: 'POST', p: '/changes/{id}/comments', perm: 'change_comments.create', s: 'Add a comment',
         d: 'Adds an internal comment attributed to the analyst the key acts as; a preview is written to the audit trail. Comments cannot be edited (parity with the UI).',
         params: [P('id', 'path', 'Change id', true), P('text', 'body', 'The comment', true)],
         body: {text: 'Parts arrived; work confirmed for Saturday 06:00.'}},
        {m: 'DELETE', p: '/changes/{id}/comments/{comment_id}', perm: 'change_comments.delete', s: 'Delete a comment',
         d: 'Removes a comment (hard delete, like the UI).',
         params: [P('id', 'path', 'Change id', true), P('comment_id', 'path', 'Comment id', true)]},
        {m: 'GET', p: '/changes/{id}/audit', perm: 'change_audit.read', s: 'Change history',
         d: 'Every audited event — field changes, status changes, CAB votes, comments — with old/new values, newest first.',
         params: [P('id', 'path', 'Change id', true)]},
        {m: 'GET', p: '/changes/{id}/cab', perm: 'change_cab.read', s: 'CAB roster & progress',
         d: 'The CAB members with their votes, plus approval progress (required approved/rejected/total) and the approval type.',
         params: [P('id', 'path', 'Change id', true)]},
        {m: 'POST', p: '/changes/{id}/cab', perm: 'change_cab.manage', s: 'Set the CAB roster',
         d: 'Replaces the CAB member roster (adds, removals and required/optional switches are diffed and audited, like the UI).',
         params: [P('id', 'path', 'Change id', true), P('members', 'body', 'Array of {analyst_id, is_required}', true)],
         body: {members: [{analyst_id: 1, is_required: true}]}},
        {m: 'POST', p: '/changes/{id}/cab/vote', perm: 'change_cab.vote', s: 'Cast a CAB vote',
         d: 'Votes as the analyst this key acts as — they must be an un-voted CAB member. Mirrors the UI exactly: any required Reject sends the change back to Draft; when the all/majority threshold of required members approves, the change auto-moves to Approved (approval timestamp set, workflow event fired).',
         params: [
            P('id', 'path', 'Change id', true),
            P('vote', 'body', 'Approve, Reject or Abstain', true),
            P('comment', 'body', 'Optional vote comment'),
         ],
         body: {vote: 'Approve', comment: 'Rollback plan looks solid.'}},
    ]},
    {section: 'Knowledge base', items: [
        {m: 'GET', p: '/knowledge/articles', perm: 'knowledge.read', s: 'List / search articles',
         d: 'Lists published articles with keyword search (title + body), tag filter and the review-cycle filters the module\'s review screen uses. archived=true lists the recycle bin instead (and runs the same retention auto-purge as the UI). The knowledge base is install-wide.',
         params: [
            P('q', 'query', 'Keyword search across title and body'),
            P('tag', 'query', 'Only articles carrying this tag (exact name)'),
            P('author_id / owner_id', 'query', 'Filter by author or owner analyst'),
            P('review', 'query', '"overdue", "upcoming" (next 30 days) or "none" — review-cycle filters'),
            P('modified_since', 'query', 'ISO 8601 — for sync jobs'),
            P('archived', 'query', 'true lists the recycle bin instead'),
            P('sort', 'query', 'modified_at (default, desc), created_at, title, view_count, next_review_date, id — prefix "-" for descending'),
            P('page / per_page', 'query', 'Pagination (default 1 / 25, max per_page 100)'),
         ]},
        {m: 'POST', p: '/knowledge/articles', perm: 'knowledge.create', s: 'Create an article',
         d: 'Creates an article, published immediately (the product has no draft workflow). Tags are created on the fly by name. If the module\'s OpenAI key is configured the search embedding is generated automatically, so AI chat finds API-written articles. Body is HTML stored as-is — send trusted HTML only.',
         params: [
            P('title', 'body', 'Article title (max 255)', true),
            P('body_html', 'body', 'The article content (TinyMCE-style HTML)'),
            P('tags', 'body', 'Array of tag names, e.g. ["vpn", "how-to"]'),
            P('owner_id', 'body', 'Owning analyst (for the review cycle)'),
            P('next_review_date', 'body', 'YYYY-MM-DD'),
         ],
         body: {title: 'How to reset your VPN token', body_html: '<h2>Steps</h2><ol><li>Open the portal…</li></ol>', tags: ['vpn', 'how-to']}},
        {m: 'GET', p: '/knowledge/articles/{id}', perm: 'knowledge.read', s: 'Get one article',
         d: 'The full article with HTML body and tags. Unlike the UI, reading via the API does NOT bump the view counter unless you pass count_view=true — so sync jobs don\'t inflate the stats.',
         params: [P('id', 'path', 'Article id', true), P('count_view', 'query', 'true = count this read as a view (UI parity)')]},
        {m: 'PATCH', p: '/knowledge/articles/{id}', perm: 'knowledge.update', s: 'Update an article',
         d: 'Updates title, body, tags (replaces the set), owner and review date. Pass save_as_version=true to snapshot the CURRENT content into the version history and bump the version number — exactly like the UI\'s "Save as new version". The search embedding is refreshed automatically.',
         params: [
            P('id', 'path', 'Article id', true),
            P('title / body_html', 'body', 'New content'),
            P('tags', 'body', 'Replaces the article\'s tag set'),
            P('owner_id / next_review_date', 'body', 'Review-cycle fields (null clears)'),
            P('save_as_version', 'body', 'true = snapshot the previous content first'),
         ],
         body: {body_html: '<h2>Steps (updated)</h2>…', save_as_version: true}},
        {m: 'DELETE', p: '/knowledge/articles/{id}', perm: 'knowledge.delete', s: 'Move to recycle bin',
         d: 'Soft-archives the article (the module\'s recycle bin — restorable until the retention window purges it).',
         params: [P('id', 'path', 'Article id', true)]},
        {m: 'POST', p: '/knowledge/articles/{id}/restore', perm: 'knowledge.restore', s: 'Restore from the bin',
         d: 'Brings an archived article back.',
         params: [P('id', 'path', 'Article id', true)]},
        {m: 'DELETE', p: '/knowledge/articles/{id}/permanent', perm: 'knowledge.purge', s: 'Permanently delete',
         d: 'Hard-deletes an article — only allowed once it is in the recycle bin (same guard as the UI). Orphaned tags are cleaned up.',
         params: [P('id', 'path', 'Article id', true)]},
        {m: 'GET', p: '/knowledge/articles/{id}/versions', perm: 'knowledge_versions.read', s: 'Version history',
         d: 'The saved version snapshots (version, title, who, when), newest first.',
         params: [P('id', 'path', 'Article id', true)]},
        {m: 'GET', p: '/knowledge/articles/{id}/versions/{version}', perm: 'knowledge_versions.read', s: 'Get a version',
         d: 'One snapshot including its full HTML body.',
         params: [P('id', 'path', 'Article id', true), P('version', 'path', 'Version number', true)]},
    ]},
    {section: 'Tasks', items: [
        {m: 'GET', p: '/tasks', perm: 'tasks.read', s: 'List / board feed',
         d: 'Lists top-level tasks in board order by default (pass parent_task_id to list a task\'s subtasks). Each task carries its tags, subtask done/total counts and board position. Tasks are install-wide.',
         params: [
            P('state', 'query', '"open", "closed" or "all" (default all)'),
            P('status / status_id', 'query', 'One board column, by name (e.g. "In Progress") or id'),
            P('priority / priority_id', 'query', 'By name or id'),
            P('assigned_analyst_id / assigned_team_id', 'query', 'Assignment filters; unassigned=true finds unassigned tasks'),
            P('ticket_id / change_id / contract_id', 'query', 'Tasks linked to a record'),
            P('parent_task_id', 'query', 'List the subtasks of this task (default: top-level only)'),
            P('tag', 'query', 'Only tasks carrying this tag (exact name)'),
            P('q', 'query', 'Search title and description'),
            P('due_before / due_after', 'query', 'YYYY-MM-DD bounds; overdue=true finds open tasks past their due date'),
            P('sort', 'query', 'board_position (default), created_at, updated_at, due_date, completed_at, title, id — prefix "-" for descending'),
            P('page / per_page', 'query', 'Pagination (default 1 / 25, max per_page 100)'),
         ]},
        {m: 'POST', p: '/tasks', perm: 'tasks.create', s: 'Create a task',
         d: 'Creates a task exactly like the UI: defaults To Do / Medium, the card is appended to the end of its status column. Links to a ticket/change/contract are validated (a ticket link must be within the key\'s company scope); parent_task_id makes it a subtask. Tags must already exist (they\'re a curated list in Tasks > Settings).',
         params: [
            P('title', 'body', 'Task title', true),
            P('description', 'body', 'Details'),
            P('status / status_id · priority / priority_id', 'body', 'By name or id (defaults To Do / Medium)'),
            P('assigned_analyst_id / assigned_team_id', 'body', 'Assignment'),
            P('start_date / due_date', 'body', 'YYYY-MM-DD'),
            P('parent_task_id', 'body', 'Create as a subtask of this task'),
            P('ticket_id / change_id / contract_id', 'body', 'Link to a record'),
            P('tags', 'body', 'Array of existing tag names or ids'),
         ],
         body: {title: 'Renew SSL certificate for portal', priority: 'High', due_date: '2026-07-20', tags: ['Security']}},
        {m: 'GET', p: '/tasks/{id}', perm: 'tasks.read', s: 'Get one task',
         d: 'Full detail: parent summary, ordered subtask list, comments, and linked ticket/change summaries (linked-ticket details only appear when the key\'s company scope could read that ticket directly).',
         params: [P('id', 'path', 'Task id', true)]},
        {m: 'PATCH', p: '/tasks/{id}', perm: 'tasks.update', s: 'Update a task',
         d: 'Updates any combination of fields; null clears. Moving to a closed status stamps completed_at and fires the task.completed workflow event (moving back clears it). tags replaces the tag set.',
         params: [
            P('id', 'path', 'Task id', true),
            P('…all POST fields…', 'body', 'Everything creatable is patchable'),
            P('board_position', 'body', 'Raw position override (prefer POST /tasks/{id}/move)'),
         ],
         body: {status: 'Done'}},
        {m: 'POST', p: '/tasks/{id}/move', perm: 'tasks.update', s: 'Move on the board',
         d: 'The kanban drag as an API call: optionally change column (status) and place the card at a position (0-based; omit for end of column) — the column is re-packed automatically. Mirrors the UI drag exactly, including that a drag into Done does NOT fire the task.completed workflow event (only a status change via PATCH does — the product behaves the same way).',
         params: [
            P('id', 'path', 'Task id', true),
            P('status / status_id', 'body', 'Target column (omit to reorder within the current one)'),
            P('position', 'body', '0-based position in the column (omit = end)'),
         ],
         body: {status: 'In Progress', position: 0}},
        {m: 'DELETE', p: '/tasks/{id}', perm: 'tasks.delete', s: 'Delete a task',
         d: 'Permanently deletes the task — subtasks, comments and tag links go with it (no trash).',
         params: [P('id', 'path', 'Task id', true)]},
        {m: 'GET', p: '/tasks/{id}/comments', perm: 'task_comments.read', s: 'List comments',
         d: 'Comments oldest first.', params: [P('id', 'path', 'Task id', true)]},
        {m: 'POST', p: '/tasks/{id}/comments', perm: 'task_comments.create', s: 'Add a comment',
         d: 'Appends a comment attributed to the analyst the key acts as. Comments cannot be edited or deleted (parity with the UI).',
         params: [P('id', 'path', 'Task id', true), P('text', 'body', 'The comment', true)],
         body: {text: 'Cert ordered; waiting on validation email.'}},
    ]},
    {section: 'CMDB', items: [
        {m: 'GET', p: '/cmdb/classes', perm: 'cmdb_classes.read', s: 'List classes',
         d: 'The CI classes with icon, active flag and object counts. Class design stays in the UI — the API reads definitions so integrations can build valid writes.',
         params: []},
        {m: 'GET', p: '/cmdb/classes/{id}', perm: 'cmdb_classes.read', s: 'Get a class + its properties',
         d: 'The class with its full typed property definitions: property_key, type (text/number/date/boolean/dropdown/object_ref), required flag, dropdown options (with colours) and object_ref target class.',
         params: [P('id', 'path', 'Class id', true)]},
        {m: 'GET', p: '/cmdb/objects', perm: 'cmdb_objects.read', s: 'List / search objects',
         d: 'Configuration items with class info. The CMDB is install-wide.',
         params: [
            P('class_id / class_key', 'query', 'Filter by class'),
            P('q', 'query', 'Search by name'),
            P('parent_id', 'query', 'Children of an object; top_level=true for roots only'),
            P('is_planned', 'query', 'true / false — planned vs live CIs'),
            P('sort', 'query', 'name (default), id, created_at, updated_at — prefix "-" for descending'),
            P('page / per_page', 'query', 'Pagination (default 1 / 25, max per_page 100)'),
         ]},
        {m: 'POST', p: '/cmdb/objects', perm: 'cmdb_objects.create', s: 'Create an object',
         d: 'Creates a CI. Properties are sent as a map keyed by property_key and validated exactly like the UI: numbers must be numeric, object_ref values must exist (and match the property\'s target class), required properties enforced — plus dropdown values are checked against the option list (tighter than the UI). Class is immutable after creation.',
         params: [
            P('name', 'body', 'Object name (max 255)', true),
            P('class_id / class_key', 'body', 'The class (one of the two)', true),
            P('parent_id', 'body', 'Parent object (hierarchy)'),
            P('is_planned', 'body', 'Boolean — a planned (not yet live) CI'),
            P('properties', 'body', 'Map of property_key → value, e.g. {"ip_address": "10.0.0.5", "environment": "Production"}'),
         ],
         body: {name: 'SQL01', class_key: 'server', properties: {}}},
        {m: 'GET', p: '/cmdb/objects/{id}', perm: 'cmdb_objects.read', s: 'Get one object',
         d: 'Fully hydrated: every class property with its typed value (object_ref values include the referenced object), parent, children, relationships in both directions with natural-reading verbs, and the cached AI summary.',
         params: [P('id', 'path', 'Object id', true)]},
        {m: 'PATCH', p: '/cmdb/objects/{id}', perm: 'cmdb_objects.update', s: 'Update an object',
         d: 'Updates name, parent (cycle-checked), is_planned and any property values — only the properties you send are touched (required checks apply only to sent properties, like the UI\'s inline edit). Empty/null clears a property.',
         params: [
            P('id', 'path', 'Object id', true),
            P('name / parent_id / is_planned', 'body', 'Object fields'),
            P('properties', 'body', 'Map of property_key → value (partial — only sent keys change)'),
         ],
         body: {properties: {environment: 'Production'}}},
        {m: 'DELETE', p: '/cmdb/objects/{id}', perm: 'cmdb_objects.delete', s: 'Delete an object',
         d: 'Permanently deletes the object AND its whole descendant tree (children cascade by design), plus its properties, relationships and ticket links; object_ref properties pointing at deleted objects are cleared. The response reports deleted_descendants — check the impact endpoint first.',
         params: [P('id', 'path', 'Object id', true)]},
        {m: 'GET', p: '/cmdb/objects/{id}/impact', perm: 'cmdb_objects.read', s: 'Impact analysis',
         d: 'The blast radius: all descendants (with depth), objects referencing this one via object_ref properties, and incoming relationships (things that depend on it).',
         params: [P('id', 'path', 'Object id', true)]},
        {m: 'POST', p: '/cmdb/objects/{id}/relationships', perm: 'cmdb_relationships.create', s: 'Link two objects',
         d: 'Creates a directed relationship from this object to another (e.g. "depends on"). No self-links; the same from/to/type triple twice → 409.',
         params: [
            P('id', 'path', 'From object id', true),
            P('to_object_id', 'body', 'The other object', true),
            P('verb / relationship_type_id', 'body', 'Relationship type by verb (e.g. "depends on") or id', true),
         ],
         body: {to_object_id: 2, verb: 'depends on'}},
        {m: 'DELETE', p: '/cmdb/objects/{id}/relationships/{rel_id}', perm: 'cmdb_relationships.delete', s: 'Unlink objects',
         d: 'Removes a relationship — it must involve this object (either direction).',
         params: [P('id', 'path', 'Object id', true), P('rel_id', 'path', 'Relationship id', true)]},
        {m: 'GET', p: '/cmdb/objects/{id}/tickets', perm: 'cmdb_ticket_links.read', s: 'Linked tickets',
         d: 'The tickets linked to this CI — scoped to the key\'s companies (the API does not reproduce the internal endpoint\'s unscoped read).',
         params: [P('id', 'path', 'Object id', true)]},
        {m: 'POST', p: '/cmdb/objects/{id}/tickets', perm: 'cmdb_ticket_links.create', s: 'Link a ticket',
         d: 'Links a ticket to this CI. The ticket must be within the key\'s company scope; already linked → 409.',
         params: [P('id', 'path', 'Object id', true), P('ticket_id', 'body', 'Ticket to link', true)],
         body: {ticket_id: 1}},
        {m: 'DELETE', p: '/cmdb/objects/{id}/tickets/{ticket_id}', perm: 'cmdb_ticket_links.delete', s: 'Unlink a ticket',
         d: 'Removes the link.',
         params: [P('id', 'path', 'Object id', true), P('ticket_id', 'path', 'Linked ticket id', true)]},
    ]},
    {section: 'Contracts', items: [
        {m: 'GET', p: '/contracts', perm: 'contracts.read', s: 'List / search contracts',
         d: 'Contracts with supplier/owner/status names. Renewal filters mirror the dashboard and Watchtower windows. Install-wide (the RFP Builder is not exposed — it\'s internal-only by design).',
         params: [
            P('q', 'query', 'Search contract number, title and supplier legal name'),
            P('supplier_id / contract_status_id / contract_owner_id / payment_schedule_id', 'query', 'Id filters'),
            P('is_active', 'query', 'true / false'),
            P('expiring_within_days', 'query', '⏳ Active contracts ending within N days (Watchtower\'s 30/90-day shapes as a parameter)'),
            P('notice_within_days', 'query', '🔔 Active contracts whose notice date falls within N days'),
            P('expired', 'query', 'true = contract_end already passed'),
            P('ends_before / ends_after', 'query', 'YYYY-MM-DD bounds on contract_end'),
            P('sort', 'query', 'contract_end (default), contract_start, created_at, title, contract_number, contract_value, id — prefix "-" for descending'),
            P('page / per_page', 'query', 'Pagination (default 1 / 25, max per_page 100)'),
         ]},
        {m: 'POST', p: '/contracts', perm: 'contracts.create', s: 'Create a contract',
         d: 'contract_number and title are required. A duplicate contract_number is refused (409) — a deliberate API safeguard (the UI has no such check). Lookups validated with friendly 422s; dates YYYY-MM-DD; currency a 3-letter code.',
         params: [
            P('contract_number', 'body', 'Unique reference', true),
            P('title', 'body', 'Contract title', true),
            P('description', 'body', 'Details'),
            P('supplier_id / contract_owner_id / contract_status_id / payment_schedule_id', 'body', 'Lookups'),
            P('contract_start / contract_end / notice_date', 'body', 'YYYY-MM-DD'),
            P('notice_period_days', 'body', 'Notice period in days'),
            P('contract_value / currency', 'body', 'Amount + 3-letter currency code'),
            P('cost_centre / dms_link / terms_status', 'body', 'Admin fields (dms_link = external document URL)'),
            P('personal_data_transferred / dpia_required / dpia_completed_date / dpia_dms_link', 'body', 'GDPR governance fields'),
            P('is_active', 'body', 'Default true'),
         ],
         body: {contract_number: 'CN-2026-014', title: 'Managed print services', supplier_id: 1, contract_start: '2026-08-01', contract_end: '2028-07-31', notice_period_days: 90, notice_date: '2028-05-02', contract_value: 24000, currency: 'GBP'}},
        {m: 'GET', p: '/contracts/{id}', perm: 'contracts.read', s: 'Get one contract',
         d: 'Full detail: dates, value, governance block, supplier/owner/status/payment-schedule names.',
         params: [P('id', 'path', 'Contract id', true)]},
        {m: 'PATCH', p: '/contracts/{id}', perm: 'contracts.update', s: 'Update a contract',
         d: 'Send only what changes; null clears nullable fields; changing contract_number to one already in use → 409. Set is_active=false to retire without deleting.',
         params: [P('id', 'path', 'Contract id', true), P('…all POST fields…', 'body', 'Everything creatable is patchable')],
         body: {contract_end: '2029-07-31', notice_date: '2029-05-02'}},
        {m: 'DELETE', p: '/contracts/{id}', perm: 'contracts.delete', s: 'Delete a contract',
         d: 'Permanently deletes the contract and its term values; tasks/calendar events/RFPs that referenced it are unlinked (not deleted). Prefer is_active=false for retirement.',
         params: [P('id', 'path', 'Contract id', true)]},
        {m: 'GET', p: '/contracts/{id}/terms', perm: 'contract_terms.read', s: 'Read contract terms',
         d: 'Every active term tab with this contract\'s content for it (null where nothing recorded).',
         params: [P('id', 'path', 'Contract id', true)]},
        {m: 'POST', p: '/contracts/{id}/terms', perm: 'contract_terms.update', s: 'Write contract terms',
         d: 'Per-tab upsert, exactly like the UI: only the tabs you send are touched. Returns the full term set.',
         params: [P('id', 'path', 'Contract id', true), P('terms', 'body', 'Array of {term_tab_id, content}', true)],
         body: {terms: [{term_tab_id: 1, content: 'Termination requires 90 days written notice.'}]}},
    ]},
    {section: 'Suppliers & contacts', items: [
        {m: 'GET', p: '/suppliers/{id}', perm: 'suppliers.read', s: 'Get a supplier',
         d: 'The full record — registration/VAT numbers, address, questionnaire dates, type/status, contract + contact counts, and the contact list inline. (The lite picker list stays at GET /suppliers under reference.read.)',
         params: [P('id', 'path', 'Supplier id', true)]},
        {m: 'POST', p: '/suppliers', perm: 'suppliers.create', s: 'Create a supplier',
         d: 'legal_name is required. The API can also set supplies_assets (the flag the Assets module filters suppliers by) — the UI never writes it.',
         params: [
            P('legal_name', 'body', 'Registered name', true),
            P('trading_name / reg_number / vat_number', 'body', 'Identity fields'),
            P('supplier_type_id / supplier_status_id', 'body', 'Lookups'),
            P('address_line_1 / address_line_2 / city / county / postcode / country', 'body', 'Address'),
            P('questionnaire_date_issued / questionnaire_date_received', 'body', 'YYYY-MM-DD'),
            P('comments / is_active / supplies_assets', 'body', 'Notes + flags'),
         ],
         body: {legal_name: 'Acme Print Ltd', trading_name: 'Acme Print', supplies_assets: true}},
        {m: 'PATCH', p: '/suppliers/{id}', perm: 'suppliers.update', s: 'Update a supplier',
         d: 'Send only what changes.', params: [P('id', 'path', 'Supplier id', true)], body: {supplier_status_id: 2}},
        {m: 'DELETE', p: '/suppliers/{id}', perm: 'suppliers.delete', s: 'Delete a supplier',
         d: 'Deletes the supplier; its contracts, contacts and assets keep their rows but are unlinked (same behaviour as the UI).',
         params: [P('id', 'path', 'Supplier id', true)]},
        {m: 'GET', p: '/suppliers/{id}/contacts', perm: 'supplier_contacts.read', s: 'List contacts',
         d: 'The supplier\'s contacts.', params: [P('id', 'path', 'Supplier id', true)]},
        {m: 'POST', p: '/suppliers/{id}/contacts', perm: 'supplier_contacts.create', s: 'Add a contact',
         d: 'first_name and surname required.',
         params: [P('id', 'path', 'Supplier id', true), P('first_name / surname', 'body', 'Required', true), P('email / mobile / job_title / direct_dial / switchboard / is_active', 'body', 'Optional')],
         body: {first_name: 'Jo', surname: 'Bates', email: 'jo@acmeprint.example', job_title: 'Account manager'}},
        {m: 'PATCH', p: '/suppliers/{id}/contacts/{contact_id}', perm: 'supplier_contacts.update', s: 'Update a contact',
         d: 'Send only what changes.', params: [P('id', 'path', 'Supplier id', true), P('contact_id', 'path', 'Contact id', true)], body: {mobile: '07700 900123'}},
        {m: 'DELETE', p: '/suppliers/{id}/contacts/{contact_id}', perm: 'supplier_contacts.delete', s: 'Remove a contact',
         d: 'Deletes the contact.', params: [P('id', 'path', 'Supplier id', true), P('contact_id', 'path', 'Contact id', true)]},
    ]},
    {section: 'Calendar', items: [
        {m: 'GET', p: '/calendar/events', perm: 'calendar_events.read', s: 'List events in a window',
         d: 'The shared team calendar. Provide a from/to window (the UI\'s exact overlap logic: starts in, ends in, or spans the window; from inclusive, to exclusive) — or contract_id, or all=true. IMPORTANT: calendar datetimes are NAIVE SERVER-LOCAL (no timezone conversion, matching the UI and the ICS feed) — send and read them without Z/offset. Responses include `source`: null = manual, "asset_warranty" = generated (read-only).',
         params: [
            P('from / to', 'query', 'Naive local datetimes, e.g. 2026-07-01 00:00:00 (T separator OK)'),
            P('contract_id', 'query', 'Events linked to a contract (window optional)'),
            P('all', 'query', 'true = no window (paginate!)'),
            P('category_id', 'query', 'One category — or categories=1,2,3 for several'),
            P('source', 'query', '"manual" (source is null) or a generator name like "asset_warranty"'),
            P('q', 'query', 'Search title and location'),
            P('page / per_page', 'query', 'Pagination (default 1 / 25, max per_page 100)'),
         ]},
        {m: 'POST', p: '/calendar/events', perm: 'calendar_events.create', s: 'Create an event',
         d: 'Creates a manual event (consumers can never set source). end_at defaults to start_at; category/contract ids get friendly 422s.',
         params: [
            P('title', 'body', 'Event title', true),
            P('start_at', 'body', 'Naive local datetime', true),
            P('end_at', 'body', 'Defaults to start_at; must not be before it'),
            P('all_day', 'body', 'Boolean'),
            P('description / location', 'body', 'Details'),
            P('category_id', 'body', 'Calendar category'),
            P('contract_id', 'body', 'Link to a contract'),
         ],
         body: {title: 'Maintenance window — core switch', start_at: '2026-07-05 06:00:00', end_at: '2026-07-05 08:00:00', category_id: 1, location: 'Server room'}},
        {m: 'GET', p: '/calendar/events/{id}', perm: 'calendar_events.read', s: 'Get one event',
         d: 'One event with category, creator and source.', params: [P('id', 'path', 'Event id', true)]},
        {m: 'PATCH', p: '/calendar/events/{id}', perm: 'calendar_events.update', s: 'Update an event',
         d: 'Send only what changes. Generated events (source set) answer 409 — they belong to their sync and would be recreated anyway.',
         params: [P('id', 'path', 'Event id', true), P('…all POST fields…', 'body', 'Everything creatable is patchable')],
         body: {start_at: '2026-07-06 06:00:00', end_at: '2026-07-06 08:00:00'}},
        {m: 'DELETE', p: '/calendar/events/{id}', perm: 'calendar_events.delete', s: 'Delete an event',
         d: 'Deletes a manual event. Generated events answer 409.',
         params: [P('id', 'path', 'Event id', true)]},
    ]},
    {section: 'Software', items: [
        {m: 'GET', p: '/software/apps', perm: 'software_inventory.read', s: 'List applications',
         d: 'The agent-collected application catalogue with distinct-machine install counts and licence counts. Inventory is agent-owned — read-only via the API, like the UI. Install-wide.',
         params: [
            P('q', 'query', 'Search name and publisher'),
            P('filter', 'query', '"apps" (non-components, the UI\'s default tab), "components", or "all" (default)'),
            P('sort', 'query', 'name (default), publisher, install_count, id — prefix "-" for descending'),
            P('page / per_page', 'query', 'Pagination (default 1 / 25, max per_page 100)'),
         ]},
        {m: 'GET', p: '/software/apps/{id}', perm: 'software_inventory.read', s: 'App + compliance',
         d: 'One application with its licences and computed COMPLIANCE numbers: distinct non-component installs vs licensed seats (sum of Active licence quantities), with seats_available — the seats-vs-installs view the UI doesn\'t have. unmetered_licences=true flags an Active licence without a seat count (e.g. a site licence), which makes seats_available null.',
         params: [P('id', 'path', 'Application id', true)]},
        {m: 'GET', p: '/software/apps/{id}/machines', perm: 'software_inventory.read', s: 'Where it\'s installed',
         d: 'Every machine the application is installed on, with version, install date and last seen — includes asset_id for joining to /assets.',
         params: [P('id', 'path', 'Application id', true)]},
        {m: 'GET', p: '/software/licences', perm: 'software_licences.read', s: 'List licences',
         d: 'Licences joined to their app, each carrying app_installs (distinct non-component machines) next to quantity, plus a computed renewal_status (ok / due_soon / overdue — due_soon means within the licence\'s own notice period, default 30 days, matching the licence screen\'s colours).',
         params: [
            P('app_id', 'query', 'Licences for one application'),
            P('status', 'query', 'e.g. Active'),
            P('q', 'query', 'Search app name, licence type and vendor contact'),
            P('renewal_within_days', 'query', 'Renewal date within N days'),
            P('due_soon', 'query', 'true = within each licence\'s own notice period (not yet overdue)'),
            P('renewal_overdue', 'query', 'true = renewal date already passed'),
            P('sort', 'query', 'renewal_date (default), app, cost, created_at, id — prefix "-" for descending'),
            P('page / per_page', 'query', 'Pagination'),
         ]},
        {m: 'POST', p: '/software/licences', perm: 'software_licences.create', s: 'Create a licence',
         d: 'app_id and licence_type are required (app validated). Currency defaults GBP, status defaults Active; dates YYYY-MM-DD; created_by is the analyst the key acts as.',
         params: [
            P('app_id', 'body', 'The application', true),
            P('licence_type', 'body', 'e.g. Per-seat subscription', true),
            P('quantity', 'body', 'Seat count (omit for site/unmetered licences)'),
            P('renewal_date / purchase_date', 'body', 'YYYY-MM-DD'),
            P('notice_period_days', 'body', 'Drives due_soon (default 30)'),
            P('cost / currency', 'body', 'Amount + currency (default GBP)'),
            P('licence_key / portal_url / vendor_contact / notes / status', 'body', 'Admin fields'),
         ],
         body: {app_id: 1, licence_type: 'Per-seat subscription', quantity: 50, renewal_date: '2027-03-31', cost: 2400, notice_period_days: 60}},
        {m: 'GET', p: '/software/licences/{id}', perm: 'software_licences.read', s: 'Get one licence',
         d: 'Full licence detail including app_installs and renewal_status.',
         params: [P('id', 'path', 'Licence id', true)]},
        {m: 'PATCH', p: '/software/licences/{id}', perm: 'software_licences.update', s: 'Update a licence',
         d: 'Send only what changes; created_by is never touched (UI parity).',
         params: [P('id', 'path', 'Licence id', true), P('…all POST fields…', 'body', 'Everything creatable is patchable')],
         body: {quantity: 75, renewal_date: '2028-03-31'}},
        {m: 'DELETE', p: '/software/licences/{id}', perm: 'software_licences.delete', s: 'Delete a licence',
         d: 'Permanently deletes the licence record (nothing else references it).',
         params: [P('id', 'path', 'Licence id', true)]},
    ]},
    {section: 'Service status', items: [
        {m: 'GET', p: '/service-status/services', perm: 'services.read', s: 'The health board',
         d: 'Active services, each with its DERIVED live status: the worst impact level across its open incidents, else Operational — the module\'s exact dashboard computation. Everything a status-page builder needs in one call. Install-wide.',
         params: [
            P('is_active', 'query', 'Default true (the board); false or all via explicit value'),
            P('q', 'query', 'Search service names'),
         ]},
        {m: 'POST', p: '/service-status/services', perm: 'services.create', s: 'Add a service',
         d: 'Only name is required.',
         params: [P('name', 'body', 'Service name', true), P('description / display_order / is_active', 'body', 'Optional')],
         body: {name: 'Email', description: 'Exchange Online'}},
        {m: 'GET', p: '/service-status/services/{id}', perm: 'services.read', s: 'Get one service',
         d: 'The service with its derived status and its open incidents inline.',
         params: [P('id', 'path', 'Service id', true)]},
        {m: 'PATCH', p: '/service-status/services/{id}', perm: 'services.update', s: 'Update a service',
         d: 'Send only what changes.', params: [P('id', 'path', 'Service id', true)], body: {display_order: 5}},
        {m: 'DELETE', p: '/service-status/services/{id}', perm: 'services.delete', s: 'Delete a service',
         d: 'Removes the service and its incident links (transactionally). The incidents themselves remain.',
         params: [P('id', 'path', 'Service id', true)]},
        {m: 'GET', p: '/service-status/incidents', perm: 'service_incidents.read', s: 'List incidents',
         d: 'Open-first, then most recently updated (the module\'s ordering). Each incident carries its affected services with per-service impact levels.',
         params: [
            P('state', 'query', '"open", "resolved" or "all" (default all)'),
            P('service_id', 'query', 'Incidents touching one service'),
            P('q', 'query', 'Search titles'),
            P('created_since / resolved_since', 'query', 'ISO 8601 bounds'),
            P('page / per_page', 'query', 'Pagination'),
         ]},
        {m: 'POST', p: '/service-status/incidents', perm: 'service_incidents.create', s: 'Open an incident',
         d: 'The monitoring-integration verb: title required, status defaults Investigating, and services is an array of {service_id, impact_level} pairs (impact by name or impact_level_id; defaults Operational). Unknown service/impact values are a 422 — stricter than the UI, which silently skips them. Creating directly in a resolved status stamps resolved_at.',
         params: [
            P('title', 'body', 'Incident title', true),
            P('status / status_id', 'body', 'Lifecycle status (default Investigating)'),
            P('comment', 'body', 'The current-state narrative (a single field, overwritten on update — the module has no timeline)'),
            P('services', 'body', 'Array of {service_id, impact_level | impact_level_id}'),
         ],
         body: {title: 'Email delivery delays', comment: 'Probes report SMTP queue growth; investigating.', services: [{service_id: 1, impact_level: 'Degraded'}]}},
        {m: 'GET', p: '/service-status/incidents/{id}', perm: 'service_incidents.read', s: 'Get one incident',
         d: 'Full incident with affected services and impacts.',
         params: [P('id', 'path', 'Incident id', true)]},
        {m: 'PATCH', p: '/service-status/incidents/{id}', perm: 'service_incidents.update', s: 'Update / resolve',
         d: 'Update title, status, comment, and/or replace the affected-services set. Moving to a resolved status stamps resolved_at once (preserved if already set); reopening clears it — the UI\'s exact rule.',
         params: [
            P('id', 'path', 'Incident id', true),
            P('status / status_id', 'body', 'e.g. "Monitoring", "Resolved"'),
            P('comment', 'body', 'Replaces the narrative'),
            P('services', 'body', 'Replaces the affected-services set when sent'),
         ],
         body: {status: 'Resolved', comment: 'Queue drained; delivery normal since 14:20.'}},
        {m: 'DELETE', p: '/service-status/incidents/{id}', perm: 'service_incidents.delete', s: 'Delete an incident',
         d: 'Removes the incident and its service links (transactionally).',
         params: [P('id', 'path', 'Incident id', true)]},
    ]},
    {section: 'Morning checks', items: [
        {m: 'GET', p: '/morning-checks/board', perm: 'morning_check_results.read', s: 'The day board',
         d: 'Every ACTIVE check with its result for one date (null if not yet done) — the dashboard\'s exact view. Results whose status was later deleted come back with is_orphan and the original label. Install-wide.',
         params: [P('date', 'query', 'YYYY-MM-DD (default today, server-local)')]},
        {m: 'POST', p: '/morning-checks/results', perm: 'morning_check_results.record', s: 'Record a result',
         d: 'The monitoring-integration verb: one result per check per day — recording again for the same date overwrites (200; a first record is a 201). Status by label or id, must be active; statuses flagged "requires notes" reject an empty notes field. A malformed date or unknown check is a 422 (the UI silently substitutes today). Results recorded here stamp created_by with the key\'s acting analyst.',
         params: [
            P('check_id', 'body', 'The check', true),
            P('status / status_id', 'body', 'Status label (e.g. "Green") or id', true),
            P('notes', 'body', 'Required when the status requires notes'),
            P('date', 'body', 'YYYY-MM-DD (default today, server-local)'),
         ],
         body: {check_id: 1, status: 'Green', notes: ''}},
        {m: 'GET', p: '/morning-checks/results', perm: 'morning_check_results.read', s: 'Result history',
         d: 'Newest date first. orphans=true returns only rows whose status has since been deleted (the dashboard\'s warning-banner set).',
         params: [
            P('check_id', 'query', 'One check'),
            P('status_id', 'query', 'One status'),
            P('date', 'query', 'Exact date (YYYY-MM-DD)'),
            P('from / to', 'query', 'Inclusive date bounds'),
            P('orphans', 'query', 'true = only orphaned results'),
            P('page / per_page', 'query', 'Pagination'),
         ]},
        {m: 'GET', p: '/morning-checks/results/{id}', perm: 'morning_check_results.read', s: 'Get one result',
         params: [P('id', 'path', 'Result id', true)]},
        {m: 'GET', p: '/morning-checks/checks', perm: 'morning_checks.read', s: 'List checks',
         d: 'All check definitions (active and inactive) in board order.',
         params: [P('is_active', 'query', 'true / false (default all)'), P('q', 'query', 'Search check names')]},
        {m: 'POST', p: '/morning-checks/checks', perm: 'morning_checks.create', s: 'Add a check',
         d: 'Only name is required.',
         params: [P('name', 'body', 'Check name', true), P('description / sort_order / is_active', 'body', 'Optional')],
         body: {name: 'Backups completed', description: 'Veeam overnight jobs all green'}},
        {m: 'GET', p: '/morning-checks/checks/{id}', perm: 'morning_checks.read', s: 'Get one check',
         params: [P('id', 'path', 'Check id', true)]},
        {m: 'PATCH', p: '/morning-checks/checks/{id}', perm: 'morning_checks.update', s: 'Update a check',
         d: 'Send only what changes. Deactivating (is_active: false) removes it from the board but keeps its history.',
         params: [P('id', 'path', 'Check id', true)], body: {is_active: false}},
        {m: 'DELETE', p: '/morning-checks/checks/{id}', perm: 'morning_checks.delete', s: 'Delete a check',
         d: 'Removes the check AND all its historical results (transactionally). Prefer deactivating to keep history.',
         params: [P('id', 'path', 'Check id', true)]},
    ]},
    {section: 'Forms', items: [
        {m: 'GET', p: '/forms', perm: 'forms.read', s: 'List forms',
         d: 'One row per version chain — the current (leaf) version, with field + submission counts. Install-wide.',
         params: [P('is_active', 'query', 'true / false (default all)'), P('q', 'query', 'Search titles')]},
        {m: 'POST', p: '/forms', perm: 'forms.create', s: 'Create a form',
         d: 'Title required; fields is an ordered array of {field_type, label, options?, is_required?}. Types: text, textarea, email, number, checkbox, checkboxes, dropdown, radio (options array for the last three). Unknown types and empty labels are a 422 — stricter than the UI.',
         params: [
            P('title', 'body', 'Form title', true),
            P('description', 'body', 'Shown above the form'),
            P('fields', 'body', 'Ordered array of field definitions'),
         ],
         body: {title: 'New starter', fields: [{field_type: 'text', label: 'Full name', is_required: true}, {field_type: 'email', label: 'Manager email', is_required: true}, {field_type: 'dropdown', label: 'Department', options: ['IT', 'HR', 'Finance']}]}},
        {m: 'GET', p: '/forms/{id}', perm: 'forms.read', s: 'Get one form',
         d: 'The form with its fields and version info (version.is_current = editable leaf).',
         params: [P('id', 'path', 'Form id', true)]},
        {m: 'PATCH', p: '/forms/{id}', perm: 'forms.update', s: 'Update the current version',
         d: 'In-place save of the leaf — a frozen historical version is a 409. Sending fields replaces the set using the UI\'s positional sync (existing field ids survive, so historical submission data stays mapped; removed fields lose their data). Can also set is_active — the API is currently the only way to retire a form without deleting it.',
         params: [P('id', 'path', 'Form id', true), P('title / description / is_active / fields', 'body', 'What to change')],
         body: {is_active: false}},
        {m: 'DELETE', p: '/forms/{id}', perm: 'forms.delete', s: 'Delete a version / chain',
         d: 'Deletes ONE version and its submissions — leaf only (deleting the leaf resurfaces the previous version; a mid-chain id is a 409). Pass chain=true to delete the whole version chain and every submission, transactionally.',
         params: [P('id', 'path', 'Form id', true), P('chain', 'query', 'true = delete the entire chain')]},
        {m: 'GET', p: '/forms/{id}/versions', perm: 'forms.read', s: 'The version chain',
         d: 'Every version in the chain containing this form, oldest first (v1 → v2 → …).',
         params: [P('id', 'path', 'Any form id in the chain', true)]},
        {m: 'POST', p: '/forms/{id}/versions', perm: 'forms.create', s: 'Fork a new version',
         d: 'Clones the current (leaf) version into a new editable one — version_number + 1; the source freezes. Forking from a non-leaf is a 409.',
         params: [P('id', 'path', 'The current version\'s id', true)]},
        {m: 'GET', p: '/forms/{id}/submissions', perm: 'form_submissions.read', s: 'List submissions',
         d: 'Submissions for this version, newest first, each with its answers joined to field labels (checkboxes values decoded to arrays).',
         params: [
            P('id', 'path', 'Form id', true),
            P('submitted_since / submitted_before', 'query', 'ISO 8601 bounds'),
            P('page / per_page', 'query', 'Pagination'),
         ]},
        {m: 'POST', p: '/forms/{id}/submissions', perm: 'form_submissions.create', s: 'Submit the form',
         d: 'data maps field id → value. Required + per-type validation matches the UI (email format, numeric, checkboxes must tick at least one when required); booleans and arrays are accepted natively. Unknown field ids are a 422; an inactive form is a 409. Fires the form.submitted workflow event with the label-keyed answers — exactly like the UI.',
         params: [
            P('id', 'path', 'Form id', true),
            P('data', 'body', 'Map of field_id → value', true),
         ],
         body: {data: {'1': 'Sam Jones', '2': 'manager@example.com', '3': 'IT'}}},
        {m: 'GET', p: '/forms/{id}/submissions/{submission_id}', perm: 'form_submissions.read', s: 'Get one submission',
         params: [P('id', 'path', 'Form id', true), P('submission_id', 'path', 'Submission id', true)]},
        {m: 'DELETE', p: '/forms/{id}/submissions/{submission_id}', perm: 'form_submissions.delete', s: 'Delete a submission',
         d: 'Removes the submission and its answers (transactionally).',
         params: [P('id', 'path', 'Form id', true), P('submission_id', 'path', 'Submission id', true)]},
    ]},
    {section: 'Workflows', items: [
        {m: 'GET', p: '/workflows', perm: 'workflows.read', s: 'List workflows',
         d: 'All automation rules, most recently updated first, with run stats and condition/action counts (full rule bodies on GET one). Install-wide.',
         params: [
            P('trigger_event', 'query', 'Filter by trigger (e.g. ticket.created)'),
            P('is_active', 'query', 'true / false (default all)'),
            P('q', 'query', 'Search names and descriptions'),
         ]},
        {m: 'POST', p: '/workflows', perm: 'workflows.create', s: 'Create a workflow',
         d: 'trigger_event, condition operators and action types are validated against the engine\'s catalogues (see GET /workflow-triggers and /workflow-actions) so nothing unexecutable can be stored — unknown operators are a 422, stricter than the UI. Zero actions is allowed (draft-friendly, like the editor). Action args support {{dot.path}} template variables resolved from the event payload. NOTE: actions run with engine privileges (create tickets, send email) — grant create/fire to trusted keys only.',
         params: [
            P('name', 'body', 'Workflow name', true),
            P('trigger_event', 'body', 'One of the trigger catalogue keys', true),
            P('description', 'body', 'What the rule is for'),
            P('conditions', 'body', 'Array of {field, op, value} — AND semantics; empty = always fire'),
            P('actions', 'body', 'Ordered array of {type, args}'),
            P('is_active', 'body', 'Default true'),
         ],
         body: {name: 'Escalate P1s', trigger_event: 'ticket.priority_changed', conditions: [{field: 'new_priority_id', op: 'in', value: ['1']}], actions: [{type: 'add_ticket_note', args: {ticket_id: '{{ticket.id}}', note: 'Auto-escalated: priority raised to P1.'}}]}},
        {m: 'GET', p: '/workflows/{id}', perm: 'workflows.read', s: 'Get one workflow',
         d: 'The full rule: decoded conditions and actions, creator, run stats.',
         params: [P('id', 'path', 'Workflow id', true)]},
        {m: 'PATCH', p: '/workflows/{id}', perm: 'workflows.update', s: 'Update a workflow',
         d: 'Partial update; sent fields are validated like create. Toggle is_active to pause/resume a rule without losing it.',
         params: [P('id', 'path', 'Workflow id', true), P('name / description / trigger_event / conditions / actions / is_active', 'body', 'What to change')],
         body: {is_active: false}},
        {m: 'DELETE', p: '/workflows/{id}', perm: 'workflows.delete', s: 'Delete a workflow',
         d: 'Hard delete. Execution history survives as the audit trail — runs are detached (workflow_id null) but stay attributable via their workflow_name snapshot.',
         params: [P('id', 'path', 'Workflow id', true)]},
        {m: 'POST', p: '/workflows/{id}/fire', perm: 'workflows.fire', s: 'Test-fire a workflow',
         d: 'Runs the workflow immediately with a synthetic payload — the editor\'s "Test fire" button. Executes real actions but does not bump run_count / last-run stats. Returns the execution result including the per-step log.',
         params: [P('id', 'path', 'Workflow id', true), P('payload', 'body', 'Synthetic event payload the conditions/templates read')],
         body: {payload: {ticket: {id: 123, subject: 'Test', priority_id: 1}}}},
        {m: 'GET', p: '/workflows/{id}/executions', perm: 'workflow_executions.read', s: 'List a workflow\'s runs',
         d: 'Newest first.',
         params: [
            P('id', 'path', 'Workflow id', true),
            P('status', 'query', 'running / success / failed / skipped / aborted'),
            P('trigger_event', 'query', 'Filter by trigger'),
            P('started_since', 'query', 'ISO 8601 lower bound'),
            P('page / per_page', 'query', 'Pagination'),
         ]},
        {m: 'GET', p: '/workflow-executions', perm: 'workflow_executions.read', s: 'List all runs',
         d: 'Install-wide run history, including orphaned runs whose workflow has since been deleted.',
         params: [
            P('workflow_id', 'query', 'One workflow\'s runs'),
            P('orphaned', 'query', 'true = only runs whose workflow is deleted'),
            P('status', 'query', 'running / success / failed / skipped / aborted'),
            P('trigger_event', 'query', 'Filter by trigger'),
            P('started_since', 'query', 'ISO 8601 lower bound'),
            P('page / per_page', 'query', 'Pagination'),
         ]},
        {m: 'GET', p: '/workflow-executions/{id}', perm: 'workflow_executions.read', s: 'Get one run',
         d: 'Full detail: the trigger payload snapshot and the per-step log (each condition evaluated, each action\'s result or error).',
         params: [P('id', 'path', 'Execution id', true)]},
    ]},
    {section: 'Network Mapper', items: [
        {m: 'GET', p: '/network-diagrams', perm: 'network_diagrams.read', s: 'List diagrams',
         d: 'Current versions only by default (all_versions=true for history), most recently updated first, with node/connector counts. Find every diagram a CI appears on with contains_object_id.',
         params: [
            P('q', 'query', 'Search titles and descriptions'),
            P('contains_object_id', 'query', 'Only diagrams this CMDB object is drawn on'),
            P('all_versions', 'query', 'true = include frozen historical versions'),
            P('created_by', 'query', 'Filter by author analyst id'),
            P('updated_since', 'query', 'ISO 8601 lower bound'),
            P('page / per_page', 'query', 'Pagination'),
         ]},
        {m: 'POST', p: '/network-diagrams', perm: 'network_diagrams.create', s: 'Create a diagram',
         d: 'Title required. Optionally ship the initial contents in the same call: nodes (each needs cmdb_object_id; x/y optional — omitted nodes are auto-placed in a grid) and connectors (endpoints by node "ref" or by object id). Unknown object ids, sizes, icons and line styles are 422s — stricter than the editor, which silently skips bad rows.',
         params: [
            P('title', 'body', 'Diagram title', true),
            P('description / version_label', 'body', 'Metadata (version_label defaults to v1)'),
            P('nodes', 'body', 'Initial nodes: [{cmdb_object_id, x?, y?, size?, icon_override?, ref?}]'),
            P('connectors', 'body', 'Initial connectors: [{from_ref|from_object_id, to_ref|to_object_id, cmdb_relationship_id?, label?, line_style?}]'),
            P('paper_size / paper_orientation', 'body', 'A4/A3/A2/Letter/Tabloid + portrait/landscape (null = no paper overlay)'),
            P('branding', 'body', '{header:{left,center,right}, footer:{...}} — null slot = inherit org default'),
         ],
         body: {title: 'Head office network', nodes: [{cmdb_object_id: 1, ref: 'fw'}, {cmdb_object_id: 2, ref: 'sw1'}], connectors: [{from_ref: 'fw', to_ref: 'sw1', cmdb_relationship_id: 'auto'}]}},
        {m: 'GET', p: '/network-diagrams/{id}', perm: 'network_diagrams.read', s: 'Get one diagram, fully hydrated',
         d: 'Everything an agent needs to understand the drawing: nodes with their CMDB object\'s name/class/effective icon/planned flag, connectors with both endpoints\' objects and the CMDB relationship verb, branding, and a layout block (canvas bounding box + pixel size per node size class). Add include_properties=true for each object\'s full typed CI property values.',
         params: [P('id', 'path', 'Diagram id', true), P('include_properties', 'query', 'true = include CI properties per node')]},
        {m: 'PATCH', p: '/network-diagrams/{id}', perm: 'network_diagrams.update', s: 'Update metadata / replace contents',
         d: 'Partial metadata update (title, description, version_label, paper, branding). Sending nodes and/or connectors switches to FULL CONTENTS REPLACE (both sets — node ids regenerate, so old connectors can\'t survive a node replace); for surgical edits use the node/connector endpoints below. Historical versions are a 409 — only the current (leaf) version is editable.',
         params: [P('id', 'path', 'Diagram id', true), P('title / description / version_label / paper_size / paper_orientation / branding / nodes / connectors', 'body', 'What to change')],
         body: {title: 'Head office network (2026)'}},
        {m: 'DELETE', p: '/network-diagrams/{id}', perm: 'network_diagrams.delete', s: 'Delete a version / chain',
         d: 'Leaf-only: deleting the current version resurfaces its parent as current. A version with history after it is a 409 (the UI lets this corrupt the chain; the API doesn\'t). Pass chain=true to delete the entire version chain.',
         params: [P('id', 'path', 'Diagram id', true), P('chain', 'query', 'true = delete the whole version chain')]},
        {m: 'GET', p: '/network-diagrams/{id}/versions', perm: 'network_diagrams.read', s: 'The version chain',
         d: 'Every version in this diagram\'s chain, oldest first, with is_current flags.',
         params: [P('id', 'path', 'Any diagram id in the chain', true)]},
        {m: 'POST', p: '/network-diagrams/{id}/versions', perm: 'network_diagrams.create', s: 'Snapshot a new version',
         d: 'Clones the current version forward (nodes, connectors, paper, branding) — the editor\'s "New version". The clone becomes editable; the source freezes. 409 unless called on the current version. Snapshot before automated changes for a free undo point.',
         params: [P('id', 'path', 'The current version\'s id', true), P('title / description / version_label', 'body', 'Defaults inherit from the source')],
         body: {version_label: 'v2'}},
        {m: 'GET', p: '/network-diagrams/{id}/suggestions', perm: 'network_diagrams.read', s: 'What\'s missing from this diagram',
         d: 'CMDB neighbours of on-diagram objects that aren\'t drawn yet — via relationships (both directions) and object_ref property links. Each suggestion carries the connecting verb and relationship id, ready to POST as a node + connector. The discovery-agent workhorse.',
         params: [P('id', 'path', 'Diagram id', true), P('object_id', 'query', 'Scope to one on-diagram object'), P('limit', 'query', 'Max suggestions (default 50)')]},
        {m: 'POST', p: '/network-diagrams/{id}/nodes', perm: 'network_diagrams.update', s: 'Add node(s)',
         d: 'One node (body = the node) or a batch ({nodes: [...]}). x/y optional — omitted nodes are auto-placed in a fresh column right of the existing drawing. An object already on the diagram is a 409 unless allow_duplicate=true. Node ids returned are STABLE (unlike editor saves, which regenerate all ids).',
         params: [
            P('id', 'path', 'Diagram id', true),
            P('cmdb_object_id', 'body', 'The CI to draw', true),
            P('x / y / size / icon_override', 'body', 'Position (auto if omitted), small|medium|large, icon key'),
            P('allow_duplicate', 'body', 'true = allow the same CI twice'),
         ],
         body: {cmdb_object_id: 3, size: 'large'}},
        {m: 'PATCH', p: '/network-diagrams/{id}/nodes/{node_id}', perm: 'network_diagrams.update', s: 'Move / restyle a node',
         d: 'Partial: x, y, size, icon_override (null reverts to the class icon).',
         params: [P('id', 'path', 'Diagram id', true), P('node_id', 'path', 'Node id', true), P('x / y / size / icon_override', 'body', 'What to change')],
         body: {x: 420, y: 180}},
        {m: 'DELETE', p: '/network-diagrams/{id}/nodes/{node_id}', perm: 'network_diagrams.update', s: 'Remove a node',
         d: 'The node and every connector touching it (count returned).',
         params: [P('id', 'path', 'Diagram id', true), P('node_id', 'path', 'Node id', true)]},
        {m: 'POST', p: '/network-diagrams/{id}/connectors', perm: 'network_diagrams.update', s: 'Connect two nodes',
         d: 'Endpoints by from_node_id/to_node_id or from_object_id/to_object_id (422 if the object isn\'t on the diagram or is ambiguous). cmdb_relationship_id: an id, null, or "auto" — auto binds to the existing CMDB relationship between the two objects. An already-connected pair (either direction) is a 409 unless allow_duplicate=true.',
         params: [
            P('id', 'path', 'Diagram id', true),
            P('from_node_id / from_object_id', 'body', 'One endpoint', true),
            P('to_node_id / to_object_id', 'body', 'The other endpoint', true),
            P('cmdb_relationship_id', 'body', 'Relationship id, null, or "auto"'),
            P('label / line_style', 'body', 'Label + solid|dashed'),
         ],
         body: {from_object_id: 1, to_object_id: 3, cmdb_relationship_id: 'auto', line_style: 'dashed'}},
        {m: 'PATCH', p: '/network-diagrams/{id}/connectors/{connector_id}', perm: 'network_diagrams.update', s: 'Update a connector',
         d: 'label, line_style, cmdb_relationship_id (null clears, "auto" re-resolves).',
         params: [P('id', 'path', 'Diagram id', true), P('connector_id', 'path', 'Connector id', true), P('label / line_style / cmdb_relationship_id', 'body', 'What to change')],
         body: {label: '10GbE uplink'}},
        {m: 'DELETE', p: '/network-diagrams/{id}/connectors/{connector_id}', perm: 'network_diagrams.update', s: 'Remove a connector',
         params: [P('id', 'path', 'Diagram id', true), P('connector_id', 'path', 'Connector id', true)]},
    ]},
    {section: 'Requesters', items: [
        {m: 'GET', p: '/users', perm: 'users.read', s: 'List / search requesters',
         d: 'End users who raise tickets. Search with q, or look one up exactly with email.',
         params: [
            P('q', 'query', 'Search email and names'),
            P('email', 'query', 'Exact email lookup'),
            P('page / per_page', 'query', 'Pagination'),
         ]},
        {m: 'GET', p: '/users/{id}', perm: 'users.read', s: 'Get a requester',
         d: 'One requester, including their ticket counts (within the key\'s company scope).',
         params: [P('id', 'path', 'Requester id', true)]},
        {m: 'POST', p: '/users', perm: 'users.create', s: 'Create a requester',
         d: 'Creates a requester. Note POST /tickets creates requesters automatically — use this for directory sync.',
         params: [
            P('email', 'body', 'Email (must be unique)', true),
            P('display_name', 'body', 'Full name'),
            P('preferred_name', 'body', 'Preferred first name'),
         ],
         body: {email: 'sam@example.com', display_name: 'Sam Jones'}},
        {m: 'PATCH', p: '/users/{id}', perm: 'users.update', s: 'Update a requester',
         d: 'Updates email and/or names.',
         params: [
            P('id', 'path', 'Requester id', true),
            P('email / display_name / preferred_name', 'body', 'Fields to change'),
         ],
         body: {display_name: 'Sam Jones-Smith'}},
    ]},
    {section: 'Reference data', items: [
        {m: 'GET', p: '/analysts', perm: 'analysts.read', s: 'List analysts', d: 'Active analysts, for assignment lookups.', params: []},
        {m: 'GET', p: '/companies', perm: 'companies.read', s: 'List companies', d: 'The companies (tenants) this key is scoped to see.', params: []},
        {m: 'GET', p: '/statuses', perm: 'reference.read', s: 'List ticket statuses', d: 'All statuses with is_closed, is_default, pauses_sla and colour.', params: []},
        {m: 'GET', p: '/priorities', perm: 'reference.read', s: 'List priorities', d: 'All priorities with their SLA response/resolution targets in minutes.', params: []},
        {m: 'GET', p: '/ticket-types', perm: 'reference.read', s: 'List ticket types', d: 'Pass company_id to apply that company\'s add/hide overrides.', params: [P('company_id', 'query', 'Resolve the list for one company')]},
        {m: 'GET', p: '/origins', perm: 'reference.read', s: 'List ticket origins', d: 'Pass company_id to apply that company\'s add/hide overrides.', params: [P('company_id', 'query', 'Resolve the list for one company')]},
        {m: 'GET', p: '/departments', perm: 'reference.read', s: 'List departments', d: 'All departments.', params: []},
        {m: 'GET', p: '/asset-types', perm: 'reference.read', s: 'List asset types', d: 'All asset types.', params: []},
        {m: 'GET', p: '/asset-statuses', perm: 'reference.read', s: 'List asset statuses', d: 'All asset statuses.', params: []},
        {m: 'GET', p: '/asset-locations', perm: 'reference.read', s: 'List asset locations', d: 'All locations, flat, each with its full path (e.g. "UK › London › Office 1").', params: []},
        {m: 'GET', p: '/suppliers', perm: 'reference.read', s: 'List suppliers', d: 'All suppliers with their display name and whether they supply assets.', params: []},
        {m: 'GET', p: '/problem-statuses', perm: 'reference.read', s: 'List problem statuses', d: 'All problem statuses with is_closed, is_default and colour.', params: []},
        {m: 'GET', p: '/problem-priorities', perm: 'reference.read', s: 'List problem priorities', d: 'All problem priorities.', params: []},
        {m: 'GET', p: '/change-statuses', perm: 'reference.read', s: 'List change statuses', d: 'All change statuses with is_closed, is_default and colour (Draft → … → Completed).', params: []},
        {m: 'GET', p: '/change-types', perm: 'reference.read', s: 'List change types', d: 'Standard, Normal, Emergency (plus any custom).', params: []},
        {m: 'GET', p: '/change-priorities', perm: 'reference.read', s: 'List change priorities', d: 'All change priorities.', params: []},
        {m: 'GET', p: '/change-impacts', perm: 'reference.read', s: 'List change impacts', d: 'All change impacts.', params: []},
        {m: 'GET', p: '/change-categories', perm: 'reference.read', s: 'List change categories', d: 'All change categories (empty until configured).', params: []},
        {m: 'GET', p: '/knowledge/tags', perm: 'reference.read', s: 'List knowledge tags', d: 'All tags with their published-article counts.', params: []},
        {m: 'GET', p: '/task-statuses', perm: 'reference.read', s: 'List task statuses', d: 'The board columns with is_closed, colour and display order (To Do → … → Done).', params: []},
        {m: 'GET', p: '/task-priorities', perm: 'reference.read', s: 'List task priorities', d: 'All task priorities.', params: []},
        {m: 'GET', p: '/task-tags', perm: 'reference.read', s: 'List task tags', d: 'The curated tag list with colours and usage counts.', params: []},
        {m: 'GET', p: '/cmdb-relationship-types', perm: 'reference.read', s: 'List CMDB relationship types', d: 'Verb + inverse verb pairs (depends on / is depended on by, …).', params: []},
        {m: 'GET', p: '/contract-statuses', perm: 'reference.read', s: 'List contract statuses', d: 'All contract statuses (populated via Contracts > Settings).', params: []},
        {m: 'GET', p: '/payment-schedules', perm: 'reference.read', s: 'List payment schedules', d: 'All payment schedules.', params: []},
        {m: 'GET', p: '/supplier-types', perm: 'reference.read', s: 'List supplier types', d: 'All supplier types.', params: []},
        {m: 'GET', p: '/supplier-statuses', perm: 'reference.read', s: 'List supplier statuses', d: 'All supplier statuses.', params: []},
        {m: 'GET', p: '/contract-term-tabs', perm: 'reference.read', s: 'List contract term tabs', d: 'The configured term tabs (Termination, Liability, …).', params: []},
        {m: 'GET', p: '/calendar-categories', perm: 'reference.read', s: 'List calendar categories', d: 'All categories with colour and event counts.', params: []},
        {m: 'GET', p: '/service-incident-statuses', perm: 'reference.read', s: 'List incident statuses', d: 'The incident lifecycle (Investigating → … → Resolved) with is_resolved flags and colours.', params: []},
        {m: 'GET', p: '/service-impact-levels', perm: 'reference.read', s: 'List impact levels', d: 'Impact levels ordered worst-first (severity_order 1 = Major Outage).', params: []},
        {m: 'GET', p: '/morning-check-statuses', perm: 'reference.read', s: 'List morning-check statuses', d: 'The status options (Green / Amber / Red by default) with colour and requires_notes; inactive ones included so history always resolves.', params: []},
        {m: 'GET', p: '/workflow-triggers', perm: 'reference.read', s: 'List workflow triggers', d: 'Every trigger event with its condition fields — each field\'s type and the operators valid for it.', params: []},
        {m: 'GET', p: '/workflow-actions', perm: 'reference.read', s: 'List workflow actions', d: 'Every action type with its args spec: labels, required flags, lookup sources and {{template}} variable support.', params: []},
        {m: 'GET', p: '/cmdb-icons', perm: 'reference.read', s: 'List the icon catalogue', d: 'Every icon key usable as a CMDB class icon or a diagram node\'s icon_override.', params: []},
    ]},
    ];

    // --- Render ---------------------------------------------------------------
    const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    let epCounter = 0;

    function renderEndpoint(ep) {
        const id = 'ep' + (epCounter++);
        const pathParams = (ep.params || []).filter(p => p.in === 'path');
        const queryParams = (ep.params || []).filter(p => p.in === 'query');
        const hasBody = ['POST', 'PATCH'].includes(ep.m);

        const paramsTable = (ep.params || []).length ? `
            <table class="params">
                <thead><tr><th>Parameter</th><th>In</th><th>Description</th></tr></thead>
                <tbody>${ep.params.map(p => `
                    <tr><td><code>${esc(p.name)}</code>${p.req ? ' <span class="param-req">required</span>' : ''}</td>
                    <td>${esc(p.in)}</td><td>${esc(p.desc)}</td></tr>`).join('')}
                </tbody>
            </table>` : '';

        const tryPathInputs = pathParams.map(p => `
            <div class="try-row"><label>${esc(p.name)}</label>
            <input type="text" data-role="path" data-name="${esc(p.name)}" placeholder="e.g. 1"></div>`).join('');
        const tryQuery = queryParams.length ? `
            <div class="try-row"><label>Query string</label>
            <input type="text" data-role="query" placeholder="e.g. state=open&per_page=5"></div>` : '';
        const tryBody = hasBody ? `
            <textarea class="try-body" data-role="body">${ep.body ? esc(JSON.stringify(ep.body, null, 2)) : '{\n}'}</textarea>` : '';

        return `<div class="endpoint" id="${id}">
            <div class="ep-head" onclick="this.parentElement.classList.toggle('open')">
                <span class="method ${ep.m}">${ep.m}</span>
                <span class="ep-path">${esc(ep.p)}</span>
                <span class="ep-summary">${esc(ep.s)}</span>
                <span class="ep-perm">${esc(ep.perm)}</span>
            </div>
            <div class="ep-body">
                <p class="ep-desc">${esc(ep.d)}</p>
                ${paramsTable}
                <div class="tryit">
                    <h4>Try it</h4>
                    ${tryPathInputs}${tryQuery}${tryBody}
                    <div class="try-row" style="margin-top:8px;">
                        <button class="send-btn" onclick="sendRequest('${id}', '${ep.m}', '${esc(ep.p)}')">Send</button>
                    </div>
                    <div class="resp"><div class="resp-status"></div><pre></pre></div>
                    <div class="curl-line">cURL equivalent:<code data-role="curl"></code></div>
                </div>
            </div>
        </div>`;
    }

    document.getElementById('docs').innerHTML = SPEC.map(sec =>
        `<div class="section-title">${esc(sec.section)}</div>` + sec.items.map(renderEndpoint).join('')
    ).join('');

    // --- Tester ----------------------------------------------------------------
    const keyInput = document.getElementById('testKey');
    keyInput.value = localStorage.getItem('freeitsm_api_test_key') || '';
    keyInput.addEventListener('change', () => localStorage.setItem('freeitsm_api_test_key', keyInput.value.trim()));

    function buildUrl(container, pathTemplate) {
        let path = pathTemplate;
        container.querySelectorAll('input[data-role=path]').forEach(inp => {
            path = path.replace('{' + inp.dataset.name + '}', encodeURIComponent(inp.value.trim()));
        });
        const q = container.querySelector('input[data-role=query]');
        if (q && q.value.trim()) path += (path.includes('?') ? '&' : '?') + q.value.trim();
        return BASE + path;
    }

    window.sendRequest = async function (id, method, pathTemplate) {
        const container = document.getElementById(id);
        const key = keyInput.value.trim();
        const respBox = container.querySelector('.resp');
        const statusEl = container.querySelector('.resp-status');
        const preEl = container.querySelector('pre');
        const btn = container.querySelector('.send-btn');
        if (!key) { alert('Paste an API key in the "Test key" box at the top first.'); return; }

        const url = buildUrl(container, pathTemplate);
        const bodyEl = container.querySelector('[data-role=body]');
        let bodyText = null;
        if (bodyEl) {
            bodyText = bodyEl.value.trim();
            if (bodyText) {
                try { JSON.parse(bodyText); } catch (e) { alert('The request body is not valid JSON: ' + e.message); return; }
            }
        }

        // Show the cURL equivalent for copy/paste into scripts.
        const curlEl = container.querySelector('[data-role=curl]');
        let curl = `curl -X ${method} "${url}" -H "Authorization: Bearer YOUR_KEY"`;
        if (bodyText) curl += ` -H "Content-Type: application/json" -d '${bodyText.replace(/\n\s*/g, ' ')}'`;
        curlEl.textContent = curl;

        btn.disabled = true;
        try {
            const res = await fetch(url, {
                method: method,
                headers: Object.assign({'Authorization': 'Bearer ' + key},
                    bodyText ? {'Content-Type': 'application/json'} : {}),
                body: bodyText || undefined
            });
            const text = await res.text();
            let pretty = text;
            try { pretty = JSON.stringify(JSON.parse(text), null, 2); } catch (e) { /* leave as-is */ }
            const remaining = res.headers.get('X-RateLimit-Remaining');
            statusEl.textContent = `HTTP ${res.status}` + (remaining !== null ? ` — rate limit remaining: ${remaining}` : '');
            statusEl.className = 'resp-status ' + (res.ok ? 'ok' : 'err');
            preEl.textContent = pretty;
        } catch (e) {
            statusEl.textContent = 'Request failed';
            statusEl.className = 'resp-status err';
            preEl.textContent = String(e);
        } finally {
            respBox.classList.add('show');
            btn.disabled = false;
        }
    };

    document.getElementById('pingBtn').addEventListener('click', async () => {
        const key = keyInput.value.trim();
        const out = document.getElementById('pingResult');
        if (!key) { out.textContent = 'Paste a key first.'; return; }
        localStorage.setItem('freeitsm_api_test_key', key);
        try {
            const res = await fetch(BASE + '/ping', {headers: {'Authorization': 'Bearer ' + key}});
            const data = await res.json();
            if (res.ok) {
                const perms = Object.entries(data.data.key.permissions).map(([r, a]) => r + ':' + a.join('/')).join('  ');
                out.textContent = `✓ Key "${data.data.key.name}" works (acts as ${data.data.key.acts_as}). Permissions — ${perms || 'none'}`;
            } else {
                out.textContent = '✗ ' + (data.error ? data.error.message : `HTTP ${res.status}`);
            }
        } catch (e) {
            out.textContent = '✗ Request failed: ' + e;
        }
    });
    </script>
</body>
</html>
