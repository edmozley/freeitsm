<?php
/**
 * Admin Settings - Manage Departments, Ticket Types, and Exchange Integration
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';

// Check for OAuth success message
$oauthSuccess = isset($_GET['oauth']) && $_GET['oauth'] === 'success';
$oauthMailboxId = $_GET['mailbox_id'] ?? null;

$current_page = 'settings';
$path_prefix = '../../';  // Two levels up from tickets/settings/

// Namespaces the inline JS needs (action-button tooltips, etc.)
$translationNamespaces = ['common', 'tickets'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.settings.page_title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
    <script src="../../assets/js/toast.js"></script>
    <style>
        /* Page-specific overrides for settings page */
        body {
            overflow: auto;
            height: auto;
        }

        /* Override the shared .container 1200px cap so settings fills the
         * full width, matching other modules' settings pages (#268-#270). */
        .container { max-width: none; }

        /* Settings page uses .action-btn for table buttons */
        .tab-content .action-btn {
            background: none;
            border: 1px solid #ddd;
            color: #666;
            cursor: pointer;
            padding: 6px;
            margin-right: 4px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .tab-content .action-btn:hover {
            background: #f0f0f0;
            border-color: #0078d4;
            color: #0078d4;
        }

        .tab-content .action-btn.delete {
            color: #d13438;
        }

        .tab-content .action-btn.delete:hover {
            background: #fdf3f3;
            border-color: #d13438;
            color: #a00;
        }

        .tab-content .action-btn svg {
            width: 16px;
            height: 16px;
        }

        /* Exchange status boxes */
        .exchange-status {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .exchange-status.authenticated {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .exchange-status.not-authenticated {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
        }

        .exchange-status .status-icon {
            font-size: 24px;
        }

        /* Exchange result messages */
        .exchange-result {
            padding: 20px;
            border-radius: 8px;
            display: none;
        }

        .exchange-result.success {
            display: block;
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .exchange-result.error {
            display: block;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .exchange-result.info {
            display: block;
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        .exchange-result pre {
            background: rgba(0, 0, 0, 0.05);
            padding: 10px;
            border-radius: 4px;
            overflow-x: auto;
            margin-top: 10px;
            font-size: 12px;
        }

        /* Modal content override for settings modals */
        .modal-content {
            padding: 30px;
            max-width: 500px;
        }

        .modal-header {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #333;
            padding: 0;
            border-bottom: none;
        }
        /* Toggle switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
            vertical-align: middle;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background: #ccc;
            border-radius: 24px;
            transition: background 0.2s;
        }
        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            left: 3px;
            bottom: 3px;
            background: #fff;
            border-radius: 50%;
            transition: transform 0.2s;
        }
        .toggle-switch input:checked + .toggle-slider {
            background: #0078d4;
        }
        .toggle-switch input:checked + .toggle-slider::before {
            transform: translateX(20px);
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="departments" onclick="switchTab('departments')"><?php echo htmlspecialchars(t('tickets.settings.tabs.departments')); ?></button>
            <button class="tab" data-tab="teams" onclick="switchTab('teams')"><?php echo htmlspecialchars(t('tickets.settings.tabs.teams')); ?></button>
            <button class="tab" data-tab="ticket-types" onclick="switchTab('ticket-types')"><?php echo htmlspecialchars(t('tickets.settings.tabs.ticket_types')); ?></button>
            <button class="tab" data-tab="ticket-origins" onclick="switchTab('ticket-origins')"><?php echo htmlspecialchars(t('tickets.settings.tabs.ticket_origins')); ?></button>
            <button class="tab" data-tab="statuses" onclick="switchTab('statuses')"><?php echo htmlspecialchars(t('tickets.settings.tabs.statuses')); ?></button>
            <button class="tab" data-tab="priorities" onclick="switchTab('priorities')"><?php echo htmlspecialchars(t('tickets.settings.tabs.priorities')); ?></button>
            <button class="tab" data-tab="sla" onclick="switchTab('sla')"><?php echo htmlspecialchars(t('tickets.settings.tabs.sla')); ?></button>
            <button class="tab" data-tab="rota-locations" onclick="switchTab('rota-locations')"><?php echo htmlspecialchars(t('tickets.settings.tabs.rota_locations')); ?></button>
            <button class="tab" data-tab="mailboxes" onclick="switchTab('mailboxes')"><?php echo htmlspecialchars(t('tickets.settings.tabs.mailboxes')); ?></button>
            <button class="tab" data-tab="email-templates" onclick="switchTab('email-templates')"><?php echo htmlspecialchars(t('tickets.settings.tabs.email_templates')); ?></button>
            <button class="tab" data-tab="rota" onclick="switchTab('rota')"><?php echo htmlspecialchars(t('tickets.settings.tabs.rota')); ?></button>
            <button class="tab" data-tab="analysts" onclick="switchTab('analysts')"><?php echo htmlspecialchars(t('tickets.settings.tabs.analysts')); ?></button>
            <button class="tab" data-tab="general" onclick="switchTab('general')"><?php echo htmlspecialchars(t('tickets.settings.tabs.general')); ?></button>
            <button class="tab" data-tab="reply-cleanup" onclick="switchTab('reply-cleanup')"><?php echo htmlspecialchars(t('tickets.settings.tabs.reply_cleanup')); ?></button>
        </div>

        <!-- Departments Tab -->
        <div class="tab-content active" id="departments-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.departments')); ?></h2>
                <button class="add-btn" onclick="openAddModal('department')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.description')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.teams')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="departments-list">
                    <tr><td colspan="6" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Teams Tab -->
        <div class="tab-content" id="teams-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.teams')); ?></h2>
                <button class="add-btn" onclick="openAddModal('team')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: #666;">Teams determine which departments analysts can access. Assign departments to teams, then assign analysts to teams to control their access.</p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.description')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.departments')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.analysts')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="teams-list">
                    <tr><td colspan="7" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Ticket Types Tab -->
        <div class="tab-content" id="ticket-types-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.ticket_types')); ?></h2>
                <button class="add-btn" onclick="openAddModal('ticket-type')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.description')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="ticket-types-list">
                    <tr><td colspan="5" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Ticket Origins Tab -->
        <div class="tab-content" id="ticket-origins-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.ticket_origins')); ?></h2>
                <button class="add-btn" onclick="openAddModal('ticket-origin')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.description')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="ticket-origins-list">
                    <tr><td colspan="5" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Statuses Tab -->
        <div class="tab-content" id="statuses-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.statuses')); ?></h2>
                <button class="add-btn" onclick="openAddModal('status')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: #666;">Workflow states a ticket can be in. Statuses flagged as <em>Closed</em> count as terminal — used by reports, watchtower counters and the closed-datetime auto-set on assign. Exactly one status is the default for new tickets.</p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.colour')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.closed')); ?></th>
                        <th>Pause SLA</th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.default')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="statuses-list">
                    <tr><td colspan="8" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Priorities Tab -->
        <div class="tab-content" id="priorities-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.priorities')); ?></h2>
                <button class="add-btn" onclick="openAddModal('priority')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: #666;">Priority bands shown on tickets. Exactly one priority is the default for new tickets.</p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.colour')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.default')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="priorities-list">
                    <tr><td colspan="6" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- SLA Tab — see docs/sla.md -->
        <div class="tab-content" id="sla-tab">
            <h2>Service Level Agreements</h2>
            <p style="margin-bottom: 20px; color: #666;">
                Business-hours-aware SLAs with per-priority response and resolution targets. The clock pauses on statuses
                flagged "Pauses SLA" on the <a href="#" onclick="event.preventDefault();switchTab('statuses');">Statuses tab</a>.
                See <a href="https://github.com/edmozley/freeitsm/blob/main/docs/sla.md" target="_blank">design notes</a>.
            </p>

            <!-- ===== Global SLA settings ===== -->
            <div class="settings-group" style="background:#fafafa;border:1px solid #e0e0e0;border-radius:6px;padding:20px;margin-bottom:30px;">
                <h3 style="margin-top:0;">Global settings</h3>
                <form id="slaGlobalForm" style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
                    <div class="form-group" style="grid-column:span 2;">
                        <label for="slaEnforceFrom">Enforce SLAs from</label>
                        <input type="datetime-local" id="slaEnforceFrom" style="max-width:260px;">
                        <small style="display:block;color:#666;margin-top:4px;">
                            Leave blank to <strong>disable SLA enforcement entirely</strong>. Set to a datetime and only tickets created
                            at or after that point get evaluated &mdash; useful for grandfathering in existing tickets when first activating SLAs.
                        </small>
                    </div>

                    <div class="form-group">
                        <label>When a ticket's priority changes mid-flight</label>
                        <label style="display:block;margin-top:6px;font-weight:400;">
                            <input type="radio" name="slaPriorityChange" value="forward"> Apply new SLA from the change point forward
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="radio" name="slaPriorityChange" value="recompute"> Recompute retroactively against the new target
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="radio" name="slaPriorityChange" value="reset"> Reset the SLA clock entirely
                        </label>
                    </div>

                    <div class="form-group">
                        <label>When a closed ticket is reopened</label>
                        <label style="display:block;margin-top:6px;font-weight:400;">
                            <input type="radio" name="slaReopen" value="reset"> Start the SLA fresh
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="radio" name="slaReopen" value="continue"> Continue from where the clock paused
                        </label>
                    </div>

                    <div class="form-group">
                        <label>First-response counts as</label>
                        <label style="display:block;margin-top:6px;font-weight:400;">
                            <input type="radio" name="slaFirstResponse" value="outbound_email"> Outbound email only (Reply / Forward)
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="radio" name="slaFirstResponse" value="status_change"> Status change away from the default
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="radio" name="slaFirstResponse" value="either"> Either, whichever happens first
                        </label>
                    </div>

                    <div class="form-group">
                        <label for="slaWarningThreshold">Warning threshold (%)</label>
                        <input type="number" id="slaWarningThreshold" min="1" max="100" style="max-width:120px;">
                        <small style="display:block;color:#666;margin-top:4px;">Tickets flag visually in the inbox at this % of their SLA elapsed.</small>
                    </div>

                    <div class="form-group">
                        <label>Notifications</label>
                        <label style="display:block;margin-top:6px;font-weight:400;">
                            <input type="checkbox" id="slaNotifyAssignee"> Email the assignee at warning threshold
                        </label>
                        <label style="display:block;font-weight:400;">
                            <input type="checkbox" id="slaNotifyLead"> Email the team lead at breach
                        </label>
                    </div>

                    <div style="grid-column:span 2;margin-top:8px;">
                        <button type="button" class="btn btn-primary" onclick="saveSlaGlobalSettings()">Save global settings</button>
                        <span id="slaGlobalSaveStatus" style="margin-left:10px;font-size:13px;color:#16a34a;"></span>
                    </div>
                </form>
            </div>

            <!-- ===== SLA Targets per priority ===== -->
            <div class="settings-group" style="margin-bottom:30px;">
                <h3>SLA Targets per priority</h3>
                <p style="color:#666;margin-bottom:14px;">Response and resolution times for each ticket priority. Times are in minutes (60 = 1 hour, 240 = 4 hours, 1440 = 1 day). The calendar determines which business hours the clock ticks against. Leave blank to skip that target.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Priority</th>
                            <th>Response (mins)</th>
                            <th>Resolution (mins)</th>
                            <th>Calendar</th>
                            <th style="width:90px;">Save</th>
                        </tr>
                    </thead>
                    <tbody id="slaTargetsList">
                        <tr><td colspan="5" style="text-align:center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ===== Business Calendars ===== -->
            <div class="settings-group">
                <div class="section-header">
                    <h3 style="margin:0;">Business Calendars</h3>
                    <button class="add-btn" onclick="openSlaCalendarModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
                </div>
                <p style="color:#666;margin-bottom:14px;">Define working hours, timezones, and holiday lists. Calendars are referenced by SLA targets (above) and by individual priorities. One calendar is the default for new priorities.</p>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Timezone</th>
                            <th>Hours</th>
                            <th>Holidays</th>
                            <th>Default</th>
                            <th style="width:120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="slaCalendarsList">
                        <tr><td colspan="6" style="text-align:center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- ===== Breach Notifications ===== -->
            <div class="settings-group">
                <div class="section-header">
                    <h3 style="margin:0;">Breach Notifications</h3>
                    <button class="add-btn" onclick="openSlaNotifModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
                </div>

                <div style="background:#eff6ff;border-left:4px solid #2563eb;padding:14px 16px;border-radius:4px;margin-bottom:18px;font-size:13px;line-height:1.6;color:#1e3a8a;">
                    <strong style="display:block;margin-bottom:6px;font-size:14px;">How notification rules work</strong>
                    Each rule has four parts: a <strong>scope</strong>, a <strong>trigger</strong>, a <strong>target</strong>, and a list of <strong>recipients</strong>.<br><br>

                    <strong>Scope</strong> can be either <em>Default</em> (the rule applies to tickets in every department)
                    or a <em>specific department</em> (the rule applies only to tickets in that department).<br>

                    When a ticket is being evaluated, the system looks for a rule matching its department first &mdash; if one exists, that rule wins
                    and the default is ignored. If there's no department-specific rule, the default rule is used. This way you can set sensible
                    defaults that cover every department, then carve out exceptions where one team has different escalation needs.<br><br>

                    <strong>Trigger</strong> is either <em>Warning</em> (the ticket has crossed the warning threshold &mdash; potential breach approaching) or
                    <em>Breach</em> (the ticket has now exceeded its SLA target &mdash; actual breach). Each is its own rule, so you can notify
                    different people for warnings vs. breaches.<br><br>

                    <strong>Target</strong> is which SLA clock to watch &mdash; <em>Response</em>, <em>Resolution</em>, or <em>Both</em>.<br><br>

                    <strong>Recipients</strong> can be any combination of: the ticket's current assignee, every analyst in the ticket's department teams,
                    one named analyst, and/or a list of free-form email addresses (useful for shared inboxes or Slack/Teams email bridges).<br><br>

                    <strong style="color:#92400e;">No rules = no emails.</strong> Even if a ticket breaches, nothing fires until you add at least
                    one rule (start with a Default-scope Warning + Breach pair to get coverage for everything).
                </div>

                <p style="color:#666;margin-bottom:14px;font-size:13px;">
                    Each ticket fires at most one email per target per trigger &mdash; the cron worker tracks what's already been sent so you don't get duplicates.
                </p>
                <table>
                    <thead>
                        <tr>
                            <th>Scope</th>
                            <th>Trigger</th>
                            <th>Target</th>
                            <th>Recipients</th>
                            <th>Active</th>
                            <th style="width:120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="slaNotifRulesList">
                        <tr><td colspan="6" style="text-align:center;">Loading...</td></tr>
                    </tbody>
                </table>
                <p style="color:#888;margin-top:14px;font-size:12px;">
                    The cron worker that fires these emails lives at <code>cron/sla_breach_check.php</code>.
                    See <code>docs/sla-cron-setup.md</code> for Windows Task Scheduler + Linux cron setup.
                </p>
            </div>

            <!-- ===== Cron Activity ===== -->
            <div class="settings-group">
                <div class="section-header">
                    <h3 style="margin:0;">Cron Activity</h3>
                    <button class="add-btn" onclick="loadSlaCronRuns()" title="Refresh">&#x21bb;</button>
                </div>
                <p style="color:#666;margin-bottom:14px;font-size:13px;">
                    Last <span id="slaCronRunsLimit">20</span> invocations of the breach-check cron (CLI and HTTP).
                    Includes rejected requests (rate-limited, auth-failed) so the same source-of-truth supports the rate-limit
                    checks and security audits. Pruned automatically after
                    <strong><span id="slaCronRetentionDays">30</span> days</strong>; min interval between successful runs is
                    <strong><span id="slaCronMinInterval">30</span>s</strong> (both configurable in <code>system_settings</code>).
                </p>
                <table>
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Source</th>
                            <th>Duration</th>
                            <th>Sent</th>
                            <th>Skipped</th>
                            <th>Errors</th>
                            <th>Outcome</th>
                        </tr>
                    </thead>
                    <tbody id="slaCronRunsList">
                        <tr><td colspan="7" style="text-align:center;color:#888;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ===== Breach Notification rule modal ===== -->
        <div id="slaNotifModal" class="modal">
            <div class="modal-content" style="max-width:640px;">
                <div class="modal-header">
                    <h3 id="slaNotifModalTitle">Add Notification Rule</h3>
                    <span class="close" onclick="closeSlaNotifModal()">&times;</span>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="slaNotifId" value="">

                    <div class="form-group">
                        <label for="slaNotifDept">Scope</label>
                        <select id="slaNotifDept" class="form-control">
                            <option value="">Default (applies to every department without a specific rule)</option>
                        </select>
                    </div>

                    <div class="form-group" style="display:flex;gap:12px;">
                        <div style="flex:1;">
                            <label for="slaNotifTrigger">Trigger</label>
                            <select id="slaNotifTrigger" class="form-control">
                                <option value="warning">Warning &mdash; approaching breach (potential)</option>
                                <option value="breach">Breach &mdash; target exceeded (actual)</option>
                            </select>
                        </div>
                        <div style="flex:1;">
                            <label for="slaNotifTarget">Target</label>
                            <select id="slaNotifTarget" class="form-control">
                                <option value="both">Both response and resolution</option>
                                <option value="response">Response only</option>
                                <option value="resolution">Resolution only</option>
                            </select>
                        </div>
                    </div>

                    <fieldset style="border:1px solid #ddd;padding:12px 16px;border-radius:4px;margin-bottom:14px;">
                        <legend style="padding:0 6px;font-weight:600;font-size:13px;">Recipients</legend>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label><input type="checkbox" id="slaNotifAssignee"> The ticket's assignee</label>
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label><input type="checkbox" id="slaNotifTeams"> Members of the ticket's department teams</label>
                        </div>
                        <div class="form-group" style="margin-bottom:8px;">
                            <label for="slaNotifAnalyst">A specific analyst</label>
                            <select id="slaNotifAnalyst" class="form-control">
                                <option value="">&mdash; none &mdash;</option>
                            </select>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label for="slaNotifEmails">Additional email addresses</label>
                            <textarea id="slaNotifEmails" class="form-control" rows="2" placeholder="alerts@company.com, slm@company.com"></textarea>
                            <small style="color:#888;">Comma, semicolon, or newline separated. Useful for distribution lists or Slack/Teams email bridges.</small>
                        </div>
                    </fieldset>

                    <div class="form-group">
                        <label><input type="checkbox" id="slaNotifActive" checked> Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-secondary" onclick="closeSlaNotifModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button class="btn btn-primary" onclick="saveSlaNotifRule()"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </div>
        </div>

        <!-- Rota Locations Tab -->
        <div class="tab-content" id="rota-locations-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.rota_locations')); ?></h2>
                <button class="add-btn" onclick="openAddModal('rota-location')"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 20px; color: #666;">Where each analyst is working on a given day — used by the staff rota and shown as a coloured badge on every entry. Exactly one location is the default for new rota entries.</p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.colour')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.default')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="rota-locations-list">
                    <tr><td colspan="6" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Mailboxes Tab -->
        <div class="tab-content" id="mailboxes-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.mailboxes')); ?></h2>
                <div>
                    <button class="btn btn-secondary" onclick="window.location.href='../activity.php'" style="margin-right: 10px;"><?php echo htmlspecialchars(t('tickets.settings.buttons.logs')); ?></button>
                    <button class="btn btn-primary" onclick="checkAllMailboxes()" style="margin-right: 10px;"><?php echo htmlspecialchars(t('tickets.settings.buttons.check_all')); ?></button>
                    <button class="add-btn" onclick="openMailboxModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
                </div>
            </div>

            <?php if ($oauthSuccess && $oauthMailboxId): ?>
            <div class="exchange-status authenticated" id="oauth-success-msg">
                <span class="status-icon">&#10003;</span>
                <div>
                    <strong>Authentication Successful!</strong><br>
                    Mailbox is now connected and ready to check for emails.
                </div>
            </div>
            <?php endif; ?>

            <div id="mailboxesResult" class="exchange-result"></div>

            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.mailbox')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.last_checked')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="mailboxes-list">
                    <tr><td colspan="5" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Email Templates Tab -->
        <div class="tab-content" id="email-templates-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.email_templates')); ?></h2>
                <button class="add-btn" onclick="openTemplateModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <p style="margin-bottom: 15px; color: #666;">Automated email responses triggered by ticket events. Only the first active template per event is used.</p>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.event')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.subject')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="email-templates-list">
                    <tr><td colspan="6" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- Rota Tab -->
        <div class="tab-content" id="rota-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.rota_shifts')); ?></h2>
                <button class="add-btn" onclick="openRotaShiftModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.start')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.end')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.order')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="rota-shifts-list">
                    <tr><td colspan="6" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
            <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e0e0e0;">
                <h2 style="font-size: 16px; margin-bottom: 12px;"><?php echo htmlspecialchars(t('tickets.settings.headings.rota_settings')); ?></h2>
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" id="rotaIncludeWeekends" onchange="saveRotaWeekendSetting()">
                    Include weekends on the rota
                </label>
            </div>
        </div>

        <!-- Analysts Tab -->
        <div class="tab-content" id="analysts-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.analysts')); ?></h2>
                <button class="add-btn" onclick="openAnalystModal()"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.username')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.full_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.email')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.teams')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.status')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.last_login')); ?></th>
                        <th><?php echo htmlspecialchars(t('tickets.settings.columns.actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="analysts-list">
                    <tr><td colspan="7" style="text-align: center;">Loading...</td></tr>
                </tbody>
            </table>
        </div>

        <!-- General Tab -->
        <div class="tab-content" id="general-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.general_settings')); ?></h2>
            </div>
            <form id="generalSettingsForm" style="max-width: 600px;">
                <div class="form-group">
                    <label for="systemName">System Name</label>
                    <input type="text" id="systemName" placeholder="e.g., Service Desk Ticketing System">
                    <small style="color: #666;">This name appears in the header and page titles.</small>
                </div>

                <div class="form-group">
                    <label for="systemTimezone">Timezone</label>
                    <select id="systemTimezone" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value="">Loading...</option>
                    </select>
                    <small style="color: #666;">Used for displaying dates and times throughout the system.</small>
                </div>

                <div class="modal-actions" style="justify-content: flex-start; margin-top: 30px;">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>

        </div>

        <!-- Reply Cleanup Tab -->
        <div class="tab-content" id="reply-cleanup-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tickets.settings.headings.reply_cleanup_ai')); ?></h2>
            </div>
            <p style="max-width: 700px; color: #555;">
                When an analyst types a rough reply in the ticket compose modal, the
                <strong>✨ Cleanup</strong> button will rewrite it as a properly formatted
                email — adding a "Dear [name]," greeting, fixing grammar, applying the
                tone you choose below, and signing off with "Kind regards,". It will
                <strong>not</strong> invent technical details or pad the content.
            </p>
            <p style="max-width: 700px; color: #555;">
                This feature uses its own Anthropic API key (separate from RFP AI and
                Knowledge AI) so its usage shows up as a discrete line on the
                Anthropic billing dashboard.
            </p>

            <form id="replyCleanupForm" style="max-width: 600px; margin-top: 24px;">
                <div class="form-group">
                    <label for="rcApiKey">Anthropic API Key</label>
                    <input type="password" id="rcApiKey" autocomplete="off" placeholder="sk-ant-...">
                    <small style="color: #666;">Encrypted at rest. Leave the masked value untouched to keep the existing key.</small>
                </div>

                <div class="form-group">
                    <label for="rcModel">Model</label>
                    <select id="rcModel" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value="claude-haiku-4-5-20251001">Claude Haiku 4.5 (recommended — fast and cheap)</option>
                        <option value="claude-sonnet-4-6">Claude Sonnet 4.6</option>
                        <option value="claude-opus-4-7">Claude Opus 4.7</option>
                    </select>
                    <small style="color: #666;">Haiku is plenty for grammar fixes and greetings.</small>
                </div>

                <div class="form-group">
                    <label for="rcTone">Tone</label>
                    <select id="rcTone" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                        <option value="Friendly">Friendly (default)</option>
                        <option value="Formal">Formal</option>
                        <option value="Brief">Brief</option>
                    </select>
                    <small style="color: #666;">Applied to every cleanup unless changed here.</small>
                </div>

                <div class="form-group">
                    <label for="rcCustomInstructions">Custom Instructions <span style="color: #999; font-weight: normal;">(optional)</span></label>
                    <textarea id="rcCustomInstructions" rows="6" maxlength="4000"
                              style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; font-family: inherit; resize: vertical;"
                              placeholder="e.g. Always sign off with 'Many thanks,'&#10;Refer to the company as 'BillCorp'&#10;Use British English spellings throughout"></textarea>
                    <small style="color: #666;">
                        Appended to the system prompt below. Use this for organisation-specific tweaks
                        (sign-off variations, company name, language preferences). The hard safety rules
                        and output format above will still take precedence.
                    </small>
                </div>

                <details style="margin-top: 24px; border: 1px solid #e5e5e5; border-radius: 4px; background: #fafafa;">
                    <summary style="padding: 12px 16px; cursor: pointer; font-weight: 600; color: #333;">
                        View system prompt (read-only)
                    </summary>
                    <div style="padding: 0 16px 16px 16px; color: #555;">
                        <p style="margin: 0 0 12px 0; font-size: 13px;">
                            This is the full system prompt sent to Claude on every cleanup. The greeting
                            name varies per ticket; the tone reflects your selection above. Your custom
                            instructions (if any) are appended at the end at runtime — they are not shown
                            here, edit them in the textarea above.
                        </p>
                        <pre id="rcPromptPreview" style="white-space: pre-wrap; word-wrap: break-word; font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; line-height: 1.5; background: white; padding: 14px; border: 1px solid #e0e0e0; border-radius: 4px; max-height: 500px; overflow-y: auto; color: #333; margin: 0;"></pre>
                    </div>
                </details>

                <div class="modal-actions" style="justify-content: flex-start; margin-top: 30px; gap: 12px;">
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                    <button type="button" id="rcTestBtn" class="btn" style="background: #6c757d; color: white;"><?php echo htmlspecialchars(t('tickets.settings.buttons.test_connection')); ?></button>
                </div>

                <div id="rcTestResult" style="margin-top: 16px; padding: 10px 14px; border-radius: 4px; display: none; font-size: 13px;"></div>
            </form>
        </div>
    </div>

    <!-- Modal for Add/Edit -->
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-header" id="modalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.add.fallback')); ?></div>
            <form id="editForm">
                <input type="hidden" id="itemId">
                <input type="hidden" id="itemType">

                <div class="form-group">
                    <label for="itemName"><?php echo htmlspecialchars(t('tickets.settings.columns.name')); ?></label>
                    <input type="text" id="itemName" required>
                </div>

                <div class="form-group" id="itemDescriptionGroup">
                    <label for="itemDescription"><?php echo htmlspecialchars(t('tickets.settings.columns.description')); ?></label>
                    <textarea id="itemDescription"></textarea>
                </div>

                <div class="form-group" id="itemColourGroup" style="display: none;">
                    <label for="itemColour"><?php echo htmlspecialchars(t('tickets.settings.columns.colour')); ?></label>
                    <input type="color" id="itemColour" value="#2563eb" style="width: 60px; height: 32px; padding: 2px;">
                    <small style="color: #666; margin-left: 8px;"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.colour_help')); ?></small>
                </div>

                <div class="form-group" id="itemClosedGroup" style="display: none;">
                    <label>
                        <input type="checkbox" id="itemClosed"> <?php echo htmlspecialchars(t('tickets.settings.modals.lookup.closed_label')); ?>
                    </label>
                    <small style="display: block; color: #666; margin-top: 4px;"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.closed_help')); ?></small>
                </div>

                <div class="form-group" id="itemPausesSlaGroup" style="display: none;">
                    <label>
                        <input type="checkbox" id="itemPausesSla"> Pauses SLA clock
                    </label>
                    <small style="display: block; color: #666; margin-top: 4px;">When a ticket is in this status, the SLA clock stops ticking. Used for statuses where the ticket isn't being actively worked (e.g. <em>On Hold</em>, <em>Awaiting Response</em>).</small>
                </div>

                <div class="form-group" id="itemDefaultGroup" style="display: none;">
                    <label>
                        <input type="checkbox" id="itemDefault"> <?php echo htmlspecialchars(t('tickets.settings.modals.lookup.default_label')); ?>
                    </label>
                    <small style="display: block; color: #666; margin-top: 4px;"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.default_help')); ?></small>
                </div>

                <div class="form-group">
                    <label for="itemOrder"><?php echo htmlspecialchars(t('tickets.settings.modals.lookup.display_order_label')); ?></label>
                    <input type="number" id="itemOrder" value="0">
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="itemActive" checked> <?php echo htmlspecialchars(t('tickets.settings.modals.lookup.active_label')); ?>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Mailbox Modal -->
    <div class="modal" id="mailboxModal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header" id="mailboxModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.add_title')); ?></div>
            <form id="mailboxForm" autocomplete="off" style="overflow-y: auto; flex: 1; padding: 20px 24px;">
                <input type="hidden" id="mailboxId">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group" style="grid-column: span 2;">
                        <label for="mailboxProvider"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.provider')); ?> *</label>
                        <select id="mailboxProvider" onchange="toggleProviderFields()">
                            <option value="microsoft"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.provider_microsoft')); ?></option>
                            <option value="google"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.provider_google')); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="mailboxName"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.display_name')); ?> *</label>
                        <input type="text" id="mailboxName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.display_name_placeholder')); ?>">
                    </div>

                    <div class="form-group">
                        <label for="mailboxEmail"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.target_mailbox')); ?> *</label>
                        <input type="email" id="mailboxEmail" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.target_mailbox_placeholder')); ?>">
                    </div>

                    <div class="form-group provider-microsoft">
                        <label for="mailboxTenantId"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.azure_tenant_id')); ?> *</label>
                        <input type="text" id="mailboxTenantId" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    </div>

                    <div class="form-group" id="clientIdGroup">
                        <label for="mailboxClientId" id="clientIdLabel"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.client_id')); ?> *</label>
                        <input type="text" id="mailboxClientId" required placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="mailboxClientSecret" id="clientSecretLabel"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.client_secret')); ?> *</label>
                        <input type="password" id="mailboxClientSecret" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.client_secret_placeholder')); ?>">
                        <small style="color: #666;"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.client_secret_help')); ?></small>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="mailboxRedirectUri"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.oauth_redirect_uri')); ?> *</label>
                        <input type="url" id="mailboxRedirectUri" required placeholder="https://yoursite.com/oauth_callback.php">
                    </div>

                    <div class="form-group provider-microsoft" style="grid-column: span 2;">
                        <label for="mailboxScopes"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.oauth_scopes')); ?></label>
                        <input type="text" id="mailboxScopes" value="openid email offline_access Mail.Read Mail.ReadWrite Mail.Send">
                    </div>

                    <div class="form-group">
                        <label for="mailboxImapServer"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imap_server')); ?></label>
                        <input type="text" id="mailboxImapServer" value="outlook.office365.com">
                    </div>

                    <div class="form-group">
                        <label for="mailboxImapPort"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imap_port')); ?></label>
                        <input type="number" id="mailboxImapPort" value="993">
                    </div>

                    <div class="form-group">
                        <label for="mailboxFolder"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.email_folder')); ?></label>
                        <input type="text" id="mailboxFolder" value="INBOX">
                    </div>

                    <div class="form-group">
                        <label for="mailboxMaxEmails"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.max_emails_per_check')); ?></label>
                        <input type="number" id="mailboxMaxEmails" value="10" min="1" max="50">
                    </div>

                    <div class="form-group">
                        <label for="mailboxRejectedAction"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.rejected_emails')); ?></label>
                        <select id="mailboxRejectedAction">
                            <option value="delete"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.rejected_delete')); ?></option>
                            <option value="move_to_deleted"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.rejected_move_to_deleted')); ?></option>
                            <option value="mark_read"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.rejected_mark_read')); ?></option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="mailboxImportedAction"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imported_emails')); ?></label>
                        <select id="mailboxImportedAction" onchange="toggleImportedFolder()">
                            <option value="delete"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imported_delete')); ?></option>
                            <option value="move_to_folder"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.imported_move_to_folder')); ?></option>
                        </select>
                    </div>

                    <div class="form-group" id="importedFolderGroup" style="display: none; grid-column: span 2;">
                        <label for="mailboxImportedFolder"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.move_to_folder_label')); ?></label>
                        <div style="display: flex; gap: 8px; align-items: start;">
                            <input type="text" id="mailboxImportedFolder" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.move_to_folder_placeholder')); ?>" style="flex: 1;">
                            <button type="button" class="btn btn-secondary" id="verifyFolderBtn" onclick="verifyFolder()" style="padding: 8px 12px; white-space: nowrap;"><?php echo htmlspecialchars(t('tickets.settings.buttons.verify')); ?></button>
                        </div>
                        <small id="verifyFolderResult" style="display: none; margin-top: 5px;"></small>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.active')); ?>
                            <label class="toggle-switch" style="margin: 0;">
                                <input type="checkbox" id="mailboxActive" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </label>
                    </div>
                </div>

                <div style="grid-column: span 2; margin-top: 10px; border-top: 1px solid #e0e0e0; padding-top: 15px;">
                    <label style="font-weight: 600; margin-bottom: 8px; display: block;"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.whitelist_label')); ?></label>
                    <small style="color: #666; display: block; margin-bottom: 10px;"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.whitelist_help')); ?></small>

                    <div style="display: flex; gap: 8px; margin-bottom: 10px;">
                        <select id="whitelistType" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;">
                            <option value="domain"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.whitelist_domain')); ?></option>
                            <option value="email"><?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.whitelist_email')); ?></option>
                        </select>
                        <input type="text" id="whitelistValue" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.mailbox.whitelist_value_placeholder')); ?>" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;" onkeydown="if(event.key==='Enter'){event.preventDefault();addWhitelistEntry();}">
                        <button type="button" class="btn btn-primary" onclick="addWhitelistEntry()" style="padding: 8px 12px;"><?php echo htmlspecialchars(t('common.add')); ?></button>
                    </div>

                    <div id="whitelistEntries" style="display: flex; flex-wrap: wrap; gap: 6px;"></div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeMailboxModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Activity Log Modal -->
    <div class="modal" id="activityModal">
        <div class="modal-content" style="max-width: 850px;">
            <div class="modal-header" id="activityModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.activity.title')); ?></div>

            <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                <input type="text" id="activitySearch" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.activity.search_placeholder')); ?>" style="flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px;" oninput="debounceActivitySearch()">
            </div>

            <div style="max-height: 450px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.date_time')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.from')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.subject')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.action')); ?></th>
                            <th><?php echo htmlspecialchars(t('tickets.settings.columns.reason')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="activityList">
                        <tr><td colspan="5" style="text-align: center;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>

            <div id="activityPagination" style="display: flex; justify-content: space-between; align-items: center; margin-top: 15px; font-size: 13px; color: #666;"></div>

            <div id="processingLogPanel" style="display: none; margin-top: 15px; border-top: 1px solid #e0e0e0; padding-top: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <strong style="font-size: 14px;"><?php echo htmlspecialchars(t('tickets.settings.modals.activity.processing_log')); ?></strong>
                    <button type="button" class="btn btn-secondary" style="padding: 3px 10px; font-size: 12px;" onclick="closeProcessingLog()"><?php echo htmlspecialchars(t('common.close')); ?></button>
                </div>
                <pre id="processingLogContent" style="background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; padding: 12px; font-size: 12px; max-height: 250px; overflow-y: auto; white-space: pre-wrap; word-break: break-word; margin: 0;"></pre>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeActivityModal()"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <!-- Analyst Modal -->
    <div class="modal" id="analystModal">
        <div class="modal-content">
            <div class="modal-header" id="analystModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.add_title')); ?></div>
            <form id="analystForm">
                <input type="hidden" id="analystId">

                <div class="form-group">
                    <label for="analystUsername"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.username')); ?> *</label>
                    <input type="text" id="analystUsername" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.username_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="analystFullName"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.full_name')); ?> *</label>
                    <input type="text" id="analystFullName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.full_name_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="analystEmail"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.email')); ?></label>
                    <input type="email" id="analystEmail" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.email_placeholder')); ?>">
                </div>

                <div class="form-group" id="analystPasswordGroup">
                    <label for="analystPassword"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.password')); ?> *</label>
                    <input type="password" id="analystPassword" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.analyst.password_placeholder')); ?>">
                    <small style="color: #666;"><?php echo htmlspecialchars(t('tickets.settings.modals.analyst.password_help')); ?></small>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="analystActive" checked> <?php echo htmlspecialchars(t('tickets.settings.modals.analyst.active')); ?>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAnalystModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Password Reset Modal -->
    <div class="modal" id="passwordResetModal">
        <div class="modal-content">
            <div class="modal-header"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.title')); ?></div>
            <form id="passwordResetForm">
                <input type="hidden" id="resetAnalystId">

                <p style="margin-bottom: 20px;"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.resetting_for')); ?> <strong id="resetAnalystName"></strong></p>

                <div class="form-group">
                    <label for="newPassword"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.new_password')); ?> *</label>
                    <input type="password" id="newPassword" required minlength="6" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.new_password_placeholder')); ?>">
                </div>

                <div class="form-group">
                    <label for="confirmPassword"><?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.confirm_password')); ?> *</label>
                    <input type="password" id="confirmPassword" required minlength="6" placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.password_reset.confirm_password_placeholder')); ?>">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closePasswordResetModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('tickets.settings.tooltips.reset_password')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Team Assignment Modal -->
    <div class="modal" id="teamAssignmentModal">
        <div class="modal-content">
            <div class="modal-header" id="teamAssignmentTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.team_assignment.title')); ?></div>
            <form id="teamAssignmentForm">
                <input type="hidden" id="assignmentEntityType">
                <input type="hidden" id="assignmentEntityId">

                <p style="margin-bottom: 15px; color: #666;" id="teamAssignmentDesc"><?php echo htmlspecialchars(t('tickets.settings.modals.team_assignment.description')); ?></p>

                <div id="teamAssignmentList" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; border-radius: 4px;">
                    <div style="padding: 15px; text-align: center; color: #999;"><?php echo htmlspecialchars(t('tickets.settings.modals.team_assignment.loading')); ?></div>
                </div>

                <div class="modal-actions" style="margin-top: 20px;">
                    <button type="button" class="btn btn-secondary" onclick="closeTeamAssignmentModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Template Modal -->
    <div class="modal" id="templateModal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header" id="templateModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.template.add_title')); ?></div>
            <form id="templateForm">
                <input type="hidden" id="templateId">

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="templateName"><?php echo htmlspecialchars(t('tickets.settings.modals.template.name')); ?> *</label>
                        <input type="text" id="templateName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.template.name_placeholder')); ?>" autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="templateEvent"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_trigger')); ?> *</label>
                        <select id="templateEvent" required>
                            <option value=""><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_select')); ?></option>
                            <option value="new_ticket_email"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_new_ticket')); ?></option>
                            <option value="ticket_assigned"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_assigned')); ?></option>
                            <option value="ticket_closed"><?php echo htmlspecialchars(t('tickets.settings.modals.template.event_closed')); ?></option>
                        </select>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="templateSubject"><?php echo htmlspecialchars(t('tickets.settings.modals.template.subject')); ?> *</label>
                        <input type="text" id="templateSubject" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.template.subject_placeholder')); ?>" autocomplete="off">
                        <small style="color: #666;"><?php echo htmlspecialchars(t('tickets.settings.modals.template.subject_help')); ?></small>
                    </div>

                    <div class="form-group" style="grid-column: span 2;">
                        <label for="templateBody"><?php echo htmlspecialchars(t('tickets.settings.modals.template.body')); ?> *</label>
                        <textarea id="templateBody" rows="10" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.template.body_placeholder')); ?>"></textarea>
                        <small style="color: #666;">
                            <?php echo htmlspecialchars(t('tickets.settings.modals.template.body_help')); ?>
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="templateOrder"><?php echo htmlspecialchars(t('tickets.settings.modals.template.display_order')); ?></label>
                        <input type="number" id="templateOrder" value="0" autocomplete="off">
                    </div>

                    <div class="form-group" style="display: flex; align-items: center;">
                        <label style="display: flex; align-items: center; gap: 10px; margin: 0;">
                            <?php echo htmlspecialchars(t('tickets.settings.modals.template.active')); ?>
                            <label class="toggle-switch" style="margin: 0;">
                                <input type="checkbox" id="templateActive" checked>
                                <span class="toggle-slider"></span>
                            </label>
                        </label>
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeTemplateModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Rota Shift Modal -->
    <div class="modal" id="rotaShiftModal">
        <div class="modal-content">
            <div class="modal-header" id="rotaShiftModalTitle"><?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.add_title')); ?></div>
            <form id="rotaShiftForm">
                <input type="hidden" id="rotaShiftId">

                <div class="form-group">
                    <label for="rotaShiftName"><?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.name')); ?> *</label>
                    <input type="text" id="rotaShiftName" required placeholder="<?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.name_placeholder')); ?>">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label for="rotaShiftStart"><?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.start_time')); ?> *</label>
                        <input type="time" id="rotaShiftStart" required>
                    </div>

                    <div class="form-group">
                        <label for="rotaShiftEnd"><?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.end_time')); ?> *</label>
                        <input type="time" id="rotaShiftEnd" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="rotaShiftOrder"><?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.display_order')); ?></label>
                    <input type="number" id="rotaShiftOrder" value="0">
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" id="rotaShiftActive" checked> <?php echo htmlspecialchars(t('tickets.settings.modals.rota_shift.active')); ?>
                    </label>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeRotaShiftModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- SLA Calendar Modal -->
    <div class="modal" id="slaCalendarModal">
        <div class="modal-content" style="max-width:680px;">
            <div class="modal-header" id="slaCalendarModalTitle">Add Business Calendar</div>
            <form id="slaCalendarForm" style="padding:20px 24px;overflow-y:auto;flex:1;">
                <input type="hidden" id="slaCalendarId">

                <div style="display:grid;grid-template-columns:2fr 2fr 1fr;gap:15px;">
                    <div class="form-group">
                        <label for="slaCalendarName">Name *</label>
                        <input type="text" id="slaCalendarName" required placeholder="e.g., London Business Hours">
                    </div>
                    <div class="form-group">
                        <label for="slaCalendarTimezone">Timezone *</label>
                        <select id="slaCalendarTimezone" required></select>
                        <small style="color:#666;">IANA zone (e.g. Europe/London, America/New_York)</small>
                    </div>
                    <div class="form-group" style="display:flex;align-items:end;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" id="slaCalendarIsDefault"> Default
                        </label>
                    </div>
                </div>

                <h4 style="margin:18px 0 8px;">Weekly working hours</h4>
                <p style="color:#666;font-size:13px;margin:0 0 10px;">Uncheck a day to mark it as closed. Most desks use Mon-Fri 09:00-17:00.</p>
                <div id="slaCalendarHoursGrid" style="display:grid;grid-template-columns:90px 80px 1fr 1fr;gap:8px 12px;align-items:center;">
                    <!-- rows injected by JS: 7 weekdays -->
                </div>

                <h4 style="margin:24px 0 8px;">Holidays</h4>
                <p style="color:#666;font-size:13px;margin:0 0 10px;">Dates that override the weekly pattern (the clock won't tick on these days).</p>
                <div id="slaCalendarHolidaysList" style="margin-bottom:10px;"></div>
                <div style="display:flex;gap:8px;">
                    <input type="date" id="slaCalendarHolidayDate" style="padding:6px 10px;border:1px solid #ddd;border-radius:4px;">
                    <input type="text" id="slaCalendarHolidayName" placeholder="Name (optional, e.g. Christmas Day)" style="flex:1;padding:6px 10px;border:1px solid #ddd;border-radius:4px;">
                    <button type="button" class="btn btn-secondary" onclick="addSlaHoliday()">Add holiday</button>
                </div>
                <small style="color:#666;display:block;margin-top:4px;">Note: holidays are saved with the rest of the calendar — they only persist when you hit Save.</small>

                <div class="modal-actions">
                    <button type="button" class="btn btn-danger" id="slaCalendarDeleteBtn" onclick="deleteSlaCalendar()" style="display:none;margin-right:auto;"><?php echo htmlspecialchars(t('common.delete')); ?></button>
                    <button type="button" class="btn btn-secondary" onclick="closeSlaCalendarModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/tickets/';
        const API_SETTINGS = '../../api/settings/';
        let currentTab = 'departments';

        let mailboxes = [];
        let whitelistEntries = [];
        let teams = [];
        let departmentTeams = {}; // Cache for department->teams mapping
        let analystTeams = {}; // Cache for analyst->teams mapping

        // Load data on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadTeams().then(() => {
                loadDepartments();
                loadAnalysts();
            });
            loadTicketTypes();
            loadTicketOrigins();
            loadTicketStatuses();
            loadTicketPriorities();
            loadRotaLocations();
            loadMailboxes();
            loadEmailTemplates();
            loadRotaShifts();
            loadRotaWeekendSetting();
            loadSlaTab();

            // Auto-switch to mailboxes tab if OAuth success
            <?php if ($oauthSuccess && $oauthMailboxId): ?>
            switchTab('mailboxes');
            <?php endif; ?>
        });

        // Switch tabs
        function switchTab(tab) {
            currentTab = tab;

            // Update tab buttons
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');

            // Update tab content
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        // Load departments
        async function loadDepartments() {
            try {
                const response = await fetch(API_BASE + 'get_departments.php');
                const data = await response.json();

                if (data.success) {
                    renderDepartments(data.departments);
                } else {
                    alert('Error loading departments: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Load ticket types
        async function loadTicketTypes() {
            try {
                const response = await fetch(API_BASE + 'get_ticket_types.php');
                const data = await response.json();

                if (data.success) {
                    renderTicketTypes(data.ticket_types);
                } else {
                    alert('Error loading ticket types: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Load ticket origins
        async function loadTicketOrigins() {
            try {
                const response = await fetch(API_BASE + 'get_ticket_origins.php');
                const data = await response.json();

                if (data.success) {
                    renderTicketOrigins(data.origins);
                } else {
                    alert('Error loading ticket origins: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Load ticket statuses
        let ticketStatusesCache = [];
        async function loadTicketStatuses() {
            try {
                const response = await fetch(API_BASE + 'get_ticket_statuses.php');
                const data = await response.json();
                if (data.success) {
                    ticketStatusesCache = data.statuses;
                    renderTicketStatuses(data.statuses);
                } else {
                    alert('Error loading statuses: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        function renderTicketStatuses(statuses) {
            const tbody = document.getElementById('statuses-list');
            if (!statuses || statuses.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;">No statuses found</td></tr>';
                return;
            }
            tbody.innerHTML = statuses.map(s => {
                const safeName = escapeHtml(s.name).replace(/'/g, "\\'");
                const swatch = s.colour
                    ? `<span style="display:inline-block; width:20px; height:20px; border-radius:4px; background:${escapeHtml(s.colour)}; vertical-align:middle; border:1px solid #ddd; margin-right:6px;"></span><code style="font-size:12px;">${escapeHtml(s.colour)}</code>`
                    : '<span style="color:#999;">—</span>';
                const closed  = s.is_closed  ? '<span class="status-badge status-active">Yes</span>' : '<span style="color:#999;">No</span>';
                const def     = s.is_default ? '<span class="status-badge status-active">Yes</span>' : '<span style="color:#999;">No</span>';
                const pauseCell = s.pauses_sla
                    ? '<span class="status-badge status-active" title="SLA clock pauses while a ticket is in this status">&#9208; Yes</span>'
                    : '<span style="color:#999;">No</span>';
                return `
                <tr>
                    <td><strong>${escapeHtml(s.name)}</strong></td>
                    <td>${swatch}</td>
                    <td>${closed}</td>
                    <td>${pauseCell}</td>
                    <td>${def}</td>
                    <td>${s.display_order}</td>
                    <td><span class="status-badge status-${s.is_active ? 'active' : 'inactive'}">${s.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('status', ${s.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('status', ${s.id}, '${safeName}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        // Load ticket priorities
        let ticketPrioritiesCache = [];
        async function loadTicketPriorities() {
            try {
                const response = await fetch(API_BASE + 'get_ticket_priorities.php');
                const data = await response.json();
                if (data.success) {
                    ticketPrioritiesCache = data.priorities;
                    renderTicketPriorities(data.priorities);
                } else {
                    alert('Error loading priorities: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        function renderTicketPriorities(priorities) {
            const tbody = document.getElementById('priorities-list');
            if (!priorities || priorities.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No priorities found</td></tr>';
                return;
            }
            tbody.innerHTML = priorities.map(p => {
                const safeName = escapeHtml(p.name).replace(/'/g, "\\'");
                const swatch = p.colour
                    ? `<span style="display:inline-block; width:20px; height:20px; border-radius:4px; background:${escapeHtml(p.colour)}; vertical-align:middle; border:1px solid #ddd; margin-right:6px;"></span><code style="font-size:12px;">${escapeHtml(p.colour)}</code>`
                    : '<span style="color:#999;">—</span>';
                const def = p.is_default ? '<span class="status-badge status-active">Yes</span>' : '<span style="color:#999;">No</span>';
                return `
                <tr>
                    <td><strong>${escapeHtml(p.name)}</strong></td>
                    <td>${swatch}</td>
                    <td>${def}</td>
                    <td>${p.display_order}</td>
                    <td><span class="status-badge status-${p.is_active ? 'active' : 'inactive'}">${p.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('priority', ${p.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('priority', ${p.id}, '${safeName}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        // Load rota locations
        let rotaLocationsCache = [];
        async function loadRotaLocations() {
            try {
                const response = await fetch(API_BASE + 'get_rota_locations.php');
                const data = await response.json();
                if (data.success) {
                    rotaLocationsCache = data.locations;
                    renderRotaLocations(data.locations);
                } else {
                    alert('Error loading rota locations: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        function renderRotaLocations(locations) {
            const tbody = document.getElementById('rota-locations-list');
            if (!locations || locations.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No rota locations found</td></tr>';
                return;
            }
            tbody.innerHTML = locations.map(l => {
                const safeName = escapeHtml(l.name).replace(/'/g, "\\'");
                const swatch = l.colour
                    ? `<span style="display:inline-block; width:20px; height:20px; border-radius:4px; background:${escapeHtml(l.colour)}; vertical-align:middle; border:1px solid #ddd; margin-right:6px;"></span><code style="font-size:12px;">${escapeHtml(l.colour)}</code>`
                    : '<span style="color:#999;">—</span>';
                const def = l.is_default ? '<span class="status-badge status-active">Yes</span>' : '<span style="color:#999;">No</span>';
                return `
                <tr>
                    <td><strong>${escapeHtml(l.name)}</strong></td>
                    <td>${swatch}</td>
                    <td>${def}</td>
                    <td>${l.display_order}</td>
                    <td><span class="status-badge status-${l.is_active ? 'active' : 'inactive'}">${l.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('rota-location', ${l.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('rota-location', ${l.id}, '${safeName}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        // Configure modal field visibility for the entity being edited
        function configureModalFields(type) {
            const isStatus       = type === 'status';
            const isPriority     = type === 'priority';
            const isRotaLocation = type === 'rota-location';
            const isColouredLookup = isStatus || isPriority || isRotaLocation;
            document.getElementById('itemDescriptionGroup').style.display = isColouredLookup ? 'none' : '';
            document.getElementById('itemColourGroup').style.display      = isColouredLookup ? '' : 'none';
            document.getElementById('itemClosedGroup').style.display      = isStatus ? '' : 'none';
            document.getElementById('itemPausesSlaGroup').style.display   = isStatus ? '' : 'none';
            document.getElementById('itemDefaultGroup').style.display     = isColouredLookup ? '' : 'none';
        }

        // Load teams
        async function loadTeams() {
            try {
                const response = await fetch(API_BASE + 'get_teams.php');
                const data = await response.json();

                if (data.success) {
                    teams = data.teams;
                    renderTeams(teams);
                    return teams;
                } else {
                    console.error('Error loading teams:', data.error);
                    document.getElementById('teams-list').innerHTML =
                        '<tr><td colspan="7" style="text-align: center; color: red;">Error: ' + data.error + '</td></tr>';
                    return [];
                }
            } catch (error) {
                console.error('Error loading teams:', error);
                document.getElementById('teams-list').innerHTML =
                    '<tr><td colspan="7" style="text-align: center; color: red;">Failed to load teams.</td></tr>';
                return [];
            }
        }

        // Render departments
        async function renderDepartments(departments) {
            const tbody = document.getElementById('departments-list');

            if (departments.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No departments found</td></tr>';
                return;
            }

            // Load team assignments for all departments
            for (const dept of departments) {
                if (!departmentTeams[dept.id]) {
                    try {
                        const response = await fetch(`${API_BASE}get_department_teams.php?department_id=${dept.id}`);
                        const data = await response.json();
                        departmentTeams[dept.id] = data.success ? data.teams : [];
                    } catch (e) {
                        departmentTeams[dept.id] = [];
                    }
                }
            }

            tbody.innerHTML = departments.map(dept => {
                const deptTeams = departmentTeams[dept.id] || [];
                const teamsText = deptTeams.length > 0
                    ? deptTeams.map(t => `<span class="status-badge" style="background: #e3f2fd; color: #1565c0; margin-right: 4px;">${escapeHtml(t.name)}</span>`).join('')
                    : '<span style="color: #999;">None</span>';

                return `
                <tr>
                    <td><strong>${escapeHtml(dept.name)}</strong></td>
                    <td>${escapeHtml(dept.description || '')}</td>
                    <td>${teamsText}</td>
                    <td>${dept.display_order}</td>
                    <td><span class="status-badge status-${dept.is_active ? 'active' : 'inactive'}">${dept.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('department', ${dept.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn" onclick="openTeamAssignment('department', ${dept.id}, '${escapeHtml(dept.name).replace(/'/g, "\\'")}')" title="${t('tickets.settings.tooltips.assign_teams')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('department', ${dept.id}, '${escapeHtml(dept.name)}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `}).join('');
        }

        // Render ticket types
        function renderTicketTypes(types) {
            const tbody = document.getElementById('ticket-types-list');

            if (types.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No ticket types found</td></tr>';
                return;
            }

            tbody.innerHTML = types.map(type => `
                <tr>
                    <td><strong>${escapeHtml(type.name)}</strong></td>
                    <td>${escapeHtml(type.description || '')}</td>
                    <td>${type.display_order}</td>
                    <td><span class="status-badge status-${type.is_active ? 'active' : 'inactive'}">${type.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('ticket-type', ${type.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('ticket-type', ${type.id}, '${escapeHtml(type.name)}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Render ticket origins
        function renderTicketOrigins(origins) {
            const tbody = document.getElementById('ticket-origins-list');

            if (origins.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">No ticket origins found</td></tr>';
                return;
            }

            tbody.innerHTML = origins.map(origin => `
                <tr>
                    <td><strong>${escapeHtml(origin.name)}</strong></td>
                    <td>${escapeHtml(origin.description || '')}</td>
                    <td>${origin.display_order}</td>
                    <td><span class="status-badge status-${origin.is_active ? 'active' : 'inactive'}">${origin.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('ticket-origin', ${origin.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('ticket-origin', ${origin.id}, '${escapeHtml(origin.name)}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        // Render teams
        async function renderTeams(teamsList) {
            const tbody = document.getElementById('teams-list');

            if (teamsList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No teams found. Click "Add" to create your first team.</td></tr>';
                return;
            }

            // For each team, we need to get department and analyst counts
            const teamsWithCounts = await Promise.all(teamsList.map(async (team) => {
                let deptCount = 0;
                let analystCount = 0;

                try {
                    // Get departments linked to this team
                    const deptResponse = await fetch(`${API_BASE}get_team_departments.php?team_id=${team.id}`);
                    const deptData = await deptResponse.json();
                    deptCount = deptData.success ? deptData.departments.length : 0;
                } catch (e) { }

                try {
                    // Get analysts linked to this team
                    const analystResponse = await fetch(`${API_BASE}get_team_analysts.php?team_id=${team.id}`);
                    const analystData = await analystResponse.json();
                    analystCount = analystData.success ? analystData.analysts.length : 0;
                } catch (e) { }

                return { ...team, deptCount, analystCount };
            }));

            tbody.innerHTML = teamsWithCounts.map(team => {
                const safeName = escapeHtml(team.name).replace(/'/g, "\\'");

                return `
                <tr>
                    <td><strong>${escapeHtml(team.name)}</strong></td>
                    <td>${escapeHtml(team.description || '')}</td>
                    <td>${team.deptCount} department(s)</td>
                    <td>${team.analystCount} analyst(s)</td>
                    <td>${team.display_order}</td>
                    <td><span class="status-badge status-${team.is_active ? 'active' : 'inactive'}">${team.is_active ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editItem('team', ${team.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteItem('team', ${team.id}, '${safeName}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `}).join('');
        }

        // Open add modal
        function openAddModal(type) {
            const titles = {
                'department':    t('tickets.settings.modals.lookup.add.department'),
                'ticket-type':   t('tickets.settings.modals.lookup.add.ticket_type'),
                'ticket-origin': t('tickets.settings.modals.lookup.add.ticket_origin'),
                'team':          t('tickets.settings.modals.lookup.add.team'),
                'status':        t('tickets.settings.modals.lookup.add.status'),
                'priority':      t('tickets.settings.modals.lookup.add.priority'),
                'rota-location': t('tickets.settings.modals.lookup.add.rota_location')
            };
            document.getElementById('modalTitle').textContent = titles[type] || t('tickets.settings.modals.lookup.add.fallback');
            document.getElementById('itemType').value = type;
            document.getElementById('itemId').value = '';
            document.getElementById('itemName').value = '';
            document.getElementById('itemDescription').value = '';
            document.getElementById('itemOrder').value = '0';
            document.getElementById('itemActive').checked = true;
            document.getElementById('itemColour').value = type === 'status' ? '#2563eb' : '#2563eb';
            document.getElementById('itemClosed').checked = false;
            document.getElementById('itemPausesSla').checked = false;
            document.getElementById('itemDefault').checked = false;
            configureModalFields(type);
            document.getElementById('editModal').classList.add('active');
        }

        // Edit item
        async function editItem(type, id) {
            const endpoints = {
                'department': API_BASE + 'get_departments.php',
                'ticket-type': API_BASE + 'get_ticket_types.php',
                'ticket-origin': API_BASE + 'get_ticket_origins.php',
                'team': API_BASE + 'get_teams.php',
                'status': API_BASE + 'get_ticket_statuses.php',
                'priority': API_BASE + 'get_ticket_priorities.php',
                'rota-location': API_BASE + 'get_rota_locations.php'
            };
            const titles = {
                'department':    t('tickets.settings.modals.lookup.edit.department'),
                'ticket-type':   t('tickets.settings.modals.lookup.edit.ticket_type'),
                'ticket-origin': t('tickets.settings.modals.lookup.edit.ticket_origin'),
                'team':          t('tickets.settings.modals.lookup.edit.team'),
                'status':        t('tickets.settings.modals.lookup.edit.status'),
                'priority':      t('tickets.settings.modals.lookup.edit.priority'),
                'rota-location': t('tickets.settings.modals.lookup.edit.rota_location')
            };
            const endpoint = endpoints[type];

            try {
                const response = await fetch(endpoint);
                const data = await response.json();

                if (data.success) {
                    let items;
                    if (type === 'department') items = data.departments;
                    else if (type === 'ticket-type') items = data.ticket_types;
                    else if (type === 'ticket-origin') items = data.origins;
                    else if (type === 'team') items = data.teams;
                    else if (type === 'status') items = data.statuses;
                    else if (type === 'priority') items = data.priorities;
                    else if (type === 'rota-location') items = data.locations;

                    const item = items.find(i => i.id == id);

                    if (item) {
                        document.getElementById('modalTitle').textContent = titles[type] || t('tickets.settings.modals.lookup.edit.fallback');
                        document.getElementById('itemType').value = type;
                        document.getElementById('itemId').value = item.id;
                        document.getElementById('itemName').value = item.name;
                        document.getElementById('itemDescription').value = item.description || '';
                        document.getElementById('itemOrder').value = item.display_order;
                        document.getElementById('itemActive').checked = item.is_active;
                        document.getElementById('itemColour').value = item.colour || '#2563eb';
                        document.getElementById('itemClosed').checked = !!item.is_closed;
                        document.getElementById('itemPausesSla').checked = !!item.pauses_sla;
                        document.getElementById('itemDefault').checked = !!item.is_default;
                        configureModalFields(type);
                        document.getElementById('editModal').classList.add('active');
                    }
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Delete item
        async function deleteItem(type, id, name) {
            if (!confirm(`Are you sure you want to delete "${name}"?`)) {
                return;
            }

            const endpoints = {
                'department': API_BASE + 'delete_department.php',
                'ticket-type': API_BASE + 'delete_ticket_type.php',
                'ticket-origin': API_BASE + 'delete_ticket_origin.php',
                'team': API_BASE + 'delete_team.php',
                'status': API_BASE + 'delete_ticket_status.php',
                'priority': API_BASE + 'delete_ticket_priority.php',
                'rota-location': API_BASE + 'delete_rota_location.php'
            };
            const endpoint = endpoints[type];

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();

                if (data.success) {
                    if (type === 'department') {
                        loadDepartments();
                    } else if (type === 'ticket-type') {
                        loadTicketTypes();
                    } else if (type === 'ticket-origin') {
                        loadTicketOrigins();
                    } else if (type === 'team') {
                        loadTeams().then(() => {
                            loadDepartments();
                            loadAnalysts();
                        });
                    } else if (type === 'status') {
                        loadTicketStatuses();
                    } else if (type === 'priority') {
                        loadTicketPriorities();
                    } else if (type === 'rota-location') {
                        loadRotaLocations();
                    }
                } else {
                    alert('Error deleting item: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to delete item');
            }
        }

        // Close modal
        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        // Open team assignment modal
        async function openTeamAssignment(entityType, entityId, entityName) {
            document.getElementById('assignmentEntityType').value = entityType;
            document.getElementById('assignmentEntityId').value = entityId;

            if (entityType === 'department') {
                document.getElementById('teamAssignmentTitle').textContent = `Assign Teams to "${entityName}"`;
                document.getElementById('teamAssignmentDesc').textContent = 'Select which teams should have access to this department:';
            } else if (entityType === 'analyst') {
                document.getElementById('teamAssignmentTitle').textContent = `Assign Teams to "${entityName}"`;
                document.getElementById('teamAssignmentDesc').textContent = 'Select which teams this analyst belongs to:';
            }

            const listContainer = document.getElementById('teamAssignmentList');
            listContainer.innerHTML = '<div style="padding: 15px; text-align: center; color: #999;">Loading teams...</div>';

            // Get current assignments
            let currentTeamIds = [];
            try {
                const endpoint = entityType === 'department'
                    ? `${API_BASE}get_department_teams.php?department_id=${entityId}`
                    : `${API_BASE}get_analyst_teams.php?analyst_id=${entityId}`;
                const response = await fetch(endpoint);
                const data = await response.json();
                if (data.success) {
                    currentTeamIds = data.teams.map(t => t.id);
                }
            } catch (e) {
                console.error('Error loading current assignments:', e);
            }

            // Render checkboxes for all active teams
            const activeTeams = teams.filter(t => t.is_active);
            if (activeTeams.length === 0) {
                listContainer.innerHTML = '<div style="padding: 15px; text-align: center; color: #999;">No active teams available. Create teams first.</div>';
            } else {
                listContainer.innerHTML = activeTeams.map(team => `
                    <label style="display: flex; align-items: center; padding: 12px 15px; border-bottom: 1px solid #eee; cursor: pointer; transition: background 0.2s;"
                           onmouseover="this.style.background='#f5f5f5'" onmouseout="this.style.background='white'">
                        <input type="checkbox" name="team_ids" value="${team.id}" ${currentTeamIds.includes(team.id) ? 'checked' : ''}
                               style="margin-right: 12px; width: 18px; height: 18px;">
                        <div>
                            <strong>${escapeHtml(team.name)}</strong>
                            ${team.description ? `<div style="font-size: 12px; color: #666; margin-top: 2px;">${escapeHtml(team.description)}</div>` : ''}
                        </div>
                    </label>
                `).join('');
            }

            document.getElementById('teamAssignmentModal').classList.add('active');
        }

        // Close team assignment modal
        function closeTeamAssignmentModal() {
            document.getElementById('teamAssignmentModal').classList.remove('active');
        }

        // Team assignment form submission
        document.getElementById('teamAssignmentForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const entityType = document.getElementById('assignmentEntityType').value;
            const entityId = document.getElementById('assignmentEntityId').value;

            // Get selected team IDs
            const checkboxes = document.querySelectorAll('#teamAssignmentList input[name="team_ids"]:checked');
            const teamIds = Array.from(checkboxes).map(cb => parseInt(cb.value));

            const endpoint = entityType === 'department'
                ? API_BASE + 'save_department_teams.php'
                : API_BASE + 'save_analyst_teams.php';

            const payload = entityType === 'department'
                ? { department_id: entityId, team_ids: teamIds }
                : { analyst_id: entityId, team_ids: teamIds };

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();

                if (data.success) {
                    closeTeamAssignmentModal();
                    // Clear cache and reload
                    if (entityType === 'department') {
                        delete departmentTeams[entityId];
                        loadDepartments();
                    } else {
                        delete analystTeams[entityId];
                        loadAnalysts();
                    }
                    // Also reload teams to update counts
                    loadTeams();
                } else {
                    alert('Error saving team assignments: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to save team assignments');
            }
        });

        // Handle form submission
        document.getElementById('editForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const type = document.getElementById('itemType').value;
            const id = document.getElementById('itemId').value;
            const endpoints = {
                'department': API_BASE + 'save_department.php',
                'ticket-type': API_BASE + 'save_ticket_type.php',
                'ticket-origin': API_BASE + 'save_ticket_origin.php',
                'team': API_BASE + 'save_team.php',
                'status': API_BASE + 'save_ticket_status.php',
                'priority': API_BASE + 'save_ticket_priority.php',
                'rota-location': API_BASE + 'save_rota_location.php'
            };
            const endpoint = endpoints[type];

            let formData;
            if (type === 'status') {
                formData = {
                    id: id || null,
                    name: document.getElementById('itemName').value,
                    colour: document.getElementById('itemColour').value,
                    is_closed: document.getElementById('itemClosed').checked ? 1 : 0,
                    pauses_sla: document.getElementById('itemPausesSla').checked ? 1 : 0,
                    is_default: document.getElementById('itemDefault').checked ? 1 : 0,
                    display_order: parseInt(document.getElementById('itemOrder').value),
                    is_active: document.getElementById('itemActive').checked ? 1 : 0
                };
            } else if (type === 'priority' || type === 'rota-location') {
                formData = {
                    id: id || null,
                    name: document.getElementById('itemName').value,
                    colour: document.getElementById('itemColour').value,
                    is_default: document.getElementById('itemDefault').checked ? 1 : 0,
                    display_order: parseInt(document.getElementById('itemOrder').value),
                    is_active: document.getElementById('itemActive').checked ? 1 : 0
                };
            } else {
                formData = {
                    id: id || null,
                    name: document.getElementById('itemName').value,
                    description: document.getElementById('itemDescription').value,
                    display_order: parseInt(document.getElementById('itemOrder').value),
                    is_active: document.getElementById('itemActive').checked ? 1 : 0
                };
            }

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    closeModal();
                    if (type === 'department') {
                        loadDepartments();
                    } else if (type === 'ticket-type') {
                        loadTicketTypes();
                    } else if (type === 'ticket-origin') {
                        loadTicketOrigins();
                    } else if (type === 'team') {
                        loadTeams().then(() => {
                            loadDepartments();
                            loadAnalysts();
                        });
                    } else if (type === 'status') {
                        loadTicketStatuses();
                    } else if (type === 'priority') {
                        loadTicketPriorities();
                    } else if (type === 'rota-location') {
                        loadRotaLocations();
                    }
                } else {
                    alert('Error saving: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to save');
            }
        });

        // Utility function
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Mailbox Functions
        async function loadMailboxes() {
            try {
                const response = await fetch(API_BASE + 'get_mailboxes.php');
                const data = await response.json();
                console.log('Mailboxes loaded:', data);

                if (data.success) {
                    mailboxes = data.mailboxes;
                    console.log('Mailboxes array:', mailboxes);
                    renderMailboxes(mailboxes);
                } else {
                    console.error('Error loading mailboxes:', data.error);
                    document.getElementById('mailboxes-list').innerHTML =
                        '<tr><td colspan="5" style="text-align: center; color: red;">Error: ' + data.error + '</td></tr>';
                }
            } catch (error) {
                console.error('Error loading mailboxes:', error);
                document.getElementById('mailboxes-list').innerHTML =
                    '<tr><td colspan="5" style="text-align: center; color: red;">Failed to load mailboxes. Check console for details.</td></tr>';
            }
        }

        function renderMailboxes(mailboxes) {
            const tbody = document.getElementById('mailboxes-list');

            if (mailboxes.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align: center;">${escapeHtml(t('tickets.settings.modals.mailbox.empty_state'))}</td></tr>`;
                return;
            }

            tbody.innerHTML = mailboxes.map(mb => {
                const statusBadge = mb.is_authenticated
                    ? '<span class="status-badge status-active">Authenticated</span>'
                    : '<span class="status-badge status-inactive">Not Authenticated</span>';

                const activeBadge = mb.is_active
                    ? ''
                    : ' <span class="status-badge status-inactive">Inactive</span>';

                const lastChecked = mb.last_checked_datetime
                    ? new Date(mb.last_checked_datetime).toLocaleString()
                    : 'Never';

                let actions = `<button class="action-btn" onclick="editMailbox(${mb.id})" title="${t('common.edit')}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                </button>`;

                actions += `<button class="action-btn" onclick="openActivityModal(${mb.id})" title="${t('tickets.settings.tooltips.activity')}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </button>`;

                if (mb.is_authenticated) {
                    actions += `<button class="action-btn" onclick="checkMailboxEmails(${mb.id})" title="${t('tickets.settings.tooltips.check_emails')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
                    </button>`;
                    actions += `<button class="action-btn" onclick="logoutMailbox(${mb.id})" title="${t('tickets.settings.tooltips.logout')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    </button>`;
                } else {
                    actions += `<button class="action-btn" onclick="authenticateMailbox(${mb.id})" title="${t('tickets.settings.tooltips.authenticate')}">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                    </button>`;
                }

                const safeName = escapeHtml(mb.name).replace(/'/g, "\\'");
                actions += `<button class="action-btn delete" onclick="deleteMailbox(${mb.id}, '${safeName}')" title="${t('common.delete')}">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>`;

                const providerBadge = (mb.provider === 'google')
                    ? ' <span class="status-badge" style="background:#e8f5e9;color:#2e7d32;">Google</span>'
                    : ' <span class="status-badge" style="background:#e3f2fd;color:#1565c0;">Microsoft</span>';

                return `
                    <tr>
                        <td><strong>${escapeHtml(mb.name)}</strong>${providerBadge}${activeBadge}</td>
                        <td>${escapeHtml(mb.target_mailbox)}</td>
                        <td>${statusBadge}</td>
                        <td>${lastChecked}</td>
                        <td>${actions}</td>
                    </tr>
                `;
            }).join('');
        }

        async function openMailboxModal(mailbox = null) {
            document.getElementById('mailboxModalTitle').textContent = mailbox ? t('tickets.settings.modals.mailbox.edit_title') : t('tickets.settings.modals.mailbox.add_title');
            document.getElementById('mailboxId').value = mailbox ? mailbox.id : '';
            document.getElementById('mailboxProvider').value = mailbox ? (mailbox.provider || 'microsoft') : 'microsoft';
            document.getElementById('mailboxName').value = mailbox ? mailbox.name : '';
            document.getElementById('mailboxEmail').value = mailbox ? mailbox.target_mailbox : '';
            document.getElementById('mailboxTenantId').value = mailbox ? mailbox.azure_tenant_id : '';
            document.getElementById('mailboxClientId').value = mailbox ? mailbox.azure_client_id : '';
            document.getElementById('mailboxClientSecret').value = '';
            document.getElementById('mailboxRedirectUri').value = mailbox ? mailbox.oauth_redirect_uri : 'https://your-server.com/oauth_callback.php';
            document.getElementById('mailboxScopes').value = mailbox ? mailbox.oauth_scopes : 'openid email offline_access Mail.Read Mail.ReadWrite Mail.Send';
            document.getElementById('mailboxImapServer').value = mailbox ? mailbox.imap_server : 'outlook.office365.com';
            document.getElementById('mailboxImapPort').value = mailbox ? mailbox.imap_port : 993;
            toggleProviderFields();
            document.getElementById('mailboxFolder').value = mailbox ? mailbox.email_folder : 'INBOX';
            document.getElementById('mailboxMaxEmails').value = mailbox ? mailbox.max_emails_per_check : 10;
            document.getElementById('mailboxRejectedAction').value = mailbox ? (mailbox.rejected_action || 'delete') : 'delete';
            document.getElementById('mailboxImportedAction').value = mailbox ? (mailbox.imported_action || 'delete') : 'delete';
            document.getElementById('mailboxImportedFolder').value = mailbox ? (mailbox.imported_folder || '') : '';
            toggleImportedFolder();
            document.getElementById('verifyFolderResult').style.display = 'none';
            document.getElementById('mailboxActive').checked = mailbox ? mailbox.is_active : true;

            // Load whitelist
            whitelistEntries = [];
            if (mailbox && mailbox.id) {
                try {
                    const res = await fetch(API_BASE + 'get_mailbox_whitelist.php?mailbox_id=' + mailbox.id);
                    const data = await res.json();
                    if (data.success) {
                        whitelistEntries = data.entries.map(e => ({ entry_type: e.entry_type, entry_value: e.entry_value }));
                    }
                } catch (err) {
                    console.error('Failed to load whitelist:', err);
                }
            }
            renderWhitelistEntries();

            document.getElementById('mailboxModal').classList.add('active');
        }

        function closeMailboxModal() {
            document.getElementById('mailboxModal').classList.remove('active');
        }

        function toggleProviderFields() {
            const provider = document.getElementById('mailboxProvider').value;
            const isMicrosoft = provider === 'microsoft';

            // Show/hide Microsoft-only fields
            document.querySelectorAll('.provider-microsoft').forEach(el => {
                el.style.display = isMicrosoft ? '' : 'none';
            });

            // Update labels
            document.getElementById('clientIdLabel').textContent = isMicrosoft ? 'Azure Client ID *' : 'Google Client ID *';
            document.getElementById('clientSecretLabel').textContent = isMicrosoft ? 'Azure Client Secret *' : 'Google Client Secret *';

            // Update placeholders
            const clientIdInput = document.getElementById('mailboxClientId');
            clientIdInput.placeholder = isMicrosoft
                ? 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx'
                : 'xxxxxxxxxx-xxxxxxxxx.apps.googleusercontent.com';

            const redirectInput = document.getElementById('mailboxRedirectUri');
            if (!document.getElementById('mailboxId').value) {
                // Only auto-fill for new mailboxes
                redirectInput.placeholder = isMicrosoft
                    ? 'https://yoursite.com/oauth_callback.php'
                    : 'https://yoursite.com/google_oauth_callback.php';
            }
        }

        function toggleImportedFolder() {
            const action = document.getElementById('mailboxImportedAction').value;
            document.getElementById('importedFolderGroup').style.display = action === 'move_to_folder' ? '' : 'none';
        }

        async function verifyFolder() {
            const folderName = document.getElementById('mailboxImportedFolder').value.trim();
            const mailboxId = document.getElementById('mailboxId').value;
            const resultEl = document.getElementById('verifyFolderResult');
            const btn = document.getElementById('verifyFolderBtn');

            if (!folderName) {
                resultEl.style.display = '';
                resultEl.style.color = '#856404';
                resultEl.textContent = 'Enter a folder name first.';
                return;
            }
            if (!mailboxId) {
                resultEl.style.display = '';
                resultEl.style.color = '#856404';
                resultEl.textContent = 'Save the mailbox first, then verify.';
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Verifying...';
            resultEl.style.display = 'none';

            try {
                const res = await fetch(API_BASE + 'verify_mailbox_folder.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mailbox_id: parseInt(mailboxId), folder_name: folderName })
                });
                const data = await res.json();

                resultEl.style.display = '';
                if (data.success) {
                    resultEl.style.color = '#155724';
                    let msg = 'Folder "' + escapeHtml(data.folder.displayName) + '" found';
                    if (data.folder.totalItemCount !== null) {
                        msg += ' (' + data.folder.totalItemCount + ' items, ' + data.folder.unreadItemCount + ' unread)';
                    }
                    resultEl.textContent = msg;
                } else {
                    resultEl.style.color = '#721c24';
                    resultEl.textContent = data.error || 'Folder not found';
                }
            } catch (err) {
                resultEl.style.display = '';
                resultEl.style.color = '#721c24';
                resultEl.textContent = 'Failed to verify folder';
            } finally {
                btn.disabled = false;
                btn.textContent = 'Verify';
            }
        }

        async function editMailbox(id) {
            const mailbox = mailboxes.find(m => m.id == id);
            if (mailbox) {
                openMailboxModal(mailbox);
            } else {
                alert('Mailbox not found. ID: ' + id);
            }
        }

        async function deleteMailbox(id, name) {
            if (!confirm(`Are you sure you want to delete the mailbox "${name}"?`)) {
                return;
            }

            try {
                const response = await fetch(API_BASE + 'delete_mailbox.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();

                if (data.success) {
                    loadMailboxes();
                } else {
                    alert('Error deleting mailbox: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to delete mailbox');
            }
        }

        function authenticateMailbox(id) {
            const mailbox = mailboxes.find(m => m.id == id);
            if (!mailbox) {
                alert('Mailbox not found. ID: ' + id);
                return;
            }

            const provider = mailbox.provider || 'microsoft';

            if (provider === 'google') {
                // Google OAuth flow
                const state = 'google_mailbox_' + id + '_' + Math.random().toString(36).substring(2, 18);
                const params = new URLSearchParams({
                    client_id: mailbox.azure_client_id,
                    redirect_uri: mailbox.oauth_redirect_uri,
                    response_type: 'code',
                    scope: 'https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/gmail.send',
                    access_type: 'offline',
                    prompt: 'consent',
                    state: state
                });
                window.location.href = 'https://accounts.google.com/o/oauth2/v2/auth?' + params.toString();
            } else {
                // Microsoft OAuth flow
                const state = 'mailbox_' + id + '_' + Math.random().toString(36).substring(2, 18);
                const params = new URLSearchParams({
                    client_id: mailbox.azure_client_id,
                    response_type: 'code',
                    redirect_uri: mailbox.oauth_redirect_uri,
                    response_mode: 'query',
                    scope: mailbox.oauth_scopes,
                    state: state
                });
                window.location.href = 'https://login.microsoftonline.com/' + mailbox.azure_tenant_id + '/oauth2/v2.0/authorize?' + params.toString();
            }
        }

        async function logoutMailbox(id) {
            if (!confirm('This will remove authentication for this mailbox. You will need to re-authenticate. Continue?')) {
                return;
            }

            try {
                const response = await fetch(API_BASE + 'mailbox_logout.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mailbox_id: id })
                });
                const data = await response.json();

                if (data.success) {
                    loadMailboxes();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to logout mailbox');
            }
        }

        async function checkMailboxEmails(id) {
            const result = document.getElementById('mailboxesResult');
            const mailbox = mailboxes.find(m => m.id == id);

            result.className = 'exchange-result info';
            result.innerHTML = `<span class="spinner"></span> Checking emails for ${escapeHtml(mailbox?.name || 'mailbox')}...`;

            try {
                const response = await fetch(API_BASE + 'check_mailbox_email.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mailbox_id: id })
                });
                const data = await response.json();

                if (data.success) {
                    result.className = 'exchange-result success';
                    result.innerHTML = `
                        <strong>&#10003; Success!</strong>
                        <p>${data.message}</p>
                        ${data.details ? '<pre>' + JSON.stringify(data.details, null, 2) + '</pre>' : ''}
                    `;
                    loadMailboxes(); // Refresh to update last checked time
                } else {
                    result.className = 'exchange-result error';
                    result.innerHTML = `
                        <strong>&#10007; Error</strong>
                        <p>${data.error || data.message}</p>
                    `;
                }
            } catch (error) {
                result.className = 'exchange-result error';
                result.innerHTML = `
                    <strong>&#10007; Connection Error</strong>
                    <p>Failed to connect to the server: ${error.message}</p>
                `;
            }
        }

        async function checkAllMailboxes() {
            const result = document.getElementById('mailboxesResult');
            const authenticatedMailboxes = mailboxes.filter(m => m.is_authenticated && m.is_active);

            if (authenticatedMailboxes.length === 0) {
                result.className = 'exchange-result error';
                result.innerHTML = 'No authenticated and active mailboxes to check.';
                return;
            }

            result.className = 'exchange-result info';
            result.innerHTML = `<span class="spinner"></span> Checking ${authenticatedMailboxes.length} mailbox(es)...`;

            let successCount = 0;
            let errorCount = 0;
            let totalEmails = 0;
            const results = [];

            for (const mb of authenticatedMailboxes) {
                try {
                    const response = await fetch(API_BASE + 'check_mailbox_email.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ mailbox_id: mb.id })
                    });
                    const data = await response.json();

                    if (data.success) {
                        successCount++;
                        totalEmails += data.details?.emails_saved || 0;
                        results.push(`&#10003; ${mb.name}: ${data.details?.emails_saved || 0} email(s)`);
                    } else {
                        errorCount++;
                        results.push(`&#10007; ${mb.name}: ${data.error || 'Unknown error'}`);
                    }
                } catch (error) {
                    errorCount++;
                    results.push(`&#10007; ${mb.name}: Connection error`);
                }
            }

            if (errorCount === 0) {
                result.className = 'exchange-result success';
            } else if (successCount === 0) {
                result.className = 'exchange-result error';
            } else {
                result.className = 'exchange-result info';
            }

            result.innerHTML = `
                <strong>Check Complete</strong>
                <p>${successCount} mailbox(es) checked successfully, ${totalEmails} total email(s) processed</p>
                <ul style="margin-top: 10px; padding-left: 20px;">
                    ${results.map(r => '<li>' + r + '</li>').join('')}
                </ul>
            `;

            loadMailboxes(); // Refresh to update last checked times
        }

        // Mailbox form submission
        document.getElementById('mailboxForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = {
                id: document.getElementById('mailboxId').value || null,
                provider: document.getElementById('mailboxProvider').value,
                name: document.getElementById('mailboxName').value,
                target_mailbox: document.getElementById('mailboxEmail').value,
                azure_tenant_id: document.getElementById('mailboxTenantId').value,
                azure_client_id: document.getElementById('mailboxClientId').value,
                azure_client_secret: document.getElementById('mailboxClientSecret').value,
                oauth_redirect_uri: document.getElementById('mailboxRedirectUri').value,
                oauth_scopes: document.getElementById('mailboxScopes').value,
                imap_server: document.getElementById('mailboxImapServer').value,
                imap_port: parseInt(document.getElementById('mailboxImapPort').value),
                email_folder: document.getElementById('mailboxFolder').value,
                max_emails_per_check: parseInt(document.getElementById('mailboxMaxEmails').value),
                rejected_action: document.getElementById('mailboxRejectedAction').value,
                imported_action: document.getElementById('mailboxImportedAction').value,
                imported_folder: document.getElementById('mailboxImportedFolder').value || null,
                is_active: document.getElementById('mailboxActive').checked
            };

            try {
                const response = await fetch(API_BASE + 'save_mailbox.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    // Save whitelist entries
                    const mailboxId = data.id || formData.id;
                    if (mailboxId) {
                        try {
                            await fetch(API_BASE + 'save_mailbox_whitelist.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ mailbox_id: mailboxId, entries: whitelistEntries })
                            });
                        } catch (wErr) {
                            console.error('Failed to save whitelist:', wErr);
                        }
                    }

                    closeMailboxModal();
                    loadMailboxes();
                } else {
                    alert('Error saving mailbox: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to save mailbox');
            }
        });

        // Whitelist Functions
        function addWhitelistEntry() {
            const type = document.getElementById('whitelistType').value;
            const value = document.getElementById('whitelistValue').value.trim().toLowerCase();

            if (!value) return;

            // Validate
            if (type === 'email' && !value.includes('@')) {
                showToast('Please enter a valid email address', 'warning');
                return;
            }
            if (type === 'domain' && value.includes('@')) {
                showToast('Enter a domain without @, e.g. company.com', 'warning');
                return;
            }

            // Check for duplicates
            if (whitelistEntries.some(e => e.entry_type === type && e.entry_value === value)) {
                showToast('Entry already exists', 'warning');
                return;
            }

            whitelistEntries.push({ entry_type: type, entry_value: value });
            renderWhitelistEntries();
            document.getElementById('whitelistValue').value = '';
        }

        function removeWhitelistEntry(index) {
            whitelistEntries.splice(index, 1);
            renderWhitelistEntries();
        }

        function renderWhitelistEntries() {
            const container = document.getElementById('whitelistEntries');
            if (whitelistEntries.length === 0) {
                container.innerHTML = '<span style="color: #999; font-size: 12px;">No whitelist entries — all senders allowed</span>';
                return;
            }

            container.innerHTML = whitelistEntries.map((e, i) => {
                const color = e.entry_type === 'domain' ? '#0078d4' : '#6c757d';
                return `<span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; background: ${color}15; border: 1px solid ${color}40; border-radius: 20px; font-size: 12px; color: ${color};">
                    <strong>${e.entry_type === 'domain' ? '@' : ''}${escapeHtml(e.entry_value)}</strong>
                    <button type="button" onclick="removeWhitelistEntry(${i})" style="background: none; border: none; cursor: pointer; color: ${color}; font-size: 14px; padding: 0 2px; line-height: 1;">&times;</button>
                </span>`;
            }).join('');
        }

        // Activity Log Functions
        let activityMailboxId = null;
        let activitySearchTimer = null;

        function openActivityModal(mailboxId) {
            activityMailboxId = mailboxId;
            const mb = mailboxes.find(m => m.id == mailboxId);
            document.getElementById('activityModalTitle').textContent = 'Activity — ' + (mb ? mb.name : 'Mailbox');
            document.getElementById('activitySearch').value = '';
            closeProcessingLog();
            loadActivity(mailboxId, '', 1);
            document.getElementById('activityModal').classList.add('active');
        }

        function closeActivityModal() {
            document.getElementById('activityModal').classList.remove('active');
        }

        function showProcessingLog(logJson) {
            const panel = document.getElementById('processingLogPanel');
            const content = document.getElementById('processingLogContent');
            if (!logJson) {
                content.textContent = 'No processing log available for this entry.';
            } else {
                try {
                    const parsed = typeof logJson === 'string' ? JSON.parse(logJson) : logJson;
                    content.textContent = JSON.stringify(parsed, null, 2);
                } catch (e) {
                    content.textContent = logJson;
                }
            }
            panel.style.display = '';
        }

        function closeProcessingLog() {
            document.getElementById('processingLogPanel').style.display = 'none';
        }

        function debounceActivitySearch() {
            clearTimeout(activitySearchTimer);
            activitySearchTimer = setTimeout(() => {
                const search = document.getElementById('activitySearch').value;
                loadActivity(activityMailboxId, search, 1);
            }, 300);
        }

        async function loadActivity(mailboxId, search, page) {
            const tbody = document.getElementById('activityList');
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center;">Loading...</td></tr>';

            try {
                let url = API_BASE + 'get_mailbox_activity.php?mailbox_id=' + mailboxId + '&page=' + page;
                if (search) url += '&search=' + encodeURIComponent(search);

                const res = await fetch(url);
                const data = await res.json();

                if (!data.success) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: red;">' + escapeHtml(data.error) + '</td></tr>';
                    return;
                }

                if (data.entries.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: #999;">No activity found</td></tr>';
                    document.getElementById('activityPagination').innerHTML = '';
                    return;
                }

                // Store logs for click handler
                window._activityLogs = data.entries.map(e => e.processing_log || null);

                tbody.innerHTML = data.entries.map((e, idx) => {
                    const dt = new Date(e.created_datetime + 'Z').toLocaleString();
                    const badge = e.action === 'imported'
                        ? '<span style="display: inline-block; padding: 2px 8px; background: #d4edda; color: #155724; border-radius: 10px; font-size: 11px;">Imported</span>'
                        : '<span style="display: inline-block; padding: 2px 8px; background: #f8d7da; color: #721c24; border-radius: 10px; font-size: 11px;">Rejected</span>';
                    const from = escapeHtml(e.from_name ? e.from_name + ' <' + e.from_address + '>' : e.from_address);
                    return `<tr style="cursor: pointer;" onclick="showProcessingLog(window._activityLogs[${idx}])">
                        <td style="white-space: nowrap;">${dt}</td>
                        <td>${from}</td>
                        <td>${escapeHtml(e.subject || '')}</td>
                        <td>${badge}</td>
                        <td>${escapeHtml(e.reason || '')}</td>
                    </tr>`;
                }).join('');

                // Pagination
                const totalPages = Math.ceil(data.total / data.per_page);
                const currentSearch = document.getElementById('activitySearch').value;
                let paginationHtml = `<span>Showing ${data.entries.length} of ${data.total} entries</span>`;

                if (totalPages > 1) {
                    paginationHtml += '<div>';
                    if (page > 1) {
                        paginationHtml += `<button class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;" onclick="loadActivity(${mailboxId}, '${currentSearch.replace(/'/g, "\\'")}', ${page - 1})">${t('common.calendar.previous')}</button>`;
                    }
                    paginationHtml += `<span style="margin: 0 8px;">Page ${page} of ${totalPages}</span>`;
                    if (page < totalPages) {
                        paginationHtml += `<button class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-left: 4px;" onclick="loadActivity(${mailboxId}, '${currentSearch.replace(/'/g, "\\'")}', ${page + 1})">${t('common.calendar.next')}</button>`;
                    }
                    paginationHtml += '</div>';
                }

                document.getElementById('activityPagination').innerHTML = paginationHtml;

            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: red;">Failed to load activity</td></tr>';
            }
        }

        // Analyst Functions
        let analysts = [];

        async function loadAnalysts() {
            try {
                const response = await fetch(API_BASE + 'get_analysts.php');
                const data = await response.json();

                if (data.success) {
                    analysts = data.analysts;
                    renderAnalysts(analysts);
                } else {
                    console.error('Error loading analysts:', data.error);
                    document.getElementById('analysts-list').innerHTML =
                        '<tr><td colspan="6" style="text-align: center; color: red;">Error: ' + data.error + '</td></tr>';
                }
            } catch (error) {
                console.error('Error loading analysts:', error);
                document.getElementById('analysts-list').innerHTML =
                    '<tr><td colspan="6" style="text-align: center; color: red;">Failed to load analysts.</td></tr>';
            }
        }

        async function renderAnalysts(analystsList) {
            const tbody = document.getElementById('analysts-list');

            if (analystsList.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center;">No analysts found.</td></tr>';
                return;
            }

            // Load team assignments for all analysts
            for (const analyst of analystsList) {
                if (!analystTeams[analyst.id]) {
                    try {
                        const response = await fetch(`${API_BASE}get_analyst_teams.php?analyst_id=${analyst.id}`);
                        const data = await response.json();
                        analystTeams[analyst.id] = data.success ? data.teams : [];
                    } catch (e) {
                        analystTeams[analyst.id] = [];
                    }
                }
            }

            tbody.innerHTML = analystsList.map(a => {
                const statusBadge = a.is_active
                    ? '<span class="status-badge status-active">Active</span>'
                    : '<span class="status-badge status-inactive">Inactive</span>';

                const lastLogin = a.last_login_datetime
                    ? new Date(a.last_login_datetime).toLocaleString()
                    : 'Never';

                const aTeams = analystTeams[a.id] || [];
                const teamsText = aTeams.length > 0
                    ? aTeams.map(t => `<span class="status-badge" style="background: #e8f5e9; color: #2e7d32; margin-right: 4px;">${escapeHtml(t.name)}</span>`).join('')
                    : '<span style="color: #999;">None</span>';

                const safeName = escapeHtml(a.full_name).replace(/'/g, "\\'");
                const safeUsername = escapeHtml(a.username).replace(/'/g, "\\'");

                return `
                    <tr>
                        <td><strong>${escapeHtml(a.username)}</strong></td>
                        <td>${escapeHtml(a.full_name)}</td>
                        <td>${escapeHtml(a.email || '')}</td>
                        <td>${teamsText}</td>
                        <td>${statusBadge}</td>
                        <td>${lastLogin}</td>
                        <td>
                            <button class="action-btn" onclick="editAnalyst(${a.id})" title="${t('common.edit')}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </button>
                            <button class="action-btn" onclick="openTeamAssignment('analyst', ${a.id}, '${safeName}')" title="${t('tickets.settings.tooltips.assign_teams')}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </button>
                            <button class="action-btn" onclick="openPasswordResetModal(${a.id}, '${safeName}')" title="${t('tickets.settings.tooltips.reset_password')}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"></path></svg>
                            </button>
                            <button class="action-btn delete" onclick="deleteAnalyst(${a.id}, '${safeUsername}')" title="${t('common.delete')}">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function openAnalystModal(analyst = null) {
            document.getElementById('analystModalTitle').textContent = analyst ? t('tickets.settings.modals.analyst.edit_title') : t('tickets.settings.modals.analyst.add_title');
            document.getElementById('analystId').value = analyst ? analyst.id : '';
            document.getElementById('analystUsername').value = analyst ? analyst.username : '';
            document.getElementById('analystFullName').value = analyst ? analyst.full_name : '';
            document.getElementById('analystEmail').value = analyst ? (analyst.email || '') : '';
            document.getElementById('analystPassword').value = '';
            document.getElementById('analystActive').checked = analyst ? analyst.is_active : true;

            // Password is required only for new analysts
            const passwordInput = document.getElementById('analystPassword');
            const passwordGroup = document.getElementById('analystPasswordGroup');
            if (analyst) {
                passwordInput.removeAttribute('required');
                passwordGroup.querySelector('small').textContent = 'Leave blank to keep existing password.';
            } else {
                passwordInput.setAttribute('required', 'required');
                passwordGroup.querySelector('small').textContent = 'Required for new analysts.';
            }

            document.getElementById('analystModal').classList.add('active');
        }

        function closeAnalystModal() {
            document.getElementById('analystModal').classList.remove('active');
        }

        function editAnalyst(id) {
            const analyst = analysts.find(a => a.id == id);
            if (analyst) {
                openAnalystModal(analyst);
            } else {
                alert('Analyst not found.');
            }
        }

        async function deleteAnalyst(id, username) {
            if (!confirm(`Are you sure you want to delete the analyst "${username}"?`)) {
                return;
            }

            try {
                const response = await fetch(API_BASE + 'delete_analyst.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();

                if (data.success) {
                    loadAnalysts();
                } else {
                    alert('Error deleting analyst: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to delete analyst');
            }
        }

        function openPasswordResetModal(id, name) {
            document.getElementById('resetAnalystId').value = id;
            document.getElementById('resetAnalystName').textContent = name;
            document.getElementById('newPassword').value = '';
            document.getElementById('confirmPassword').value = '';
            document.getElementById('passwordResetModal').classList.add('active');
        }

        function closePasswordResetModal() {
            document.getElementById('passwordResetModal').classList.remove('active');
        }

        // Analyst form submission
        document.getElementById('analystForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = {
                id: document.getElementById('analystId').value || null,
                username: document.getElementById('analystUsername').value,
                full_name: document.getElementById('analystFullName').value,
                email: document.getElementById('analystEmail').value || null,
                password: document.getElementById('analystPassword').value || null,
                is_active: document.getElementById('analystActive').checked
            };

            try {
                const response = await fetch(API_BASE + 'save_analyst.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    closeAnalystModal();
                    loadAnalysts();
                } else {
                    alert('Error saving analyst: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to save analyst');
            }
        });

        // Password reset form submission
        document.getElementById('passwordResetForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;

            if (newPassword !== confirmPassword) {
                alert('Passwords do not match.');
                return;
            }

            if (newPassword.length < 6) {
                alert('Password must be at least 6 characters.');
                return;
            }

            try {
                const response = await fetch(API_BASE + 'reset_analyst_password.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: document.getElementById('resetAnalystId').value,
                        password: newPassword
                    })
                });
                const data = await response.json();

                if (data.success) {
                    closePasswordResetModal();
                    alert('Password reset successfully.');
                } else {
                    alert('Error resetting password: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to reset password');
            }
        });

        // Load analysts on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadAnalysts();
            loadGeneralSettings();
            loadReplyCleanupSettings();
        });

        // ============================
        // Reply Cleanup AI settings
        // ============================
        const API_TICKETS = '../../api/tickets/';

        async function loadReplyCleanupSettings() {
            try {
                const res = await fetch(API_TICKETS + 'get_reply_cleanup_settings.php');
                const data = await res.json();
                if (!data.success) return;

                document.getElementById('rcApiKey').value = data.api_key_masked || '';
                document.getElementById('rcApiKey').placeholder = data.has_api_key ? '' : 'sk-ant-...';
                document.getElementById('rcModel').value = data.model || 'claude-haiku-4-5-20251001';
                document.getElementById('rcTone').value  = data.tone  || 'Friendly';
                document.getElementById('rcCustomInstructions').value = data.custom_instructions || '';
                document.getElementById('rcPromptPreview').textContent = data.prompt_preview || '';
            } catch (err) {
                console.error('Failed to load reply cleanup settings:', err);
            }
        }

        // Re-fetch the prompt preview when the tone selection changes so the
        // read-only panel always reflects the currently-chosen tone clause.
        document.addEventListener('DOMContentLoaded', function() {
            const toneSelect = document.getElementById('rcTone');
            if (toneSelect) {
                toneSelect.addEventListener('change', loadReplyCleanupSettings);
            }
        });

        document.getElementById('replyCleanupForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const payload = {
                api_key:             document.getElementById('rcApiKey').value,
                model:               document.getElementById('rcModel').value,
                tone:                document.getElementById('rcTone').value,
                custom_instructions: document.getElementById('rcCustomInstructions').value,
            };
            try {
                const res = await fetch(API_TICKETS + 'save_reply_cleanup_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Reply Cleanup settings saved', 'success');
                    loadReplyCleanupSettings();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (err) {
                showToast('Failed to save settings', 'error');
            }
        });

        document.getElementById('rcTestBtn').addEventListener('click', async function() {
            const btn = this;
            const result = document.getElementById('rcTestResult');
            btn.disabled = true;
            btn.textContent = 'Testing…';
            result.style.display = 'none';
            try {
                const res = await fetch(API_TICKETS + 'test_reply_cleanup_key.php', { method: 'POST' });
                const data = await res.json();
                result.style.display = 'block';
                if (data.success) {
                    result.style.background = '#e6f4e6';
                    result.style.color = '#1b5e20';
                    result.style.border = '1px solid #a5d6a7';
                    result.textContent = data.message || 'Connection OK';
                } else {
                    result.style.background = '#fdecea';
                    result.style.color = '#a00';
                    result.style.border = '1px solid #f5c2c0';
                    result.textContent = data.error || 'Test failed';
                }
            } catch (err) {
                result.style.display = 'block';
                result.style.background = '#fdecea';
                result.style.color = '#a00';
                result.style.border = '1px solid #f5c2c0';
                result.textContent = 'Network error: ' + err.message;
            } finally {
                btn.disabled = false;
                btn.textContent = 'Test connection';
            }
        });

        // General Settings Functions
        const timezones = [
            'UTC',
            'Europe/London',
            'Europe/Paris',
            'Europe/Berlin',
            'Europe/Amsterdam',
            'Europe/Brussels',
            'Europe/Dublin',
            'Europe/Madrid',
            'Europe/Rome',
            'Europe/Zurich',
            'Europe/Vienna',
            'Europe/Warsaw',
            'Europe/Stockholm',
            'Europe/Oslo',
            'Europe/Copenhagen',
            'Europe/Helsinki',
            'Europe/Athens',
            'Europe/Moscow',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Los_Angeles',
            'America/Phoenix',
            'America/Toronto',
            'America/Vancouver',
            'America/Mexico_City',
            'America/Sao_Paulo',
            'America/Buenos_Aires',
            'Asia/Tokyo',
            'Asia/Shanghai',
            'Asia/Hong_Kong',
            'Asia/Singapore',
            'Asia/Seoul',
            'Asia/Bangkok',
            'Asia/Jakarta',
            'Asia/Manila',
            'Asia/Kolkata',
            'Asia/Mumbai',
            'Asia/Dubai',
            'Asia/Jerusalem',
            'Australia/Sydney',
            'Australia/Melbourne',
            'Australia/Brisbane',
            'Australia/Perth',
            'Pacific/Auckland',
            'Pacific/Fiji',
            'Africa/Cairo',
            'Africa/Johannesburg',
            'Africa/Lagos'
        ];

        function populateTimezoneDropdown(selectedTimezone = '') {
            const select = document.getElementById('systemTimezone');
            select.innerHTML = timezones.map(tz =>
                `<option value="${tz}"${tz === selectedTimezone ? ' selected' : ''}>${tz}</option>`
            ).join('');
        }

        async function loadGeneralSettings() {
            populateTimezoneDropdown();

            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();

                if (data.success) {
                    document.getElementById('systemName').value = data.settings.system_name || '';
                    populateTimezoneDropdown(data.settings.timezone || 'Europe/London');
                } else {
                    console.error('Error loading settings:', data.error);
                }
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        }

        // General settings form submission
        document.getElementById('generalSettingsForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const settings = {
                system_name: document.getElementById('systemName').value,
                timezone: document.getElementById('systemTimezone').value
            };

            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: settings })
                });
                const data = await response.json();

                if (data.success) {
                    showToast('Settings saved', 'success');
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save settings', 'error');
            }
        });

        // ============================
        // Email Templates
        // ============================

        const EVENT_LABELS = {
            'new_ticket_email': 'New ticket from email',
            'ticket_assigned': 'Ticket assigned',
            'ticket_closed': 'Ticket closed'
        };

        let emailTemplates = [];

        async function loadEmailTemplates() {
            try {
                const response = await fetch(API_BASE + 'get_email_templates.php');
                const data = await response.json();
                if (data.success) {
                    emailTemplates = data.templates;
                    renderEmailTemplates(data.templates);
                }
            } catch (error) {
                console.error('Error loading templates:', error);
            }
        }

        function renderEmailTemplates(templates) {
            const tbody = document.getElementById('email-templates-list');
            if (templates.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #999;">No email templates configured</td></tr>';
                return;
            }

            tbody.innerHTML = templates.map(tpl => `
                <tr>
                    <td>${escapeHtml(tpl.name)}</td>
                    <td>${EVENT_LABELS[tpl.event_trigger] || tpl.event_trigger}</td>
                    <td>${escapeHtml(tpl.subject_template)}</td>
                    <td>${tpl.display_order}</td>
                    <td><span class="status-badge ${tpl.is_active == 1 ? 'active' : 'inactive'}">${tpl.is_active == 1 ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editTemplate(${tpl.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteTemplate(${tpl.id}, '${escapeHtml(tpl.name)}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        function openTemplateModal(template = null) {
            document.getElementById('templateId').value = template ? template.id : '';
            document.getElementById('templateName').value = template ? template.name : '';
            document.getElementById('templateEvent').value = template ? template.event_trigger : '';
            document.getElementById('templateSubject').value = template ? template.subject_template : '';
            document.getElementById('templateBody').value = template ? template.body_template : '';
            document.getElementById('templateOrder').value = template ? template.display_order : 0;
            document.getElementById('templateActive').checked = template ? template.is_active == 1 : true;
            document.getElementById('templateModalTitle').textContent = template ? t('tickets.settings.modals.template.edit_title') : t('tickets.settings.modals.template.add_title');
            document.getElementById('templateModal').classList.add('active');
        }

        function editTemplate(id) {
            const template = emailTemplates.find(t => t.id == id);
            if (template) openTemplateModal(template);
        }

        function closeTemplateModal() {
            document.getElementById('templateModal').classList.remove('active');
        }

        async function deleteTemplate(id, name) {
            if (!confirm(`Delete template "${name}"?`)) return;

            try {
                const response = await fetch(API_BASE + 'delete_email_template.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Template deleted', 'success');
                    loadEmailTemplates();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to delete template', 'error');
            }
        }

        document.getElementById('templateForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const templateData = {
                id: document.getElementById('templateId').value || null,
                name: document.getElementById('templateName').value,
                event_trigger: document.getElementById('templateEvent').value,
                subject_template: document.getElementById('templateSubject').value,
                body_template: document.getElementById('templateBody').value,
                display_order: parseInt(document.getElementById('templateOrder').value) || 0,
                is_active: document.getElementById('templateActive').checked ? 1 : 0
            };

            try {
                const response = await fetch(API_BASE + 'save_email_template.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(templateData)
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Template saved', 'success');
                    closeTemplateModal();
                    loadEmailTemplates();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save template', 'error');
            }
        });

        // ==================== Rota Shifts ====================

        async function loadRotaShifts() {
            try {
                const response = await fetch(API_BASE + 'get_rota_shifts.php');
                const data = await response.json();
                if (data.success) {
                    renderRotaShifts(data.shifts);
                }
            } catch (error) {
                console.error('Error loading rota shifts:', error);
            }
        }

        function renderRotaShifts(shifts) {
            const tbody = document.getElementById('rota-shifts-list');
            if (!shifts || shifts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; color: #999;">No shifts defined. Click Add to create one.</td></tr>';
                return;
            }

            tbody.innerHTML = shifts.map(s => `
                <tr>
                    <td>${escapeHtml(s.name)}</td>
                    <td>${s.start_time ? s.start_time.substring(0, 5) : ''}</td>
                    <td>${s.end_time ? s.end_time.substring(0, 5) : ''}</td>
                    <td>${s.display_order}</td>
                    <td><span class="status-badge ${s.is_active == 1 ? 'active' : 'inactive'}">${s.is_active == 1 ? 'Active' : 'Inactive'}</span></td>
                    <td>
                        <button class="action-btn" onclick="editRotaShift(${s.id})" title="${t('common.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteRotaShift(${s.id}, '${escapeHtml(s.name)}')" title="${t('common.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>
            `).join('');
        }

        let rotaShiftsCache = [];

        async function openRotaShiftModal(id) {
            document.getElementById('rotaShiftId').value = '';
            document.getElementById('rotaShiftName').value = '';
            document.getElementById('rotaShiftStart').value = '';
            document.getElementById('rotaShiftEnd').value = '';
            document.getElementById('rotaShiftOrder').value = '0';
            document.getElementById('rotaShiftActive').checked = true;
            document.getElementById('rotaShiftModalTitle').textContent = t('tickets.settings.modals.rota_shift.add_title');

            if (id) {
                document.getElementById('rotaShiftModalTitle').textContent = t('tickets.settings.modals.rota_shift.edit_title');
                try {
                    const response = await fetch(API_BASE + 'get_rota_shifts.php');
                    const data = await response.json();
                    if (data.success) {
                        const shift = data.shifts.find(s => s.id == id);
                        if (shift) {
                            document.getElementById('rotaShiftId').value = shift.id;
                            document.getElementById('rotaShiftName').value = shift.name;
                            document.getElementById('rotaShiftStart').value = shift.start_time ? shift.start_time.substring(0, 5) : '';
                            document.getElementById('rotaShiftEnd').value = shift.end_time ? shift.end_time.substring(0, 5) : '';
                            document.getElementById('rotaShiftOrder').value = shift.display_order || 0;
                            document.getElementById('rotaShiftActive').checked = shift.is_active == 1;
                        }
                    }
                } catch (error) {
                    console.error('Error loading shift:', error);
                }
            }

            document.getElementById('rotaShiftModal').classList.add('active');
        }

        function editRotaShift(id) {
            openRotaShiftModal(id);
        }

        function closeRotaShiftModal() {
            document.getElementById('rotaShiftModal').classList.remove('active');
        }

        document.getElementById('rotaShiftForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const shiftData = {
                id: document.getElementById('rotaShiftId').value || null,
                name: document.getElementById('rotaShiftName').value,
                start_time: document.getElementById('rotaShiftStart').value,
                end_time: document.getElementById('rotaShiftEnd').value,
                display_order: parseInt(document.getElementById('rotaShiftOrder').value) || 0,
                is_active: document.getElementById('rotaShiftActive').checked ? 1 : 0
            };

            try {
                const response = await fetch(API_BASE + 'save_rota_shift.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(shiftData)
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Shift saved', 'success');
                    closeRotaShiftModal();
                    loadRotaShifts();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save shift', 'error');
            }
        });

        async function deleteRotaShift(id, name) {
            if (!confirm('Delete shift "' + name + '"?')) return;

            try {
                const response = await fetch(API_BASE + 'delete_rota_shift.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Shift deleted', 'success');
                    loadRotaShifts();
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to delete shift', 'error');
            }
        }

        // ==================== Rota Weekend Setting ====================

        async function loadRotaWeekendSetting() {
            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();
                if (data.success && data.settings) {
                    document.getElementById('rotaIncludeWeekends').checked = data.settings.rota_include_weekends == '1';
                }
            } catch (error) {
                console.error('Error loading weekend setting:', error);
            }
        }

        async function saveRotaWeekendSetting() {
            const val = document.getElementById('rotaIncludeWeekends').checked ? '1' : '0';
            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: { rota_include_weekends: val } })
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Setting saved', 'success');
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (error) {
                showToast('Failed to save setting', 'error');
            }
        }

        // ==================== SLA Tab ====================
        // Single in-memory state object; populated by loadSlaTab(), used by
        // the per-section render functions + the calendar edit modal.
        let slaData = { settings: {}, priorities: [], calendars: [] };
        let slaTimezones = null; // lazy-loaded on first calendar modal open
        const SLA_WEEKDAYS = [
            { num: 1, label: 'Monday' },
            { num: 2, label: 'Tuesday' },
            { num: 3, label: 'Wednesday' },
            { num: 4, label: 'Thursday' },
            { num: 5, label: 'Friday' },
            { num: 6, label: 'Saturday' },
            { num: 7, label: 'Sunday' },
        ];

        async function loadSlaTab() {
            try {
                const res = await fetch(API_BASE + 'get_sla_settings.php');
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'load failed');
                slaData = { settings: data.settings || {}, priorities: data.priorities || [], calendars: data.calendars || [] };
                renderSlaGlobalSettings();
                renderSlaTargets();
                renderSlaCalendars();
                loadSlaNotifRules();
                loadSlaCronRuns();
            } catch (e) {
                console.error('SLA load failed:', e);
            }
        }

        function renderSlaGlobalSettings() {
            const s = slaData.settings;
            // Datetime-local input format: YYYY-MM-DDTHH:MM
            const ef = s.sla_enforce_from;
            document.getElementById('slaEnforceFrom').value = ef ? ef.replace(' ', 'T').substring(0, 16) : '';
            document.getElementById('slaWarningThreshold').value = s.sla_warning_threshold_percent || '80';
            document.getElementById('slaNotifyAssignee').checked = s.sla_notify_assignee_at_warning === '1';
            document.getElementById('slaNotifyLead').checked = s.sla_notify_lead_at_breach === '1';

            const setRadio = (name, val) => {
                document.querySelectorAll(`input[name="${name}"]`).forEach(r => r.checked = (r.value === val));
            };
            setRadio('slaPriorityChange', s.sla_priority_change_behaviour || 'forward');
            setRadio('slaReopen', s.sla_reopen_behaviour || 'reset');
            setRadio('slaFirstResponse', s.sla_first_response_definition || 'either');
        }

        async function saveSlaGlobalSettings() {
            const getRadio = name => {
                const r = document.querySelector(`input[name="${name}"]:checked`);
                return r ? r.value : null;
            };
            const payload = {
                sla_enforce_from:               document.getElementById('slaEnforceFrom').value || null,
                sla_priority_change_behaviour:  getRadio('slaPriorityChange'),
                sla_reopen_behaviour:           getRadio('slaReopen'),
                sla_warning_threshold_percent:  document.getElementById('slaWarningThreshold').value || null,
                sla_notify_assignee_at_warning: document.getElementById('slaNotifyAssignee').checked,
                sla_notify_lead_at_breach:      document.getElementById('slaNotifyLead').checked,
                sla_first_response_definition:  getRadio('slaFirstResponse'),
            };
            try {
                const res = await fetch(API_BASE + 'save_sla_global_settings.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'save failed');
                const status = document.getElementById('slaGlobalSaveStatus');
                status.textContent = '✓ Saved';
                setTimeout(() => { status.textContent = ''; }, 2500);
                // Refresh local copy from server (normalised values)
                loadSlaTab();
            } catch (e) {
                showToast('Failed to save SLA settings: ' + e.message, 'error');
            }
        }

        function renderSlaTargets() {
            const tbody = document.getElementById('slaTargetsList');
            if (slaData.priorities.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#999;">No active priorities — add some on the Priorities tab.</td></tr>';
                return;
            }
            const calOptions = slaData.calendars.map(c =>
                `<option value="${c.id}">${escapeHtml(c.name)}</option>`
            ).join('');
            tbody.innerHTML = slaData.priorities.map(p => `
                <tr>
                    <td><strong style="color:${escapeHtml(p.colour || '#333')};">${escapeHtml(p.name)}</strong></td>
                    <td><input type="number" min="0" value="${p.sla_response_minutes || ''}" data-pid="${p.id}" data-field="response" style="width:90px;padding:4px 8px;"></td>
                    <td><input type="number" min="0" value="${p.sla_resolution_minutes || ''}" data-pid="${p.id}" data-field="resolution" style="width:90px;padding:4px 8px;"></td>
                    <td>
                        <select data-pid="${p.id}" data-field="calendar" style="padding:4px 8px;">
                            <option value="">— None —</option>
                            ${calOptions.replace(`value="${p.sla_calendar_id}"`, `value="${p.sla_calendar_id}" selected`)}
                        </select>
                    </td>
                    <td><button type="button" class="btn btn-secondary" style="padding:4px 12px;" onclick="savePrioritySla(${p.id})">Save</button></td>
                </tr>
            `).join('');
        }

        async function savePrioritySla(priorityId) {
            const row = document.querySelector(`#slaTargetsList tr [data-pid="${priorityId}"]`).closest('tr');
            const payload = {
                id: priorityId,
                sla_response_minutes:   row.querySelector('input[data-field="response"]').value,
                sla_resolution_minutes: row.querySelector('input[data-field="resolution"]').value,
                sla_calendar_id:        row.querySelector('select[data-field="calendar"]').value,
            };
            try {
                const res = await fetch(API_BASE + 'save_priority_sla.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'save failed');
                showToast('Priority SLA saved', 'success');
                loadSlaTab();
            } catch (e) {
                showToast('Failed to save: ' + e.message, 'error');
            }
        }

        function renderSlaCalendars() {
            const tbody = document.getElementById('slaCalendarsList');
            if (slaData.calendars.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#999;">No calendars defined yet. Add one above.</td></tr>';
                return;
            }
            tbody.innerHTML = slaData.calendars.map(c => {
                const openDays = (c.hours || []).length;
                const hoursLabel = openDays > 0
                    ? `${openDays} open day${openDays === 1 ? '' : 's'}`
                    : '<span style="color:#c62828;">No hours set</span>';
                return `
                    <tr>
                        <td><strong>${escapeHtml(c.name)}</strong></td>
                        <td><code style="font-size:12px;">${escapeHtml(c.timezone)}</code></td>
                        <td>${hoursLabel}</td>
                        <td>${c.holiday_count || 0}</td>
                        <td>${c.is_default ? '<span class="status-badge status-active">Default</span>' : ''}</td>
                        <td>
                            <button class="action-btn" onclick="openSlaCalendarModal(${c.id})" title="${escapeHtml(t('common.edit'))}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        // ---------- Calendar modal ----------
        let slaModalHolidays = []; // in-flight list for the open modal

        async function openSlaCalendarModal(calendarId) {
            // Lazy-load timezones once
            if (!slaTimezones) {
                try {
                    const res = await fetch(API_BASE + 'list_timezones.php');
                    const data = await res.json();
                    if (data.success) slaTimezones = data.groups;
                } catch (e) { slaTimezones = {}; }
            }
            // Populate the timezone select (only the first time, or if it's empty)
            const tzSelect = document.getElementById('slaCalendarTimezone');
            if (tzSelect.options.length === 0 && slaTimezones) {
                Object.keys(slaTimezones).sort().forEach(region => {
                    const og = document.createElement('optgroup');
                    og.label = region;
                    slaTimezones[region].forEach(tz => {
                        const opt = document.createElement('option');
                        opt.value = tz;
                        opt.textContent = tz;
                        og.appendChild(opt);
                    });
                    tzSelect.appendChild(og);
                });
            }

            // Build the 7-day hours grid
            const grid = document.getElementById('slaCalendarHoursGrid');
            grid.innerHTML = SLA_WEEKDAYS.map(w => `
                <label style="font-weight:500;">${w.label}</label>
                <label style="display:flex;align-items:center;gap:6px;font-weight:400;">
                    <input type="checkbox" class="sla-hours-open" data-wd="${w.num}"> Open
                </label>
                <input type="time" class="sla-hours-start" data-wd="${w.num}" style="padding:4px 8px;" disabled>
                <input type="time" class="sla-hours-end" data-wd="${w.num}" style="padding:4px 8px;" disabled>
            `).join('');
            // Wire the open-checkbox to enable/disable the time inputs
            grid.querySelectorAll('.sla-hours-open').forEach(cb => {
                cb.addEventListener('change', e => {
                    const wd = e.target.dataset.wd;
                    grid.querySelector(`.sla-hours-start[data-wd="${wd}"]`).disabled = !e.target.checked;
                    grid.querySelector(`.sla-hours-end[data-wd="${wd}"]`).disabled = !e.target.checked;
                });
            });

            // Default values for a fresh calendar OR load existing
            const reset = () => {
                document.getElementById('slaCalendarId').value = '';
                document.getElementById('slaCalendarName').value = '';
                tzSelect.value = 'Europe/London';
                document.getElementById('slaCalendarIsDefault').checked = false;
                slaModalHolidays = [];
                // Default: Mon-Fri 09:00-17:00 open, Sat/Sun closed
                SLA_WEEKDAYS.forEach(w => {
                    const open = w.num <= 5;
                    grid.querySelector(`.sla-hours-open[data-wd="${w.num}"]`).checked = open;
                    grid.querySelector(`.sla-hours-start[data-wd="${w.num}"]`).value = open ? '09:00' : '';
                    grid.querySelector(`.sla-hours-end[data-wd="${w.num}"]`).value = open ? '17:00' : '';
                    grid.querySelector(`.sla-hours-start[data-wd="${w.num}"]`).disabled = !open;
                    grid.querySelector(`.sla-hours-end[data-wd="${w.num}"]`).disabled = !open;
                });
                document.getElementById('slaCalendarModalTitle').textContent = 'Add Business Calendar';
                document.getElementById('slaCalendarDeleteBtn').style.display = 'none';
            };
            reset();

            if (calendarId) {
                try {
                    const res = await fetch(API_BASE + 'get_sla_calendar.php?id=' + calendarId);
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'load failed');
                    const c = data.calendar;
                    document.getElementById('slaCalendarId').value = c.id;
                    document.getElementById('slaCalendarName').value = c.name;
                    tzSelect.value = c.timezone;
                    document.getElementById('slaCalendarIsDefault').checked = c.is_default;
                    document.getElementById('slaCalendarModalTitle').textContent = 'Edit Business Calendar';
                    document.getElementById('slaCalendarDeleteBtn').style.display = '';
                    // Reset all days closed first, then apply
                    SLA_WEEKDAYS.forEach(w => {
                        grid.querySelector(`.sla-hours-open[data-wd="${w.num}"]`).checked = false;
                        grid.querySelector(`.sla-hours-start[data-wd="${w.num}"]`).value = '';
                        grid.querySelector(`.sla-hours-end[data-wd="${w.num}"]`).value = '';
                        grid.querySelector(`.sla-hours-start[data-wd="${w.num}"]`).disabled = true;
                        grid.querySelector(`.sla-hours-end[data-wd="${w.num}"]`).disabled = true;
                    });
                    (c.hours || []).forEach(h => {
                        grid.querySelector(`.sla-hours-open[data-wd="${h.weekday}"]`).checked = true;
                        grid.querySelector(`.sla-hours-start[data-wd="${h.weekday}"]`).value = h.start_time;
                        grid.querySelector(`.sla-hours-end[data-wd="${h.weekday}"]`).value = h.end_time;
                        grid.querySelector(`.sla-hours-start[data-wd="${h.weekday}"]`).disabled = false;
                        grid.querySelector(`.sla-hours-end[data-wd="${h.weekday}"]`).disabled = false;
                    });
                    slaModalHolidays = (c.holidays || []).map(h => ({ holiday_date: h.holiday_date, name: h.name || '' }));
                } catch (e) {
                    showToast('Failed to load calendar: ' + e.message, 'error');
                    return;
                }
            }

            renderSlaModalHolidays();
            document.getElementById('slaCalendarModal').classList.add('active');
        }

        function closeSlaCalendarModal() {
            document.getElementById('slaCalendarModal').classList.remove('active');
        }

        function renderSlaModalHolidays() {
            const container = document.getElementById('slaCalendarHolidaysList');
            if (slaModalHolidays.length === 0) {
                container.innerHTML = '<div style="color:#999;font-size:13px;font-style:italic;">No holidays added yet.</div>';
                return;
            }
            container.innerHTML = slaModalHolidays.map((h, i) => `
                <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;padding:6px 10px;background:#f5f5f5;border-radius:4px;">
                    <code style="font-size:12px;">${escapeHtml(h.holiday_date)}</code>
                    ${h.name ? `<span style="color:#555;flex:1;">${escapeHtml(h.name)}</span>` : '<span style="flex:1;color:#999;font-style:italic;">(no name)</span>'}
                    <button type="button" class="action-btn delete" onclick="removeSlaHoliday(${i})" title="${escapeHtml(t('common.delete'))}" style="padding:2px 8px;">&times;</button>
                </div>
            `).join('');
        }

        function addSlaHoliday() {
            const date = document.getElementById('slaCalendarHolidayDate').value;
            const name = document.getElementById('slaCalendarHolidayName').value.trim();
            if (!date) { showToast('Pick a date first', 'error'); return; }
            if (slaModalHolidays.some(h => h.holiday_date === date)) {
                showToast('That date is already in the list', 'error');
                return;
            }
            slaModalHolidays.push({ holiday_date: date, name });
            slaModalHolidays.sort((a, b) => a.holiday_date.localeCompare(b.holiday_date));
            renderSlaModalHolidays();
            document.getElementById('slaCalendarHolidayDate').value = '';
            document.getElementById('slaCalendarHolidayName').value = '';
        }

        function removeSlaHoliday(idx) {
            slaModalHolidays.splice(idx, 1);
            renderSlaModalHolidays();
        }

        // Wire the calendar form submit
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('slaCalendarForm');
            if (!form) return;
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                const grid = document.getElementById('slaCalendarHoursGrid');
                const hours = [];
                SLA_WEEKDAYS.forEach(w => {
                    const open = grid.querySelector(`.sla-hours-open[data-wd="${w.num}"]`).checked;
                    if (!open) return;
                    const start = grid.querySelector(`.sla-hours-start[data-wd="${w.num}"]`).value;
                    const end   = grid.querySelector(`.sla-hours-end[data-wd="${w.num}"]`).value;
                    if (!start || !end) return;
                    hours.push({ weekday: w.num, start_time: start, end_time: end });
                });

                const idRaw = document.getElementById('slaCalendarId').value;
                const payload = {
                    name:       document.getElementById('slaCalendarName').value.trim(),
                    timezone:   document.getElementById('slaCalendarTimezone').value,
                    is_default: document.getElementById('slaCalendarIsDefault').checked,
                    hours,
                    holidays:   slaModalHolidays,
                };
                if (idRaw) payload.id = parseInt(idRaw, 10);

                try {
                    const res = await fetch(API_BASE + 'save_sla_calendar.php', {
                        method: 'POST', headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await res.json();
                    if (!data.success) throw new Error(data.error || 'save failed');
                    showToast('Calendar saved', 'success');
                    closeSlaCalendarModal();
                    loadSlaTab();
                } catch (e) {
                    showToast('Failed to save calendar: ' + e.message, 'error');
                }
            });
        });

        async function deleteSlaCalendar() {
            const id = parseInt(document.getElementById('slaCalendarId').value, 10);
            if (!id) return;
            if (!confirm('Delete this calendar? This cannot be undone.')) return;
            try {
                const res = await fetch(API_BASE + 'delete_sla_calendar.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'delete failed');
                showToast('Calendar deleted', 'success');
                closeSlaCalendarModal();
                loadSlaTab();
            } catch (e) {
                showToast(e.message, 'error');
            }
        }

        // ===== Breach Notification rules =====

        // Cache the auxiliary lists alongside the rules so the modal can populate
        // dept + analyst dropdowns without a second round-trip on every open.
        let slaNotifData = { rules: [], departments: [], analysts: [] };

        async function loadSlaNotifRules() {
            try {
                const res = await fetch(API_BASE + 'get_sla_notification_rules.php');
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'load failed');
                slaNotifData = {
                    rules: data.rules || [],
                    departments: data.departments || [],
                    analysts: data.analysts || [],
                };
                renderSlaNotifRules();
            } catch (e) {
                console.error('SLA notif load failed:', e);
            }
        }

        function renderSlaNotifRules() {
            const tbody = document.getElementById('slaNotifRulesList');
            if (!slaNotifData.rules.length) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888;">No rules configured &mdash; SLA breach emails are disabled until you add at least one.</td></tr>';
                return;
            }
            const targetLabel = { response: 'Response', resolution: 'Resolution', both: 'Both' };
            const triggerLabel = { warning: 'Warning', breach: 'Breach' };
            const triggerColour = { warning: '#f59e0b', breach: '#dc2626' };

            tbody.innerHTML = slaNotifData.rules.map(r => {
                const scope = r.department_id
                    ? escapeHtml(r.department_name || ('Department #' + r.department_id))
                    : '<em>Default (all departments)</em>';
                const recipients = [];
                if (r.notify_assignee)         recipients.push('Assignee');
                if (r.notify_department_teams) recipients.push('Dept teams');
                if (r.notify_analyst_name)     recipients.push(escapeHtml(r.notify_analyst_name));
                if (r.notify_emails) {
                    const list = r.notify_emails.split(',').map(s => s.trim()).filter(Boolean);
                    if (list.length === 1) recipients.push(escapeHtml(list[0]));
                    else if (list.length > 1) recipients.push(escapeHtml(list[0]) + ' <span style="color:#888;">+' + (list.length - 1) + ' more</span>');
                }
                return `
                    <tr>
                        <td>${scope}</td>
                        <td><span style="display:inline-block;padding:2px 8px;border-radius:10px;background:${triggerColour[r.trigger_type]}1A;color:${triggerColour[r.trigger_type]};font-size:11px;font-weight:600;">${triggerLabel[r.trigger_type]}</span></td>
                        <td>${targetLabel[r.target_type] || r.target_type}</td>
                        <td>${recipients.join(', ') || '<span style="color:#c00;">none</span>'}</td>
                        <td>${r.is_active ? 'Yes' : '<span style="color:#888;">No</span>'}</td>
                        <td>
                            <button class="action-btn" onclick="openSlaNotifModal(${r.id})" title="Edit">&#9998;</button>
                            <button class="action-btn" onclick="deleteSlaNotifRule(${r.id})" title="Delete">&times;</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function openSlaNotifModal(id) {
            // Populate the department dropdown (default option is hardcoded in markup)
            const dept = document.getElementById('slaNotifDept');
            dept.innerHTML = '<option value="">Default (applies to every department without a specific rule)</option>'
                + slaNotifData.departments.map(d => `<option value="${d.id}">${escapeHtml(d.name)}</option>`).join('');

            // Populate the analyst dropdown
            const an = document.getElementById('slaNotifAnalyst');
            an.innerHTML = '<option value="">&mdash; none &mdash;</option>'
                + slaNotifData.analysts.map(a => `<option value="${a.id}">${escapeHtml(a.full_name)}</option>`).join('');

            if (id) {
                const r = slaNotifData.rules.find(x => x.id === id);
                if (!r) return;
                document.getElementById('slaNotifModalTitle').textContent = 'Edit Notification Rule';
                document.getElementById('slaNotifId').value = r.id;
                dept.value = r.department_id || '';
                document.getElementById('slaNotifTrigger').value = r.trigger_type;
                document.getElementById('slaNotifTarget').value = r.target_type;
                document.getElementById('slaNotifAssignee').checked = !!r.notify_assignee;
                document.getElementById('slaNotifTeams').checked = !!r.notify_department_teams;
                an.value = r.notify_analyst_id || '';
                document.getElementById('slaNotifEmails').value = r.notify_emails || '';
                document.getElementById('slaNotifActive').checked = !!r.is_active;
            } else {
                document.getElementById('slaNotifModalTitle').textContent = 'Add Notification Rule';
                document.getElementById('slaNotifId').value = '';
                dept.value = '';
                document.getElementById('slaNotifTrigger').value = 'warning';
                document.getElementById('slaNotifTarget').value = 'both';
                document.getElementById('slaNotifAssignee').checked = true;
                document.getElementById('slaNotifTeams').checked = false;
                an.value = '';
                document.getElementById('slaNotifEmails').value = '';
                document.getElementById('slaNotifActive').checked = true;
            }
            document.getElementById('slaNotifModal').style.display = 'block';
        }

        function closeSlaNotifModal() {
            document.getElementById('slaNotifModal').style.display = 'none';
        }

        async function saveSlaNotifRule() {
            const idVal = document.getElementById('slaNotifId').value;
            const payload = {
                id: idVal ? parseInt(idVal, 10) : null,
                department_id: document.getElementById('slaNotifDept').value || null,
                trigger_type: document.getElementById('slaNotifTrigger').value,
                target_type: document.getElementById('slaNotifTarget').value,
                notify_assignee: document.getElementById('slaNotifAssignee').checked,
                notify_department_teams: document.getElementById('slaNotifTeams').checked,
                notify_analyst_id: document.getElementById('slaNotifAnalyst').value || null,
                notify_emails: document.getElementById('slaNotifEmails').value,
                is_active: document.getElementById('slaNotifActive').checked,
            };
            try {
                const res = await fetch(API_BASE + 'save_sla_notification_rule.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'save failed');
                showToast('Rule saved', 'success');
                closeSlaNotifModal();
                loadSlaNotifRules();
            } catch (e) {
                showToast(e.message, 'error');
            }
        }

        async function deleteSlaNotifRule(id) {
            if (!confirm('Delete this notification rule? This cannot be undone.')) return;
            try {
                const res = await fetch(API_BASE + 'delete_sla_notification_rule.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'delete failed');
                showToast('Rule deleted', 'success');
                loadSlaNotifRules();
            } catch (e) {
                showToast(e.message, 'error');
            }
        }

        // ===== Cron Activity =====

        async function loadSlaCronRuns() {
            try {
                const res = await fetch(API_BASE + 'get_sla_cron_runs.php?limit=20');
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'load failed');
                renderSlaCronRuns(data.runs || [], data.settings || {});
            } catch (e) {
                console.error('SLA cron runs load failed:', e);
            }
        }

        function renderSlaCronRuns(runs, settings) {
            // Update the settings echo line
            if (typeof settings.min_interval_seconds === 'number') {
                document.getElementById('slaCronMinInterval').textContent = settings.min_interval_seconds;
            }
            if (typeof settings.retention_days === 'number') {
                document.getElementById('slaCronRetentionDays').textContent = settings.retention_days;
            }

            const tbody = document.getElementById('slaCronRunsList');
            if (!runs.length) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888;">No runs yet &mdash; once you set up the scheduled task they\'ll appear here.</td></tr>';
                return;
            }
            const outcomeColour = {
                ok:             { bg: '#dcfce7', fg: '#166534' },
                rate_limited:   { bg: '#fef3c7', fg: '#92400e' },
                auth_failed:    { bg: '#fee2e2', fg: '#991b1b' },
                config_missing: { bg: '#fee2e2', fg: '#991b1b' },
                error:          { bg: '#fee2e2', fg: '#991b1b' },
            };
            const outcomeLabel = {
                ok: 'OK',
                rate_limited: 'Rate limited',
                auth_failed: 'Auth failed',
                config_missing: 'Config missing',
                error: 'Error',
            };

            tbody.innerHTML = runs.map(r => {
                const c = outcomeColour[r.outcome] || { bg: '#f3f4f6', fg: '#555' };
                const label = outcomeLabel[r.outcome] || r.outcome;
                const duration = r.duration_ms != null ? r.duration_ms + ' ms' : '&mdash;';
                const source = r.invocation === 'http'
                    ? `HTTP <span style="color:#888;">${escapeHtml(r.client_ip || 'unknown')}</span>`
                    : 'CLI';
                const notesAttr = r.notes ? ` title="${escapeHtml(r.notes)}"` : '';
                return `
                    <tr${notesAttr}>
                        <td>${escapeHtml(r.started_at || '')}</td>
                        <td>${source}</td>
                        <td>${duration}</td>
                        <td>${r.sent_count ?? 0}</td>
                        <td>${r.skipped_count ?? 0}</td>
                        <td>${r.error_count ?? 0}</td>
                        <td><span style="display:inline-block;padding:2px 8px;border-radius:10px;background:${c.bg};color:${c.fg};font-size:11px;font-weight:600;">${label}</span></td>
                    </tr>
                `;
            }).join('');
        }

    </script>
</body>
</html>
