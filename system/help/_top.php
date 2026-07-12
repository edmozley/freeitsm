<?php
/**
 * Shared chrome for a System help topic page (head + CSS + header + hero +
 * sidebar, then opens the content area). Expects $helpSlug — the page's key in
 * _registry.php — from which the hero, standfirst and sidebar nav are resolved.
 * A page can still set $helpHero / $helpSub / $helpNav itself to override.
 * Must be included at top-level scope (see _init.php). Pair with _bottom.php.
 */
$helpTopic = isset($helpSlug) ? getHelpTopic($helpSlug) : null;
$helpHero  = $helpHero ?? ($helpTopic['hero'] ?? 'System help');
$helpSub   = $helpSub  ?? ($helpTopic['sub'] ?? '');
$helpNav   = $helpNav  ?? ($helpTopic['sections'] ?? []);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Help — <?php echo htmlspecialchars($helpHero); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* Pin the shared --accent (header/inbox.css primitives) to the System accent. */
        body {
            /* System is the FIRST module whose DARK accent is a LIGHT colour (#90a4ae).
               inbox.css renders .btn-primary/.add-btn as background:var(--accent) +
               color:var(--on-accent) — and the global --on-accent stays WHITE in dark.
               So pinning --accent alone would put white text on a light button. Pin
               --on-accent too: it flips to near-black in dark. */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        .syshelp-container { display: flex; height: calc(100vh - 48px); background: var(--app-bg, #f5f6fa); }

        .syshelp-sidebar { width: 250px; background: var(--surface, #fff); border-right: 1px solid var(--border, #e5e7eb); padding: 20px; flex-shrink: 0; overflow-y: auto; }
        .syshelp-sidebar h3 { font-size: 11px; font-weight: 700; color: var(--text-dim, #9aa); text-transform: uppercase; letter-spacing: 0.6px; margin: 0 0 12px; }
        .syshelp-back { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: #6366f1; text-decoration: none; margin-bottom: 18px; }
        .syshelp-back:hover { text-decoration: underline; }
        .syshelp-nav-link { display: flex; align-items: center; gap: 10px; padding: 9px 11px; border-radius: 6px; font-size: 13px; color: var(--text-muted, #555); text-decoration: none; }
        .syshelp-nav-link:hover { background: var(--surface-hover, #f3f4f6); color: var(--text, #222); }
        .syshelp-nav-link.active { background: #eef2ff; color: #3730a3; font-weight: 600; }
        .syshelp-nav-num { display: flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; border-radius: 50%; background: var(--surface-3, #eee); color: var(--text-dim, #888); font-weight: 700; font-size: 11px; flex-shrink: 0; }
        .syshelp-nav-link.active .syshelp-nav-num { background: #6366f1; color: #fff; }

        .syshelp-main { flex: 1; overflow-y: auto; }
        .syshelp-hero { background: linear-gradient(135deg, #4f46e5 0%, #4338ca 50%, #3730a3 100%); color: #fff; padding: 40px 48px 34px; }
        .syshelp-hero h2 { margin: 0 0 8px; font-size: 25px; font-weight: 700; }
        .syshelp-hero p { margin: 0; font-size: 14.5px; opacity: 0.9; max-width: 760px; line-height: 1.5; }
        .syshelp-content { max-width: 900px; margin: 0 auto; padding: 8px 48px 56px; }

        .syshelp-section { padding: 26px 0; border-bottom: 1px solid var(--border-soft, #ececf1); scroll-margin-top: 20px; }
        .syshelp-section:last-child { border-bottom: none; }
        .syshelp-section-header { display: flex; align-items: flex-start; gap: 14px; margin-bottom: 14px; }
        .syshelp-section-header h3 { margin: 0; font-size: 18px; color: var(--text, #1f2330); }
        .syshelp-section-num { display: flex; align-items: center; justify-content: center; min-width: 30px; height: 30px; border-radius: 50%; background: #eef2ff; color: #3730a3; font-weight: 700; font-size: 13px; flex-shrink: 0; }
        .syshelp-section.highlight { background: #eef2ff; margin: 0 -48px; padding: 26px 48px; border-top: 2px solid #c7d2fe; border-bottom: none; }
        .syshelp-section.highlight .syshelp-section-num { background: #6366f1; color: #fff; }
        .syshelp-section p { font-size: 14px; color: var(--text-muted, #4b5563); line-height: 1.7; margin: 0 0 12px; }
        .syshelp-lead { font-size: 14.5px !important; color: var(--text, #374151) !important; }
        .syshelp-section ul, .syshelp-section ol { font-size: 14px; color: var(--text-muted, #4b5563); line-height: 1.7; margin: 0 0 12px; padding-left: 22px; }
        .syshelp-section li { margin-bottom: 6px; }
        .syshelp-section code { background: #eef2ff; color: #3730a3; padding: 2px 6px; border-radius: 4px; font-size: 0.88em; font-family: ui-monospace, Consolas, monospace; }
        .syshelp-section h4 { font-size: 14px; color: var(--text, #1f2330); margin: 18px 0 8px; }

        .syshelp-cards { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin: 6px 0 14px; }
        .syshelp-card { padding: 18px; border-radius: 10px; border: 1px solid var(--border, #e5e7eb); background: var(--surface, #fff); }
        .syshelp-card h4 { margin: 0 0 6px; font-size: 14.5px; color: var(--text, #1f2330); }
        .syshelp-card p { margin: 0; font-size: 13px; color: var(--text-muted, #6b7280); line-height: 1.55; }

        .syshelp-steps { display: flex; flex-direction: column; gap: 10px; margin: 8px 0 14px; }
        .syshelp-step { display: flex; align-items: flex-start; gap: 14px; padding: 12px 16px; border-radius: 8px; background: var(--surface-2, #fafafa); font-size: 14px; color: var(--text, #444); line-height: 1.55; }
        .syshelp-step-num { display: flex; align-items: center; justify-content: center; min-width: 26px; height: 26px; border-radius: 50%; background: #6366f1; color: #fff; font-weight: 700; font-size: 12px; flex-shrink: 0; }
        .syshelp-step strong { color: var(--text, #1f2330); }

        .syshelp-callout { font-size: 13.5px; line-height: 1.6; padding: 12px 16px; border-radius: 8px; margin: 12px 0; border-left: 3px solid #9ca3af; background: var(--surface-2, #f9fafb); color: var(--text, #374151); }
        .syshelp-callout.info { border-left-color: #6366f1; background: #eef2ff; color: #3730a3; }
        .syshelp-callout.warn { border-left-color: #f59e0b; background: #fffbeb; color: var(--warning-text, #92400e); }
        .syshelp-callout.ok   { border-left-color: #10b981; background: #ecfdf5; color: #065f46; }
        .syshelp-callout strong { color: inherit; }

        .syshelp-table { width: 100%; border-collapse: collapse; margin: 8px 0 14px; font-size: 13.5px; }
        .syshelp-table th, .syshelp-table td { text-align: left; padding: 9px 12px; border-bottom: 1px solid var(--border-soft, #ececf1); color: var(--text-muted, #4b5563); vertical-align: top; }
        .syshelp-table th { color: var(--text, #1f2330); font-weight: 600; background: var(--surface-2, #f9fafb); }
        .syshelp-table code { background: #eef2ff; color: #3730a3; padding: 1px 5px; border-radius: 4px; }

        /* ---- Dark mode: the indigo help chrome + pale washes ----
           NOTE: these help pages are styled indigo, NOT the System blue-grey
           (--sys-accent). Remapping them to --sys-accent would change LIGHT mode,
           so the indigo is kept and only re-tinted for dark. The indigo fills
           (nav pill number, step numbers, hero) stay saturated, so white text on
           them remains legible in dark — no --sys-on-accent needed here. */
        [data-theme-mode="dark"] .syshelp-back { color: #a5b4fc; }
        [data-theme-mode="dark"] .syshelp-nav-link.active { background: #2b2f4a; color: #c7d2fe; }
        [data-theme-mode="dark"] .syshelp-hero { filter: brightness(0.82); }
        [data-theme-mode="dark"] .syshelp-section-num { background: #2b2f4a; color: #c7d2fe; }
        [data-theme-mode="dark"] .syshelp-section.highlight { background: #232742; border-top-color: #3f4270; }
        [data-theme-mode="dark"] .syshelp-section code,
        [data-theme-mode="dark"] .syshelp-table code { background: #2b2f4a; color: #c7d2fe; }
        [data-theme-mode="dark"] .syshelp-callout { border-left-color: #6b7280; }
        [data-theme-mode="dark"] .syshelp-callout.info { background: #232742; color: #c7d2fe; }
        [data-theme-mode="dark"] .syshelp-callout.warn { background: var(--warning-bg, #3a2e12); }
        [data-theme-mode="dark"] .syshelp-callout.ok   { background: #16331f; color: #86efac; }

        @media (max-width: 900px) {
            .syshelp-sidebar { display: none; }
            .syshelp-content { padding: 8px 22px 44px; }
            .syshelp-hero { padding: 30px 22px; }
            .syshelp-section.highlight { margin: 0 -22px; padding: 22px; }
            .syshelp-cards { grid-template-columns: 1fr; }
        }
    </style>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="syshelp-container">
        <div class="syshelp-sidebar">
            <a href="index.php" class="syshelp-back">&larr; All system help</a>
            <h3>On this page</h3>
            <?php foreach ($helpNav as $i => $s): ?>
                <a href="#<?php echo htmlspecialchars($s['id']); ?>" class="syshelp-nav-link<?php echo $i === 0 ? ' active' : ''; ?>" data-section="<?php echo htmlspecialchars($s['id']); ?>">
                    <span class="syshelp-nav-num"><?php echo $i + 1; ?></span>
                    <?php echo htmlspecialchars($s['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="syshelp-main" id="helpMain">
            <div class="syshelp-hero">
                <h2><?php echo htmlspecialchars($helpHero); ?></h2>
                <p><?php echo htmlspecialchars($helpSub); ?></p>
            </div>
            <div class="syshelp-content">
