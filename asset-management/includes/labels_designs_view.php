<?php
/**
 * The label print page with designs (GH #229): pick a design and a label size, see
 * every label as it will print, and download a PDF at the label's exact size.
 *
 * Included by asset-management/labels.php once the design tables exist, with these
 * already worked out: $conn, $analystId, $activeTenantId, $ids, $assets (each with
 * url), $customValues, $labelSettings, $hostIsLocal, $labelHost.
 *
 * ?design=<id>   the design (default: the company's default)
 * ?stock=<key>   the label size - a template design is drawn for whichever size is
 *                chosen; ?sheet= from older links still works
 *
 * @wiki Asset-Label-Designer-Design
 */

$designs = assetLabelDesignsFor($conn, $activeTenantId, $analystId);
$design = $designs[0];
foreach ($designs as $d) {
    if ((int)($_GET['design'] ?? 0) === $d['id']) { $design = $d; break; }
    if (empty($_GET['design']) && $d['is_default']) { $design = $d; break; }
}
$stockKey = (string)($_GET['stock'] ?? ($_GET['sheet'] ?? ''));
try {
    $stock = $stockKey !== '' && $stockKey !== ($design['stock']['key'] ?? '')
        ? assetLabelStockFrom(['key' => $stockKey] + ($stockKey === 'custom' ? $design['stock'] : []))
        : $design['stock'];
} catch (InvalidArgumentException) {
    $stock = $design['stock'];
}
$isCustom = ($design['design']['template'] ?? '') === 'custom';
if ($isCustom) $stock = $design['stock'];   // a designed label is drawn for its own size only
$items = assetLabelItemsFor($design, $stock, $labelSettings);

$labels = [];
foreach ($assets as $a) {
    $labels[] = ['values' => assetLabelValues($a, $customValues[(int)$a['id']] ?? []), 'url' => $a['url'], 'tag' => (string)($a['asset_tag'] ?? '')];
}
$job = [
    'stock'    => $stock,
    'items'    => $items,
    'labels'   => $labels,
    'captions' => assetLabelPrintFields($conn, $activeTenantId),
    'noTag'    => t('asset-management.labels.no_tag'),
    'font'     => (string)($design['design']['font'] ?? ASSET_LABEL_BUILTIN_FONT),
    'mono'     => assetLabelMono($design, $stock),
    'logo'     => $labelSettings['logo_path'] !== '' ? BASE_URL . ltrim($labelSettings['logo_path'], '/') : null,
    'base'     => BASE_URL,
    'file'     => 'asset-labels-' . date('Ymd-His') . '.pdf',
];
$canManage = analystHasCapability($conn, $analystId, Cap::ASSETS_LABELS);
$tt = fn(string $k, array $p = []) => htmlspecialchars(t('asset-management.label_designs.' . $k, $p));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars(pageTitle('assets', t('asset-management.labels.browser_title'))); ?></title>
    <script src="../assets/js/qrcode.min.js"></script>
    <script src="../assets/js/vendor/jspdf.umd.min.js"></script>
    <script src="../assets/js/asset-labels/render.js?v=3"></script>
    <style>
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f3f6fa; color: #222; }
        .bar { background: #546e7a; color: #fff; padding: 12px 18px; display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .bar h1 { font-size: 16px; margin: 0; font-weight: 600; }
        .bar label { font-size: 13px; display: flex; align-items: center; gap: 6px; }
        .bar select, .bar button, .bar a.btn { font-size: 13px; padding: 7px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.4);
            background: rgba(255,255,255,0.18); color: #fff; cursor: pointer; text-decoration: none; }
        .bar select option { background: #fff; color: #222; }
        .bar button.primary { background: #fff; color: #37474f; font-weight: 600; border-color: #fff; }
        .bar button:disabled { opacity: .6; cursor: progress; }
        .hint { padding: 12px 18px; font-size: 13px; color: #555; background: #fff8e1; border-bottom: 1px solid #ffe0a3; }
        .hint.bad { background: #fdeceb; border-bottom-color: #f5c6cb; color: #8a1f1a; }
        .previews { padding: 18px; display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start; }
        .previews canvas { background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.15); border-radius: 2px; }
        .meta { font-size: 12px; opacity: .85; margin-left: auto; }
    </style>
</head>
<body>
<div class="bar">
    <h1><?php echo htmlspecialchars(t('asset-management.labels.heading')); ?></h1>
    <label><?php echo $tt('design'); ?>
        <select id="designSel">
            <?php foreach ($designs as $d): ?>
                <option value="<?php echo $d['id']; ?>" <?php echo $d['id'] === $design['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['name'] . ($d['is_default'] ? ' ★' : '')); ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label><?php echo $tt('size'); ?>
        <select id="stockSel"<?php echo $isCustom ? ' disabled title="' . $tt('size_fixed') . '"' : ''; ?>>
            <?php foreach (assetLabelStocks() as $k => $st): ?>
                <option value="<?php echo htmlspecialchars((string)$k); ?>" <?php echo (string)$k === (string)$stock['key'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($st['name']); ?></option>
            <?php endforeach; ?>
            <?php if ($stock['key'] === 'custom'): ?><option value="custom" selected><?php echo $tt('size_custom', ['w' => $stock['w'], 'h' => $stock['h']]); ?></option><?php endif; ?>
        </select>
    </label>
    <button class="primary" id="btnPdf" disabled><?php echo $tt('download_pdf'); ?></button>
    <?php if ($ids): ?>
        <a class="btn" href="?ids=<?php echo urlencode(implode(',', $ids)); ?>&csv=1"><?php echo htmlspecialchars(t('asset-management.labels.csv')); ?></a>
    <?php endif; ?>
    <?php if ($canManage): ?><a class="btn" href="settings/?tab=asset-labels"><?php echo $tt('manage'); ?></a><?php endif; ?>
    <span class="meta" id="meta"><?php echo htmlspecialchars(t(count($assets) === 1 ? 'asset-management.labels.count_one' : 'asset-management.labels.count_many', ['n' => count($assets)])); ?></span>
</div>
<?php if ($hostIsLocal): ?>
    <div class="hint bad"><strong><?php echo t('asset-management.labels.localhost_warn_title', ['host' => '<code>' . htmlspecialchars($labelHost) . '</code>']); ?></strong>
        <?php echo t('asset-management.labels.localhost_warn_body'); ?></div>
<?php endif; ?>
<?php if (!$ids || !$assets): ?>
    <div class="hint"><?php echo htmlspecialchars(t(!$ids ? 'asset-management.labels.no_ids_body' : 'asset-management.labels.none_visible_body')); ?></div>
<?php else: ?>
    <div class="hint"><?php echo $tt($stock['kind'] === 'sheet' ? 'hint_sheet' : 'hint_tape'); ?></div>
<?php endif; ?>
<div class="previews" id="previews"></div>
<script>
(function () {
    'use strict';
    const JOB = <?php echo json_encode($job, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;
    const IDS = <?php echo json_encode(implode(',', $ids)); ?>;
    const go = (design, stock) => { location.href = 'labels.php?ids=' + encodeURIComponent(IDS) + '&design=' + design + (stock ? '&stock=' + encodeURIComponent(stock) : ''); };
    document.getElementById('designSel').addEventListener('change', e => go(e.target.value, ''));
    document.getElementById('stockSel').addEventListener('change', e => go(document.getElementById('designSel').value, e.target.value));

    const loadImage = src => new Promise(res => { if (!src) return res(null); const i = new Image(); i.onload = () => res(i); i.onerror = () => res(null); i.src = src; });
    (async () => {
        const [logo] = await Promise.all([loadImage(JOB.logo), LabelRender.loadFonts(JOB.base, [JOB.font])]);
        JOB.logo = logo;
        // The preview: every label (up to 200) drawn as it will print, about four pixels to the millimetre.
        const box = document.getElementById('previews');
        const px = Math.max(3, Math.min(8, 220 / JOB.stock.w)) * (window.devicePixelRatio || 1);
        JOB.labels.forEach(lab => {
            const cv = document.createElement('canvas');
            cv.width = Math.round(JOB.stock.w * px); cv.height = Math.round(JOB.stock.h * px);
            cv.style.width = (cv.width / (window.devicePixelRatio || 1)) + 'px';
            const g = cv.getContext('2d');
            g.fillStyle = '#fff'; g.fillRect(0, 0, cv.width, cv.height);
            LabelRender.drawLabel(g, { items: JOB.items, values: lab.values, url: lab.url, captions: JOB.captions, noTag: JOB.noTag, font: JOB.font, logo, mono: JOB.mono }, px);
            box.appendChild(cv);
        });
        const btn = document.getElementById('btnPdf');
        btn.disabled = !JOB.labels.length;
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            const meta = document.getElementById('meta'), was = meta.textContent;
            try {
                const doc = await LabelRender.pdf(Object.assign({}, JOB, { onProgress: (n, t) => { meta.textContent = <?php echo json_encode(t('asset-management.label_designs.making', ['n' => '{n}', 't' => '{t}'])); ?>.replace('{n}', n).replace('{t}', t); } }));
                doc.save(JOB.file);
            } catch (e) {
                alert(e.message);
            } finally {
                meta.textContent = was;
                btn.disabled = false;
            }
        });
    })();
})();
</script>
</body>
</html>
