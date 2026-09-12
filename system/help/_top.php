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
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Help — <?php echo htmlspecialchars($helpHero); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=70">
    <link rel="stylesheet" href="../../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should say for itself: its colour.
           See Help Page House Style §2 — help.css draws the hero, the badges,
           the notes, the cards and the steps from these four variables.

           ⚠️ --on-accent is pinned as well, which most modules do not need to
           do. System is the one module whose DARK accent is a LIGHT colour
           (#90a4ae), and inbox.css renders .btn-primary/.add-btn as
           background:var(--accent) + color:var(--on-accent) while the global
           --on-accent stays WHITE in dark. Pinning --accent alone would put
           white text on a near-white button. --sys-on-accent flips to
           near-black in dark, which is what makes the header buttons legible. */
        body {
            --accent:       var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --accent-soft:  var(--sys-accent-soft, #eceff1);
            --on-accent:    var(--sys-on-accent, #fff);
        }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=139">
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
</head>
<body data-mobile-module="system" data-mobile-page="sys-help">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="help-container">
        <div class="help-sidebar">
            <a href="index.php" class="help-back">&larr; All system help</a>
            <h3>On this page</h3>
            <?php foreach ($helpNav as $i => $s): ?>
                <a href="#<?php echo htmlspecialchars($s['id']); ?>" class="help-nav-link<?php echo $i === 0 ? ' active' : ''; ?>" data-section="<?php echo htmlspecialchars($s['id']); ?>">
                    <span class="help-nav-num"><?php echo $i + 1; ?></span>
                    <?php echo htmlspecialchars($s['label']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="help-main" id="helpMain">
            <div class="help-hero">
                <h2><?php echo htmlspecialchars($helpHero); ?></h2>
                <p><?php echo htmlspecialchars($helpSub); ?></p>
            </div>
            <div class="help-content">
