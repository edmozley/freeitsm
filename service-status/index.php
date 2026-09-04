<?php
/**
 * Service Status Module - Dashboard
 * Shows service board with worst current impact + recent incidents
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
requireModuleAccess('service-status');
I18n::initFromSession();
Tz::init();

$current_page = 'dashboard';
$path_prefix = '../';
$translationNamespaces = ['common', 'service-status'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('service-status.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <style>
        /* Pin the shared --accent to the module's emerald so modals, focus
           rings and the secondary button read on-brand. */
        body { --accent: var(--ss-accent, #10b981); }

        .status-layout {
            height: calc(100vh - 48px);
            overflow-y: auto;
            padding: 30px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text, #333);
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .section-title .count {
            font-size: 13px;
            font-weight: 400;
            color: var(--text-dim, #888);
        }

        /* Service Board Grid */
        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 36px;
        }


        /* Incident update thread (discussion #59, phase 2) */
        /* An icon in its own narrow column, so every toggle sits at the same
           x down the page. It was a text link after the title, which put it
           wherever that title happened to end (Ed). */
        .inc-upd-col { width: 1%; white-space: nowrap; text-align: center; }
        .inc-updates-toggle {
            background: none; border: 1px solid transparent; cursor: pointer;
            padding: 5px; border-radius: 4px; line-height: 0;
            color: var(--text-faint, #9ca3af);
        }
        .inc-updates-toggle svg { width: 15px; height: 15px; }
        .inc-updates-toggle:hover { background: var(--surface-hover, #f0f0f0); color: var(--ss-accent, #10b981); }
        /* Open reads as pressed, since the icon can no longer say "Hide". */
        .inc-updates-toggle.is-open {
            color: var(--ss-accent, #10b981);
            border-color: var(--ss-accent, #10b981);
            background: var(--ss-accent-soft, transparent);
        }
        .inc-updates-row[hidden] { display: none !important; }
        .inc-updates { padding: 4px 0 8px; }
        .inc-update { padding: 8px 0; border-top: 1px solid var(--border-soft, #f1f5f9); }
        .inc-update:first-child { border-top: none; }
        .inc-update-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 4px; }
        .inc-update-when { font-size: 12px; color: var(--text-muted, #6b7280); }
        .inc-update-who  { font-size: 11px; color: var(--text-dim, #9ca3af); }
        .inc-update-comment { font-size: 13px; margin-bottom: 6px; }
        .inc-update-clear { font-size: 11px; color: var(--text-dim, #9ca3af); font-style: italic; }

        /* Internal vs external on an update (#99). */
        .inc-visibility { margin-top: 10px; display: flex; flex-wrap: wrap; align-items: center; gap: 16px; }
        .inc-vis-choice { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }
        .inc-vis-hint { flex-basis: 100%; font-size: 12px; line-height: 1.45; color: var(--text-muted, #6b7280); }
        /* Amber when it really will be published, grey when it will not. The
           colour is the fastest way to see which of the two you are about to do. */
        .inc-vis-hint.is-live { color: var(--warning-text, #92400e); }
        .inc-vis-hint.is-off  { color: var(--text-dim, #9ca3af); }

        /* The badge on each update in the thread, so the two are distinguishable
           at a glance rather than only in the dialog that wrote them. */
        .inc-update-vis {
            font-size: 10.5px; font-weight: 600; padding: 1px 7px; border-radius: 10px;
            background: var(--surface-3, #eee); color: var(--text-muted, #666);
        }
        /* Edit and remove, on the row they belong to. Pushed right so they
           do not sit between the badge and the words. */
        /* The correction dialog. A textarea with room to read the whole
           update, because these run to several sentences and the thing it
           replaced was a one-line box. */
        .upd-edit-note { margin: 0 0 14px; font-size: 12.5px; line-height: 1.5; color: var(--text-muted, #6b7280); }
        .upd-edit-text { height: 130px !important; }

        .inc-update-acts { margin-left: auto; display: inline-flex; gap: 2px; }
        .inc-update-act {
            border: none; background: none; cursor: pointer; padding: 2px 6px;
            border-radius: 4px; font-size: 13px; line-height: 1; color: var(--text-faint, #bbb);
        }
        .inc-update-act:hover { background: var(--surface-3, #eee); color: var(--text, #333); }
        .inc-update-act-danger:hover { background: var(--danger-bg, #fee2e2); color: var(--danger-text, #991b1b); }

        .inc-update-vis.is-external {
            background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e);
        }

        /* ─── Service history + uptime (discussion #59) ────────────────────── */
        /* ⚠️ The badge and the History link have to line up ACROSS cards, and two
           separate things stopped them.
           1. Descriptions are one line or two ("Email" wraps, "VPN" does not), so
              the badge started at a different height in each card.
           2. Badge and link on one line wrapped unpredictably — "Operational"
              left room for the link beside it, "Degraded Performance" did not —
              so some cards showed one row and others two.
           The card is a flex column with the description absorbing the slack, and
           the pair is always stacked. Consistent beats compact here: the eye is
           scanning DOWN a row of cards, so the badges must share a baseline. */
        .service-card { display: flex; flex-direction: column; }
        .service-card .service-desc { flex: 1 1 auto; }
        .service-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            margin-top: auto;       /* pinned to the bottom, whatever the description did */
        }

        .svc-history-toggle {
            background: none; border: none; padding: 0;
            color: var(--ss-accent, #10b981); font-size: 12px; cursor: pointer;
            text-decoration: underline;
        }
        .svc-history[hidden] { display: none !important; }
        /* ⚠️ An open history SPANS THE WHOLE GRID. The service cards are a
           minmax(200px, 1fr) grid, and a four-column table plus a 90-cell strip
           inside 200px is unreadable: the incident titles were clipped and the
           strip rendered as a barcode of 1px slivers. Screenshot caught both.
           Spanning gives the table room and makes each strip cell ~10px. */
        .service-card.is-expanded { grid-column: 1 / -1; text-align: left; }
        .service-card.is-expanded .service-name,
        .service-card.is-expanded .service-desc { text-align: left; }
        /* Expanded there is width to spare, so the pair goes back on one row. */
        .service-card.is-expanded .service-actions {
            flex-direction: row; align-items: center; justify-content: flex-start; gap: 12px;
        }
        .svc-history { margin-top: 12px; border-top: 1px solid var(--border, #e5e7eb); padding-top: 12px; }
        .svc-history-loading { font-size: 12px; color: var(--text-muted, #6b7280); padding: 6px 0; }

        .svc-history-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 10px; flex-wrap: wrap; }
        .svc-uptime-figure { font-size: 20px; font-weight: 700; color: var(--text, #111); }
        .svc-uptime-label  { font-size: 11px; color: var(--text-muted, #6b7280); margin-left: 6px; }
        .svc-win-group { display: flex; gap: 4px; }
        .svc-win {
            border: 1px solid var(--border, #e5e7eb); background: var(--surface, #fff);
            color: var(--text-muted, #6b7280); font-size: 11px; padding: 3px 8px;
            border-radius: 5px; cursor: pointer;
            transition: background 150ms ease, color 150ms ease, transform 140ms cubic-bezier(0.23, 1, 0.32, 1);
        }
        .svc-win:hover { border-color: var(--ss-accent, #10b981); }
        .svc-win:active { transform: scale(0.94); }
        .svc-win.is-on { background: var(--ss-accent, #10b981); border-color: var(--ss-accent, #10b981); color: #fff; font-weight: 600; }

        /* The strip. flex with min-width 0 cells so 7, 30, 90 or 365 days all fit
           the same width without a horizontal scrollbar appearing at 365. */
        .svc-strip { display: flex; gap: 1px; align-items: stretch; height: 30px; }
        /* ⚠️ A LITERAL, like every other day colour below, and it used to be
           var(--ok-bg, …) — a token that does not exist in theme.css, so it has
           always silently used this fallback. Nothing looked wrong because the
           fallback is the right colour; what was wrong was the implication that
           the strip follows the theme.
           It must not. --success-bg is the obvious candidate and it is #16331f
           in dark mode, against a card of #1e2228 — a good day would all but
           vanish while an outage (#dc2626) still shouted, which inverts the one
           thing this strip exists to show. Same reasoning as the excluded-day
           note below. */
        .svc-day { flex: 1 1 0; min-width: 0; border-radius: 1px; background: #d1fae5; }
        .svc-day-ok   { background: #d1fae5; }
        /* ⚠️ NOT var(--border). That token is a divider colour and it is #343b45 in
           dark mode, so an excluded day rendered as a near-black gap in the strip —
           it read as "broken", which is the one thing it is not. A literal mid-grey
           is legible against both the light and dark card backgrounds, and this is
           a data colour rather than chrome, so it should not follow a chrome token. */
        .svc-day-info { background: #94a3b8; }   /* logged, but not counted as downtime */
        .svc-day-down { background: #dc2626; }
        .svc-strip-ends { display: flex; justify-content: space-between; font-size: 10px; color: var(--text-muted, #9ca3af); margin-top: 4px; }

        .svc-history-table { width: 100%; margin-top: 12px; border-collapse: collapse; font-size: 12px; }
        .svc-history-table td { padding: 6px 8px; border-top: 1px solid var(--border-soft, #f1f5f9); vertical-align: middle; }
        .svc-excluded { font-size: 10px; color: var(--text-muted, #9ca3af); font-style: italic; }

        @media (prefers-reduced-motion: reduce) { .svc-win:active { transform: none; } }
        .service-card {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            padding: 16px;
            text-align: center;
            transition: box-shadow 0.2s;
        }

        .service-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }

        .service-card .service-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text, #333);
            margin-bottom: 8px;
        }

        .service-card .service-desc {
            font-size: 12px;
            color: var(--text-dim, #888);
            margin-bottom: 10px;
            min-height: 16px;
        }

        /* Impact badges */
        .impact-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .impact-major-outage { background: #fee2e2; color: #991b1b; }
        .impact-partial-outage { background: #fff1f2; color: #be123c; }
        .impact-degraded { background: #fff7ed; color: #c2410c; }
        .impact-maintenance { background: #dbeafe; color: #1e40af; }
        .impact-operational { background: #d1fae5; color: #065f46; }
        .impact-no-disruption { background: #f3f4f6; color: #6b7280; }

        /* Status badges for incident status */
        .incident-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .incident-status-3rd-party { background: #fef3c7; color: #92400e; }
        .incident-status-identified { background: #e0e7ff; color: #3730a3; }
        .incident-status-investigating { background: #fff7ed; color: #c2410c; }
        .incident-status-monitoring { background: #dbeafe; color: #1e40af; }
        .incident-status-resolved { background: #d1fae5; color: #065f46; }

        /* Incidents list */
        .incidents-section { margin-bottom: 30px; }

        .incident-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--surface, #fff);
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border, #e5e7eb);
        }

        .incident-table th {
            background: var(--surface-2, #f9fafb);
            padding: 10px 14px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted, #666);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border, #e5e7eb);
        }

        .incident-table td {
            padding: 12px 14px;
            font-size: 13px;
            color: var(--text, #333);
            border-bottom: 1px solid var(--border-soft, #f3f4f6);
        }

        .incident-table tr:last-child td { border-bottom: none; }

        .incident-table tr.resolved td { color: var(--text-dim, #999); }

        .incident-title {
            font-weight: 500;
            cursor: pointer;
        }

        .incident-title:hover { color: var(--ss-accent, #10b981); }
        /* The title is still a link, and now it looks like one. It was the only
           way into an incident and nothing said so — discussion #100. */
        .incident-title { text-decoration: underline; text-decoration-style: dotted; text-underline-offset: 3px; text-decoration-color: var(--border, #ddd); }
        .incident-title:hover { text-decoration-color: var(--ss-accent, #10b981); }

        /* Actions column (#100). Same shape as the contracts list, so the two
           read as the same control rather than two ideas of one. */
        .inc-actions-col { width: 1%; white-space: nowrap; text-align: right; }
        .incident-actions { white-space: nowrap; text-align: right; }
        .action-btn {
            background: none; border: 1px solid var(--border, #ddd); color: var(--text-muted, #666);
            cursor: pointer; padding: 6px; margin-left: 4px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center; transition: all 0.2s;
        }
        .action-btn:hover { background: var(--surface-hover, #f0f0f0); border-color: var(--ss-accent, #10b981); color: var(--ss-accent, #10b981); }
        .action-btn svg { width: 16px; height: 16px; }
        /* Delete turns red on hover only. Red at rest would make every row look
           like a warning, on a board whose whole job is telling you which rows
           are the problem. */
        .action-btn-danger:hover { background: var(--danger-bg, #fee2e2); border-color: var(--danger-border, #f0c2c2); color: var(--danger-text, #991b1b); }

        .incident-services-list {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
        }

        .incident-svc-tag {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 500;
        }

        .new-btn {
            padding: 8px 18px;
            background: var(--ss-accent, #10b981);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        .new-btn:hover { background: var(--ss-accent-hover, #059669); }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-dim, #999);
            font-size: 14px;
        }

        /* Incident modal */
        .modal-content { padding: 20px; max-width: 600px; }
        .modal-header { font-size: 20px; font-weight: 600; margin-bottom: 20px; color: var(--text, #333); padding: 0; border-bottom: none; }

        .modal .form-group { margin-bottom: 15px; }
        .modal .form-group label { display: block; margin-bottom: 5px; font-weight: 500; font-size: 13px; color: var(--text, #333); }
        .modal .form-group input,
        .modal .form-group textarea,
        .modal .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border, #ddd); border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .modal .form-group textarea { height: 80px; resize: vertical; }
        .modal .form-group input:focus,
        .modal .form-group textarea:focus,
        .modal .form-group select:focus { outline: none; border-color: var(--ss-accent, #10b981); box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.1); }

        .modal-actions { margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end; }

        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; transition: background-color 0.15s; }
        .btn-primary { background-color: var(--ss-accent, #10b981); color: white; }
        .btn-primary:hover { background-color: var(--ss-accent-hover, #059669); }
        .btn-danger { background-color: #ef4444; color: white; }
        .btn-danger:hover { background-color: #dc2626; }

        /* Affected services rows in modal */
        .affected-services { margin-top: 5px; }

        .affected-row {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-bottom: 8px;
        }

        .affected-row select { flex: 1; }

        .affected-row .remove-svc {
            background: none;
            border: none;
            color: var(--danger-accent, #d13438);
            cursor: pointer;
            font-size: 18px;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .affected-row .remove-svc:hover { background: #fdf3f3; }

        .add-svc-btn {
            background: none;
            border: 1px dashed var(--border, #ccc);
            color: var(--text-muted, #666);
            padding: 6px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }

        .add-svc-btn:hover { border-color: var(--ss-accent, #10b981); color: var(--ss-accent, #10b981); }

        .incident-date {
            font-size: 12px;
            color: var(--text-dim, #999);
            white-space: nowrap;
        }

        /* Pale-red remove-service hover wash → dark red in dark mode so it
           doesn't glow. Impact/incident-status badges stay hardcoded (data). */
        [data-theme-mode="dark"] .affected-row .remove-svc:hover { background: #3a1a1a; }
    </style>
    <!-- Mobile: LAYER 18 — board grid two-up, incidents as a card feed. -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=130">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="status-layout">
        <!-- Service Board -->
        <div class="section-title">
            <?php echo htmlspecialchars(t('service-status.board.services')); ?>
            <span class="count" id="serviceCount"></span>
        </div>
        <div class="service-grid" id="serviceGrid">
            <div class="empty-state"><?php echo htmlspecialchars(t('service-status.board.loading')); ?></div>
        </div>

        <!-- Incidents -->
        <div class="incidents-section">
            <div class="section-title">
                <?php echo htmlspecialchars(t('service-status.board.incidents')); ?>
                <button class="new-btn" onclick="openIncidentModal()"><?php echo htmlspecialchars(t('service-status.board.new')); ?></button>
            </div>
            <table class="incident-table" id="incidentTable" style="display: none;">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('service-status.board.col_title')); ?></th>
                        <?php /* ⚠️ Its OWN column, not tacked onto the title (Ed).
                                 As a link after the title it sat wherever that
                                 title happened to end, so the toggles staggered
                                 down the page. Turning it into an icon in the
                                 same cell would have made a smaller mess in the
                                 same places; a fixed narrow column is what
                                 actually lines them up. Header left empty: a
                                 heading over one icon is noise. */ ?>
                        <th class="inc-upd-col"></th>
                        <th><?php echo htmlspecialchars(t('service-status.board.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('service-status.board.col_affected')); ?></th>
                        <th><?php echo htmlspecialchars(t('service-status.board.col_updated')); ?></th>
                        <th class="inc-actions-col"><?php echo htmlspecialchars(t('service-status.board.col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="incidentList"></tbody>
            </table>
            <div class="empty-state" id="incidentEmpty" style="display: none;"><?php echo htmlspecialchars(t('service-status.board.no_incidents')); ?></div>
        </div>
    </div>

    <!-- Incident Modal -->
    <!-- Right-click an incident (discussion #100) -->
    <div class="ticket-context-menu" id="incidentContextMenu" role="menu">
        <div class="ticket-context-menu-header" id="incidentCtxHeader"></div>

        <button class="ticket-context-menu-item" type="button" data-action="edit"
                onclick="closeIncidentContextMenu(); editIncident(ctxIncidentId);">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            <span><?php echo htmlspecialchars(t('service-status.actions.edit')); ?></span>
        </button>

        <button class="ticket-context-menu-item" type="button" data-action="updates"
                onclick="closeIncidentContextMenu(); toggleIncidentUpdatesById(ctxIncidentId);">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            <span><?php echo htmlspecialchars(t('service-status.actions.show_updates')); ?></span>
        </button>

        <button class="ticket-context-menu-item" type="button" data-action="resolve"
                onclick="closeIncidentContextMenu(); resolveIncident(ctxIncidentId);">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
            <span><?php echo htmlspecialchars(t('service-status.actions.resolve')); ?></span>
        </button>

        <button class="ticket-context-menu-item" type="button" data-action="delete"
                onclick="closeIncidentContextMenu(); deleteIncidentById(ctxIncidentId);">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
            <span><?php echo htmlspecialchars(t('service-status.actions.delete')); ?></span>
        </button>
    </div>

    <!--
      Correcting a posted update (Ed).

      ⚠️ A real dialog, not window.prompt(). The native one names the host
      ("freeitsm.internal says"), looks nothing like the product, and — worse
      here — gives a single-line box for text that is routinely several
      sentences, so you cannot see what you are correcting.
    -->
    <div class="modal" id="updateEditModal">
        <div class="modal-content">
            <div class="modal-header"><?php echo htmlspecialchars(t('service-status.updates.edit')); ?></div>
            <form id="updateEditForm" autocomplete="off">
                <input type="hidden" id="updateEditId">
                <p class="upd-edit-note"><?php echo t('service-status.updates.edit_note'); ?></p>
                <div class="form-group">
                    <label for="updateEditComment"><?php echo htmlspecialchars(t('service-status.modal.comment')); ?></label>
                    <textarea id="updateEditComment" class="upd-edit-text"></textarea>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('service-status.updates.who_can_see')); ?></label>
                    <div class="inc-visibility">
                        <label class="inc-vis-choice">
                            <input type="radio" name="updEditVis" value="internal" onchange="syncEditVisHint()">
                            <span><?php echo htmlspecialchars(t('service-status.modal.vis_internal')); ?></span>
                        </label>
                        <label class="inc-vis-choice">
                            <input type="radio" name="updEditVis" value="external" onchange="syncEditVisHint()">
                            <span><?php echo htmlspecialchars(t('service-status.modal.vis_external')); ?></span>
                        </label>
                        <div class="inc-vis-hint" id="updEditVisHint"></div>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeUpdateEditModal()"><?php echo htmlspecialchars(t('service-status.modal.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('service-status.modal.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="incidentModal">
        <div class="modal-content">
            <div class="modal-header" id="incidentModalTitle"><?php echo htmlspecialchars(t('service-status.modal.new_incident')); ?></div>
            <form id="incidentForm" autocomplete="off">
                <input type="hidden" id="incidentId">
                <div class="form-group">
                    <label for="incidentTitle"><?php echo htmlspecialchars(t('service-status.modal.title')); ?></label>
                    <input type="text" id="incidentTitle" required placeholder="<?php echo htmlspecialchars(t('service-status.modal.title_placeholder')); ?>">
                </div>
                <div class="form-group">
                    <label for="incidentStatus"><?php echo htmlspecialchars(t('service-status.modal.status')); ?></label>
                    <select id="incidentStatus"></select>
                </div>
                <div class="form-group">
                    <label for="incidentComment"><?php echo htmlspecialchars(t('service-status.modal.comment')); ?></label>
                    <textarea id="incidentComment" placeholder="<?php echo htmlspecialchars(t('service-status.modal.comment_placeholder')); ?>"></textarea>

                    <?php /* Discussion #99. Internal is FIRST and checked by
                             default: the safe answer should be the one you get
                             by not thinking about it, and publishing is the
                             deliberate act. The hint under it changes to say
                             what will actually happen, because "external" on
                             its own does not tell you the portal is switched
                             off. */ ?>
                    <div class="inc-visibility">
                        <label class="inc-vis-choice">
                            <input type="radio" name="incVisibility" value="internal" checked onchange="syncVisibilityHint()">
                            <span><?php echo htmlspecialchars(t('service-status.modal.vis_internal')); ?></span>
                        </label>
                        <label class="inc-vis-choice">
                            <input type="radio" name="incVisibility" value="external" onchange="syncVisibilityHint()">
                            <span><?php echo htmlspecialchars(t('service-status.modal.vis_external')); ?></span>
                        </label>
                        <div class="inc-vis-hint" id="incVisHint"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label><?php echo htmlspecialchars(t('service-status.modal.affected_services')); ?></label>
                    <div class="affected-services" id="affectedServices"></div>
                    <button type="button" class="add-svc-btn" onclick="addServiceRow()"><?php echo htmlspecialchars(t('service-status.modal.add_service')); ?></button>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-danger" id="deleteIncidentBtn" onclick="deleteIncident()" style="display: none; margin-right: auto;"><?php echo htmlspecialchars(t('service-status.modal.delete')); ?></button>
                    <button type="button" class="btn btn-secondary" onclick="closeIncidentModal()"><?php echo htmlspecialchars(t('service-status.modal.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('service-status.modal.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../api/service-status/';
        let allServices = [];
        let dashboardData = { services: [], incidents: [] };
        // Loaded from new lookup endpoints — drives dropdowns and badge colours
        let incidentStatuses = [];   // [{id, name, colour, is_resolved, is_default}]
        /* Whether the portal is actually publishing (#99). Rendered with the page
           rather than fetched: it only changes when an administrator changes it,
           and the dialog needs it the instant it opens to say what "external"
           will really do. */
        const portalShowsUpdates = <?php
            require_once '../includes/service_status_portal.php';
            $ssPortalOn = false;
            try { $ssPortalOn = ssPortalUpdatesEnabled(connectToDatabase()); } catch (Throwable $e) { $ssPortalOn = false; }
            echo $ssPortalOn ? 'true' : 'false';
        ?>;
        let impactLevels = [];       // [{id, name, colour, severity_order, is_default}]

        const statusByName = (name) => incidentStatuses.find(s => s.name === name);
        const impactByName = (name) => impactLevels.find(l => l.name === name);

        document.addEventListener('DOMContentLoaded', loadDashboard);

        async function loadDashboard() {
            try {
                // Load lookup tables (for dropdowns + badge colours)
                const [stsResp, ilResp, svcResp] = await Promise.all([
                    fetch(API_BASE + 'get_incident_statuses.php').then(r => r.json()),
                    fetch(API_BASE + 'get_impact_levels.php').then(r => r.json()),
                    fetch(API_BASE + 'get_services.php').then(r => r.json())
                ]);
                if (stsResp.success) incidentStatuses = stsResp.statuses.filter(s => s.is_active);
                if (ilResp.success)  impactLevels    = ilResp.impact_levels.filter(l => l.is_active);
                if (svcResp.success) allServices     = svcResp.services.filter(s => s.is_active);

                // Populate the incident-status dropdown
                const stsSelect = document.getElementById('incidentStatus');
                stsSelect.innerHTML = incidentStatuses.map(s =>
                    `<option value="${escapeHtml(s.name)}">${escapeHtml(s.name)}</option>`
                ).join('');

                // Load dashboard data
                const resp = await fetch(API_BASE + 'get_dashboard.php');
                const data = await resp.json();
                if (data.success) {
                    dashboardData = data;
                    renderServiceGrid(data.services);
                    renderIncidents(data.incidents);
                }
            } catch (error) {
                console.error('Failed to load dashboard:', error);
            }
        }

        function renderServiceGrid(services) {
            const grid = document.getElementById('serviceGrid');
            document.getElementById('serviceCount').textContent = window.t('service-status.board.service_count', { count: services.length });

            if (services.length === 0) {
                grid.innerHTML = '<div class="empty-state">' + escapeHtml(window.t('service-status.board.no_services')) + '</div>';
                return;
            }

            grid.innerHTML = services.map(svc => {
                // Colour comes from the row the API resolved, not from a name lookup
                // here: a level that was renamed OR deactivated still has to paint its
                // badge (GH #70). impactByName is the fallback for older payloads.
                const colour = svc.current_status_colour || impactByName(svc.current_status)?.colour;
                const style = colour ? `style="background:${colour}; color:#fff;"` : '';
                // History is loaded on demand (discussion #59): a dashboard with
                // twenty services should not fire twenty history queries to draw a
                // page most people are only glancing at.
                return `
                <div class="service-card">
                    <div class="service-name">${escapeHtml(svc.name)}</div>
                    <div class="service-desc">${escapeHtml(svc.description || '')}</div>
                    <div class="service-actions">
                        <span class="impact-badge" ${style}>${escapeHtml(svc.current_status)}</span>
                        <button type="button" class="svc-history-toggle" onclick="toggleServiceHistory(${svc.id}, this)">
                            ${escapeHtml(window.t('service-status.board.history_show'))}
                        </button>
                    </div>
                    <div class="svc-history" id="svcHistory${svc.id}" hidden></div>
                </div>`;
            }).join('');
        }

        /* ─── Service history + uptime (discussion #59) ───────────────────────
           Everything shown here is derived from incidents; there is no history
           table. See includes/services/service_uptime.php for what that can and
           cannot see (changes made DURING an incident are not yet recorded). */
        const svcHistoryCache = {};

        async function toggleServiceHistory(serviceId, btn) {
            const box = document.getElementById('svcHistory' + serviceId);
            if (!box) return;
            const card = box.closest('.service-card');
            if (!box.hidden) {
                box.hidden = true;
                if (card) card.classList.remove('is-expanded');
                btn.textContent = window.t('service-status.board.history_show');
                return;
            }
            box.hidden = false;
            if (card) card.classList.add('is-expanded');
            btn.textContent = window.t('service-status.board.history_hide');
            if (svcHistoryCache[serviceId]) { renderServiceHistory(serviceId, svcHistoryCache[serviceId]); return; }
            box.innerHTML = `<div class="svc-history-loading">${escapeHtml(window.t('service-status.board.history_loading'))}</div>`;
            await loadServiceHistory(serviceId);
        }

        async function loadServiceHistory(serviceId, days) {
            const box = document.getElementById('svcHistory' + serviceId);
            try {
                const q = days ? ('&days=' + encodeURIComponent(days)) : '';
                const res = await fetch(`../api/service-status/get_service_history.php?service_id=${serviceId}${q}`);
                const data = await res.json();
                if (!data.success) {
                    box.innerHTML = `<div class="svc-history-loading">${escapeHtml(data.error || '')}</div>`;
                    return;
                }
                svcHistoryCache[serviceId] = data;
                renderServiceHistory(serviceId, data);
            } catch (e) {
                box.innerHTML = `<div class="svc-history-loading">${escapeHtml(e.message)}</div>`;
            }
        }

        function renderServiceHistory(serviceId, data) {
            const box = document.getElementById('svcHistory' + serviceId);
            const s = data.summary;

            const windowPicker = data.windows.map(w =>
                `<button type="button" class="svc-win${w === s.window_days ? ' is-on' : ''}"
                         onclick="loadServiceHistory(${serviceId}, ${w})">${w}d</button>`).join('');

            // One cell per day, oldest first. title carries the detail rather than a
            // tooltip component: it is a 90-cell strip and a hover card per cell
            // would be a lot of DOM for something read at a glance.
            const strip = data.strip.map(d => {
                // ⚠️ Name the actual impact level. This used to say "maintenance"
                // for any day whose only incident was excluded from downtime — but
                // "excluded" covers Operational, No Disruption and anything an
                // administrator adds, so a day was reporting a level it never had.
                const label = d.impact
                    ? `${d.date} — ${d.impact}`
                    : `${d.date} — ${window.t('service-status.board.history_no_issues')}`;
                const bg = (d.state === 'down' && d.colour) ? ` style="background:${d.colour}"` : '';
                return `<span class="svc-day svc-day-${d.state}"${bg} title="${escapeHtml(label)}"></span>`;
            }).join('');

            const rows = data.incidents.length
                ? data.incidents.map(i => `
                    <tr>
                        <td>${escapeHtml(i.started)}</td>
                        <td><span class="impact-badge"${i.colour ? ` style="background:${i.colour};color:#fff;"` : ''}>${escapeHtml(i.impact)}</span></td>
                        <td>${i.ongoing ? escapeHtml(window.t('service-status.board.history_ongoing')) : escapeHtml(i.duration)}</td>
                        <td>${escapeHtml(i.title)}${i.counts ? '' : ` <span class="svc-excluded">${escapeHtml(window.t('service-status.board.history_excluded'))}</span>`}</td>
                    </tr>`).join('')
                : `<tr><td colspan="4" class="svc-history-loading">${escapeHtml(window.t('service-status.board.history_none'))}</td></tr>`;

            box.innerHTML = `
                <div class="svc-history-head">
                    <div class="svc-uptime">
                        <span class="svc-uptime-figure">${s.uptime_percent.toFixed(2)}%</span>
                        <span class="svc-uptime-label">${escapeHtml(window.t('service-status.board.history_uptime'))}</span>
                    </div>
                    <div class="svc-win-group">${windowPicker}</div>
                </div>
                <div class="svc-strip">${strip}</div>
                <div class="svc-strip-ends">
                    <span>${s.window_days}${escapeHtml(window.t('service-status.board.history_days_ago'))}</span>
                    <span>${escapeHtml(window.t('service-status.board.history_today'))}</span>
                </div>
                <table class="svc-history-table"><tbody>${rows}</tbody></table>
                <div id="svcDocuments${serviceId}" style="margin-top:18px;"></div>`;

            // Attached documents (discussion #76) — the runbook, the recovery
            // procedure, the supplier's SLA. Inside the expanded card, because
            // that is where a service already tells its longer story.
            if (window.FreeITSMDocuments) {
                FreeITSMDocuments.mount(document.getElementById('svcDocuments' + serviceId), {
                    parentType: 'status_service',
                    parentId:   serviceId,
                    apiBase:    '../api/documents/',
                    showHeading: true
                });
            }
        }

        function renderIncidents(incidents) {
            const table = document.getElementById('incidentTable');
            const empty = document.getElementById('incidentEmpty');
            const tbody = document.getElementById('incidentList');

            if (incidents.length === 0) {
                table.style.display = 'none';
                empty.style.display = 'block';
                return;
            }

            table.style.display = 'table';
            empty.style.display = 'none';

            tbody.innerHTML = incidents.map(inc => {
                const sts = statusByName(inc.status);
                const isResolved = !!(sts && sts.is_resolved);
                const statusStyle = sts && sts.colour ? `style="background:${sts.colour}; color:#fff;"` : '';
                const svcs = (inc.services || []).map(s => {
                    const il = impactByName(s.impact_level);
                    const tagStyle = il && il.colour ? `style="background:${il.colour}; color:#fff;"` : '';
                    return `<span class="incident-svc-tag" ${tagStyle}>${escapeHtml(s.service_name)}</span>`;
                }).join('');

                const date = inc.updated_datetime || inc.created_datetime;
                const dateStr = date ? formatDate(date) : '';

                // Discussion #100: the title was the only way in, and nothing
                // about it said so. Icons in an actions column, plus the same
                // actions on a right-click. The title stays clickable — taking
                // that away would break the habit of anybody who had found it.
                const resolveBtn = isResolved ? '' : `
                            <button type="button" class="action-btn" title="${escapeHtml(window.t('service-status.actions.resolve'))}"
                                    onclick="resolveIncident(${inc.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                            </button>`;

                return `
                    <tr class="${isResolved ? 'resolved' : ''}" oncontextmenu="return openIncidentContextMenu(event, ${inc.id});">
                        <td>
                            <span class="incident-title" onclick="editIncident(${inc.id})">${escapeHtml(inc.title)}</span>
                        </td>
                        <td class="inc-upd-col">
                            <button type="button" class="inc-updates-toggle" title="${escapeHtml(window.t('service-status.board.updates_show'))}"
                                    onclick="toggleIncidentUpdates(${inc.id}, this)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            </button>
                        </td>
                        <td><span class="incident-status" ${statusStyle}>${escapeHtml(inc.status)}</span></td>
                        <td><div class="incident-services-list">${svcs || `<span style="color:var(--text-dim, #999)">${escapeHtml(window.t('service-status.board.none'))}</span>`}</div></td>
                        <td><span class="incident-date">${dateStr}</span></td>
                        <?php /* ⚠️ RESOLVE FIRST, then edit, then delete (Ed).
                                 The column is right-aligned and Resolve is the
                                 only button that is sometimes absent, so it has
                                 to be the leftmost one: anything to its left
                                 would shift by a button's width on a resolved
                                 incident, which is exactly what happened when
                                 Edit was first. Edit and Delete now sit in the
                                 same place on every row, resolved or not. */ ?>
                        <td class="incident-actions">${resolveBtn}
                            <button type="button" class="action-btn" title="${escapeHtml(window.t('service-status.actions.edit'))}"
                                    onclick="editIncident(${inc.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <button type="button" class="action-btn action-btn-danger" title="${escapeHtml(window.t('service-status.actions.delete'))}"
                                    onclick="deleteIncidentById(${inc.id})">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>
                            </button>
                        </td>
                    </tr>
                    <tr class="inc-updates-row" id="incUpdatesRow${inc.id}" hidden>
                        <td colspan="6"><div class="inc-updates" id="incUpdates${inc.id}"></div></td>
                    </tr>
                `;
            }).join('');

            reopenUpdateThreads();
        }

        /**
         * Put back any update thread that was open before the table was rebuilt.
         *
         * ⚠️ Driven through the row's OWN toggle rather than by setting hidden
         * directly, so the button label, the fetch and the open-set all stay in
         * step — the same reason the context menu drives it that way. The set is
         * copied first because toggling mutates it while we iterate.
         */
        function reopenUpdateThreads() {
            if (!openUpdateThreads.size) return;
            Array.from(openUpdateThreads).forEach(id => {
                const row = document.getElementById('incUpdatesRow' + id);
                if (!row || !row.hidden) return;
                const btn = row.previousElementSibling
                         && row.previousElementSibling.querySelector('.inc-updates-toggle');
                // Gone entirely: the incident was deleted or filtered away, so
                // stop remembering it rather than leaving it open forever.
                if (!btn) { openUpdateThreads.delete(id); return; }
                btn.click();
            });
        }

        /* ─── Incident update thread (discussion #59, phase 2) ────────────────
           The rows behind the per-service history: what was said, when, by whom,
           and which services were at which impact at that moment. Loaded on
           demand — most people reading the board never open one. */
        /**
         * Which incidents have their update thread open.
         *
         * ⚠️ Kept because loadDashboard() rebuilds the whole incident table, so
         * anything open at the time is destroyed and comes back collapsed. That
         * showed up as having to click Updates again after correcting one (Ed),
         * but it was never only about editing — resolving or deleting from the
         * right-click menu closed an open thread the same way.
         */
        const openUpdateThreads = new Set();

        async function toggleIncidentUpdates(incidentId, btn) {
            const row = document.getElementById('incUpdatesRow' + incidentId);
            const box = document.getElementById('incUpdates' + incidentId);
            if (!row) return;
            if (!row.hidden) {
                row.hidden = true;
                openUpdateThreads.delete(Number(incidentId));
                // ⚠️ NOT textContent — that would wipe the icon out of the
                // button. The state lives in the tooltip and a class.
                btn.title = window.t('service-status.board.updates_show');
                btn.classList.remove('is-open');
                return;
            }
            openUpdateThreads.add(Number(incidentId));
            row.hidden = false;
            btn.title = window.t('service-status.board.updates_hide');
            btn.classList.add('is-open');
            box.innerHTML = `<div class="svc-history-loading">${escapeHtml(window.t('service-status.board.history_loading'))}</div>`;
            try {
                const res = await fetch(API_BASE + 'get_incident_updates.php?incident_id=' + incidentId);
                const data = await res.json();
                // ⚠️ "None recorded" and "could not load" are different facts and
                // must not share a message. Collapsing them says an incident has
                // no history when the request simply failed — the same shape of
                // lie as a strip cell that named a level it never had.
                if (!data.success) {
                    box.innerHTML = `<div class="svc-history-loading">${escapeHtml(data.error || window.t('service-status.board.updates_failed'))}</div>`;
                    return;
                }
                if (!data.updates.length) {
                    // An incident raised before phase 2 legitimately has none.
                    box.innerHTML = `<div class="svc-history-loading">${escapeHtml(window.t('service-status.board.updates_none'))}</div>`;
                    return;
                }
                box.innerHTML = data.updates.map(u => {
                    const tags = (u.services || []).map(s =>
                        `<span class="incident-svc-tag"${s.colour ? ` style="background:${s.colour};color:#fff;"` : ''}>${escapeHtml(s.service)}${s.impact ? ' · ' + escapeHtml(s.impact) : ''}</span>`
                    ).join('');
                    return `<div class="inc-update" data-update="${u.id}">
                        <div class="inc-update-meta">
                            <span class="inc-update-when">${escapeHtml(formatDate(u.created_datetime))}</span>
                            ${u.status ? `<span class="incident-status"${u.status_colour ? ` style="background:${u.status_colour};color:#fff;"` : ''}>${escapeHtml(u.status)}</span>` : ''}
                            ${u.author ? `<span class="inc-update-who">${escapeHtml(u.author)}</span>` : ''}
                            ${Number(u.is_internal) === 0
                                ? `<span class="inc-update-vis is-external">${escapeHtml(window.t('service-status.modal.vis_external'))}</span>`
                                : `<span class="inc-update-vis">${escapeHtml(window.t('service-status.modal.vis_internal'))}</span>`}
                            <span class="inc-update-acts">
                                <button type="button" class="inc-update-act" title="${escapeHtml(window.t('service-status.updates.edit'))}"
                                        onclick="editUpdate(${u.id}, ${incidentId})">&#9998;</button>
                                <button type="button" class="inc-update-act inc-update-act-danger" title="${escapeHtml(window.t('service-status.updates.delete'))}"
                                        onclick="deleteUpdate(${u.id}, ${incidentId})">&times;</button>
                            </span>
                        </div>
                        ${u.comment ? `<div class="inc-update-comment">${escapeHtml(u.comment)}</div>` : ''}
                        ${tags ? `<div class="incident-services-list">${tags}</div>` : `<div class="inc-update-clear">${escapeHtml(window.t('service-status.board.updates_all_clear'))}</div>`}
                    </div>`;
                }).join('');
            } catch (e) {
                box.innerHTML = `<div class="svc-history-loading">${escapeHtml(e.message)}</div>`;
            }
        }

        // Incident timestamps come from the API as UTC strings ("YYYY-MM-DD HH:MM:SS",
        // stamped with UTC_TIMESTAMP()). Render them in the analyst's chosen display
        // zone via the shared tz.js helpers (parseUTCDate marks the value UTC; tzOpts
        // injects window.USER_TIMEZONE, falling back to the browser zone when unset).
        function formatDate(dateStr) {
            try {
                const d = parseUTCDate(dateStr);
                if (!d || isNaN(d.getTime())) return dateStr;
                return fmtDateTime(d);
            } catch (e) {
                return dateStr;
            }
        }

        // --- Incident Modal ---

        function openIncidentModal() {
            document.getElementById('incidentModalTitle').textContent = window.t('service-status.modal.new_incident');
            document.getElementById('incidentId').value = '';
            document.getElementById('incidentTitle').value = '';
            const defaultSts = incidentStatuses.find(s => s.is_default) || incidentStatuses[0];
            document.getElementById('incidentStatus').value = defaultSts ? defaultSts.name : '';
            document.getElementById('incidentComment').value = '';
            document.getElementById('affectedServices').innerHTML = '';
            document.getElementById('deleteIncidentBtn').style.display = 'none';
            resetVisibility();
            addServiceRow();
            document.getElementById('incidentModal').classList.add('active');
        }

        function editIncident(id) {
            const inc = dashboardData.incidents.find(i => i.id == id);
            if (!inc) return;

            document.getElementById('incidentModalTitle').textContent = window.t('service-status.modal.edit_incident');
            document.getElementById('incidentId').value = inc.id;
            document.getElementById('incidentTitle').value = inc.title;
            document.getElementById('incidentStatus').value = inc.status;
            document.getElementById('incidentComment').value = inc.comment || '';
            document.getElementById('deleteIncidentBtn').style.display = 'inline-flex';
            resetVisibility();

            const container = document.getElementById('affectedServices');
            container.innerHTML = '';

            if (inc.services && inc.services.length > 0) {
                inc.services.forEach(s => addServiceRow(s.service_id, s.impact_level));
            } else {
                addServiceRow();
            }

            document.getElementById('incidentModal').classList.add('active');
        }

        function addServiceRow(serviceId, impactLevel) {
            const container = document.getElementById('affectedServices');
            const row = document.createElement('div');
            row.className = 'affected-row';

            const svcOptions = allServices.map(s =>
                `<option value="${s.id}" ${s.id == serviceId ? 'selected' : ''}>${escapeHtml(s.name)}</option>`
            ).join('');

            // Default impact for a freshly added row. This used to name 'Degraded'
            // literally, which broke the same way GH #70 did — rename the level and
            // the row silently fell back to the baseline, i.e. an incident that
            // affects nothing. Ask by MEANING instead: the mildest level that still
            // counts as downtime. On the stock lookup that IS Degraded, and it stays
            // right whatever the levels are called.
            const mildestDowntime = impactLevels
                .filter(l => l.counts_as_downtime && !l.is_default)
                .sort((a, b) => b.severity_order - a.severity_order)[0];
            const defaultImpact = impactLevel
                || mildestDowntime?.name
                || impactLevels.find(l => l.is_default)?.name
                || impactLevels[0]?.name || '';
            const impactOptions = impactLevels.map(level =>
                `<option value="${escapeHtml(level.name)}" ${level.name === defaultImpact ? 'selected' : ''}>${escapeHtml(level.name)}</option>`
            ).join('');

            row.innerHTML = `
                <select class="svc-select">${svcOptions}</select>
                <select class="impact-select">${impactOptions}</select>
                <button type="button" class="remove-svc">&times;</button>
            `;

            // Removing an affected service used to happen on the first tap with
            // no way back — easy to do by accident on a phone, where the × sits
            // next to two dropdowns. Uses the app-wide showConfirm (not the
            // browser's confirm()) so it matches every other destructive action,
            // and the generic delete_title / delete_message pair so no new
            // translation key is needed.
            row.querySelector('.remove-svc').addEventListener('click', async function () {
                const name = row.querySelector('.svc-select')?.selectedOptions[0]?.textContent || '';
                const ok = await showConfirm({
                    title: window.t('service-status.confirm.delete_title'),
                    message: window.t('service-status.confirm.delete_message', { name: name }),
                    okLabel: window.t('service-status.confirm.delete_label'),
                    okClass: 'danger'
                });
                if (ok) row.remove();
            });

            container.appendChild(row);
        }

        function closeIncidentModal() {
            document.getElementById('incidentModal').classList.remove('active');
        }

        document.getElementById('incidentForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const rows = document.querySelectorAll('#affectedServices .affected-row');
            const services = [];
            rows.forEach(row => {
                const svcId = row.querySelector('.svc-select').value;
                const impact = row.querySelector('.impact-select').value;
                if (svcId) {
                    services.push({ service_id: parseInt(svcId), impact_level: impact });
                }
            });

            const payload = {
                id: document.getElementById('incidentId').value || null,
                title: document.getElementById('incidentTitle').value,
                status: document.getElementById('incidentStatus').value,
                comment: document.getElementById('incidentComment').value,
                // #99. Sent explicitly rather than left to the server's default,
                // so the screen and the stored row cannot disagree about which
                // one the analyst picked.
                is_internal: (document.querySelector('input[name="incVisibility"]:checked') || {}).value !== 'external',
                services: services
            };

            try {
                const response = await fetch(API_BASE + 'save_incident.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.success) {
                    closeIncidentModal();
                    showToast(window.t('service-status.toast.incident_saved'), 'success');
                    loadDashboard();
                } else {
                    showToast(data.error || window.t('service-status.toast.save_failed'), 'error');
                }
            } catch (error) {
                showToast(window.t('service-status.toast.save_incident_failed'), 'error');
            }
        });

        /**
         * Delete from the modal. Kept as the entry point the modal's button
         * already calls, delegating so there is one implementation rather than
         * two that drift.
         */
        async function deleteIncident() {
            const id = document.getElementById('incidentId').value;
            if (!id) return;
            await deleteIncidentById(parseInt(id, 10));
        }

        async function deleteIncidentById(id) {
            if (!id) return;
            const ok = await showConfirm({
                title: window.t('service-status.confirm.delete_incident_title'),
                message: window.t('service-status.confirm.delete_incident_message'),
                okLabel: window.t('service-status.confirm.delete_label'),
                okClass: 'danger'
            });
            if (!ok) return;

            try {
                const response = await fetch(API_BASE + 'delete_incident.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: parseInt(id) })
                });
                const data = await response.json();
                if (data.success) {
                    closeIncidentModal();
                    showToast(window.t('service-status.toast.incident_deleted'), 'success');
                    loadDashboard();
                } else {
                    showToast(data.error || window.t('service-status.toast.delete_failed'), 'error');
                }
            } catch (error) {
                showToast(window.t('service-status.toast.delete_incident_failed'), 'error');
            }
        }

        /**
         * Correct an update that has already been posted (Ed).
         *
         * ⚠️ In place. Before this, fixing a typo meant saving the incident,
         * which appended a second entry — so anybody reading the portal saw the
         * same sentence twice with the wrong version first.
         *
         * The visibility is editable here too, and that is the more useful half:
         * an update written as internal that should have gone out can be
         * published without retyping it, and one published by mistake can be
         * pulled back without deleting the record of it.
         */
        let editingIncidentId = null;

        function editUpdate(updateId, incidentId) {
            const row = document.querySelector(`#incUpdates${incidentId} [data-update="${updateId}"]`);
            const box = row && row.querySelector('.inc-update-comment');
            const isExternal = !!(row && row.querySelector('.inc-update-vis.is-external'));

            editingIncidentId = incidentId;
            document.getElementById('updateEditId').value = updateId;
            document.getElementById('updateEditComment').value = box ? box.textContent : '';
            document.querySelector(`input[name="updEditVis"][value="${isExternal ? 'external' : 'internal'}"]`).checked = true;
            syncEditVisHint();

            document.getElementById('updateEditModal').classList.add('active');
            document.getElementById('updateEditComment').focus();
        }

        function closeUpdateEditModal() {
            document.getElementById('updateEditModal').classList.remove('active');
            editingIncidentId = null;
        }

        /** Same honesty as the incident dialog: say what "external" will do. */
        function syncEditVisHint() {
            const hint = document.getElementById('updEditVisHint');
            if (!hint) return;
            const external = (document.querySelector('input[name="updEditVis"]:checked') || {}).value === 'external';
            if (!external) {
                hint.textContent = window.t('service-status.modal.vis_internal_hint');
                hint.className = 'inc-vis-hint';
                return;
            }
            hint.textContent = window.t(portalShowsUpdates
                ? 'service-status.modal.vis_external_hint'
                : 'service-status.modal.vis_external_hint_off');
            hint.className = 'inc-vis-hint' + (portalShowsUpdates ? ' is-live' : ' is-off');
        }

        document.getElementById('updateEditForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            const id = parseInt(document.getElementById('updateEditId').value, 10);
            const incidentId = editingIncidentId;

            try {
                const r = await fetch(API_BASE + 'save_incident_update.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: id,
                        comment: document.getElementById('updateEditComment').value,
                        is_internal: (document.querySelector('input[name="updEditVis"]:checked') || {}).value !== 'external',
                    })
                });
                const d = await r.json();
                if (!d.success) { showToast(d.error || window.t('service-status.toast.save_failed'), 'error'); return; }
                closeUpdateEditModal();
                showToast(window.t('service-status.updates.edited'), 'success');
                // loadDashboard() rebuilds the table (the board shows the latest
                // comment, which may be this one) and puts the open thread back
                // with the corrected text.
                loadDashboard();
            } catch (err) {
                showToast(window.t('service-status.toast.save_failed'), 'error');
            }
        });

        async function deleteUpdate(updateId, incidentId) {
            const ok = await showConfirm({
                title:   window.t('service-status.updates.delete'),
                message: window.t('service-status.updates.delete_message'),
                okLabel: window.t('service-status.confirm.delete_label'),
                okClass: 'danger'
            });
            if (!ok) return;

            try {
                const r = await fetch(API_BASE + 'delete_incident_update.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: updateId })
                });
                const d = await r.json();
                if (!d.success) { showToast(d.error || window.t('service-status.toast.delete_failed'), 'error'); return; }
                showToast(window.t('service-status.updates.deleted'), 'success');
                loadDashboard();
            } catch (e) {
                showToast(window.t('service-status.toast.delete_failed'), 'error');
            }
        }

        /* refreshUpdates() used to close and reopen the thread by hand here.
           It is gone: loadDashboard() rebuilds the table and reopenUpdateThreads()
           puts the thread back with fresh contents, so doing it twice was both
           redundant and the reason it sometimes ended up collapsed — the manual
           reopen raced the rebuild that was about to destroy it. One mechanism. */

        /**
         * Say what marking an update external will actually do (#99).
         *
         * ⚠️ "External" alone is a half-truth while the portal switch is off —
         * the update is marked, and nobody outside sees it. Telling somebody
         * their message is going to customers when it is not is how a status
         * page ends up trusted for something it is not doing.
         */
        function syncVisibilityHint() {
            const hint = document.getElementById('incVisHint');
            if (!hint) return;
            const external = (document.querySelector('input[name="incVisibility"]:checked') || {}).value === 'external';
            if (!external) {
                hint.textContent = window.t('service-status.modal.vis_internal_hint');
                hint.className = 'inc-vis-hint';
                return;
            }
            hint.textContent = window.t(portalShowsUpdates
                ? 'service-status.modal.vis_external_hint'
                : 'service-status.modal.vis_external_hint_off');
            hint.className = 'inc-vis-hint' + (portalShowsUpdates ? ' is-live' : ' is-off');
        }

        /**
         * Put the visibility choice back to INTERNAL every time the dialog
         * opens, whether for a new incident or an existing one.
         *
         * ⚠️ It is not remembered from last time. Each update is its own
         * decision, and a sticky control would let somebody publish because of
         * a choice they made an hour ago on a different incident.
         */
        function resetVisibility() {
            const internal = document.querySelector('input[name="incVisibility"][value="internal"]');
            if (internal) internal.checked = true;
            syncVisibilityHint();
        }

        /**
         * Resolve an incident in one click (discussion #100, "Close Incident").
         *
         * ⚠️ Sends ONLY the id and the status. The service treats an update as a
         * partial one and keeps the current title, comment and affected services
         * for anything not supplied, so this cannot quietly blank the incident —
         * and it goes through exactly the same path as resolving it in the
         * modal, so resolved_datetime is stamped, an entry is written to the
         * update thread and the workflow event fires.
         */
        async function resolveIncident(id) {
            const resolved = incidentStatuses.find(s => s.is_resolved);
            if (!resolved) {
                // No resolved status is configured, so there is nothing to set.
                // Said plainly rather than failing silently or guessing a name.
                showToast(window.t('service-status.toast.no_resolved_status'), 'error');
                return;
            }

            const inc = dashboardData.incidents.find(i => i.id == id);
            const ok  = await showConfirm({
                title:   window.t('service-status.confirm.resolve_title'),
                message: window.t('service-status.confirm.resolve_message', {
                    title:  inc ? inc.title : '',
                    status: resolved.name
                }),
                okLabel: window.t('service-status.actions.resolve')
            });
            if (!ok) return;

            try {
                const response = await fetch(API_BASE + 'save_incident.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: parseInt(id), status: resolved.name })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('service-status.toast.incident_resolved'), 'success');
                    loadDashboard();
                } else {
                    showToast(data.error || window.t('service-status.toast.save_failed'), 'error');
                }
            } catch (error) {
                showToast(window.t('service-status.toast.save_failed'), 'error');
            }
        }

        /**
         * Toggle the update thread when we have an id but no button — from the
         * context menu.
         *
         * ⚠️ It finds the row's own toggle and drives THAT, rather than
         * duplicating the open/close logic. Otherwise the row's button would
         * still say "Show updates" while the updates were showing.
         */
        function toggleIncidentUpdatesById(id) {
            const row = document.getElementById('incUpdatesRow' + id);
            if (!row) return;
            const btn = row.previousElementSibling
                     && row.previousElementSibling.querySelector('.inc-updates-toggle');
            if (btn) { btn.click(); }
        }

        // ── Right-click an incident (discussion #100) ────────────────────────
        //
        // The same actions as the column, named rather than drawn. Reuses the
        // .ticket-context-menu classes from inbox.css, which this page already
        // loads — the names say "ticket" and the styles are structural.
        let ctxIncidentId = null;

        function openIncidentContextMenu(event, id) {
            // Never over a text selection or a link: the browser's own menu is
            // the right one when somebody is trying to copy something.
            if (window.getSelection && String(window.getSelection()).length) return true;
            event.preventDefault();

            ctxIncidentId = id;
            const inc  = dashboardData.incidents.find(i => i.id == id);
            const sts  = inc ? statusByName(inc.status) : null;
            const menu = document.getElementById('incidentContextMenu');

            document.getElementById('incidentCtxHeader').textContent = inc ? inc.title : '';

            // Resolving something already resolved is a no-op, so it is absent
            // rather than present and inert.
            const resolveItem = menu.querySelector('[data-action="resolve"]');
            resolveItem.style.display = (sts && sts.is_resolved) ? 'none' : '';

            // The updates row says whether it is currently open, so the item can
            // say Show or Hide rather than guessing.
            const row = document.getElementById('incUpdatesRow' + id);
            menu.querySelector('[data-action="updates"] span').textContent =
                window.t(row && !row.hidden ? 'service-status.board.updates_hide'
                                            : 'service-status.actions.show_updates');

            menu.classList.add('active');
            const w = menu.offsetWidth, h = menu.offsetHeight;
            menu.style.left = Math.max(8, Math.min(event.clientX, window.innerWidth  - w - 8)) + 'px';
            menu.style.top  = Math.max(8, Math.min(event.clientY, window.innerHeight - h - 8)) + 'px';
            return false;
        }

        function closeIncidentContextMenu() {
            const menu = document.getElementById('incidentContextMenu');
            if (menu) menu.classList.remove('active');
        }

        document.addEventListener('click', closeIncidentContextMenu);
        document.addEventListener('scroll', closeIncidentContextMenu, true);
        window.addEventListener('resize', closeIncidentContextMenu);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeIncidentContextMenu(); });

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }

        // Close modal on outside click
        document.getElementById('incidentModal').addEventListener('click', function(e) {
            if (e.target === this) closeIncidentModal();
        });
    </script>
    <script src="../assets/js/mobile.js?v=53"></script>
</body>
</html>
