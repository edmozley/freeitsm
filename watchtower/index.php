<?php
/**
 * Watchtower — Unified Attention Dashboard
 * Single pane of glass showing actionable items across all modules
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/timezone.php';
require_once '../includes/theme.php';
requireModuleAccess('watchtower');
I18n::initFromSession();
Tz::init();

$current_page = 'dashboard';
$path_prefix = '../';
$translationNamespaces = ['common', 'watchtower'];

// Whose work this analyst last chose to look at (#58). Read HERE rather than in
// JS so the first paint already has the right button lit — fetching it after
// the page draws means the toggle visibly jumps, which reads as a fault.
require_once '../includes/watchtower_settings.php';
$wtScope = WT_SCOPE_ALL;
try {
    $wtScope = wtScopeFor(connectToDatabase(), (int)$_SESSION['analyst_id']);
} catch (Exception $e) {
    // 'all' stands — the widest view, and the one that hides nothing.
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('watchtower.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        /* Pin the shared accent to the Watchtower slate so inbox.css components
           (modals, buttons, tabs) pick up the module colour. */
        body { --accent: var(--wt-accent, #1e293b); }

        /* ── Watchtower Layout ──────────────────────────────────────────────── */
        .wt-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            background: var(--app-bg, #f0f2f5);
            padding: 24px;
        }
        .wt-top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }
        .wt-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text, #1e293b);
        }
        .wt-refresh-info {
            font-size: 12px;
            color: var(--text-faint, #94a3b8);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        /* Whose work (#58). A segmented control rather than three loose
           buttons, so it reads as one question with one answer. */
        .wt-scope-toggle {
            display: inline-flex;
            border: 1px solid var(--border, #cbd5e1);
            border-radius: 6px;
            overflow: hidden;
            margin-left: auto;
            margin-right: 12px;
        }
        .wt-scope-btn {
            background: none;
            border: none;
            border-right: 1px solid var(--border, #cbd5e1);
            padding: 5px 12px;
            font-size: 12px;
            color: var(--text-muted, #64748b);
            cursor: pointer;
            white-space: nowrap;
        }
        .wt-scope-btn:last-child { border-right: none; }
        .wt-scope-btn:hover { background: var(--surface-hover, #f8fafc); }
        .wt-scope-btn.active {
            background: var(--wt-accent, #0f766e);
            color: var(--wt-on-accent, #fff);
        }
        /* A card the scope cannot narrow, while a narrowed scope is showing.
           It says so rather than letting a team-wide number read as a personal
           one — see the note beside it in the markup. */
        .wt-card.wt-everyone .wt-card-name::after {
            content: attr(data-everyone-label);
            margin-left: 8px;
            font-size: 10px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--text-faint, #94a3b8);
        }

        .wt-refresh-btn {
            background: none;
            border: 1px solid var(--border, #cbd5e1);
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 12px;
            color: var(--text-muted, #64748b);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .wt-refresh-btn:hover { background: #f8fafc; border-color: #94a3b8; }
        .wt-refresh-btn.spinning svg { animation: wt-spin 0.8s linear infinite; }
        @keyframes wt-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* ── Card Grid ──────────────────────────────────────────────────────── */
        .wt-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        @media (max-width: 1100px) { .wt-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 700px) { .wt-grid { grid-template-columns: 1fr; } }

        /* ── Attention Card ─────────────────────────────────────────────────── */
        .wt-card {
            background: var(--surface, #fff);
            border-radius: 10px;
            border: 1px solid var(--border, #e2e8f0);
            overflow: hidden;
            transition: box-shadow 0.15s;
        }
        .wt-card:hover { box-shadow: 0 2px 12px var(--shadow, rgba(0,0,0,0.08)); }
        .wt-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px 10px;
            border-bottom: 1px solid var(--border-soft, #f1f5f9);
        }
        .wt-card-header-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .wt-card-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .wt-card-icon svg { width: 18px; height: 18px; stroke: #fff; stroke-width: 2; fill: none; }
        .wt-card-name {
            font-size: 14px;
            font-weight: 600;
            color: var(--text, #334155);
        }
        .wt-card-name a {
            color: inherit;
            text-decoration: none;
        }
        .wt-card-name a:hover { text-decoration: underline; }
        .wt-status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .wt-status-dot.green  { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.2); }
        .wt-status-dot.amber  { background: #f59e0b; box-shadow: 0 0 0 3px rgba(245,158,11,0.2); }
        .wt-status-dot.red    { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.2); }

        .wt-card-body {
            padding: 12px 16px 16px;
            min-height: 80px;
        }
        .wt-card-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 80px;
            color: var(--text-faint, #94a3b8);
            font-size: 13px;
        }
        .wt-card-loading .wt-spinner {
            width: 20px;
            height: 20px;
            border: 2px solid var(--border, #e2e8f0);
            border-top-color: var(--text-muted, #64748b);
            border-radius: 50%;
            animation: wt-spin 0.6s linear infinite;
        }

        /* ── Metrics ────────────────────────────────────────────────────────── */
        .wt-metrics {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .wt-metric {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 52px;
        }
        .wt-metric-value {
            font-size: 22px;
            font-weight: 700;
            line-height: 1.1;
        }
        .wt-metric-label {
            font-size: 11px;
            color: var(--text-faint, #94a3b8);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 2px;
        }

        /* ── Attention Items ────────────────────────────────────────────────── */
        .wt-attention {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .wt-attention-item {
            display: flex;
            /* Not `center`: these rows wrap onto two lines whenever the text is
               long ("12 tickets paused over 24h (SLA clock stopped)"), and
               centring put the dot halfway between the two lines, belonging to
               neither. It aligns to the FIRST line, like a bullet. */
            align-items: flex-start;
            gap: 8px;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 13px;
            line-height: 1.45;
            color: var(--text, #334155);
        }
        /* Severity washes = DATA (red/amber/green signalling). Light values kept
           verbatim; the dark equivalents live in the override block below. */
        .wt-attention-item.red    { background: #fef2f2; color: #991b1b; }
        .wt-attention-item.amber  { background: #fffbeb; color: #92400e; }
        .wt-attention-item.green  { background: #f0fdf4; color: #166534; }
        .wt-attention-item.blue   { background: #eff6ff; color: #1e40af; }
        .wt-attention-item.neutral { background: #f8fafc; color: #475569; }
        .wt-attention-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
            /* Centres the 6px dot on the first line's box: (13px x 1.45 - 6) / 2
               = 6.4. A single-line row is therefore unchanged from the old
               `align-items: center`, so this only alters rows that wrap. */
            margin-top: 6px;
        }
        .wt-attention-item.red .wt-attention-dot    { background: #ef4444; }
        .wt-attention-item.amber .wt-attention-dot   { background: #f59e0b; }
        .wt-attention-item.green .wt-attention-dot   { background: #22c55e; }
        .wt-attention-item.blue .wt-attention-dot    { background: #3b82f6; }
        .wt-attention-item.neutral .wt-attention-dot { background: #94a3b8; }
        .wt-attention-bold { font-weight: 600; }
        /* Which priorities a combined count actually covered — quieter than the
           number itself, but present, so the line can never claim the wrong set. */
        .wt-attention-note { color: var(--text-dim, #94a3b8); font-size: 12px; }

        /* ── Event list ─────────────────────────────────────────────────────── */
        .wt-event-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-top: 6px;
        }
        .wt-event {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 5px 10px;
            background: #f8fafc;
            border-radius: 6px;
            font-size: 12px;
            color: var(--text-muted, #475569);
        }
        .wt-event-time {
            font-weight: 600;
            color: var(--text, #334155);
            white-space: nowrap;
            min-width: 50px;
        }
        .wt-event-title {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ── Article list ───────────────────────────────────────────────────── */
        .wt-article-list {
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-top: 6px;
        }
        .wt-article {
            padding: 5px 10px;
            background: #f8fafc;
            border-radius: 6px;
            font-size: 12px;
            color: var(--text-muted, #475569);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* ── Service status items ───────────────────────────────────────────── */
        .wt-service-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 12px;
            margin-top: 4px;
        }
        .wt-service-item .wt-impact-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            white-space: nowrap;
        }
        /* Impact pills = DATA. The colour is now set inline from the impact level
           itself, so a renamed or newly added level is drawn in ITS colour rather
           than falling through to one of four styles picked by English name. This
           is the fallback for a level with no colour saved against it. */
        .wt-service-item .wt-impact-badge { background: var(--surface-2, #f1f5f9); color: var(--text, #334155); }

        /* ── All-clear banner ───────────────────────────────────────────────── */
        .wt-all-clear {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f0fdf4;
            border-radius: 6px;
            font-size: 13px;
            color: #166534;
            font-weight: 500;
        }

        /* Workflows card — the failing workflows, named, with what they said. */
        .wt-wf-item {
            padding: 7px 0;
            border-top: 1px solid var(--border-soft, #f0f0f0);
        }
        .wt-wf-name {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text, #334155);
        }
        .wt-wf-count {
            flex-shrink: 0;
            font-size: 11px;
            font-weight: 600;
            color: var(--danger-text, #b91c1c);
        }
        .wt-wf-err {
            margin-top: 2px;
            font-size: 11.5px;
            line-height: 1.45;
            color: var(--text-muted, #64748b);
            word-break: break-word;
        }

        /* ── Dark mode ──────────────────────────────────────────────────────────
           Watchtower is a health dashboard: the red/amber/green signal is DATA and
           must read the same in both modes. The saturated dots, metric colours and
           icon tiles are left alone. What CAN'T survive dark is the pale severity
           WASHES — near-white tints that would glow on a dark card — so each one is
           sunk to a dark-tinted equivalent of the same hue, with the text lifted. */
        [data-theme-mode="dark"] .wt-refresh-btn:hover { background: var(--surface-hover, #2a3039); border-color: #64748b; }

        [data-theme-mode="dark"] .wt-attention-item.red     { background: #3a1a1d; color: #fca5a5; }
        [data-theme-mode="dark"] .wt-attention-item.amber   { background: #3a2e12; color: #fcd34d; }
        [data-theme-mode="dark"] .wt-attention-item.green   { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .wt-attention-item.blue    { background: #1d3346; color: #93c5fd; }
        [data-theme-mode="dark"] .wt-attention-item.neutral { background: #22293a; color: #cbd5e1; }

        [data-theme-mode="dark"] .wt-event,
        [data-theme-mode="dark"] .wt-article { background: #22293a; }

        [data-theme-mode="dark"] .wt-all-clear { background: #16331f; color: #86efac; }

        /* The impact pill's colour now comes from the level itself and is applied
           inline, which reads on both grounds (the tint is the same hue as the
           text), so there is nothing left here to flip for dark mode. */
    </style>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=130">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wt-container">
        <div class="wt-top-bar">
            <div class="wt-title"><?php echo htmlspecialchars(t('watchtower.dashboard.heading')); ?></div>

            <?php /* Whose work (#58). Rendered server-side with the remembered
                     choice already active, so the first paint is the right set.
                     Painting "Everyone" and then swapping to "Mine" a moment
                     later reads as a bug — the tickets calendar learned that. */ ?>
            <div class="wt-scope-toggle" id="wtScopeToggle">
                <button class="wt-scope-btn<?php echo $wtScope === 'mine' ? ' active' : ''; ?>" data-scope="mine"><?php echo htmlspecialchars(t('watchtower.scope.mine')); ?></button>
                <button class="wt-scope-btn<?php echo $wtScope === 'team' ? ' active' : ''; ?>" data-scope="team"><?php echo htmlspecialchars(t('watchtower.scope.team')); ?></button>
                <button class="wt-scope-btn<?php echo $wtScope === 'all'  ? ' active' : ''; ?>" data-scope="all"><?php echo htmlspecialchars(t('watchtower.scope.all')); ?></button>
            </div>

            <div class="wt-refresh-info">
                <span id="wtLastRefresh"></span>
                <button class="wt-refresh-btn" id="wtRefreshBtn" onclick="loadDashboard()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="23 4 23 10 17 10"></polyline>
                        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
                    </svg>
                    <?php echo htmlspecialchars(t('watchtower.dashboard.refresh')); ?>
                </button>
            </div>
        </div>

        <div class="wt-grid">
            <!-- Morning Checks -->
            <div class="wt-card" id="wtMorningChecks">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#00acc1;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                        </div>
                        <div class="wt-card-name"><a href="../morning-checks/"><?php echo htmlspecialchars(t('watchtower.cards.morning_checks')); ?></a></div>
                    </div>
                    <div class="wt-status-dot" id="wtMcDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Tickets -->
            <div class="wt-card" id="wtTickets">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#0078d4;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                        </div>
                        <div class="wt-card-name"><a href="../tickets/"><?php echo htmlspecialchars(t('watchtower.cards.tickets')); ?></a></div>
                    </div>
                    <div class="wt-status-dot" id="wtTkDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Changes -->
            <div class="wt-card" id="wtChanges">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#00897b;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line></svg>
                        </div>
                        <div class="wt-card-name"><a href="../change-management/"><?php echo htmlspecialchars(t('watchtower.cards.changes')); ?></a></div>
                    </div>
                    <div class="wt-status-dot" id="wtChDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Calendar -->
            <div class="wt-card" id="wtCalendar">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#ef6c00;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                        </div>
                        <div class="wt-card-name"><a href="../calendar/"><?php echo htmlspecialchars(t('watchtower.cards.calendar')); ?></a></div>
                    </div>
                    <div class="wt-status-dot" id="wtCalDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Service Status -->
            <div class="wt-card" id="wtServiceStatus">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#10b981;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                        </div>
                        <div class="wt-card-name"><a href="../service-status/"><?php echo htmlspecialchars(t('watchtower.cards.service_status')); ?></a></div>
                    </div>
                    <div class="wt-status-dot" id="wtSsDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Contracts -->
            <div class="wt-card" id="wtContracts">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#f59e0b;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><line x1="12" y1="9" x2="8" y2="9"></line></svg>
                        </div>
                        <div class="wt-card-name"><a href="../contracts/"><?php echo htmlspecialchars(t('watchtower.cards.contracts')); ?></a></div>
                    </div>
                    <div class="wt-status-dot" id="wtCtDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Knowledge -->
            <div class="wt-card" id="wtKnowledge">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#8764b8;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                        </div>
                        <div class="wt-card-name"><a href="../knowledge/"><?php echo htmlspecialchars(t('watchtower.cards.knowledge')); ?></a></div>
                    </div>
                    <div class="wt-status-dot" id="wtKbDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Assets -->
            <div class="wt-card" id="wtAssets">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#107c10;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                        </div>
                        <div class="wt-card-name"><a href="../asset-management/"><?php echo htmlspecialchars(t('watchtower.cards.assets')); ?></a></div>
                    </div>
                    <div class="wt-status-dot" id="wtAsDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Tasks -->
            <div class="wt-card" id="wtTasks">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#7c3aed;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                        </div>
                        <div class="wt-card-name"><a href="../tasks/"><?php echo htmlspecialchars(t('watchtower.cards.tasks')); ?></a></div>
                    </div>
                    <div class="wt-status-dot" id="wtTasksDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>

            <!-- Workflows — hidden entirely if the engine's tables aren't there yet -->
            <div class="wt-card" id="wtWorkflows" style="display:none;">
                <div class="wt-card-header">
                    <div class="wt-card-header-left">
                        <div class="wt-card-icon" style="background:#f59e0b;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
                        </div>
                        <div class="wt-card-name"><a href="../workflow/"><?php echo htmlspecialchars(t('watchtower.cards.workflows')); ?></a></div>
                    </div>
                    <div class="wt-status-dot" id="wtWfDot"></div>
                </div>
                <div class="wt-card-body"><div class="wt-card-loading"><div class="wt-spinner"></div></div></div>
            </div>
        </div>
    </div>

    <script>
    let refreshTimer = null;

    function setDot(id, color) {
        const dot = document.getElementById(id);
        if (dot) { dot.className = 'wt-status-dot ' + color; }
    }

    function setBody(cardId, html) {
        const card = document.getElementById(cardId);
        if (card) { card.querySelector('.wt-card-body').innerHTML = html; }
    }

    // Calendar event start times are NAIVE wall-clock values (stored without a
    // zone, by design). Show them exactly as typed — parse literally, no tzOpts.
    function formatTime(dt) {
        if (!dt) return '';
        const d = window.parseNaiveDate(dt);
        return fmtNaiveTime(d);
    }

    function attentionItem(level, text) {
        return `<div class="wt-attention-item ${level}"><div class="wt-attention-dot"></div><span>${text}</span></div>`;
    }

    // Status and priority names are now read from the database and rendered as
    // labels, so they must be escaped — they are free text an admin types.
    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    // Colours come from the same rows and go straight into a style attribute.
    // Same guard as the inbox uses: a hex value or nothing at all.
    function safeColour(value) {
        const v = String(value || '').trim();
        return /^#[0-9a-fA-F]{3,8}$/.test(v) ? v : null;
    }

    // Card keys map to the element ids the page already uses.
    const WT_CARD_ELEMENTS = {
        morning_checks: 'wtMorningChecks', tickets: 'wtTickets', changes: 'wtChanges',
        calendar: 'wtCalendar', service_status: 'wtServiceStatus', contracts: 'wtContracts',
        knowledge: 'wtKnowledge', assets: 'wtAssets', tasks: 'wtTasks', workflows: 'wtWorkflows',
    };

    function applyCardVisibility(cards) {
        Object.entries(WT_CARD_ELEMENTS).forEach(([key, id]) => {
            const el = document.getElementById(id);
            if (el) el.style.display = (cards[key] === false) ? 'none' : '';
        });
    }

    // The colour passed in is DATA (green = done, amber = warning, red = fail) and
    // is used as given. The DEFAULT is chrome — plain text — so it must follow the
    // theme, or a neutral metric would be dark slate on a dark card.
    function metric(value, label, color) {
        return `<div class="wt-metric"><div class="wt-metric-value" style="color:${color || 'var(--text, #334155)'}">${value}</div><div class="wt-metric-label">${label}</div></div>`;
    }

    function renderMorningChecks(d) {
        const mc = d.morning_checks;
        const statuses = mc.statuses || [];          // now a LIST, in the admin's own order
        const done = mc.completed_today >= mc.total_checks && mc.total_checks > 0;
        let html = '';

        // Nothing marks a morning-check status as good or bad, so the strongest
        // honest claim is "every check is in the most favourable status you have
        // defined" — the first one in your own order. Anything else gets amber:
        // done, but with something in it worth a look. It used to go green off
        // 'Fail' and 'Warning' counts that were always zero (no status has ever
        // been called either), so it lit green on a morning when every check was red.
        // One number, worked out server-side, shared with the browser extension —
        // rather than each consumer deciding for itself what counts as trouble.
        const allBest = statuses.length > 0 && (mc.attention_count || 0) === 0;
        if (mc.not_started)        setDot('wtMcDot', 'red');
        else if (done && allBest)  setDot('wtMcDot', 'green');
        else                       setDot('wtMcDot', 'amber');

        html += '<div class="wt-metrics">';
        html += metric(mc.completed_today + '/' + mc.total_checks, window.t('watchtower.mc.metric_done'), done ? '#22c55e' : '#f59e0b');
        // One metric per status that exists, under the label and in the colour the
        // admin gave it — so a renamed or added status appears by itself.
        statuses.forEach(s => {
            html += metric(s.count, escapeHtml(s.label), s.count > 0 ? (safeColour(s.colour) || 'var(--text, #334155)') : '#94a3b8');
        });
        html += '</div>';

        html += '<div class="wt-attention">';
        if (mc.not_started) {
            html += attentionItem('red', '<span class="wt-attention-bold">' + window.t('watchtower.mc.not_started') + '</span>');
        } else if (mc.completed_today < mc.total_checks) {
            html += attentionItem('amber', window.t('watchtower.mc.pending', { count: mc.total_checks - mc.completed_today }));
        }
        // "All checks completed" — a fact. It used to say "all passing", which it
        // could not know and which was shown even when every check was red.
        if (done) {
            html += attentionItem(allBest ? 'green' : 'amber', window.t('watchtower.mc.all_completed'));
        }
        html += '</div>';

        setBody('wtMorningChecks', html);
    }

    function renderTickets(d) {
        const tk = d.tickets;
        // Every open status counts towards the total. This used to add up the three
        // called Open, In Progress and On Hold, so tickets sitting in any other open
        // status — Awaiting Response ships with FreeITSM — were missing from the
        // headline figure entirely, in every language.
        const byStatus = tk.by_status || [];
        const totalOpen = tk.total_open || 0;
        const pausedTooLong = tk.paused_too_long || 0;
        const pausedThreshold = tk.paused_threshold_hours || 24;

        if (tk.urgent_high > 0 || tk.unassigned > 0 || pausedTooLong > 0) {
            setDot('wtTkDot', tk.urgent_high > 0 ? 'red' : 'amber');
        } else {
            setDot('wtTkDot', 'green');
        }

        let html = '<div class="wt-metrics">';
        html += metric(totalOpen, window.t('watchtower.tickets.metric_open'), 'var(--text, #334155)');
        // One per open status, under its own name and colour, in the order they are
        // arranged in Tickets → Settings. Names come from the database, so they are
        // already in the reader's language and cannot drift out of date.
        byStatus.forEach(s => {
            html += metric(s.count, escapeHtml(s.name), s.count > 0 ? (safeColour(s.colour) || 'var(--text, #334155)') : '#94a3b8');
        });
        html += '</div>';

        html += '<div class="wt-attention">';
        if (tk.urgent_high > 0) {
            // The label names the priorities it actually counted, from the database,
            // rather than saying "urgent/high" for ever. That wording was baked into
            // a translated string, so it would have quietly become untrue the moment
            // anybody renamed a priority or chose a different set in settings.
            const names = (tk.high_priority_names || []).map(escapeHtml).join(', ');
            html += attentionItem('red', window.t('watchtower.tickets.urgent_high', { count: tk.urgent_high })
                + (names ? ' <span class="wt-attention-note">(' + names + ')</span>' : ''));
        }
        if (tk.unassigned > 0) {
            html += attentionItem('amber', window.t('watchtower.tickets.unassigned', { count: tk.unassigned }));
        }
        if (pausedTooLong > 0) {
            const key = pausedTooLong === 1 ? 'watchtower.tickets.paused_one' : 'watchtower.tickets.paused_many';
            html += attentionItem('amber', window.t(key, { count: pausedTooLong, hours: pausedThreshold }));
        }
        if (tk.urgent_high === 0 && tk.unassigned === 0 && pausedTooLong === 0) {
            html += attentionItem('green', window.t('watchtower.tickets.all_clear'));
        }
        html += '</div>';

        setBody('wtTickets', html);
    }

    function renderChanges(d) {
        const ch = d.changes;

        if (ch.unapproved > 0) {
            setDot('wtChDot', 'amber');
        } else {
            setDot('wtChDot', 'green');
        }

        // Open changes by status, matching the Tickets and Tasks cards. The three
        // figures that used to sit here — next 7 days, active, pending — are
        // repeated verbatim in the attention list below, so the metric row was
        // saying the same thing twice and never answered "how many are at
        // Submitted?". Those three keep their place underneath.
        let html = '<div class="wt-metrics">';
        html += metric(ch.total_open || 0, window.t('watchtower.changes.metric_open'), 'var(--text, #334155)');
        (ch.by_status || []).forEach(s => {
            html += metric(s.count, escapeHtml(s.name), s.count > 0 ? (safeColour(s.colour) || 'var(--text, #334155)') : '#94a3b8');
        });
        html += '</div>';

        html += '<div class="wt-attention">';
        if (ch.unapproved > 0) {
            html += attentionItem('amber', window.t('watchtower.changes.awaiting', { count: ch.unapproved }));
        }
        if (ch.in_progress_today > 0) {
            html += attentionItem('blue', window.t('watchtower.changes.in_progress', { count: ch.in_progress_today }));
        }
        // The change whose window closed and which is still sitting open. Counted
        // in the status breakdown, excluded from the line above, and until now
        // visible in neither.
        if (ch.overrunning > 0) {
            html += attentionItem('amber', window.t('watchtower.changes.overrunning', { count: ch.overrunning }));
        }
        if (ch.upcoming_7d > 0) {
            html += attentionItem('neutral', window.t('watchtower.changes.scheduled', { count: ch.upcoming_7d }));
        }
        if (ch.unapproved === 0 && ch.in_progress_today === 0 && ch.upcoming_7d === 0 && !ch.overrunning) {
            html += attentionItem('green', window.t('watchtower.changes.all_clear'));
        }
        html += '</div>';

        setBody('wtChanges', html);
    }

    function renderCalendar(d) {
        const cal = d.calendar;

        setDot('wtCalDot', cal.today_count > 0 ? 'amber' : 'green');

        let html = '<div class="wt-metrics">';
        html += metric(cal.today_count, window.t('watchtower.calendar.metric_today'), cal.today_count > 0 ? '#ef6c00' : '#94a3b8');
        html += metric(cal.week_count, window.t('watchtower.calendar.metric_week'), 'var(--text, #334155)');
        html += '</div>';

        if (cal.today_events && cal.today_events.length > 0) {
            html += '<div class="wt-event-list">';
            cal.today_events.forEach(function(ev) {
                const time = ev.all_day == 1 ? window.t('watchtower.calendar.all_day') : formatTime(ev.start_datetime);
                html += `<div class="wt-event"><span class="wt-event-time">${time}</span><span class="wt-event-title">${ev.title}</span></div>`;
            });
            html += '</div>';
        } else {
            html += '<div class="wt-attention">' + attentionItem('green', window.t('watchtower.calendar.no_events')) + '</div>';
        }

        setBody('wtCalendar', html);
    }

    function renderServiceStatus(d) {
        const ss = d.service_status;

        if (ss.all_operational) {
            setDot('wtSsDot', 'green');
        } else {
            // Worked out server-side from the levels you have marked as serious
            // (Watchtower → Settings → Counts), defaulting to those that count as
            // downtime. It used to test the level's NAME against 'Major Outage'
            // and 'Partial Outage', so renaming or translating either one quietly
            // downgraded a total outage to amber.
            const serious = ss.degraded_services.some(s => s.is_serious);
            setDot('wtSsDot', serious ? 'red' : 'amber');
        }

        let html = '';
        if (ss.all_operational) {
            html += '<div class="wt-all-clear"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>' + window.t('watchtower.service.all_operational') + '</div>';
        } else {
            html += '<div class="wt-attention">';
            if (ss.active_incidents > 0) {
                html += attentionItem('red', window.t('watchtower.service.active_incidents', { count: ss.active_incidents }));
            }
            html += '</div>';

            // The badge wears the colour set against that impact level, rather
            // than one of four styles chosen by matching its English name — which
            // left every renamed or newly added level looking identically mild.
            // Service and level names are admin-typed free text, so both are
            // escaped; they were being written into the page raw.
            ss.degraded_services.forEach(function(svc) {
                const c = safeColour(svc.colour);
                const style = c ? ` style="background:${c}20;color:${c};"` : '';
                html += `<div class="wt-service-item"><span>${escapeHtml(svc.name)}</span>`
                      + `<span class="wt-impact-badge"${style}>${escapeHtml(svc.current_status)}</span></div>`;
            });
        }

        setBody('wtServiceStatus', html);
    }

    function renderContracts(d) {
        const ct = d.contracts;

        if (ct.expiring_30d > 0) {
            setDot('wtCtDot', 'red');
        } else if (ct.expiring_90d > 0 || ct.notice_periods_30d > 0) {
            setDot('wtCtDot', 'amber');
        } else {
            setDot('wtCtDot', 'green');
        }

        let html = '<div class="wt-metrics">';
        html += metric(ct.expiring_30d, window.t('watchtower.contracts.metric_30d'), ct.expiring_30d > 0 ? '#ef4444' : '#94a3b8');
        html += metric(ct.expiring_90d, window.t('watchtower.contracts.metric_90d'), ct.expiring_90d > 0 ? '#f59e0b' : '#94a3b8');
        html += metric(ct.notice_periods_30d, window.t('watchtower.contracts.metric_notices'), ct.notice_periods_30d > 0 ? '#f59e0b' : '#94a3b8');
        html += '</div>';

        html += '<div class="wt-attention">';
        if (ct.expiring_30d > 0) {
            html += attentionItem('red', window.t('watchtower.contracts.expiring', { count: ct.expiring_30d }));
        }
        if (ct.notice_periods_30d > 0) {
            html += attentionItem('amber', window.t('watchtower.contracts.notices', { count: ct.notice_periods_30d }));
        }
        if (ct.expiring_30d === 0 && ct.expiring_90d === 0 && ct.notice_periods_30d === 0) {
            html += attentionItem('green', window.t('watchtower.contracts.all_clear'));
        }
        html += '</div>';

        setBody('wtContracts', html);
    }

    function renderKnowledge(d) {
        const kb = d.knowledge;

        if (kb.overdue_reviews > 0) {
            setDot('wtKbDot', 'amber');
        } else {
            setDot('wtKbDot', 'green');
        }

        let html = '<div class="wt-attention">';
        if (kb.overdue_reviews > 0) {
            html += attentionItem('amber', window.t('watchtower.knowledge.overdue', { count: kb.overdue_reviews }));
        }
        html += '</div>';

        if (kb.recent_articles && kb.recent_articles.length > 0) {
            html += '<div style="font-size:11px;color:var(--text-faint, #94a3b8);margin-top:8px;text-transform:uppercase;letter-spacing:0.3px;">' + window.t('watchtower.knowledge.published_week') + '</div>';
            html += '<div class="wt-article-list">';
            kb.recent_articles.forEach(function(art) {
                html += `<div class="wt-article">${art.title}</div>`;
            });
            html += '</div>';
        } else {
            if (kb.overdue_reviews === 0) {
                html += '<div class="wt-attention">' + attentionItem('green', window.t('watchtower.knowledge.up_to_date')) + '</div>';
            }
        }

        setBody('wtKnowledge', html);
    }

    function renderAssets(d) {
        const as = d.assets;

        const warrantyAlert = as.warranty_show && as.warranty_soon > 0;
        if (warrantyAlert) {
            setDot('wtAsDot', 'red');
        } else if (as.not_seen_7d > 0) {
            setDot('wtAsDot', 'amber');
        } else {
            setDot('wtAsDot', 'green');
        }

        let html = '<div class="wt-metrics">';
        html += metric(as.total, window.t('watchtower.assets.metric_total'), 'var(--text, #334155)');
        html += metric(as.not_seen_7d, window.t('watchtower.assets.metric_offline'), as.not_seen_7d > 0 ? '#f59e0b' : '#94a3b8');
        if (as.warranty_show) {
            html += metric(as.warranty_soon, window.t('watchtower.assets.metric_warranty'), as.warranty_soon > 0 ? '#d13438' : '#94a3b8');
        }
        html += '</div>';

        html += '<div class="wt-attention">';
        if (warrantyAlert) {
            html += attentionItem('red', window.t('watchtower.assets.warranty', { count: as.warranty_soon, days: as.warranty_days }));
        }
        if (as.not_seen_7d > 0) {
            html += attentionItem('amber', window.t('watchtower.assets.offline', { count: as.not_seen_7d }));
        } else if (!warrantyAlert) {
            html += attentionItem('green', window.t('watchtower.assets.all_active'));
        }
        html += '</div>';

        setBody('wtAssets', html);
    }

    /**
     * Workflows.
     *
     * The engine deliberately SWALLOWS its own errors — a broken workflow must
     * never break the ticket save that triggered it. Correct, and it means a
     * failing workflow is completely silent. Nothing tells you. This card is the
     * something that tells you.
     *
     * Dead-lettered webhooks are counted here too: the workflow itself
     * "succeeded" (it queued the send), so a message that never arrived would
     * otherwise show up nowhere at all.
     */
    function renderWorkflows(d) {
        const wf = d.workflows;
        const card = document.getElementById('wtWorkflows');
        // This card manages its own visibility (it is hidden entirely when the
        // Workflows module isn't in use), so it has to honour the settings choice
        // itself — applyCardVisibility runs before this and would be overruled.
        const hiddenBySetting = (d.cards || {}).workflows === false;
        if (!wf || !wf.available || hiddenBySetting) { card.style.display = 'none'; return; }
        card.style.display = '';

        const esc = (s) => { const x = document.createElement('div'); x.textContent = s == null ? '' : String(s); return x.innerHTML; };
        const broken = wf.failed_24h + wf.aborted_24h;

        setDot('wtWfDot', broken > 0 ? 'red' : (wf.dead_webhooks > 0 ? 'amber' : 'green'));

        let html = '';
        if (wf.all_clear) {
            html += '<div class="wt-all-clear"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>'
                  + window.t('watchtower.workflows.all_clear') + '</div>';
            setBody('wtWorkflows', html);
            return;
        }

        html += '<div class="wt-attention">';
        if (wf.failed_24h > 0) {
            html += attentionItem('red', '<a href="../workflow/executions.php?status=failed" style="color:inherit;">'
                  + window.t('watchtower.workflows.failed', { count: wf.failed_24h }) + '</a>');
        }
        if (wf.aborted_24h > 0) {
            html += attentionItem('red', '<a href="../workflow/executions.php?status=aborted" style="color:inherit;">'
                  + window.t('watchtower.workflows.aborted', { count: wf.aborted_24h }) + '</a>');
        }
        if (wf.dead_webhooks > 0) {
            html += attentionItem('amber', '<a href="../system/webhooks/" style="color:inherit;">'
                  + window.t('watchtower.workflows.dead_webhooks', { count: wf.dead_webhooks }) + '</a>');
        }
        html += '</div>';

        // Name names, and show what the failure actually said — a bare count sends
        // you hunting; the error message often answers the question on the spot.
        wf.worst.forEach(function (w) {
            html += '<div class="wt-wf-item">'
                  + '<div class="wt-wf-name">' + esc(w.name)
                  + '<span class="wt-wf-count">' + window.t('watchtower.workflows.failures', { count: w.failures }) + '</span></div>'
                  + (w.last_error ? '<div class="wt-wf-err">' + esc(String(w.last_error).slice(0, 110)) + '</div>' : '')
                  + '</div>';
        });

        setBody('wtWorkflows', html);
    }

    function renderTasks(d) {
        if (!d.tasks) return;
        const t = d.tasks;

        if (t.overdue > 0) {
            setDot('wtTasksDot', 'red');
        } else if (t.due_today > 0) {
            setDot('wtTasksDot', 'amber');
        } else {
            setDot('wtTasksDot', 'green');
        }

        // Was two metrics counting the statuses called 'To Do' and 'In Progress',
        // which left anything in another open status (Blocked ships as standard)
        // off the card. Now one per open status, in its own name and colour.
        let html = '<div class="wt-metrics">';
        html += metric(t.total_open || 0, window.t('watchtower.tasks.metric_open'), 'var(--text, #334155)');
        (t.by_status || []).forEach(s => {
            html += metric(s.count, escapeHtml(s.name), s.count > 0 ? (safeColour(s.colour) || 'var(--text, #334155)') : '#94a3b8');
        });
        html += '</div>';

        html += '<div class="wt-attention">';
        if (t.overdue > 0) {
            html += attentionItem('red', window.t('watchtower.tasks.overdue', { count: t.overdue }));
        }
        if (t.due_today > 0) {
            html += attentionItem('amber', window.t('watchtower.tasks.due_today', { count: t.due_today }));
        }
        if (t.overdue === 0 && t.due_today === 0) {
            html += attentionItem('green', window.t('watchtower.tasks.all_clear'));
        }
        html += '</div>';

        setBody('wtTasks', html);
    }

    // ── Whose work (#58) ────────────────────────────────────────────────────
    //
    // Seeded from the server so the lit button matches the data on first paint.
    let wtScope = <?php echo json_encode($wtScope); ?>;

    /**
     * Mark the cards the scope cannot narrow, so a team-wide number is never
     * read as a personal one.
     *
     * ⚠️ The list comes from the SERVER, not from a copy here. Which cards have
     * an owner is a fact about the data model, and a second copy in JavaScript
     * would drift the first time one gained an owner column.
     */
    function markImpersonalCards(d) {
        const impersonal = d.impersonal_cards || [];
        const narrowed   = (d.scope || 'all') !== 'all';
        const hide       = narrowed && (d.impersonal_mode === 'hide');
        const label      = window.t('watchtower.scope.everyone_tag');

        Object.entries(WT_CARD_ELEMENTS).forEach(([key, id]) => {
            const el = document.getElementById(id);
            if (!el) return;
            const isImpersonal = impersonal.indexOf(key) !== -1;
            el.classList.toggle('wt-everyone', isImpersonal && narrowed && !hide);
            const name = el.querySelector('.wt-card-name');
            if (name) name.setAttribute('data-everyone-label', label);
            // Hiding is the analyst's own choice; the default keeps them visible
            // because a degraded service is the last thing that should vanish.
            // ⚠️ ONLY EVER HIDES. Un-hiding here would resurrect a card the
            // settings screen turned off, or the workflows card on an install
            // with no workflows - neither of which is this function's business.
            if (isImpersonal && hide) el.style.display = 'none';
        });
    }

    (function initScopeToggle() {
        const root = document.getElementById('wtScopeToggle');
        if (!root) return;
        root.addEventListener('click', function (e) {
            const btn = e.target.closest('.wt-scope-btn');
            if (!btn || btn.dataset.scope === wtScope) return;
            wtScope = btn.dataset.scope;
            root.querySelectorAll('.wt-scope-btn').forEach(b =>
                b.classList.toggle('active', b.dataset.scope === wtScope));
            loadDashboard();
            // Remembered in the background: the redraw must not wait on it.
            fetch('../api/system/set_user_preference.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ key: 'watchtower_scope', value: wtScope })
            }).catch(() => { /* the view is right either way; only the memory is lost */ });
        });
    })();

    function loadDashboard() {
        const btn = document.getElementById('wtRefreshBtn');
        btn.classList.add('spinning');

        fetch('../api/watchtower/get_dashboard.php?scope=' + encodeURIComponent(wtScope))
            .then(r => r.json())
            .then(d => {
                if (!d.success) {
                    console.error('Watchtower API error:', d.error);
                    return;
                }

                // Cards turned off in Watchtower → Settings. Absent or true = shown,
                // so an installation that has never opened that screen sees exactly
                // what it saw before.
                applyCardVisibility(d.cards || {});

                renderMorningChecks(d);
                renderTickets(d);
                renderChanges(d);
                renderCalendar(d);
                renderServiceStatus(d);
                renderContracts(d);
                renderKnowledge(d);
                renderAssets(d);
                renderTasks(d);
                renderWorkflows(d);

                // 🔴 LAST. applyCardVisibility() and renderWorkflows() both set
                // display themselves, so running this any earlier would have its
                // decision quietly overwritten a few lines later.
                markImpersonalCards(d);

                // Update timestamp
                const now = new Date();
                document.getElementById('wtLastRefresh').textContent =
                    window.t('watchtower.dashboard.updated', { time: window.fmtTime(now) });
            })
            .catch(err => {
                console.error('Watchtower fetch error:', err);
            })
            .finally(() => {
                btn.classList.remove('spinning');
            });
    }

    // Initial load
    loadDashboard();

    // Auto-refresh every 5 minutes
    refreshTimer = setInterval(loadDashboard, 5 * 60 * 1000);
    </script>
    <script src="../assets/js/mobile.js?v=53"></script>
</body>
</html>
