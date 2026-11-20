<?php
/**
 * System - Storage usage: where FreeITSM keeps files, how many there are and how big.
 *
 * READ ONLY - nothing here moves, creates or deletes anything. The list is
 * storageInventory() (includes/storage_inventory.php), drawn at once; each row's
 * count and size are then fetched from api/system/storage_stats.php, a few at a
 * time, because counting a big folder can take seconds.
 *
 * Inside Docker it also says whether each folder survives an update (is on a
 * volume) - storagePersistenceStatus(), the same answer the update warning uses.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'storage-usage';
require_once __DIR__ . '/../includes/page_gate.php';
require_once '../../includes/storage_inventory.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'auth/login.php');
    exit;
}

$rows = [];
$failed = false;
try {
    $rows = storageInventory(connectToDatabase());
} catch (Throwable $ex) {
    error_log('storage page: ' . $ex->getMessage());
    $failed = true;
}
$inContainer = storagePersistenceInContainer();
$rootDev = null;
if ($inContainer) { $st = @stat('/'); $rootDev = ($st && isset($st['dev'])) ? (int)$st['dev'] : null; }
$free = @disk_free_space(dirname(__DIR__, 2));
$e = function (string $k, array $p = []): string { return htmlspecialchars(t('system.storage_usage.' . $k, $p)); };
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.storage_usage.heading'))); ?></title>
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/theme.css?v=27">
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/inbox.css?v=96">
    <style>
        body {
            /* System's dark accent is a LIGHT colour, so --on-accent is pinned too
               (see system/debug-tools/index.php). */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Page layout, title and subtitle: assets/css/system-page.css (.sys-page on .st-wrap). */
        .st-wrap { height: calc(100vh - 48px); overflow-y: auto; background: var(--app-bg, #f5f7fa); }
        .st-table-wrap { overflow-x: auto; background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; }
        table.st { width: 100%; border-collapse: collapse; }
        table.st th, table.st td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-soft, #f2f2f2); font-size: 13px; color: var(--text, #444); vertical-align: top; }
        table.st th { background: var(--surface-3, #f9fafb); font-weight: 600; font-size: 12px; white-space: nowrap; }
        table.st th.num, table.st td.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        table.st tbody tr:last-child td { border-bottom: none; }
        table.st tfoot td { border-top: 2px solid var(--border, #e5e7eb); border-bottom: none; font-weight: 600; }
        .st-what { font-weight: 600; }
        .st-path { font-family: Consolas, Menlo, monospace; font-size: 12px; color: var(--text-muted, #555); word-break: break-all; }
        .st-note { font-size: 12px; color: var(--text-dim, #888); margin-top: 3px; }
        .st-note.critical { color: var(--warning-text, #92400e); }
        .st-dim { color: var(--text-faint, #999); }
        .st-bad { color: var(--danger-text, #c0392b); }
        .st-ok { color: var(--success-text, #065f46); }
        .st-foot { font-size: 12px; color: var(--text-dim, #888); margin-top: 10px; }
        .st-foot p { margin: 4px 0; }
        .st-error { color: var(--danger-text, #c0392b); font-size: 13px; }
        .st-newest { white-space: nowrap; }
        /* Phone: each folder becomes a card - name, path, then its figures with their
           labels - instead of a table that only scrolls sideways. Phone only. */
        @media (max-width: 768px) {
            .st-table-wrap { overflow-x: visible; }
            /* TRAP: mobile.css section 34e makes every System table a nowrap sideways
               scroller, with a selector this page cannot outrank. This table carries a
               sentence (the notes), so by the same file's rule it is a card feed. */
            table.st { white-space: normal !important; overflow-x: visible !important; }
            table.st, table.st tbody, table.st tfoot { display: block; }
            table.st thead { display: none; }
            table.st tr { display: flex; flex-wrap: wrap; gap: 4px 16px; padding: 10px 12px; border-bottom: 1px solid var(--border-soft, #f2f2f2); width: 100%; box-sizing: border-box; }
            table.st td { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
            table.st tbody tr:last-child { border-bottom: none; }
            table.st td, table.st tfoot td { display: block; padding: 0; border: none; text-align: left; }
            table.st td:first-child, table.st td.st-path { flex-basis: 100%; }
            table.st td.num { text-align: left; }
            table.st td[data-label]:not(:empty)::before { content: attr(data-label) ": "; color: var(--text-dim, #888); font-weight: 400; }
            table.st tfoot tr { border-top: 2px solid var(--border, #e5e7eb); }
            table.st tfoot td:empty { display: none; }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo $path_prefix; ?>assets/js/tz.js?v=5"></script>
</head>
<body data-mobile-module="system" data-mobile-page="storage-usage">
    <?php include '../includes/header.php'; ?>

    <div class="st-wrap sys-page">
        <div class="sys-page-head"><div>
            <h1 class="sys-title"><?php echo $e('heading'); ?></h1>
            <p class="sys-subtitle"><?php echo $e('intro'); ?></p>
        </div></div>

        <?php if ($failed): ?>
            <p class="st-error"><?php echo $e('load_failed'); ?></p>
        <?php else: ?>
            <div class="st-table-wrap">
            <table class="st" id="stTable">
                <thead><tr>
                    <th><?php echo $e('col_what'); ?></th>
                    <th><?php echo $e('col_folder'); ?></th>
                    <th class="num"><?php echo $e('col_files'); ?></th>
                    <th class="num"><?php echo $e('col_size'); ?></th>
                    <th><?php echo $e('col_newest'); ?></th>
                    <?php if ($inContainer): ?><th><?php echo $e('col_volume'); ?></th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $label = $r['what'] !== null ? t('system.storage_usage.what.' . $r['what']) : $r['name'];
                    $noteP = $r['s3'] ? ['bucket' => $r['s3']['bucket'] !== '' ? $r['s3']['bucket'] : '-'] : [];
                ?>
                    <tr data-key="<?php echo htmlspecialchars($r['key']); ?>">
                        <td>
                            <div class="st-what"><?php echo htmlspecialchars($label); ?></div>
                            <?php if ($r['note']): ?><div class="st-note<?php echo $r['critical'] ? ' critical' : ''; ?>"><?php echo $e('note.' . $r['note'], $noteP); ?></div><?php endif; ?>
                        </td>
                        <td class="st-path"><?php echo htmlspecialchars($r['path']); ?></td>
                        <td class="num" data-cell="files" data-label="<?php echo $e('col_files'); ?>"><span class="st-dim"><?php echo $e('counting'); ?></span></td>
                        <td class="num" data-cell="size" data-label="<?php echo $e('col_size'); ?>"></td>
                        <td class="st-newest" data-cell="newest" data-label="<?php echo $e('col_newest'); ?>"></td>
                        <?php if ($inContainer):
                            $v = storagePersistenceStatus($r['path'], $rootDev);
                            $cls = $v === 'persisted' ? 'st-ok' : ($v === 'at_risk' ? 'st-bad' : 'st-dim');
                        ?><td class="<?php echo $cls; ?>"><?php echo $e('volume_' . $v); ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr>
                    <td colspan="2"><?php echo $e('total'); ?></td>
                    <td class="num" id="stTotFiles" data-label="<?php echo $e('col_files'); ?>"></td>
                    <td class="num" id="stTotSize" data-label="<?php echo $e('col_size'); ?>"></td>
                    <td colspan="<?php echo $inContainer ? 2 : 1; ?>"></td>
                </tr></tfoot>
            </table>
            </div>
            <div class="st-foot">
                <?php if ($free !== false && $free !== null): ?>
                    <p id="stFree" data-bytes="<?php echo (int)$free; ?>"></p>
                <?php endif; ?>
                <p id="stPartialNote" hidden><?php echo $e('partial_note'); ?></p>
                <p><?php echo $e('not_counted'); ?></p>
            </div>
        <?php endif; ?>
    </div>

    <script>
    (function () {
        const API = '<?php echo $path_prefix; ?>api/system/storage_stats.php';
        const S = <?php echo json_encode([
            'not_created' => t('system.storage_usage.not_created'),
            'unreadable'  => t('system.storage_usage.unreadable'),
            'failed'      => t('system.storage_usage.count_failed'),
            'at_least'    => t('system.storage_usage.at_least', ['n' => '{n}']),
            'free'        => t('system.storage_usage.free_space', ['size' => '{size}']),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const lang = document.documentElement.lang || undefined;
        const num = n => new Intl.NumberFormat(lang).format(n);
        // Sizes in the reader's own language: "1.2 GB", "1,2 Go", ...
        function size(b) {
            // Never in bytes: Intl says "0 byte" / "1 byte". The smallest unit is kB, and
            // a folder with nothing in it shows a dash instead.
            if (!b) return '-';
            const units = ['kilobyte', 'megabyte', 'gigabyte', 'terabyte'];
            let i = 0, v = b / 1024;
            while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
            try {
                return new Intl.NumberFormat(lang, { style: 'unit', unit: units[i], unitDisplay: 'short', maximumFractionDigits: 1 }).format(v);
            } catch (e) { return v.toFixed(1) + ' ' + ['KB', 'MB', 'GB', 'TB'][i]; }
        }
        const dim = text => { const s = document.createElement('span'); s.className = 'st-dim'; s.textContent = text; return s; };
        const set = (cell, val) => { cell.replaceChildren(typeof val === 'string' ? document.createTextNode(val) : val); };

        const free = document.getElementById('stFree');
        if (free) free.textContent = S.free.replace('{size}', size(Number(free.dataset.bytes)));

        const rows = Array.from(document.querySelectorAll('#stTable tbody tr[data-key]'));
        let totFiles = 0, totBytes = 0, partial = false, pending = rows.length;
        function finishOne() {
            if (--pending > 0) return;
            const f = num(totFiles), z = size(totBytes);
            document.getElementById('stTotFiles').textContent = partial ? S.at_least.replace('{n}', f) : f;
            document.getElementById('stTotSize').textContent = partial ? S.at_least.replace('{n}', z) : z;
            document.getElementById('stPartialNote').hidden = !partial;
        }
        async function count(tr) {
            const c = name => tr.querySelector('[data-cell="' + name + '"]');
            try {
                const r = await fetch(API + '?key=' + encodeURIComponent(tr.dataset.key), { credentials: 'same-origin' });
                const d = await r.json();
                if (!d.success) throw new Error(d.error || 'failed');
                if (!d.exists) {
                    set(c('files'), dim(S.not_created)); set(c('size'), ''); set(c('newest'), '');
                } else if (!d.readable) {
                    const s = dim(S.unreadable); s.className = 'st-bad'; set(c('files'), s);
                    partial = true;
                } else {
                    const f = num(d.files), z = size(d.bytes);
                    set(c('files'), d.complete ? f : S.at_least.replace('{n}', f));
                    set(c('size'), d.complete ? z : S.at_least.replace('{n}', z));
                    set(c('newest'), d.newest ? fmtDateTime(new Date(d.newest * 1000).toISOString().slice(0, 19).replace('T', ' ')) : '');
                    totFiles += d.files; totBytes += d.bytes;
                    if (!d.complete) partial = true;
                }
            } catch (e) {
                const s = dim(S.failed); s.className = 'st-bad'; set(c('files'), s);
                partial = true;
            }
            finishOne();
        }
        // A few at a time: one huge folder should not hold up the rest.
        let next = 0;
        const worker = async () => { while (next < rows.length) await count(rows[next++]); };
        if (!rows.length) finishOne(); else for (let i = 0; i < 3; i++) worker();
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
