<?php
/**
 * Assign tags in bulk — the reverse of printing labels.
 *
 * WHY THIS EXISTS
 * ---------------
 * Printing labels assumes FreeITSM decides the numbers. Plenty of companies
 * don't work that way: they buy a box of durable pre-printed sequential tags
 * (polyester or anodised aluminium — which matters far more than the encoding
 * once a laptop has been knocked about for three years), stick them on, and
 * then need the database to agree with the sticker.
 *
 * THE LOOP THIS IS BUILT AROUND
 * -----------------------------
 * Scan what is ALREADY on the machine — the manufacturer's serial barcode,
 * present on virtually every piece of kit — then scan or type the new tag.
 * Save, and it resets ready for the next one. A barcode scanner is just a
 * keyboard that types and presses Enter, so both fields work with one without
 * any driver, pairing or app.
 *
 * That ordering matters: identifying the asset from something already printed
 * on it is what makes this fast. Searching a list for "which laptop is this?"
 * is the slow part of a tagging day, and this removes it.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/asset_labels.php';
I18n::initFromSession();

requireModuleAccess('assets');

$conn  = connectToDatabase();
$ready = assetLabelsSchemaReady($conn);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign asset tags · FreeITSM</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=27">
    <style>
        /* Narrow-first: this is a job done standing up, on a phone or a laptop
           with a USB scanner. Same reasoning as scan.php. */
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
               background: var(--surface-2, #f3f6fa); color: var(--text, #222); }
        .bar { background: var(--accent, #546e7a); color: var(--on-accent, #fff); padding: 13px 16px;
               display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .bar h1 { font-size: 16px; margin: 0; font-weight: 600; }
        .bar a { color: var(--on-accent, #fff); font-size: 13px; text-decoration: none; opacity: 0.9; }
        .wrap { max-width: 620px; margin: 0 auto; padding: 16px; }
        .card { background: var(--surface, #fff); border: 1px solid var(--border, #e2e6ea);
                border-radius: 12px; padding: 16px; margin-bottom: 14px; }
        .step-label { display: block; font-size: 13px; font-weight: 600; color: var(--text-muted, #667); margin: 0 0 8px; }
        .step-hint { font-size: 12.5px; color: var(--text-muted, #778); margin: 6px 0 0; line-height: 1.45; }
        input.big {
            width: 100%; min-height: 50px; font-size: 17px; padding: 10px 12px;
            border-radius: 10px; border: 1px solid var(--border, #cfd6dd);
            background: var(--surface, #fff); color: var(--text, #222);
            font-family: 'Consolas', monospace; letter-spacing: 0.5px;
        }
        input.big:disabled { background: var(--surface-3, #f2f4f6); color: var(--text-dim, #999); }
        .found { margin-top: 12px; padding: 12px; border-radius: 10px;
                 background: var(--success-bg, #dcfce7); color: var(--success-text, #166534); font-size: 14px; }
        .found strong { display: block; font-size: 16px; margin-bottom: 2px; }
        .miss  { margin-top: 12px; padding: 12px; border-radius: 10px;
                 background: var(--danger-bg, #fee2e2); color: var(--danger-text, #991b1b); font-size: 14px; }
        .done-list { font-size: 13px; color: var(--text-muted, #667); }
        .done-list li { margin-bottom: 4px; }
        .counter { font-size: 13px; opacity: 0.9; }
        .btn-row { display: flex; gap: 8px; margin-top: 12px; }
        .btn-row button {
            flex: 1; min-height: 46px; font-size: 15px; font-weight: 600; border-radius: 10px;
            border: 1px solid var(--border, #cfd6dd); background: var(--surface, #fff);
            color: var(--text, #222); cursor: pointer;
        }
        .btn-row button.primary { background: var(--accent, #546e7a); color: var(--on-accent, #fff); border-color: transparent; }
        .notready { padding: 16px; background: var(--warning-bg, #fff4e5); color: var(--warning-text, #8a5300);
                    border-radius: 10px; font-size: 14px; }
    </style>
</head>
<body>
<div class="bar">
    <h1>Assign asset tags</h1>
    <span class="counter" id="counter"></span>
    <a href="./">Back to Assets</a>
</div>

<div class="wrap">
<?php if (!$ready): ?>
    <div class="notready">Asset tags need a database update first — an administrator can run <strong>System → Database Verification</strong>.</div>
<?php else: ?>
    <div class="card">
        <label class="step-label" for="findInput">1 · Scan the asset</label>
        <input class="big" id="findInput" autocomplete="off" autocapitalize="characters" spellcheck="false"
               placeholder="Serial, hostname or existing tag" autofocus>
        <p class="step-hint">Point a barcode scanner at the manufacturer's serial sticker, or type it. A scanner is just a keyboard — no setup needed.</p>
        <div id="findResult"></div>
    </div>

    <div class="card" id="tagCard" style="display:none;">
        <label class="step-label" for="tagInput">2 · The tag going on it</label>
        <input class="big" id="tagInput" autocomplete="off" autocapitalize="characters" spellcheck="false"
               maxlength="64" placeholder="e.g. LT0001">
        <p class="step-hint">Scan the pre-printed tag, or type it. Saving moves straight back to step 1 for the next one.</p>
        <div class="btn-row">
            <button onclick="resetLoop()">Skip</button>
            <button class="primary" onclick="saveTag()">Save &amp; next</button>
        </div>
        <div id="tagResult"></div>
    </div>

    <div class="card" id="doneCard" style="display:none;">
        <label class="step-label">Tagged in this session</label>
        <ul class="done-list" id="doneList"></ul>
    </div>
<?php endif; ?>
</div>

<script>
const API = '../api/assets/';
let currentAsset = null;
let doneCount = 0;

const $ = id => document.getElementById(id);
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

/* A scanner ends its input with Enter, so Enter is the whole interaction. */
$('findInput')?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); findAsset(); } });
$('tagInput')?.addEventListener('keydown',  e => { if (e.key === 'Enter') { e.preventDefault(); saveTag(); } });

async function findAsset() {
    const q = $('findInput').value.trim();
    if (!q) return;
    $('findResult').innerHTML = '';
    try {
        const res = await fetch(API + 'find_asset.php?q=' + encodeURIComponent(q));
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Lookup failed');
        if (!data.asset) {
            currentAsset = null;
            $('tagCard').style.display = 'none';
            $('findResult').innerHTML = '<div class="miss">Nothing matches that. Check the serial, or search the asset list.</div>';
            $('findInput').select();
            return;
        }
        currentAsset = data.asset;
        const a = data.asset;
        // Show what was matched, so a mis-scan is caught BEFORE a tag goes on
        // the wrong machine — the one mistake this whole page exists to avoid.
        $('findResult').innerHTML =
            `<div class="found"><strong>${esc(a.hostname || ('#' + a.id))}</strong>
             ${esc([a.type_name, a.service_tag, a.status_name].filter(Boolean).join(' · '))}
             ${a.asset_tag ? '<br>Already tagged: <strong style="display:inline">' + esc(a.asset_tag) + '</strong>' : ''}</div>`;
        $('tagCard').style.display = '';
        $('tagInput').value = a.asset_tag || '';
        $('tagInput').focus();
        $('tagInput').select();
    } catch (e) {
        $('findResult').innerHTML = '<div class="miss">' + esc(e.message) + '</div>';
    }
}

async function saveTag() {
    if (!currentAsset) return;
    const tag = $('tagInput').value.trim();
    if (!tag) return;
    $('tagResult').innerHTML = '';
    try {
        const res = await fetch(API + 'save_asset_tag.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ asset_id: currentAsset.id, asset_tag: tag })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Save failed');

        doneCount++;
        $('counter').textContent = doneCount + ' tagged';
        $('doneCard').style.display = '';
        const li = document.createElement('li');
        li.textContent = tag + ' → ' + (currentAsset.hostname || ('#' + currentAsset.id));
        $('doneList').prepend(li);
        resetLoop();
    } catch (e) {
        // Stay put on a clash: the tag is in your hand and needs a decision,
        // and resetting would lose which asset you were looking at.
        $('tagResult').innerHTML = '<div class="miss">' + esc(e.message) + '</div>';
        $('tagInput').select();
    }
}

function resetLoop() {
    currentAsset = null;
    $('tagCard').style.display = 'none';
    $('tagInput').value = '';
    $('findResult').innerHTML = '';
    $('findInput').value = '';
    $('findInput').focus();
}
</script>
</body>
</html>
