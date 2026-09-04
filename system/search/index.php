<?php
/**
 * System — Search.
 *
 * What the search index holds, and a button to rebuild it (discussion #53).
 *
 * ⚠️ DELIBERATELY NOT the screen designed in the wiki's §8.4. That design has
 * two selectors — document extraction and search backend — and today each would
 * offer exactly one option, because there is no external extractor and no second
 * backend. A dropdown with one choice is furniture that implies a capability the
 * product does not have. They belong here when they have something to choose
 * between; the design's own rule is not to build phase 2 until an install needs
 * it.
 *
 * What IS worth a screen now is the status: until this existed the only way to
 * see the index was D007 under Debug Tools, and the only way to rebuild it was a
 * command line — which is no help at all on shared hosting.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
Tz::init();

$current_page = 'search';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

// Auth before any output, so a redirect never hits "headers already sent".
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'auth/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.search.heading')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <style>
        /* System module accent — same pinning as the other System screens, and
           for the same reason: System's dark accent is a LIGHT colour, so
           --on-accent has to be pinned alongside or buttons get white-on-light. */
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        /* Full width, edge to edge — the house style for settings screens.
           Nothing here caps the width: no max-width, and no `margin: auto`,
           which would silently re-centre the content inside a flex parent even
           with the cap removed. */
        .srch-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            width: 100%;
            max-width: none;
            margin: 0;
            box-sizing: border-box;
            padding: 24px 32px 40px;
        }
        .srch-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; gap: 16px; flex-wrap: wrap; }
        .srch-header h2 { margin: 0; font-size: 22px; color: var(--text, #333); }
        .srch-header p  { margin: 5px 0 0 0; font-size: 13px; color: var(--text-dim, #888); }

        /* The System button pair, same as the other System screens. inbox.css's
           .btn-primary sets only background and colour, so on its own it renders
           a coloured rectangle with no padding or radius. */
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover:not(:disabled) { background: #455a64; }
        .btn:disabled { opacity: .55; cursor: progress; }

        .srch-panel {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 18px;
        }
        .srch-panel h3 {
            margin: 0 0 4px 0; font-size: 13px; text-transform: uppercase;
            letter-spacing: .5px; color: var(--text-dim, #888);
        }

        .srch-figures { display: flex; gap: 32px; flex-wrap: wrap; margin: 14px 0 4px; }
        .srch-figure .n { font-size: 26px; font-weight: 700; color: var(--text, #222); line-height: 1.1; }
        .srch-figure .l { font-size: 12px; color: var(--text-dim, #888); }

        .srch-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px; }
        .srch-table th {
            text-align: left; padding: 7px 10px; font-size: 11px; text-transform: uppercase;
            letter-spacing: .4px; color: var(--text-dim, #888);
            border-bottom: 1px solid var(--border, #e5e7eb);
        }
        .srch-table td { padding: 7px 10px; border-bottom: 1px solid var(--border-soft, #f0f0f0); color: var(--text, #333); }
        .srch-table td.num { text-align: right; font-variant-numeric: tabular-nums; }

        .srch-note {
            margin-top: 14px; padding: 10px 12px; border-radius: 6px; font-size: 13px;
            background: var(--warning-bg, #fef3c7); color: var(--warning-text, #92400e);
            border: 1px solid var(--warning-border, #fcd34d);
        }
        .srch-note.ok { background: var(--success-bg, #ecfdf5); color: var(--success-text, #065f46); border-color: var(--success-border, #a7f3d0); }

        .srch-progress { height: 8px; background: var(--surface-3, #eceff1); border-radius: 999px; overflow: hidden; margin-top: 12px; display: none; }
        .srch-progress.on { display: block; }
        .srch-progress span { display: block; height: 100%; width: 0; background: var(--accent, #546e7a); transition: width .25s ease; }
        .srch-status { font-size: 12px; color: var(--text-dim, #888); margin-top: 8px; min-height: 16px; }
        .srch-muted { color: var(--text-faint, #9ca3af); }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=132">
</head>
<body data-mobile-module="system" data-mobile-page="search">
    <?php include '../includes/header.php'; ?>

    <div class="srch-container">
        <div class="srch-header">
            <div>
                <h2><?php echo htmlspecialchars(t('system.search.heading')); ?></h2>
                <p><?php echo htmlspecialchars(t('system.search.intro')); ?></p>
            </div>
            <button class="btn btn-primary" id="rebuildBtn" onclick="startRebuild()">
                <?php echo htmlspecialchars(t('system.search.rebuild')); ?>
            </button>
        </div>

        <div class="srch-panel">
            <h3><?php echo htmlspecialchars(t('system.search.index_heading')); ?></h3>
            <div id="statusBody" class="srch-muted"><?php echo htmlspecialchars(t('common.loading')); ?></div>
            <div class="srch-progress" id="progress"><span id="progressBar"></span></div>
            <div class="srch-status" id="rebuildStatus"></div>
        </div>
    </div>

    <script>
        var T = <?php echo json_encode([
            'never'        => t('system.search.never'),
            'rows'         => t('system.search.rows'),
            'tickets'      => t('system.search.tickets'),
            'articles'     => t('system.search.articles'),
            'last_indexed' => t('system.search.last_indexed'),
            'source'       => t('system.search.source'),
            'no_table'     => t('system.search.no_table'),
            'all_indexed'  => t('system.search.all_indexed'),
            'not_indexed'  => t('system.search.not_indexed'),
            'min_word'     => t('system.search.min_word'),
            'rebuilding'   => t('system.search.rebuilding'),
            'rebuilt'      => t('system.search.rebuilt'),
            'failed'       => t('system.search.failed'),
            'att_heading'  => t('system.search.att_heading'),
            'att_outcome'  => t('system.search.att_outcome'),
            'att_files'    => t('system.search.att_files'),
            'att_unsupported_note' => t('system.search.att_unsupported_note'),
            'prob_heading' => t('system.search.prob_heading'),
            'prob_intro'   => t('system.search.prob_intro'),
            'prob_file'    => t('system.search.prob_file'),
            'prob_ticket'  => t('system.search.prob_ticket'),
            'prob_why'     => t('system.search.prob_why'),
        ], JSON_UNESCAPED_UNICODE); ?>;

        var ATT_LABEL = <?php echo json_encode([
            'extracted'   => t('system.search.att_extracted'),
            'truncated'   => t('system.search.att_truncated'),
            'too_large'   => t('system.search.att_too_large'),
            'unsupported' => t('system.search.att_unsupported'),
            'failed'      => t('system.search.att_failed'),
            'pending'     => t('system.search.att_pending'),
            'extracting'  => t('system.search.att_extracting'),
        ], JSON_UNESCAPED_UNICODE); ?>;

        var SOURCE_LABEL = {
            ticket: <?php echo json_encode(t('system.search.src_ticket')); ?>,
            email: <?php echo json_encode(t('system.search.src_email')); ?>,
            note: <?php echo json_encode(t('system.search.src_note')); ?>,
            kb_article: <?php echo json_encode(t('system.search.src_article')); ?>,
            attachment: <?php echo json_encode(t('system.search.src_attachment')); ?>
        };

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = s == null ? '' : s;
            return d.innerHTML;
        }
        function n(v) { return (v || 0).toLocaleString(); }

        async function loadStatus() {
            var el = document.getElementById('statusBody');
            try {
                var r = await fetch('../../api/system/search_status.php');
                var d = await r.json();
                if (!d.success) throw new Error(d.error || 'failed');

                if (!d.ready) {
                    el.innerHTML = '<div class="srch-note">' + esc(T.no_table) + '</div>';
                    document.getElementById('rebuildBtn').disabled = true;
                    return;
                }

                var missingT = Math.max(0, d.tickets_total - d.tickets_indexed);
                var missingA = Math.max(0, d.articles_total - d.articles_indexed);

                var html =
                    '<div class="srch-figures">' +
                        '<div class="srch-figure"><div class="n">' + n(d.total_rows) + '</div><div class="l">' + esc(T.rows) + '</div></div>' +
                        '<div class="srch-figure"><div class="n">' + n(d.tickets_indexed) + ' / ' + n(d.tickets_total) + '</div><div class="l">' + esc(T.tickets) + '</div></div>' +
                        '<div class="srch-figure"><div class="n">' + n(d.articles_indexed) + ' / ' + n(d.articles_total) + '</div><div class="l">' + esc(T.articles) + '</div></div>' +
                        '<div class="srch-figure"><div class="n">' + esc(d.last_indexed || T.never) + '</div><div class="l">' + esc(T.last_indexed) + '</div></div>' +
                    '</div>';

                if (d.by_source && d.by_source.length) {
                    html += '<table class="srch-table"><thead><tr>' +
                        '<th>' + esc(T.source) + '</th><th style="text-align:right">' + esc(T.rows) + '</th>' +
                        '</tr></thead><tbody>';
                    d.by_source.forEach(function (s) {
                        html += '<tr><td>' + esc(SOURCE_LABEL[s.source_type] || s.source_type) +
                                '</td><td class="num">' + n(s.rows) + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                // The reassurance an administrator actually wants: is anything missing?
                if (missingT === 0 && missingA === 0) {
                    html += '<div class="srch-note ok">' + esc(T.all_indexed) + '</div>';
                } else {
                    html += '<div class="srch-note">' +
                        esc(T.not_indexed.replace('{tickets}', n(missingT)).replace('{articles}', n(missingA))) +
                        '</div>';
                }

                // Attachment outcomes. Shown even when everything worked, because
                // "unsupported" is the normal state for every PDF until an
                // extraction service exists, and an administrator wondering why a
                // PDF is not searchable should find the answer here rather than
                // concluding search is broken.
                var att = d.attachments || {};
                var attKeys = Object.keys(att);
                if (attKeys.length) {
                    html += '<h3 style="margin-top:22px">' + esc(T.att_heading) + '</h3>';
                    html += '<table class="srch-table"><thead><tr><th>' + esc(T.att_outcome) +
                            '</th><th style="text-align:right">' + esc(T.att_files) + '</th></tr></thead><tbody>';
                    attKeys.forEach(function (k) {
                        html += '<tr><td>' + esc(ATT_LABEL[k] || k) + '</td><td class="num">' + n(att[k]) + '</td></tr>';
                    });
                    html += '</tbody></table>';
                    if (att.unsupported) {
                        html += '<div class="srch-status">' + esc(T.att_unsupported_note) + '</div>';
                    }
                }

                // Named files, not just counts. "Something failed" is not an
                // answer to "why can't I find this invoice"; the filename is.
                var probs = d.problem_files || [];
                if (probs.length) {
                    html += '<h3 style="margin-top:22px">' + esc(T.prob_heading) + '</h3>';
                    html += '<div class="srch-status" style="margin-top:0">' + esc(T.prob_intro) + '</div>';
                    html += '<table class="srch-table"><thead><tr>' +
                            '<th>' + esc(T.prob_file) + '</th>' +
                            '<th>' + esc(T.prob_ticket) + '</th>' +
                            '<th>' + esc(T.prob_why) + '</th>' +
                            '</tr></thead><tbody>';
                    probs.forEach(function (p) {
                        var ticket = p.ticket_number
                            ? '<a href="../../tickets/?ticket_id=' + p.ticket_id + '">' + esc(p.ticket_number) + '</a>'
                            : '<span class="srch-muted">&mdash;</span>';
                        html += '<tr><td>' + esc(p.filename) + '</td>' +
                                '<td>' + ticket + '</td>' +
                                '<td>' + esc(ATT_LABEL[p.status] || p.status) + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }

                html += '<div class="srch-status">' + esc(T.min_word.replace('{n}', d.min_word_length)) + '</div>';
                el.innerHTML = html;
            } catch (e) {
                el.innerHTML = '<div class="srch-note">' + esc(T.failed) + '</div>';
            }
        }

        // Rebuilds in slices — see api/system/search_rebuild.php for why. Each call
        // reports where it stopped and how much is left, which is also what drives
        // the progress bar.
        async function startRebuild() {
            var btn = document.getElementById('rebuildBtn');
            var bar = document.getElementById('progressBar');
            var wrap = document.getElementById('progress');
            var stat = document.getElementById('rebuildStatus');

            btn.disabled = true;
            wrap.classList.add('on');
            bar.style.width = '0%';

            var since = 0, total = null, totals = { tickets: 0, emails: 0, notes: 0, articles: 0 };

            try {
                for (;;) {
                    var r = await fetch('../../api/system/search_rebuild.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ since_ticket_id: since })
                    });
                    var d = await r.json();
                    if (!d.success) throw new Error(d.error || 'failed');

                    totals.tickets  += d.indexed.tickets;
                    totals.emails   += d.indexed.emails;
                    totals.notes    += d.indexed.notes;
                    totals.articles += d.indexed.articles;

                    if (total === null) total = totals.tickets + d.tickets_remaining;
                    var pct = total > 0 ? Math.round(totals.tickets * 100 / total) : 100;
                    bar.style.width = (d.done ? 100 : pct) + '%';
                    stat.textContent = T.rebuilding
                        .replace('{done}', n(totals.tickets))
                        .replace('{total}', n(total));

                    if (d.done) break;
                    since = d.last_ticket_id;
                }

                stat.textContent = T.rebuilt
                    .replace('{tickets}', n(totals.tickets))
                    .replace('{messages}', n(totals.emails))
                    .replace('{notes}', n(totals.notes))
                    .replace('{articles}', n(totals.articles));
                await loadStatus();
            } catch (e) {
                stat.textContent = T.failed;
            } finally {
                btn.disabled = false;
            }
        }

        loadStatus();
    </script>
    <script src="../../assets/js/mobile.js?v=55"></script>
</body>
</html>
