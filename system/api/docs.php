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
