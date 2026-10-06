<?php
/**
 * System — Photo Album. Just for fun.
 *
 * Takes a picture with the webcam and turns it into ASCII art, live, as you
 * move. Three styles: Braille (each character is a 2x4 grid of dots, so eight
 * times the detail of ordinary ASCII), Classic (a 70-step character ramp) and
 * Blocks (shade characters). Four screens: green, amber, paper, and colour.
 *
 * 🔑 No AI and no server work: the conversion is plain arithmetic in the
 * browser (brightness -> character, with error-diffusion dithering). The PHOTO
 * NEVER LEAVES THE BROWSER - only the finished art is sent, and only on Save.
 * Each analyst's album is their own (api/system/photo_album.php).
 *
 * The camera needs a secure context (https, or localhost). Without one the
 * page says so and Upload still works.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
$current_page = 'photo-album';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
requireModuleAccess('system');
$paT = fn(string $k) => t('system.photo_album.' . $k);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName()); ?> - <?php echo htmlspecialchars($paT('title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
            display: flex; flex-direction: column;
        }
        .pa-container { flex: 1; overflow-y: auto; padding: 24px 32px 40px; box-sizing: border-box; width: 100%; }
        .pa-header h2 { margin: 0; font-size: 22px; color: var(--text, #333); }
        .pa-header p { margin: 5px 0 20px; font-size: 13px; color: var(--text-dim, #888); line-height: 1.55; }
        .pa-verify { background: #fff8e1; border: 1px solid #ffe082; color: #7a5b00; border-radius: 8px; padding: 12px 16px; font-size: 13px; margin-bottom: 16px; }

        .pa-studio { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 16px; align-items: start; }
        .pa-panel { background: var(--surface, #fff); border: 1px solid var(--border-soft, #e5e5e5); border-radius: 10px; padding: 16px; }

        /* The stage: always a "screen" in the chosen palette's own colours. */
        .pa-stage { position: relative; border-radius: 8px; background: #020a02; min-height: 360px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .pa-stage canvas { display: block; max-width: 100%; height: auto; }
        .pa-empty { color: #5f7f66; font-family: ui-monospace, Consolas, monospace; font-size: 13px; text-align: center; padding: 24px; line-height: 1.6; }
        .pa-badge { position: absolute; top: 10px; left: 10px; font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; padding: 3px 9px; border-radius: 10px; background: rgba(0,0,0,.55); color: #fff; }
        .pa-badge.live::before { content: ''; display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: #ff3b30; margin-right: 6px; vertical-align: 1px; animation: paPulse 1.2s infinite; }
        @keyframes paPulse { 50% { opacity: .3; } }
        @media (prefers-reduced-motion: reduce) { .pa-badge.live::before { animation: none; } }
        /* The camera feeds the converter but is never shown: the art IS the preview.
           Not display:none - a hidden video can stop producing frames. */
        .pa-video { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }

        .pa-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; align-items: center; }
        .pa-actions .spacer { flex: 1; }
        .pa-menu-wrap { position: relative; }
        .pa-menu { position: absolute; right: 0; bottom: calc(100% + 4px); z-index: 30; min-width: 170px; background: var(--surface, #fff); border: 1px solid var(--border, #ddd); border-radius: 8px; box-shadow: 0 6px 20px rgba(0,0,0,.15); padding: 4px; }
        .pa-menu button { display: block; width: 100%; text-align: left; background: none; border: none; padding: 8px 12px; border-radius: 5px; font-size: 13px; color: var(--text, #333); cursor: pointer; }
        .pa-menu button:hover { background: var(--surface-2, #eceff1); }

        /* Controls */
        .pa-group { margin-bottom: 16px; }
        .pa-group:last-child { margin-bottom: 0; }
        .pa-group > label, .pa-label { display: flex; justify-content: space-between; font-size: 13px; font-weight: 600; color: var(--text, #444); margin-bottom: 6px; }
        .pa-label span { font-weight: 400; color: var(--text-dim, #888); font-variant-numeric: tabular-nums; }
        .pa-seg { display: flex; border: 1px solid var(--border, #ddd); border-radius: 7px; overflow: hidden; }
        .pa-seg button { flex: 1; border: none; background: var(--surface, #fff); color: var(--text, #444); padding: 7px 4px; font-size: 13px; cursor: pointer; }
        .pa-seg button + button { border-left: 1px solid var(--border, #ddd); }
        .pa-seg button.on { background: var(--accent); color: var(--on-accent); }
        .pa-group select { width: 100%; padding: 7px 9px; border: 1px solid var(--border, #ddd); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #333); }
        .pa-group input[type=range] { width: 100%; accent-color: var(--accent); }
        .pa-switch { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; font-size: 13px; color: var(--text, #444); }
        .pa-switch strong { display: block; font-weight: 600; }
        .pa-switch small { display: block; color: var(--text-dim, #888); font-size: 12px; margin-top: 1px; }

        /* Album */
        .pa-album { margin-top: 24px; }
        .pa-album-head { display: flex; align-items: baseline; gap: 10px; margin-bottom: 12px; }
        .pa-album-head h3 { margin: 0; font-size: 16px; color: var(--text, #333); }
        .pa-album-head span { font-size: 13px; color: var(--text-dim, #888); }
        .pa-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 14px; }
        .pa-card { background: var(--surface, #fff); border: 1px solid var(--border-soft, #e5e5e5); border-radius: 10px; overflow: hidden; cursor: pointer; text-align: left; padding: 0; font: inherit; color: inherit; transition: box-shadow .15s, transform .15s; }
        .pa-card:hover, .pa-card:focus-visible { box-shadow: 0 4px 14px rgba(0,0,0,.12); transform: translateY(-1px); }
        .pa-card img { display: block; width: 100%; aspect-ratio: 4 / 3; object-fit: contain; background: #020a02; }
        .pa-card div { padding: 9px 11px; }
        .pa-card strong { display: block; font-size: 13px; color: var(--text, #333); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pa-card small { font-size: 12px; color: var(--text-dim, #888); }
        .pa-album-empty { font-size: 13px; color: var(--text-faint, #999); padding: 18px 0; }

        /* Modals - namespaced so inbox.css's global .modal rules stay out of it. */
        .pa-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55); z-index: 2100; align-items: center; justify-content: center; }
        .pa-overlay.open { display: flex; }
        .pa-modal { background: var(--surface, #fff); border-radius: 10px; width: 440px; max-width: 94vw; max-height: 92vh; display: flex; flex-direction: column; box-shadow: 0 10px 40px rgba(0,0,0,.3); }
        .pa-modal.wide { width: 1100px; }
        .pa-modal-head { padding: 16px 20px; border-bottom: 1px solid var(--border-soft, #eee); font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .pa-modal-head small { display: block; font-size: 12px; font-weight: 400; color: var(--text-dim, #888); margin-top: 2px; }
        .pa-modal-body { padding: 16px 20px; overflow: auto; }
        .pa-modal-foot { padding: 12px 20px; border-top: 1px solid var(--border-soft, #eee); display: flex; justify-content: flex-end; gap: 8px; align-items: center; }
        .pa-modal-foot .spacer { flex: 1; }
        .pa-modal-body label { display: block; font-size: 13px; font-weight: 600; color: var(--text, #444); margin-bottom: 4px; }
        .pa-modal-body input[type=text] { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #333); }
        .pa-viewer { border-radius: 8px; display: flex; justify-content: center; }
        .pa-viewer canvas { display: block; max-width: 100%; height: auto; }
        .pa-modal-foot .pa-menu { bottom: calc(100% + 4px); }
        .pa-modal-foot .btn-danger { background: none; border: 1px solid var(--danger-accent, #d13438); color: var(--danger-accent, #d13438); }
        .pa-modal-foot .btn-danger:hover { background: var(--danger-bg, #fdf3f3); color: var(--danger-text, #a00); }

        [data-theme-mode="dark"] .pa-verify { background: #3a2e12; border-color: #6b5417; color: #fcd34d; }

        @media (max-width: 900px) {
            .pa-container { padding: 16px; }
            .pa-studio { grid-template-columns: minmax(0, 1fr); }
            .pa-stage { min-height: 240px; }
        }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=183">
</head>
<body data-mobile-module="system" data-mobile-page="photo-album">
    <?php include '../includes/header.php'; ?>
    <div class="pa-container">
        <div class="pa-header">
            <h2><?php echo htmlspecialchars($paT('title')); ?></h2>
            <p><?php echo htmlspecialchars($paT('intro')); ?></p>
        </div>

        <div class="pa-verify" id="paVerify" hidden><?php echo htmlspecialchars($paT('needs_verify')); ?></div>

        <div class="pa-studio">
            <div class="pa-panel">
                <div class="pa-stage" id="paStage">
                    <canvas id="paArt" hidden></canvas>
                    <div class="pa-empty" id="paEmpty"><?php echo htmlspecialchars($paT('empty_stage')); ?></div>
                    <span class="pa-badge" id="paBadge" hidden></span>
                    <video class="pa-video" id="paVideo" playsinline muted autoplay></video>
                </div>
                <div class="pa-actions">
                    <button type="button" class="btn btn-primary" id="paStart" title="<?php echo htmlspecialchars($paT('start_hint')); ?>"><?php echo htmlspecialchars($paT('start')); ?></button>
                    <button type="button" class="btn btn-primary" id="paCapture" hidden><?php echo htmlspecialchars($paT('capture')); ?></button>
                    <button type="button" class="btn btn-secondary" id="paRetake" hidden><?php echo htmlspecialchars($paT('retake')); ?></button>
                    <button type="button" class="btn btn-secondary" id="paFlip" hidden title="<?php echo htmlspecialchars($paT('flip_hint')); ?>"><?php echo htmlspecialchars($paT('flip')); ?></button>
                    <button type="button" class="btn btn-secondary" id="paUpload" title="<?php echo htmlspecialchars($paT('upload_hint')); ?>"><?php echo htmlspecialchars($paT('upload')); ?></button>
                    <input type="file" id="paFile" accept="image/*" hidden>
                    <span class="spacer"></span>
                    <button type="button" class="btn btn-secondary" id="paCopy" hidden><?php echo htmlspecialchars($paT('copy')); ?></button>
                    <div class="pa-menu-wrap" id="paDownloadWrap" hidden>
                        <button type="button" class="btn btn-secondary" data-menu-toggle><?php echo htmlspecialchars($paT('download')); ?></button>
                        <div class="pa-menu" hidden>
                            <button type="button" data-download="txt"><?php echo htmlspecialchars($paT('download_txt')); ?></button>
                            <button type="button" data-download="png"><?php echo htmlspecialchars($paT('download_png')); ?></button>
                        </div>
                    </div>
                    <button type="button" class="btn btn-primary" id="paSave" hidden><?php echo htmlspecialchars($paT('save')); ?></button>
                </div>
            </div>

            <div class="pa-panel">
                <div class="pa-group">
                    <div class="pa-label"><?php echo htmlspecialchars($paT('style')); ?></div>
                    <div class="pa-seg" id="paStyle">
                        <button type="button" data-style="braille"><?php echo htmlspecialchars($paT('style_braille')); ?></button>
                        <button type="button" data-style="classic"><?php echo htmlspecialchars($paT('style_classic')); ?></button>
                        <button type="button" data-style="blocks"><?php echo htmlspecialchars($paT('style_blocks')); ?></button>
                    </div>
                </div>
                <div class="pa-group">
                    <label for="paPalette"><?php echo htmlspecialchars($paT('palette')); ?></label>
                    <select id="paPalette">
                        <option value="green"><?php echo htmlspecialchars($paT('palette_green')); ?></option>
                        <option value="amber"><?php echo htmlspecialchars($paT('palette_amber')); ?></option>
                        <option value="paper"><?php echo htmlspecialchars($paT('palette_paper')); ?></option>
                        <option value="colour"><?php echo htmlspecialchars($paT('palette_colour')); ?></option>
                    </select>
                </div>
                <div class="pa-group">
                    <div class="pa-label"><?php echo htmlspecialchars($paT('width')); ?> <span id="paWidthVal"></span></div>
                    <input type="range" id="paWidth" min="60" max="300" step="4" aria-label="<?php echo htmlspecialchars($paT('width')); ?>">
                </div>
                <div class="pa-group">
                    <div class="pa-label"><?php echo htmlspecialchars($paT('brightness')); ?> <span id="paBrightVal"></span></div>
                    <input type="range" id="paBright" min="-50" max="50" step="1" aria-label="<?php echo htmlspecialchars($paT('brightness')); ?>">
                </div>
                <div class="pa-group">
                    <div class="pa-label"><?php echo htmlspecialchars($paT('contrast')); ?> <span id="paContrastVal"></span></div>
                    <input type="range" id="paContrast" min="50" max="250" step="5" aria-label="<?php echo htmlspecialchars($paT('contrast')); ?>">
                </div>
                <div class="pa-group">
                    <div class="pa-label"><?php echo htmlspecialchars($paT('sharpen')); ?> <span id="paSharpVal"></span></div>
                    <input type="range" id="paSharp" min="0" max="100" step="5" aria-label="<?php echo htmlspecialchars($paT('sharpen')); ?>">
                </div>
                <div class="pa-switch">
                    <div><strong><?php echo htmlspecialchars($paT('dither')); ?></strong><small><?php echo htmlspecialchars($paT('dither_hint')); ?></small></div>
                    <label class="toggle-switch"><input type="checkbox" id="paDither"><span class="toggle-slider"></span></label>
                </div>
                <div class="pa-switch">
                    <div><strong><?php echo htmlspecialchars($paT('mirror')); ?></strong><small><?php echo htmlspecialchars($paT('mirror_hint')); ?></small></div>
                    <label class="toggle-switch"><input type="checkbox" id="paMirror"><span class="toggle-slider"></span></label>
                </div>
            </div>
        </div>

        <div class="pa-album">
            <div class="pa-album-head">
                <h3><?php echo htmlspecialchars($paT('album')); ?></h3>
                <span id="paAlbumCount"></span>
            </div>
            <div class="pa-grid" id="paGrid"></div>
            <div class="pa-album-empty" id="paAlbumEmpty" hidden><?php echo htmlspecialchars($paT('album_empty')); ?></div>
        </div>
    </div>

    <!-- Save -->
    <div class="pa-overlay" id="paSaveModal">
        <div class="pa-modal" role="dialog" aria-modal="true" aria-labelledby="paSaveTitle">
            <div class="pa-modal-head" id="paSaveTitle"><?php echo htmlspecialchars($paT('save_title')); ?></div>
            <div class="pa-modal-body">
                <label for="paTitle"><?php echo htmlspecialchars($paT('field_title')); ?></label>
                <input type="text" id="paTitle" maxlength="150" autocomplete="off">
            </div>
            <div class="pa-modal-foot">
                <button type="button" class="btn btn-secondary" data-close><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="paSaveConfirm"><?php echo htmlspecialchars($paT('save')); ?></button>
            </div>
        </div>
    </div>

    <!-- View a saved picture -->
    <div class="pa-overlay" id="paViewModal">
        <div class="pa-modal wide" role="dialog" aria-modal="true" aria-labelledby="paViewTitle">
            <div class="pa-modal-head" id="paViewTitle"><span id="paViewName"></span><small id="paViewMeta"></small></div>
            <div class="pa-modal-body"><div class="pa-viewer" id="paViewer"><canvas id="paViewArt"></canvas></div></div>
            <div class="pa-modal-foot">
                <button type="button" class="btn btn-danger" id="paViewDelete"><?php echo htmlspecialchars($paT('delete')); ?></button>
                <span class="spacer"></span>
                <button type="button" class="btn btn-secondary" id="paViewCopy"><?php echo htmlspecialchars($paT('copy')); ?></button>
                <div class="pa-menu-wrap">
                    <button type="button" class="btn btn-secondary" data-menu-toggle><?php echo htmlspecialchars($paT('download')); ?></button>
                    <div class="pa-menu" hidden>
                        <button type="button" data-view-download="txt"><?php echo htmlspecialchars($paT('download_txt')); ?></button>
                        <button type="button" data-view-download="png"><?php echo htmlspecialchars($paT('download_png')); ?></button>
                    </div>
                </div>
                <button type="button" class="btn btn-secondary" data-close><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js"></script>
    <script>
    (function () {
        'use strict';
        const API = '../../api/system/photo_album.php';
        const $ = id => document.getElementById(id);
        const T = (k, p) => t('system.photo_album.' + k, p);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
        const toast = (m, k) => { if (typeof showToast === 'function') showToast(m, k); };

        // ══ The converter ═══════════════════════════════════════════════════
        // Plain arithmetic, no AI: shrink the picture to a grid, turn each
        // cell's brightness into a character, and scatter the rounding error to
        // the neighbours (dithering) so shading stays smooth.

        // 70 steps from empty to full (Paul Bourke's ramp).
        const RAMP_CLASSIC = " .'`^\",:;Il!i><~+_-?][}{1)(|\\/tfjrxnuvczXYUJCLQ0OZmwqpdbkhao*#MW&8%B@$";
        const RAMP_BLOCKS  = [' ', '░', '▒', '▓', '█'];
        const FONT = '"DejaVu Sans Mono", Consolas, "Cascadia Mono", Menlo, "Segoe UI Symbol", "Noto Sans Symbols 2", monospace';
        // dark: light ink on a dark screen, so BRIGHT pixels get the dense characters.
        const PALETTES = {
            green:  { bg: '#020a02', fg: '#39ff6a', dark: true },
            amber:  { bg: '#0d0700', fg: '#ffb000', dark: true },
            paper:  { bg: '#fbfaf6', fg: '#1d1d1d', dark: false },
            colour: { bg: '#000000', fg: '#ffffff', dark: true },
        };
        const GLYPH = { braille: '⣿', classic: 'M', blocks: '█' };
        const BLANK = { braille: '⠀', classic: ' ', blocks: ' ' };

        // A character's width as a fraction of its height, measured in the real
        // font, so the art keeps the photo's proportions in any browser.
        const aspectCache = {};
        function glyphAspect(style) {
            if (aspectCache[style]) return aspectCache[style];
            const c = document.createElement('canvas').getContext('2d');
            c.font = '100px ' + FONT;
            return (aspectCache[style] = Math.max(0.3, Math.min(1, c.measureText(GLYPH[style]).width / 100)));
        }

        const work = document.createElement('canvas');
        const workCtx = work.getContext('2d', { willReadFrequently: true });
        const tint = document.createElement('canvas');
        const tintCtx = tint.getContext('2d', { willReadFrequently: true });

        function drawScaled(ctx, canvas, src, sw, sh, w, h, mirror) {
            canvas.width = w; canvas.height = h;
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            if (mirror) { ctx.translate(w, 0); ctx.scale(-1, 1); }
            ctx.drawImage(src, 0, 0, sw, sh, 0, 0, w, h);
            return ctx.getImageData(0, 0, w, h).data;
        }

        function convert(src, sw, sh, s) {
            const cols = s.width;
            const rows = Math.max(5, Math.min(400, Math.round(cols * (sh / sw) * glyphAspect(s.style))));
            const sx = s.style === 'braille' ? 2 : 1, sy = s.style === 'braille' ? 4 : 1;
            const gw = cols * sx, gh = rows * sy, n = gw * gh;
            const px = drawScaled(workCtx, work, src, sw, sh, gw, gh, s.mirror);
            const contrast = s.contrast / 100, bright = s.brightness / 100;
            const dark = PALETTES[s.palette].dark;

            let L = new Float32Array(n);
            for (let i = 0, p = 0; i < n; i++, p += 4) {
                const y = (0.2126 * px[p] + 0.7152 * px[p + 1] + 0.0722 * px[p + 2]) / 255;
                L[i] = (y - 0.5) * contrast + 0.5 + bright;
            }
            if (s.sharpen > 0) L = sharpen(L, gw, gh, s.sharpen / 50);
            for (let i = 0; i < n; i++) {
                const v = L[i] < 0 ? 0 : L[i] > 1 ? 1 : L[i];
                L[i] = dark ? v : 1 - v;   // L is now "ink": how much character this cell wants
            }

            const lines = s.style === 'braille' ? toBraille(L, gw, gh, cols, rows, s.dither)
                        : toRamp(L, cols, rows, s.style === 'blocks' ? RAMP_BLOCKS : RAMP_CLASSIC, s.dither);

            let colours = null;
            if (s.palette === 'colour') {
                const cp = drawScaled(tintCtx, tint, src, sw, sh, cols, rows, s.mirror);
                colours = new Uint8Array(cols * rows * 3);
                for (let i = 0, p = 0, o = 0; i < cols * rows; i++, p += 4, o += 3) {
                    for (let k = 0; k < 3; k++) {
                        const v = ((cp[p + k] / 255 - 0.5) * contrast + 0.5 + bright) * 255;
                        colours[o + k] = v < 0 ? 0 : v > 255 ? 255 : v;
                    }
                }
            }
            return { style: s.style, palette: s.palette, cols, rows, lines, colours };
        }

        // Unsharp mask: push each pixel away from its neighbourhood average.
        function sharpen(L, w, h, amount) {
            const out = new Float32Array(L.length);
            for (let y = 0; y < h; y++) {
                for (let x = 0; x < w; x++) {
                    let sum = 0, cnt = 0;
                    for (let dy = -1; dy <= 1; dy++) {
                        const yy = y + dy; if (yy < 0 || yy >= h) continue;
                        for (let dx = -1; dx <= 1; dx++) {
                            const xx = x + dx; if (xx < 0 || xx >= w) continue;
                            sum += L[yy * w + xx]; cnt++;
                        }
                    }
                    const i = y * w + x;
                    out[i] = L[i] + amount * (L[i] - sum / cnt);
                }
            }
            return out;
        }

        // Braille: every character is 2 dots across by 4 down, eight dots that
        // are each on or off. Atkinson dithering - the classic Macintosh one -
        // keeps faces crisp at this size.
        const DOT = [[0x01, 0x08], [0x02, 0x10], [0x04, 0x20], [0x40, 0x80]];   // [row][col]
        function toBraille(L, gw, gh, cols, rows, dither) {
            const on = new Uint8Array(gw * gh);
            for (let y = 0; y < gh; y++) {
                for (let x = 0; x < gw; x++) {
                    const i = y * gw + x;
                    const v = L[i], q = v >= 0.5 ? 1 : 0;
                    on[i] = q;
                    if (!dither) continue;
                    const e = (v - q) / 8;
                    if (x + 1 < gw) L[i + 1] += e;
                    if (x + 2 < gw) L[i + 2] += e;
                    if (y + 1 < gh) {
                        if (x > 0) L[i + gw - 1] += e;
                        L[i + gw] += e;
                        if (x + 1 < gw) L[i + gw + 1] += e;
                    }
                    if (y + 2 < gh) L[i + 2 * gw] += e;
                }
            }
            const lines = new Array(rows);
            for (let r = 0; r < rows; r++) {
                let line = '';
                for (let c = 0; c < cols; c++) {
                    let bits = 0;
                    for (let dy = 0; dy < 4; dy++) {
                        const base = (r * 4 + dy) * gw + c * 2;
                        if (on[base]) bits |= DOT[dy][0];
                        if (on[base + 1]) bits |= DOT[dy][1];
                    }
                    line += String.fromCharCode(0x2800 + bits);
                }
                lines[r] = line;
            }
            return lines;
        }

        // Classic and Blocks: brightness picks a step on the ramp, and
        // Floyd-Steinberg passes the leftover on to the cells not yet drawn.
        function toRamp(L, cols, rows, ramp, dither) {
            const steps = ramp.length - 1;
            const lines = new Array(rows);
            for (let r = 0; r < rows; r++) {
                let line = '';
                for (let c = 0; c < cols; c++) {
                    const i = r * cols + c;
                    const v = L[i] < 0 ? 0 : L[i] > 1 ? 1 : L[i];
                    const q = Math.round(v * steps);
                    line += ramp[q];
                    if (!dither) continue;
                    const e = L[i] - q / steps;
                    if (c + 1 < cols) L[i + 1] += e * 7 / 16;
                    if (r + 1 < rows) {
                        if (c > 0) L[i + cols - 1] += e * 3 / 16;
                        L[i + cols] += e * 5 / 16;
                        if (c + 1 < cols) L[i + cols + 1] += e / 16;
                    }
                }
                lines[r] = line;
            }
            return lines;
        }

        // ══ Drawing the art ═════════════════════════════════════════════════
        // Onto a canvas, in the palette's colours. A row of one kind of glyph
        // goes down in one call; Blocks (mixed fonts) and Colour go glyph by glyph.
        function cellMetrics(style, fontPx) {
            return { cw: fontPx * glyphAspect(style), lh: fontPx };
        }
        function renderArt(canvas, art, fontPx, dpr) {
            const pal = PALETTES[art.palette] || PALETTES.green;
            const { cw, lh } = cellMetrics(art.style, fontPx);
            canvas.width = Math.max(1, Math.ceil(art.cols * cw * dpr));
            canvas.height = Math.max(1, Math.ceil(art.rows * lh * dpr));
            const ctx = canvas.getContext('2d');
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            ctx.fillStyle = pal.bg;
            ctx.fillRect(0, 0, art.cols * cw, art.rows * lh);
            ctx.font = fontPx + 'px ' + FONT;
            ctx.textBaseline = 'top';
            const blank = BLANK[art.style];
            if (art.colours || art.style === 'blocks') {
                const col = art.colours;
                let last = '';
                for (let r = 0; r < art.rows; r++) {
                    const line = art.lines[r];
                    for (let c = 0; c < art.cols; c++) {
                        const ch = line[c];
                        if (ch === blank || ch === ' ') continue;
                        let fill = pal.fg;
                        if (col) { const o = (r * art.cols + c) * 3; fill = 'rgb(' + col[o] + ',' + col[o + 1] + ',' + col[o + 2] + ')'; }
                        if (fill !== last) { ctx.fillStyle = fill; last = fill; }
                        ctx.fillText(ch, c * cw, r * lh);
                    }
                }
            } else {
                ctx.fillStyle = pal.fg;
                for (let r = 0; r < art.rows; r++) ctx.fillText(art.lines[r], 0, r * lh);
            }
            canvas.dataset.cssWidth = String(art.cols * cw);
        }
        // The biggest font that fits the art inside a box.
        function fitFont(art, boxW, boxH) {
            const a = glyphAspect(art.style);
            return Math.max(2, Math.min(boxW / (art.cols * a), boxH / art.rows));
        }

        // ══ Text, image and clipboard ═══════════════════════════════════════
        const artText = art => art.lines.join('\n');
        function fileName(title, ext) {
            const slug = (title || 'ascii-art').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'ascii-art';
            return slug + '.' + ext;
        }
        function saveBlob(blob, name) {
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = name;
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 1000);
        }
        function download(art, kind, title) {
            if (kind === 'txt') {
                saveBlob(new Blob([artText(art) + '\n'], { type: 'text/plain;charset=utf-8' }), fileName(title, 'txt'));
                return;
            }
            const c = document.createElement('canvas');
            renderArt(c, art, Math.max(8, Math.min(16, 2400 / (art.cols * glyphAspect(art.style)))), 1);
            c.toBlob(b => { if (b) saveBlob(b, fileName(title, 'png')); }, 'image/png');
        }
        async function copyArt(art) {
            const ok = typeof window.copyToClipboard === 'function'
                ? await window.copyToClipboard(artText(art))
                : await navigator.clipboard.writeText(artText(art)).then(() => true, () => false);
            toast(ok ? T('copied') : T('copy_failed'), ok ? 'success' : 'error');
        }
        // A small JPEG of the art for the album grid - the art, never the photo.
        function thumbnail(art) {
            const big = document.createElement('canvas');
            renderArt(big, art, Math.max(3, 720 / (art.cols * glyphAspect(art.style))), 1);
            const scale = Math.min(1, 320 / big.width);
            const t = document.createElement('canvas');
            t.width = Math.round(big.width * scale); t.height = Math.round(big.height * scale);
            const ctx = t.getContext('2d');
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(big, 0, 0, t.width, t.height);
            return t.toDataURL('image/jpeg', 0.82);
        }
        function b64FromBytes(bytes) {
            let s = '';
            for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
            return btoa(s);
        }
        function bytesFromB64(b64) {
            const s = atob(b64), out = new Uint8Array(s.length);
            for (let i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
            return out;
        }

        // ══ Settings (remembered on this device) ════════════════════════════
        const DEFAULTS = { style: 'braille', palette: 'green', width: 160, brightness: 0, contrast: 120, sharpen: 30, dither: true, mirror: true };
        let settings = Object.assign({}, DEFAULTS);
        try { Object.assign(settings, JSON.parse(localStorage.getItem('freeitsm.photoAlbum') || '{}')); } catch (e) {}
        const remember = () => { try { localStorage.setItem('freeitsm.photoAlbum', JSON.stringify(settings)); } catch (e) {} };

        function paintControls() {
            document.querySelectorAll('#paStyle button').forEach(b => b.classList.toggle('on', b.dataset.style === settings.style));
            $('paPalette').value = settings.palette;
            $('paWidth').value = settings.width;
            $('paWidthVal').textContent = T('width_value', { count: settings.width });
            $('paBright').value = settings.brightness;
            $('paBrightVal').textContent = (settings.brightness > 0 ? '+' : '') + settings.brightness;
            $('paContrast').value = settings.contrast;
            $('paContrastVal').textContent = settings.contrast + '%';
            $('paSharp').value = settings.sharpen;
            $('paSharpVal').textContent = settings.sharpen;
            $('paDither').checked = settings.dither;
            $('paMirror').checked = settings.mirror;
            $('paStage').style.background = PALETTES[settings.palette].bg;
        }
        function changed() { remember(); paintControls(); if (state === 'captured') redrawCaptured(); }
        document.querySelectorAll('#paStyle button').forEach(b => b.addEventListener('click', () => { settings.style = b.dataset.style; changed(); }));
        $('paPalette').addEventListener('change', e => { settings.palette = e.target.value; changed(); });
        [['paWidth', 'width'], ['paBright', 'brightness'], ['paContrast', 'contrast'], ['paSharp', 'sharpen']].forEach(([id, key]) =>
            $(id).addEventListener('input', e => { settings[key] = Number(e.target.value); changed(); }));
        $('paDither').addEventListener('change', e => { settings.dither = e.target.checked; changed(); });
        $('paMirror').addEventListener('change', e => { settings.mirror = e.target.checked; changed(); });

        // ══ The studio: idle -> live -> captured ════════════════════════════
        let state = 'idle', stream = null, facing = 'user', current = null, rafId = 0, lastDraw = 0, drawCost = 0;
        const video = $('paVideo');
        const frozen = document.createElement('canvas');   // the captured frame, kept only in this tab
        const albumReady = { ok: true };

        function setState(s) {
            state = s;
            $('paStart').hidden = s !== 'idle';
            $('paCapture').hidden = s !== 'live';
            $('paRetake').hidden = s !== 'captured';
            $('paFlip').hidden = !(s === 'live' && hasManyCameras);
            ['paCopy', 'paDownloadWrap'].forEach(id => $(id).hidden = s !== 'captured');
            $('paSave').hidden = s !== 'captured' || !albumReady.ok;
            $('paEmpty').hidden = s !== 'idle';
            $('paArt').hidden = s === 'idle';
            $('paBadge').hidden = s === 'idle';
            $('paBadge').className = 'pa-badge' + (s === 'live' ? ' live' : '');
            $('paBadge').textContent = s === 'live' ? T('live') : T('captured');
        }

        function showOnStage(art) {
            const stage = $('paStage');
            const boxW = stage.clientWidth - 16, boxH = Math.max(240, Math.min(window.innerHeight * 0.68, 900));
            const fontPx = fitFont(art, boxW, boxH);
            const canvas = $('paArt');
            renderArt(canvas, art, fontPx, window.devicePixelRatio || 1);
            canvas.style.width = canvas.dataset.cssWidth + 'px';
        }

        let hasManyCameras = false;
        async function startCamera() {
            if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                toast(window.isSecureContext ? T('no_camera') : T('no_https'), 'warning');
                return;
            }
            stopCamera();
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
            } catch (e) {
                const name = e && e.name;
                toast(name === 'NotAllowedError' || name === 'SecurityError' ? T('camera_denied')
                    : name === 'NotFoundError' || name === 'OverconstrainedError' ? T('no_camera')
                    : T('camera_failed', { error: (e && e.message) || name || '' }), 'error');
                return;
            }
            video.srcObject = stream;
            try { await video.play(); } catch (e) {}
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                hasManyCameras = devices.filter(d => d.kind === 'videoinput').length > 1;
            } catch (e) { hasManyCameras = false; }
            setState('live');
            loop();
        }
        function stopCamera() {
            cancelAnimationFrame(rafId);
            if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
            video.srcObject = null;
        }
        // Redraw as often as the machine comfortably can.
        function loop(now) {
            rafId = requestAnimationFrame(loop);
            if (state !== 'live' || !video.videoWidth) return;
            now = now || performance.now();
            if (now - lastDraw < Math.max(66, drawCost * 1.5)) return;
            lastDraw = now;
            const t0 = performance.now();
            current = convert(video, video.videoWidth, video.videoHeight, settings);
            showOnStage(current);
            drawCost = performance.now() - t0;
        }
        function capture() {
            if (!video.videoWidth) return;
            frozen.width = video.videoWidth; frozen.height = video.videoHeight;
            frozen.getContext('2d').drawImage(video, 0, 0);
            stopCamera();   // the camera light goes off as soon as you have your picture
            setState('captured');
            redrawCaptured();
        }
        function redrawCaptured() {
            if (!frozen.width) return;
            current = convert(frozen, frozen.width, frozen.height, settings);
            showOnStage(current);
        }

        $('paStart').addEventListener('click', startCamera);
        $('paCapture').addEventListener('click', capture);
        $('paRetake').addEventListener('click', startCamera);
        $('paFlip').addEventListener('click', () => {
            facing = facing === 'user' ? 'environment' : 'user';
            settings.mirror = facing === 'user';
            paintControls();
            startCamera();
        });
        $('paUpload').addEventListener('click', () => $('paFile').click());
        $('paFile').addEventListener('change', function () {
            const f = this.files && this.files[0];
            this.value = '';
            if (!f) return;
            const img = new Image();
            const url = URL.createObjectURL(f);
            img.onload = () => {
                stopCamera();
                // Big enough for 300 characters of Braille, small enough to stay quick.
                const scale = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
                frozen.width = Math.round(img.naturalWidth * scale); frozen.height = Math.round(img.naturalHeight * scale);
                frozen.getContext('2d').drawImage(img, 0, 0, frozen.width, frozen.height);
                URL.revokeObjectURL(url);
                settings.mirror = false;   // an uploaded picture is already the right way round
                paintControls();
                setState('captured');
                redrawCaptured();
            };
            img.onerror = () => { URL.revokeObjectURL(url); toast(T('upload_failed'), 'error'); };
            img.src = url;
        });
        window.addEventListener('pagehide', stopCamera);
        let resizeTimer = null;
        window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(() => { if (current && state === 'captured') showOnStage(current); }, 150); });

        // Download menus (the studio's and the viewer's).
        document.querySelectorAll('[data-menu-toggle]').forEach(btn => btn.addEventListener('click', e => {
            e.stopPropagation();
            const m = btn.nextElementSibling;
            document.querySelectorAll('.pa-menu').forEach(x => { if (x !== m) x.hidden = true; });
            m.hidden = !m.hidden;
        }));
        document.addEventListener('click', () => document.querySelectorAll('.pa-menu').forEach(m => m.hidden = true));
        document.querySelectorAll('[data-download]').forEach(b => b.addEventListener('click', () => { if (current) download(current, b.dataset.download, ''); }));
        $('paCopy').addEventListener('click', () => { if (current) copyArt(current); });

        // ══ Modals ══════════════════════════════════════════════════════════
        const open = id => $(id).classList.add('open');
        const close = id => $(id).classList.remove('open');
        document.querySelectorAll('.pa-overlay').forEach(o => o.addEventListener('click', e => { if (e.target === o || e.target.closest('[data-close]')) close(o.id); }));
        document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.pa-overlay.open').forEach(o => close(o.id)); });

        // ══ Save ════════════════════════════════════════════════════════════
        $('paSave').addEventListener('click', () => {
            $('paTitle').value = T('default_title', { date: new Date().toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) });
            open('paSaveModal');
            setTimeout(() => { $('paTitle').focus(); $('paTitle').select(); }, 30);
        });
        $('paTitle').addEventListener('keydown', e => { if (e.key === 'Enter') $('paSaveConfirm').click(); });
        $('paSaveConfirm').addEventListener('click', async function () {
            if (!current) return;
            const art = current;
            this.disabled = true;
            try {
                const body = {
                    action: 'save', title: $('paTitle').value.trim(), style: art.style, palette: art.palette,
                    width_chars: art.cols, height_chars: art.rows, art: artText(art),
                    colours: art.colours ? b64FromBytes(art.colours) : null, thumbnail: thumbnail(art),
                };
                const d = await (await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json();
                if (!d.success) { toast(d.error || T('save_failed'), 'error'); return; }
                close('paSaveModal');
                toast(T('saved'), 'success');
                loadAlbum();
            } catch (e) {
                toast(T('save_failed'), 'error');
            } finally {
                this.disabled = false;
            }
        });

        // ══ The album ═══════════════════════════════════════════════════════
        const when = utc => { const d = new Date(String(utc).replace(' ', 'T') + 'Z'); return isNaN(d) ? utc : d.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); };
        async function loadAlbum() {
            try {
                const d = await (await fetch(API + '?action=list', { credentials: 'same-origin' })).json();
                if (!d.success) throw new Error(d.error || '');
                albumReady.ok = d.ready;
                $('paVerify').hidden = d.ready;
                const photos = d.photos || [];
                $('paAlbumCount').textContent = photos.length ? (photos.length === 1 ? T('album_count_one') : T('album_count', { count: photos.length })) : '';
                $('paAlbumEmpty').hidden = photos.length > 0 || !d.ready;
                const grid = $('paGrid');
                grid.innerHTML = '';
                photos.forEach(p => {
                    const card = document.createElement('button');
                    card.type = 'button';
                    card.className = 'pa-card';
                    const img = document.createElement('img');
                    img.alt = p.title;
                    img.src = p.thumbnail;
                    img.style.background = (PALETTES[p.palette] || PALETTES.green).bg;
                    const meta = document.createElement('div');
                    meta.innerHTML = '<strong>' + esc(p.title) + '</strong><small>' + esc(when(p.created)) + '</small>';
                    card.append(img, meta);
                    card.addEventListener('click', () => openPhoto(p.id));
                    grid.appendChild(card);
                });
                setState(state);
            } catch (e) {
                toast(T('load_failed'), 'error');
            }
        }

        let viewing = null;
        async function openPhoto(id) {
            try {
                const d = await (await fetch(API + '?action=get&id=' + encodeURIComponent(id), { credentials: 'same-origin' })).json();
                if (!d.success) { toast(d.error || T('load_failed'), 'error'); return; }
                const p = d.photo;
                viewing = {
                    id: p.id, title: p.title,
                    art: { style: p.style, palette: p.palette, cols: p.width_chars, rows: p.height_chars,
                           lines: p.art.split('\n'), colours: p.colours ? bytesFromB64(p.colours) : null },
                };
                $('paViewName').textContent = p.title;
                $('paViewMeta').textContent = when(p.created) + ' · ' + T('style_' + p.style) + ' · ' + T('size', { width: p.width_chars, height: p.height_chars });
                $('paViewer').style.background = (PALETTES[p.palette] || PALETTES.green).bg;
                open('paViewModal');
                const box = $('paViewer');
                const fontPx = fitFont(viewing.art, Math.min(1060, window.innerWidth * 0.9), window.innerHeight * 0.66);
                const c = $('paViewArt');
                renderArt(c, viewing.art, fontPx, window.devicePixelRatio || 1);
                c.style.width = c.dataset.cssWidth + 'px';
                box.scrollTop = 0;
            } catch (e) {
                toast(T('load_failed'), 'error');
            }
        }
        $('paViewCopy').addEventListener('click', () => { if (viewing) copyArt(viewing.art); });
        document.querySelectorAll('[data-view-download]').forEach(b => b.addEventListener('click', () => { if (viewing) download(viewing.art, b.dataset.viewDownload, viewing.title); }));
        $('paViewDelete').addEventListener('click', async () => {
            if (!viewing) return;
            const ok = await showConfirm({ title: T('delete_title'), message: T('delete_confirm', { title: viewing.title }), okLabel: T('delete'), okClass: 'danger' });
            if (!ok) return;
            try {
                const d = await (await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'delete', id: viewing.id }) })).json();
                if (!d.success) { toast(d.error || T('delete_failed'), 'error'); return; }
                close('paViewModal');
                toast(T('deleted'), 'success');
                loadAlbum();
            } catch (e) {
                toast(T('delete_failed'), 'error');
            }
        });

        paintControls();
        setState('idle');
        loadAlbum();
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=77"></script>
</body>
</html>
