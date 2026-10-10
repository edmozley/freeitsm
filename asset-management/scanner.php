<?php
/**
 * Continuous camera scanner for asset labels.
 *
 * WHY THIS EXISTS. A phone's own camera already opens an asset label — the QR
 * holds a plain HTTPS URL precisely so it needs no app. That is the right tool
 * for looking one thing up. It is the wrong tool for a stocktake: to update
 * forty assets you leave FreeITSM, open the camera, tap the banner, edit, then
 * repeat the whole dance forty times. This page keeps the camera on screen so
 * the loop is scan → applied → scan, with the app never leaving the screen.
 *
 * MOBILE-FIRST, NOT MOBILE-ADAPTED — same footing as scan.php and
 * assign-tags.php: a purpose-built narrow surface with its own small sheet,
 * not the desktop module squeezed down. (It works on a desktop with a webcam,
 * but nobody will.)
 *
 * ⚠️ SECURE CONTEXT. getUserMedia is refused outside HTTPS (localhost aside),
 * so on a plain http:// LAN address the camera cannot start at all. That is a
 * browser rule, not ours, and the page says so plainly rather than showing a
 * dead black rectangle. Typing a tag or serial still works there.
 *
 * DECODING. Chrome/Android has a native BarcodeDetector; iOS Safari does not.
 * The native one is used when present and the bundled jsQR is fetched ONLY as
 * the fallback, so Android never pays for a decoder it doesn't need.
 *
 * ENGLISH-ONLY, consistent with its siblings scan.php and assign-tags.php. The
 * translated surfaces (the module nav, the list header link) carry proper keys.
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
    <title><?php echo htmlspecialchars(t('asset-management.scanner.browser_title')); ?> · FreeITSM</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=27">
    <style>
        /* Self-contained: shares no layout with the desktop module. Every
           colour is a theme token so it follows light/dark like everything else. */
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
        .hint { font-size: 12.5px; color: var(--text-muted, #778); margin: 8px 0 0; line-height: 1.45; }

        /* Mode switch. Two jobs, and which one you're doing changes what a scan
           MEANS — so it is a visible choice, not a hidden preference. */
        .modes { display: flex; gap: 0; border: 1px solid var(--border, #cfd6dd); border-radius: 10px; overflow: hidden; }
        .modes button {
            flex: 1; min-height: 46px; font-size: 15px; font-weight: 600; border: 0; cursor: pointer;
            background: var(--surface, #fff); color: var(--text-muted, #667);
        }
        .modes button.on { background: var(--accent, #546e7a); color: var(--on-accent, #fff); }

        select.big, input.big {
            width: 100%; min-height: 50px; font-size: 16px; padding: 10px 12px;
            border-radius: 10px; border: 1px solid var(--border, #cfd6dd);
            background: var(--surface, #fff); color: var(--text, #222);
        }
        /* 16px above is not a style choice: iOS zooms the page for anything
           smaller, and a zoomed page is a broken one on a screen this size. */
        input.big { font-family: 'Consolas', monospace; letter-spacing: 0.5px; }
        .field + .field { margin-top: 12px; }

        /* Camera. The frame is a guide, not a crop — decoding uses the whole
           frame, because insisting people line the code up exactly is how a
           scanner ends up slower than typing. */
        .cam { position: relative; background: #000; border-radius: 12px; overflow: hidden; aspect-ratio: 3 / 4; }
        .cam video { width: 100%; height: 100%; object-fit: cover; display: block; }
        .cam .reticle {
            position: absolute; inset: 18% 12%; border: 3px solid rgba(255,255,255,0.85);
            border-radius: 14px; box-shadow: 0 0 0 100vmax rgba(0,0,0,0.28); pointer-events: none;
        }
        .cam .torch {
            position: absolute; right: 10px; bottom: 10px; min-height: 42px; padding: 0 14px;
            border-radius: 21px; border: 0; font-size: 14px; font-weight: 600; cursor: pointer;
            background: rgba(0,0,0,0.55); color: #fff;
        }
        .cam .camstate {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            padding: 20px; text-align: center; color: #fff; font-size: 14px; line-height: 1.5;
        }

        .live { margin-top: 12px; font-size: 14px; font-weight: 600; min-height: 20px; }
        .live.ok   { color: var(--success-text, #166534); }
        .live.warn { color: var(--warning-text, #92400e); }
        .live.err  { color: var(--danger-text, #991b1b); }

        .found { margin-top: 12px; padding: 12px; border-radius: 10px;
                 background: var(--success-bg, #dcfce7); color: var(--success-text, #166534); font-size: 14px; }
        .found strong { display: block; font-size: 16px; margin-bottom: 2px; }
        .miss  { margin-top: 12px; padding: 12px; border-radius: 10px;
                 background: var(--danger-bg, #fee2e2); color: var(--danger-text, #991b1b); font-size: 14px; }

        .btn-row { display: flex; gap: 8px; margin-top: 12px; }
        .btn-row button, .btn-row a {
            flex: 1; min-height: 46px; font-size: 15px; font-weight: 600; border-radius: 10px;
            border: 1px solid var(--border, #cfd6dd); background: var(--surface, #fff);
            color: var(--text, #222); cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .btn-row button[disabled] { opacity: 0.5; cursor: default; }

        .done-list { font-size: 13px; color: var(--text-muted, #667); margin: 0; padding-left: 18px; }
        .done-list li { margin-bottom: 4px; }
        .counter { font-size: 13px; opacity: 0.9; }
        .stocktake-only { display: none; }
        body[data-mode="stocktake"] .stocktake-only { display: block; }
    </style>
</head>
<body data-mode="lookup">

<div class="bar">
    <h1><?php echo htmlspecialchars(t('asset-management.scanner.heading')); ?></h1>
    <span class="counter" id="counter"></span>
    <a href="./"><?php echo htmlspecialchars(t('common.close')); ?></a>
</div>

<div class="wrap">

<?php if (!$ready): ?>
    <div class="card">
        <p style="margin:0"><?php echo t('asset-management.scanner.not_ready'); ?></p>
    </div>
<?php else: ?>

    <div class="card">
        <span class="step-label"><?php echo htmlspecialchars(t('asset-management.scanner.mode_question')); ?></span>
        <div class="modes">
            <button type="button" id="modeLookup" class="on" onclick="setMode('lookup')"><?php echo htmlspecialchars(t('asset-management.scanner.mode_lookup')); ?></button>
            <button type="button" id="modeStock" onclick="setMode('stocktake')"><?php echo htmlspecialchars(t('asset-management.scanner.mode_stocktake')); ?></button>
        </div>
        <p class="hint" id="modeHint"><?php echo htmlspecialchars(t('asset-management.scanner.hint_lookup')); ?></p>

        <?php /* The stocktake settings are deliberately chosen BEFORE scanning
                 rather than confirmed after each one: the whole value of the mode
                 is that a scan needs no follow-up tap. */ ?>
        <div class="stocktake-only" style="margin-top:14px;">
            <div class="field">
                <span class="step-label" for="stStatus"><?php echo htmlspecialchars(t('asset-management.scanner.set_status')); ?></span>
                <select class="big" id="stStatus"><option value=""><?php echo htmlspecialchars(t('asset-management.scanner.leave_unchanged')); ?></option></select>
            </div>
            <div class="field">
                <span class="step-label" for="stLocation"><?php echo htmlspecialchars(t('asset-management.scanner.set_location')); ?></span>
                <select class="big" id="stLocation"><option value=""><?php echo htmlspecialchars(t('asset-management.scanner.leave_unchanged')); ?></option></select>
            </div>
            <p class="hint"><?php echo htmlspecialchars(t('asset-management.scanner.stocktake_hint')); ?></p>
        </div>
    </div>

    <div class="card">
        <div class="cam" id="cam">
            <video id="video" playsinline muted autoplay></video>
            <div class="reticle"></div>
            <button type="button" class="torch" id="torch" hidden onclick="toggleTorch()"><?php echo htmlspecialchars(t('asset-management.scanner.torch')); ?></button>
            <div class="camstate" id="camstate"><?php echo htmlspecialchars(t('asset-management.scanner.cam_starting')); ?></div>
        </div>
        <div class="live" id="live"></div>
        <div id="result"></div>
        <div class="btn-row">
            <button type="button" id="undoBtn" onclick="undoLast()" disabled><?php echo htmlspecialchars(t('asset-management.scanner.undo')); ?></button>
            <button type="button" id="retryBtn" onclick="startCamera()" hidden><?php echo htmlspecialchars(t('asset-management.scanner.start_camera')); ?></button>
        </div>
    </div>

    <div class="card">
        <span class="step-label" for="manual"><?php echo htmlspecialchars(t('asset-management.scanner.manual_label')); ?></span>
        <input class="big" id="manual" autocomplete="off" autocapitalize="characters" spellcheck="false"
               placeholder="<?php echo htmlspecialchars(t('asset-management.field.asset_tag_ph')); ?>" onkeydown="if(event.key==='Enter')manualLookup()">
        <p class="hint"><?php echo htmlspecialchars(t('asset-management.scanner.manual_hint')); ?></p>
    </div>

    <div class="card" id="sessionCard" hidden>
        <span class="step-label"><?php echo htmlspecialchars(t('asset-management.scanner.session_heading')); ?></span>
        <ul class="done-list" id="doneList"></ul>
    </div>

<?php endif; ?>
</div>

<?php if ($ready): ?>
<script>
/* Every string this script shows, translated on the server in one go. This
   page shares no JS bundle with the desktop module (by design - it is a phone
   screen), so there is nothing for assets/js/i18n.js to hang off. */
const S = <?php echo json_encode([
    'hint_lookup'      => t('asset-management.scanner.hint_lookup'),
    'hint_stocktake'   => t('asset-management.scanner.hint_stocktake'),
    'cam_insecure'     => t('asset-management.scanner.cam_insecure'),
    'cam_unsupported'  => t('asset-management.scanner.cam_unsupported'),
    'cam_starting'     => t('asset-management.scanner.cam_starting'),
    'cam_denied'       => t('asset-management.scanner.cam_denied'),
    'cam_failed'       => t('asset-management.scanner.cam_failed'),
    'torch'            => t('asset-management.scanner.torch'),
    'torch_off'        => t('asset-management.scanner.torch_off'),
    'looking_up'       => t('asset-management.scanner.looking_up'),
    'lookup_failed'    => t('asset-management.scanner.lookup_failed'),
    'not_yours'        => t('asset-management.scanner.not_yours'),
    'no_match'         => t('asset-management.scanner.no_match'),
    'server_error'     => t('asset-management.scanner.server_error'),
    'asset_hash'       => t('asset-management.scan.asset_hash'),
    'already_scanned'  => t('asset-management.scanner.already_scanned'),
    'unchanged'        => t('asset-management.scanner.unchanged'),
    'seen_nothing_set' => t('asset-management.scanner.seen_nothing_set'),
    'already_set'      => t('asset-management.scanner.already_set'),
    'saving'           => t('common.saving'),
    'update_failed'    => t('asset-management.scanner.update_failed'),
    'updated'          => t('asset-management.scanner.updated'),
    'field_status'     => t('asset-management.scanner.field_status'),
    'field_location'   => t('asset-management.scanner.field_location'),
    'both_fields'      => t('asset-management.scanner.both_fields'),
    'undoing'          => t('asset-management.scanner.undoing'),
    'put_back'         => t('asset-management.scanner.put_back'),
    'note_seen'        => t('asset-management.scanner.note_seen'),
    'note_already_set' => t('asset-management.scanner.note_already_set'),
    'note_updated'     => t('asset-management.scanner.note_updated'),
    'note_undone'      => t('asset-management.scanner.note_undone'),
    'counter'          => t('asset-management.scanner.counter'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

/** Fill {placeholders} in one of S's strings. */
function sfmt(str, vals) {
    return Object.keys(vals).reduce((s, k) => s.split('{' + k + '}').join(vals[k]), str);
}

const API   = <?php echo json_encode(BASE_URL . 'api/assets/'); ?>;
const JSQR  = <?php echo json_encode(BASE_URL . 'assets/js/vendor/jsQR.js'); ?>;

let mode = 'lookup';
let stream = null, track = null, detector = null, decodeTimer = null;
let torchOn = false;
let lastText = '', lastAt = 0;          // debounce: the camera sees the same code many times a second
let busy = false;                        // one lookup at a time, or a slow network queues scans
let session = [];                        // {id, label, note}
let seenIds = new Set();                 // assets already recorded this session
let undoable = null;                     // {assetId, changes, label}

/* ---------------------------------------------------------------- mode --- */

function setMode(m) {
    mode = m;
    document.body.dataset.mode = m;
    document.getElementById('modeLookup').classList.toggle('on', m === 'lookup');
    document.getElementById('modeStock').classList.toggle('on', m === 'stocktake');
    document.getElementById('modeHint').textContent = (m === 'lookup') ? S.hint_lookup : S.hint_stocktake;
    if (m === 'stocktake') loadPickers();
}

/* The two dropdowns come from the module's own endpoints, which are already
   company-scoped by two DIFFERENT rules (locations are scoped data; status
   types are a config list). Re-implementing either here would be a second copy
   destined to drift — the same call scan.php makes. */
let pickersLoaded = false;
async function loadPickers() {
    if (pickersLoaded) return;
    pickersLoaded = true;
    await Promise.all([
        fillSelect('stStatus',   API + 'get_asset_status_types.php',
                   d => (d.asset_status_types || []).filter(s => s.is_active !== false)),
        fillSelect('stLocation', API + 'get_asset_locations.php', d => d.locations || [])
    ]);
}

async function fillSelect(elId, url, pick) {
    const el = document.getElementById(elId);
    try {
        const res  = await fetch(url);
        const data = await res.json();
        (pick(data) || []).forEach(r => {
            const o = document.createElement('option');
            o.value = String(Number(r.id));
            o.textContent = r.name;
            el.appendChild(o);
        });
    } catch (e) {
        el.insertAdjacentHTML('beforeend', '<option disabled>Could not load</option>');
    }
}

/* -------------------------------------------------------------- camera --- */

async function startCamera() {
    const state = document.getElementById('camstate');
    document.getElementById('retryBtn').hidden = true;

    // A dead black rectangle is the worst possible answer here, so name the
    // actual cause. Outside HTTPS the browser refuses before we even ask.
    if (!window.isSecureContext) {
        state.innerHTML = S.cam_insecure;
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        state.textContent = S.cam_unsupported;
        return;
    }

    try {
        state.textContent = S.cam_starting;
        stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' } },   // the back camera, on a phone
            audio: false
        });
        const video = document.getElementById('video');
        video.srcObject = stream;
        await video.play();
        track = stream.getVideoTracks()[0] || null;
        state.hidden = true;

        // Torch is a real difference in a dim store room, but only some devices
        // expose it — show the button when it exists rather than promising it.
        const caps = (track && track.getCapabilities) ? track.getCapabilities() : {};
        document.getElementById('torch').hidden = !caps.torch;

        await startDecoding(video);
    } catch (e) {
        state.innerHTML = (e && e.name === 'NotAllowedError') ? S.cam_denied : S.cam_failed;
        document.getElementById('retryBtn').hidden = false;
    }
}

function toggleTorch() {
    if (!track) return;
    torchOn = !torchOn;
    track.applyConstraints({ advanced: [{ torch: torchOn }] }).catch(() => { torchOn = !torchOn; });
    document.getElementById('torch').textContent = torchOn ? S.torch_off : S.torch;
}

/* Chrome/Android decodes in the browser; iOS Safari has no BarcodeDetector, so
   jsQR is loaded ONLY on that path — Android never downloads a decoder it isn't
   going to use. */
async function startDecoding(video) {
    if ('BarcodeDetector' in window) {
        try {
            const formats = await window.BarcodeDetector.getSupportedFormats();
            if (formats.includes('qr_code')) {
                detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            }
        } catch (e) { detector = null; }
    }
    if (!detector) await loadScript(JSQR);

    const canvas = document.createElement('canvas');
    const ctx    = canvas.getContext('2d', { willReadFrequently: true });

    // Every frame is far more work than the job needs and it cooks the battery
    // on a long stocktake; five times a second feels instant to a human hand.
    decodeTimer = setInterval(async () => {
        if (busy || !video.videoWidth) return;
        try {
            if (detector) {
                const hits = await detector.detect(video);
                if (hits && hits.length) onCode(hits[0].rawValue);
                return;
            }
            canvas.width  = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const hit = window.jsQR ? window.jsQR(img.data, img.width, img.height) : null;
            if (hit && hit.data) onCode(hit.data);
        } catch (e) { /* a dropped frame is not an error worth showing */ }
    }, 200);
}

function loadScript(src) {
    return new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = src; s.onload = resolve; s.onerror = reject;
        document.head.appendChild(s);
    });
}

/* ------------------------------------------------------------ resolving --- */

/**
 * A decoded string is one of two things: our own label (a URL ending /a/<token>)
 * or whatever else was printed on the kit — most usefully the manufacturer's
 * serial barcode. Each has an endpoint that already enforces company scope, so
 * the only decision made here is which one to ask.
 */
function tokenFrom(text) {
    const m = String(text || '').match(/^https?:\/\/\S*?\/a\/([A-Za-z0-9]{6,})\/?$/);
    return m ? m[1] : null;
}

function onCode(text) {
    const now = Date.now();
    // The camera reads the same code several times a second; without this a
    // single label would be "scanned" twenty times.
    if (text === lastText && now - lastAt < 2500) return;
    lastText = text; lastAt = now;
    feedback();
    handle(text);
}

async function handle(text) {
    if (busy) return;
    busy = true;
    setLive(S.looking_up, '');
    try {
        const token = tokenFrom(text);
        const url = token
            ? API + 'resolve_scan.php?token=' + encodeURIComponent(token)
            : API + 'find_asset.php?q=' + encodeURIComponent(text);
        const res  = await fetch(url);
        const data = await res.json();

        if (!data.success) { showMiss(data.error || S.lookup_failed); return; }
        if (!data.asset) {
            showMiss(token ? S.not_yours : sfmt(S.no_match, { q: text }));
            return;
        }
        await act(data.asset);
    } catch (e) {
        showMiss(sfmt(S.server_error, { message: e.message }));
    } finally {
        busy = false;
    }
}

function labelFor(a) {
    return a.asset_tag || a.hostname || a.service_tag || sfmt(S.asset_hash, { id: a.id });
}

/** What a found asset means depends on the mode — the one decision this page makes. */
async function act(a) {
    if (mode === 'lookup') {
        stopCamera();
        window.location.href = './?asset_id=' + encodeURIComponent(a.id);
        return;
    }

    // Resting the camera on one label re-reads it every couple of seconds, which
    // would list the same laptop five times and report a 300-asset stocktake as
    // 500. Counting each asset once is the only honest number here; the debounce
    // above is about network chatter, this is about the tally being true.
    if (seenIds.has(a.id)) {
        showFound(a, sfmt(S.already_scanned, { status: a.status_name || S.unchanged }));
        return;
    }

    const statusId = document.getElementById('stStatus').value;
    const locId    = document.getElementById('stLocation').value;

    if (!statusId && !locId) {
        showFound(a, S.seen_nothing_set);
        addSession(a, S.note_seen);
        return;
    }

    // Deliberately compare before sending. The service ignores an unchanged
    // value anyway, but saying "already In Storage" is a truer answer than
    // "Saved" and stops a stocktake looking like it rewrote everything.
    const changes = [];
    if (statusId && String(a.asset_status_id || '') !== String(statusId)) {
        changes.push({ field: 'asset_status_id', value: statusId, was: a.asset_status_id });
    }
    if (locId && String(a.location_id || '') !== String(locId)) {
        changes.push({ field: 'location_id', value: locId, was: a.location_id });
    }

    if (!changes.length) {
        showFound(a, S.already_set);
        addSession(a, S.note_already_set);
        return;
    }

    setLive(S.saving, '');
    for (const ch of changes) {
        const ok = await save(a.id, ch.field, ch.value);
        if (!ok) { showMiss(sfmt(S.update_failed, { label: labelFor(a) })); return; }
    }

    // Only the last change is undoable, and only the single most recent asset:
    // a full multi-step history would be a promise this page can't honestly
    // keep once somebody walks off and scans thirty more.
    undoable = { assetId: a.id, changes: changes, label: labelFor(a) };
    document.getElementById('undoBtn').disabled = false;

    const words = changes.map(c => c.field === 'asset_status_id' ? S.field_status : S.field_location);
    const what = words.length > 1 ? sfmt(S.both_fields, { a: words[0], b: words[1] }) : words[0];
    showFound(a, sfmt(S.updated, { what: what }));
    addSession(a, sfmt(S.note_updated, { what: what }));
}

async function save(assetId, field, value) {
    try {
        const res = await fetch(API + 'update_asset_field.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ asset_id: assetId, field: field, value: value === '' ? null : value })
        });
        const data = await res.json();
        return !!data.success;
    } catch (e) { return false; }
}

async function undoLast() {
    if (!undoable) return;
    const btn = document.getElementById('undoBtn');
    btn.disabled = true;
    setLive(S.undoing, '');
    for (const ch of undoable.changes) {
        await save(undoable.assetId, ch.field, ch.was === null || ch.was === undefined ? '' : String(ch.was));
    }
    setLive(sfmt(S.put_back, { label: undoable.label }), 'ok');
    // The session list is a record of what happened, so the undo is recorded
    // rather than the original line being quietly deleted. Forgetting the id
    // re-arms the asset: having undone it, you are very likely about to scan it
    // again with the right settings.
    const undoneId = undoable.assetId;
    addSession({ id: undoneId, asset_tag: undoable.label }, S.note_undone);
    seenIds.delete(undoneId);
    updateCounter();
    undoable = null;
}

/* ------------------------------------------------------------------ ui --- */

function setLive(msg, cls) {
    const el = document.getElementById('live');
    el.className = 'live ' + (cls || '');
    el.textContent = msg;
}

function showFound(a, note) {
    setLive(note, 'ok');
    document.getElementById('result').innerHTML =
        '<div class="found"><strong>' + esc(labelFor(a)) + '</strong>' +
        esc([a.hostname, a.type_name, a.status_name].filter(Boolean).join(' · ')) + '</div>';
}

function showMiss(msg) {
    setLive('', '');
    document.getElementById('result').innerHTML = '<div class="miss">' + esc(msg) + '</div>';
}

function addSession(a, note) {
    if (a.id) seenIds.add(a.id);
    session.push({ label: labelFor(a), note: note });
    document.getElementById('sessionCard').hidden = false;
    const li = document.createElement('li');
    li.textContent = labelFor(a) + ' — ' + note;
    document.getElementById('doneList').prepend(li);
    updateCounter();
}

/* Distinct assets, not lines in the list — an undone one stops counting as done,
   and a re-read of the same label was never a second asset. */
function updateCounter() {
    document.getElementById('counter').textContent = sfmt(S.counter, { n: seenIds.size });
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** A short buzz/blip, because in a store room you are looking at the kit, not the screen. */
let audioCtx = null;
function feedback() {
    if (navigator.vibrate) { try { navigator.vibrate(35); } catch (e) {} }
    try {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        const o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.frequency.value = 880; g.gain.value = 0.05;
        o.connect(g); g.connect(audioCtx.destination);
        o.start(); o.stop(audioCtx.currentTime + 0.07);
    } catch (e) { /* audio is a nicety, never a failure */ }
}

async function manualLookup() {
    const el = document.getElementById('manual');
    const q = el.value.trim();
    if (!q) return;
    el.value = '';
    lastText = ''; // typed input should never be swallowed by the camera debounce
    await handle(q);
}

function stopCamera() {
    if (decodeTimer) clearInterval(decodeTimer);
    if (stream) stream.getTracks().forEach(t => t.stop());
}

// Releasing the camera when the page is hidden stops the phone sitting there
// with the light on and the sensor running in a pocket.
document.addEventListener('visibilitychange', () => { if (document.hidden) stopCamera(); });
window.addEventListener('pagehide', stopCamera);

setMode('lookup');
startCamera();
</script>
<?php endif; ?>
</body>
</html>
