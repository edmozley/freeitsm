<?php
/**
 * System - Date and time formats (GH #105)
 *
 * The install-wide DEFAULT date and time format. Analysts who want something
 * else set their own in System > Preferences; this is what everyone who has not
 * chosen sees, and - because portal users are not analysts and have no
 * preferences - it is the only setting the self-service portal reads.
 *
 * Format is deliberately NOT tied to the interface language: inside one country
 * people disagree about 25/08/2026 vs 25.08.2026 vs 25 Aug 2026. It is also
 * separate from the timezone, which decides WHICH INSTANT is shown rather than
 * how it is written down.
 *
 * The current values are rendered SERVER-SIDE into the checked radio, not
 * fetched after load. A page that loads its state over the wire shows a
 * plausible-looking default when the fetch fails, and Save then writes that
 * guess back as fact - the exact trap documented in system/security/index.php.
 * Rendering server-side means the control cannot disagree with the database.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
Tz::init();

$current_page = 'date-formats';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

// Read the stored install defaults. A missing row means "never set", which the
// resolver treats as the built-in default - so that is what we pre-select.
$storedDate = DateFmt::DEFAULT_DATE;
$storedTime = DateFmt::DEFAULT_TIME;
try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare(
        "SELECT setting_key, setting_value FROM system_settings
         WHERE setting_key IN ('date_format','time_format')"
    );
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['setting_value'] === null || $row['setting_value'] === '') continue;
        if ($row['setting_key'] === 'date_format' && isset(DateFmt::DATE_TEMPLATES[$row['setting_value']])) {
            $storedDate = $row['setting_value'];
        }
        if ($row['setting_key'] === 'time_format' && isset(DateFmt::TIME_TEMPLATES[$row['setting_value']])) {
            $storedTime = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    // Built-in defaults stand; the radios still show a truthful "not configured".
}

// Every example is rendered through DateFmt itself, so what an administrator
// reads on this page is produced by the same code that will render the app.
// A sample instant, not now(): a single-digit day and an afternoon time are what
// actually distinguish the formats from each other.
$sample = new DateTime('2026-08-05 14:30:00', new DateTimeZone(Tz::current()));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.dateformat.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <style>
        body {
            /* System's DARK accent is a LIGHT colour, so --on-accent must be pinned
               alongside --accent or the primary button gets white-on-pale text.
               Same note as the other System pages. */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        .df-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            padding: 30px 20px;
        }

        .page-title    { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 24px 0; max-width: 720px; line-height: 1.6; }

        .settings-card {
            background: var(--surface, #fff);
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08));
            margin-bottom: 24px;
            max-width: 720px;
        }

        .settings-card h3 { font-size: 15px; font-weight: 600; color: var(--text, #333); margin: 0 0 4px 0; }
        .settings-card .card-desc { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 18px 0; line-height: 1.5; }

        /* The example IS the label - a rendered '25.08.2026' explains the choice
           better than any name we could give it, so the name is the small print. */
        .fmt-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 8px; }

        .fmt-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid var(--border, #ddd);
            border-radius: 6px;
            cursor: pointer;
            transition: border-color .12s ease, background .12s ease;
        }
        .fmt-option:hover { background: var(--surface-2, #f7f8fa); }
        .fmt-option input { margin: 0; cursor: pointer; flex-shrink: 0; }
        .fmt-option.is-selected { border-color: var(--sys-accent, #546e7a); background: var(--surface-2, #f7f8fa); }

        .fmt-example {
            font-size: 14px;
            color: var(--text, #333);
            font-variant-numeric: tabular-nums;
        }

        /* The live preview: what a real timestamp will look like once saved. */
        .df-preview {
            display: flex;
            align-items: baseline;
            gap: 12px;
            flex-wrap: wrap;
            padding: 16px 18px;
            border-radius: 6px;
            background: var(--surface-2, #f7f8fa);
            border: 1px solid var(--border, #ddd);
        }
        .df-preview-label { font-size: 12px; color: var(--text-dim, #888); }
        .df-preview-value { font-size: 20px; font-weight: 600; color: var(--text, #333); font-variant-numeric: tabular-nums; }

        .info-note {
            font-size: 12px;
            color: var(--text-muted, #666);
            line-height: 1.6;
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 6px;
            background: var(--surface-2, #f7f8fa);
        }
        .info-note strong { color: var(--text, #333); }

        .df-actions { display: flex; align-items: center; gap: 14px; max-width: 720px; }

        [data-theme-mode="dark"] .btn-primary:hover { background: var(--sys-accent-hover, #b0bec5); }
        [data-theme-mode="dark"] .info-note,
        [data-theme-mode="dark"] .df-preview,
        [data-theme-mode="dark"] .fmt-option:hover,
        [data-theme-mode="dark"] .fmt-option.is-selected { background: var(--surface-2, #232830); }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=133">
</head>
<body data-mobile-module="system" data-mobile-page="date-formats">
    <?php include '../includes/header.php'; ?>

    <div class="df-container">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.dateformat.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.dateformat.subtitle')); ?></p>

        <form id="dateFormatForm">
            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.dateformat.date_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.dateformat.date_desc')); ?></p>
                <div class="fmt-list">
                    <?php foreach (DateFmt::DATE_TEMPLATES as $key => $tpl): ?>
                        <label class="fmt-option<?php echo $key === $storedDate ? ' is-selected' : ''; ?>">
                            <input type="radio" name="date_format" value="<?php echo htmlspecialchars($key); ?>"
                                   data-template="<?php echo htmlspecialchars($tpl); ?>"
                                   <?php echo $key === $storedDate ? 'checked' : ''; ?>>
                            <span class="fmt-example"><?php echo htmlspecialchars(DateFmt::render($sample, $tpl)); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.dateformat.time_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.dateformat.time_desc')); ?></p>
                <div class="fmt-list">
                    <?php foreach (DateFmt::TIME_TEMPLATES as $key => $tpl): ?>
                        <label class="fmt-option<?php echo $key === $storedTime ? ' is-selected' : ''; ?>">
                            <input type="radio" name="time_format" value="<?php echo htmlspecialchars($key); ?>"
                                   data-template="<?php echo htmlspecialchars($tpl); ?>"
                                   <?php echo $key === $storedTime ? 'checked' : ''; ?>>
                            <span class="fmt-example"><?php echo htmlspecialchars(DateFmt::render($sample, $tpl)); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="settings-card">
                <h3><?php echo htmlspecialchars(t('system.dateformat.preview_heading')); ?></h3>
                <p class="card-desc"><?php echo htmlspecialchars(t('system.dateformat.preview_desc')); ?></p>
                <div class="df-preview">
                    <span class="df-preview-label"><?php echo htmlspecialchars(t('system.dateformat.preview_label')); ?></span>
                    <span class="df-preview-value" id="dfPreview"><?php
                        echo htmlspecialchars(
                            DateFmt::render($sample, DateFmt::DATE_TEMPLATES[$storedDate])
                            . ' ' . DateFmt::render($sample, DateFmt::TIME_TEMPLATES[$storedTime])
                        );
                    ?></span>
                </div>
                <div class="info-note">
                    <strong><?php echo htmlspecialchars(t('system.dateformat.scope_heading')); ?></strong>
                    <?php echo htmlspecialchars(t('system.dateformat.scope_note')); ?>
                </div>
            </div>

            <div class="df-actions">
                <button type="submit" class="btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </form>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script>
    const API_BASE = '<?php echo $path_prefix; ?>api/settings/';

    // The same sample instant the server rendered, so the preview cannot drift
    // from the examples beside it. Naive: it is a wall-clock illustration, not a
    // stored UTC timestamp, so it must not be shifted by the display timezone.
    const SAMPLE = '2026-08-05 14:30:00';

    function selectedTemplate(name) {
        const el = document.querySelector('input[name="' + name + '"]:checked');
        return el ? el.getAttribute('data-template') : null;
    }

    function refreshPreview() {
        document.querySelectorAll('.fmt-option').forEach(function (opt) {
            opt.classList.toggle('is-selected', opt.querySelector('input').checked);
        });

        const dateTpl = selectedTemplate('date_format');
        const timeTpl = selectedTemplate('time_format');
        if (!dateTpl || !timeTpl) return;

        // Render through the app's own formatters rather than reimplementing the
        // templates here - if these two ever disagreed, the preview would be the
        // thing lying to the administrator.
        const saved = window.DATE_FORMAT;
        window.DATE_FORMAT = Object.assign({}, saved, { dateTemplate: dateTpl, timeTemplate: timeTpl });
        document.getElementById('dfPreview').textContent =
            window.fmtNaiveDate(SAMPLE) + ' ' + window.fmtNaiveTime(SAMPLE);
        window.DATE_FORMAT = saved;
    }

    document.querySelectorAll('input[name="date_format"], input[name="time_format"]')
        .forEach(function (el) { el.addEventListener('change', refreshPreview); });

    document.getElementById('dateFormatForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        const dateEl = document.querySelector('input[name="date_format"]:checked');
        const timeEl = document.querySelector('input[name="time_format"]:checked');
        if (!dateEl || !timeEl) return;

        btn.disabled = true;
        try {
            const resp = await fetch(API_BASE + 'save_system_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings: {
                    date_format: dateEl.value,
                    time_format: timeEl.value
                } })
            });
            const data = await resp.json();
            if (data.success) {
                showToast(window.t('system.dateformat.saved'), 'success');
            } else {
                showToast(window.t('system.dateformat.error', { error: data.error }), 'error');
            }
        } catch (err) {
            showToast(window.t('system.dateformat.save_failed'), 'error');
        }
        btn.disabled = false;
    });
    </script>
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
