<?php
/**
 * System — Self-service portal.
 *
 * How the portal LOOKS to customers, and what it LETS THEM DO. Both halves in
 * one place because an administrator setting up a portal is doing one job.
 *
 * 🔑 Why this is not part of System → Branding. Branding owns the one logo the
 * app, the login screen and the emails all share. The portal is the only screen
 * an organisation may want to brand differently - it is the face customers see,
 * and it is often the only part of FreeITSM they ever see. Putting "may a
 * customer close their own ticket?" on a screen about logos would be worse.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/self_service_settings.php';
I18n::initFromSession();

$current_page = 'self-service';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
requireModuleAccess('system');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.self_service.heading')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=72">
    <link rel="stylesheet" href="../../assets/css/self-service-patterns.css?v=2">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        .ssp-container { height: calc(100vh - 48px); overflow-y: auto; width: 100%; box-sizing: border-box; padding: 24px 32px 40px; }
        .ssp-header { margin-bottom: 22px; }
        .ssp-header h2 { margin: 0; font-size: 22px; color: var(--text, #333); }
        .ssp-header p  { margin: 5px 0 0 0; font-size: 13px; color: var(--text-dim, #888); line-height: 1.55; max-width: 780px; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all .15s; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover:not(:disabled) { background: #455a64; }
        .btn:disabled { opacity: .55; cursor: progress; }

        .ssp-panel { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px; padding: 20px; margin-bottom: 18px; }
        .ssp-panel h3 { margin: 0 0 4px 0; font-size: 13px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-dim, #888); }
        .ssp-panel > p { margin: 0 0 16px; font-size: 13px; color: var(--text-muted, #666); line-height: 1.55; max-width: 720px; }

        .ssp-field { padding: 14px 0; border-top: 1px solid var(--border-soft, #f1f1f1); }
        .ssp-field:first-of-type { border-top: none; padding-top: 0; }
        .ssp-label { display: block; font-size: 13.5px; font-weight: 600; color: var(--text, #333); margin-bottom: 3px; }
        .ssp-desc  { font-size: 12.5px; color: var(--text-muted, #666); line-height: 1.5; margin-bottom: 9px; max-width: 640px; }
        .ssp-input { width: 100%; max-width: 420px; padding: 8px 10px; border: 1px solid var(--border, #cfd8dc); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #333); box-sizing: border-box; }

        /* A colour needs both a picker and a typed value: an admin handed a
           brand hex should not have to find it on a wheel. */
        .ssp-colour-row { display: flex; align-items: center; gap: 10px; }
        .ssp-colour-row input[type=color] { width: 44px; height: 34px; padding: 2px; border: 1px solid var(--border, #cfd8dc); border-radius: 6px; background: var(--surface, #fff); cursor: pointer; }
        .ssp-colour-row input[type=text] { width: 120px; font-family: Consolas, monospace; }
        .ssp-clear { background: none; border: none; color: var(--accent); cursor: pointer; font-size: 12.5px; padding: 4px; }

        .ssp-toggle { display: flex; align-items: flex-start; gap: 10px; }
        .ssp-toggle input { margin-top: 3px; }

        .ssp-patterns { display: flex; flex-wrap: wrap; gap: 10px; }
        .ssp-pattern {
            width: 92px; height: 62px; border-radius: 8px; cursor: pointer;
            border: 2px solid var(--border, #ddd); position: relative; overflow: hidden;
            background: var(--surface-2, #f7f7f7);
        }
        .ssp-pattern.selected { border-color: var(--accent, #546e7a); box-shadow: 0 0 0 2px rgba(84,110,122,.18); }
        .ssp-pattern span { position: absolute; bottom: 0; left: 0; right: 0; font-size: 10.5px; text-align: center;
                            background: rgba(0,0,0,.55); color: #fff; padding: 2px 0; }

        /* The preview is the point of the screen: an admin choosing a colour is
           choosing what a customer sees, and they cannot see it from here. */
        .ssp-preview { border: 1px solid var(--border, #e0e0e0); border-radius: 8px; overflow: hidden; max-width: 560px; }
        .ssp-preview-bar { height: 46px; display: flex; align-items: center; padding: 0 14px; gap: 10px; background: var(--accent, #546e7a); color: #fff; font-weight: 600; font-size: 13px; }
        .ssp-preview-logo { height: 22px; max-width: 120px; object-fit: contain; }
        .ssp-preview-body { padding: 14px; min-height: 96px; }
        .ssp-preview-table { width: 100%; border-collapse: collapse; font-size: 12px; background: var(--surface, #fff); }
        .ssp-preview-table th { background: var(--surface-2, #eceff1); text-align: left; padding: 6px 8px; color: var(--text, #333); }
        .ssp-preview-table td { padding: 6px 8px; border-top: 1px solid var(--border-soft, #eee); color: var(--text-muted, #666); }
        .ssp-saved { font-size: 13px; color: var(--success-text, #166534); margin-left: 12px; }
        .ssp-logo-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        /* Bounded both ways: a tall logo would otherwise push the rest of the
           form down the page, and a wide one would stretch the row. */
        .ssp-logo-preview {
            max-height: 40px; max-width: 180px;
            border: 1px solid var(--border, #e0e0e0); border-radius: 4px;
            padding: 4px; background: var(--surface, #fff);
        }
        .ssp-logo-hint { margin-top: 6px; }
        /* .btn is display:inline-flex, which beats the [hidden] attribute, so
           Remove showed with no logo to remove. */
        .ssp-logo-row [hidden] { display: none !important; }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=152">
</head>
<body data-mobile-module="system" data-mobile-page="self-service">
    <?php include '../includes/header.php'; ?>

    <div class="ssp-container">
        <div class="ssp-header">
            <h2><?php echo htmlspecialchars(t('system.self_service.heading')); ?></h2>
            <p><?php echo t('system.self_service.intro'); ?></p>
        </div>

        <div class="ssp-panel">
            <h3><?php echo htmlspecialchars(t('system.self_service.appearance_heading')); ?></h3>
            <p><?php echo t('system.self_service.appearance_desc'); ?></p>

            <div class="ssp-field">
                <label class="ssp-label" for="sspLogoFile"><?php echo htmlspecialchars(t('system.self_service.logo_label')); ?></label>
                <div class="ssp-desc"><?php echo t('system.self_service.logo_desc'); ?></div>
                <?php /* A picker, not a path box. The old field asked for a
                         path that only the server could create, so there was
                         no way to answer it. Uploading is handled by the same
                         helper the main branding logo uses. */ ?>
                <div class="ssp-logo-row">
                    <img id="sspLogoPreview" class="ssp-logo-preview" alt="" hidden>
                    <input type="file" id="sspLogoFile" accept=".png,.jpg,.jpeg,image/png,image/jpeg">
                    <button type="button" class="btn btn-secondary" id="sspLogoRemove" hidden><?php echo htmlspecialchars(t('system.self_service.logo_remove')); ?></button>
                </div>
                <div class="ssp-desc ssp-logo-hint"><?php echo htmlspecialchars(t('system.self_service.logo_hint')); ?></div>
            </div>

            <?php /* Only meaningful once there is a logo of your own - the
                     bundled mark always sits in the bar. Shown regardless so
                     the choice is discoverable before you upload. */ ?>
            <div class="ssp-field">
                <label class="ssp-label" for="sspLogoPosition"><?php echo htmlspecialchars(t('system.self_service.logo_position_label')); ?></label>
                <div class="ssp-desc"><?php echo t('system.self_service.logo_position_desc'); ?></div>
                <select class="ssp-input" id="sspLogoPosition" style="max-width: 320px;">
                    <option value="header"><?php echo htmlspecialchars(t('system.self_service.logo_position_header')); ?></option>
                    <option value="page"><?php echo htmlspecialchars(t('system.self_service.logo_position_page')); ?></option>
                </select>
            </div>

            <div class="ssp-field">
                <label class="ssp-label" for="sspHeaderColour"><?php echo htmlspecialchars(t('system.self_service.header_colour_label')); ?></label>
                <div class="ssp-desc"><?php echo htmlspecialchars(t('system.self_service.header_colour_desc')); ?></div>
                <div class="ssp-colour-row">
                    <input type="color" id="sspHeaderColourPick" value="#546e7a">
                    <input type="text" class="ssp-input" id="sspHeaderColour" placeholder="#336699" maxlength="7">
                    <button type="button" class="ssp-clear" onclick="sspClear('sspHeaderColour')"><?php echo htmlspecialchars(t('system.self_service.use_theme')); ?></button>
                </div>
            </div>

            <div class="ssp-field">
                <label class="ssp-label" for="sspTableColour"><?php echo htmlspecialchars(t('system.self_service.table_colour_label')); ?></label>
                <div class="ssp-desc"><?php echo htmlspecialchars(t('system.self_service.table_colour_desc')); ?></div>
                <div class="ssp-colour-row">
                    <input type="color" id="sspTableColourPick" value="#eceff1">
                    <input type="text" class="ssp-input" id="sspTableColour" placeholder="#eceff1" maxlength="7">
                    <button type="button" class="ssp-clear" onclick="sspClear('sspTableColour')"><?php echo htmlspecialchars(t('system.self_service.use_theme')); ?></button>
                </div>
            </div>

            <div class="ssp-field">
                <span class="ssp-label"><?php echo htmlspecialchars(t('system.self_service.pattern_label')); ?></span>
                <div class="ssp-desc"><?php echo htmlspecialchars(t('system.self_service.pattern_desc')); ?></div>
                <div class="ssp-patterns" id="sspPatterns"></div>
            </div>
        </div>

        <div class="ssp-panel">
            <h3><?php echo htmlspecialchars(t('system.self_service.preview_heading')); ?></h3>
            <p><?php echo htmlspecialchars(t('system.self_service.preview_desc')); ?></p>
            <div class="ssp-preview" id="sspPreview">
                <div class="ssp-preview-bar" id="sspPreviewBar">
                    <img class="ssp-preview-logo" id="sspPreviewLogo" alt="" style="display:none;">
                    <span id="sspPreviewName"><?php echo htmlspecialchars(t('system.self_service.preview_portal')); ?></span>
                </div>
                <div class="ssp-preview-body" id="sspPreviewBody">
                    <table class="ssp-preview-table">
                        <thead><tr>
                            <th id="sspPreviewTh1"><?php echo htmlspecialchars(t('system.self_service.preview_col_ref')); ?></th>
                            <th id="sspPreviewTh2"><?php echo htmlspecialchars(t('system.self_service.preview_col_subject')); ?></th>
                        </tr></thead>
                        <tbody><tr><td>ABC-123-4567</td><td><?php echo htmlspecialchars(t('system.self_service.preview_row')); ?></td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="ssp-panel">
            <h3><?php echo htmlspecialchars(t('system.self_service.behaviour_heading')); ?></h3>
            <p><?php echo t('system.self_service.behaviour_desc'); ?></p>

            <div class="ssp-field">
                <label class="ssp-toggle">
                    <input type="checkbox" id="sspSelfClose">
                    <span>
                        <span class="ssp-label"><?php echo htmlspecialchars(t('system.self_service.self_close_label')); ?></span>
                        <span class="ssp-desc"><?php echo t('system.self_service.self_close_desc'); ?></span>
                    </span>
                </label>
            </div>

            <div class="ssp-field">
                <label class="ssp-toggle">
                    <input type="checkbox" id="sspMyAssets">
                    <span>
                        <span class="ssp-label"><?php echo htmlspecialchars(t('system.self_service.my_assets_label')); ?></span>
                        <span class="ssp-desc"><?php echo t('system.self_service.my_assets_desc'); ?></span>
                    </span>
                </label>
            </div>
        </div>

        <button class="btn btn-primary" id="sspSave" onclick="sspSave()"><?php echo htmlspecialchars(t('common.save')); ?></button>
        <span class="ssp-saved" id="sspSaved" style="display:none;"><?php echo htmlspecialchars(t('common.saved')); ?></span>
    </div>

    <script src="../../assets/js/toast.js"></script>
    <script>
    const SSP_API = '../../api/system/self_service_portal.php';
    const SSP_PATTERNS = <?php echo json_encode(SELF_SERVICE_PATTERNS); ?>;
    let sspPattern = '';

    const sspEsc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

    function sspRenderPatterns() {
        const box = document.getElementById('sspPatterns');
        // "None" first and always present: turning a pattern off has to be as
        // easy as turning one on, and an empty value is the default.
        const all = [''].concat(SSP_PATTERNS);
        box.innerHTML = all.map(function (p) {
            const cls = p === '' ? '' : 'ssp-pat-' + p;
            const label = p === '' ? t('system.self_service.pattern_none')
                                   : t('system.self_service.pattern_' + p);
            return '<div class="ssp-pattern ' + cls + (p === sspPattern ? ' selected' : '') +
                   '" data-pattern="' + sspEsc(p) + '" onclick="sspPickPattern(\'' + p + '\')">' +
                   '<span>' + sspEsc(label) + '</span></div>';
        }).join('');
    }

    function sspPickPattern(p) { sspPattern = p; sspRenderPatterns(); sspPaintPreview(); }

    function sspClear(id) {
        document.getElementById(id).value = '';
        sspPaintPreview();
    }

    /** Keep the swatch and the typed hex in step, in both directions. */
    function sspBindColour(textId, pickId) {
        const text = document.getElementById(textId);
        const pick = document.getElementById(pickId);
        text.addEventListener('input', function () {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) pick.value = text.value;
            sspPaintPreview();
        });
        pick.addEventListener('input', function () { text.value = pick.value; sspPaintPreview(); });
    }

    /* The stored path, held here rather than read back out of a text box - the
       box is gone, and the preview and the save both need to know it. */
    let sspLogoPath = '';

    /**
     * Show whichever logo is stored, and offer to remove it only when there is
     * one. An empty path means "use the main logo", which is the default and
     * the way an admin turns a portal-specific logo back off.
     */
    function sspSetLogo(path) {
        sspLogoPath = path || '';
        const img = document.getElementById('sspLogoPreview');
        const rm  = document.getElementById('sspLogoRemove');
        if (sspLogoPath) {
            // Cache-busted: replacing a logo keeps the same <img> on screen, and
            // without this the browser shows the old one until a hard refresh.
            img.src = '../../' + sspLogoPath + '?t=' + Date.now();
            img.hidden = false;
            rm.hidden = false;
        } else {
            img.hidden = true;
            rm.hidden = true;
            img.removeAttribute('src');
        }
        sspPaintPreview();
    }

    /**
     * Send the file, or the instruction to clear it.
     *
     * Its OWN request, separate from the colour/switch form: a file cannot ride
     * in a JSON body, and folding logo_path into that form would clear the logo
     * every time somebody saved a colour.
     */
    async function sspUploadLogo(file, remove) {
        const fd = new FormData();
        if (file)   { fd.append('logo', file); }
        if (remove) { fd.append('remove_logo', '1'); }

        const input = document.getElementById('sspLogoFile');
        input.disabled = true;
        try {
            const res  = await fetch(SSP_API, { method: 'POST', body: fd });
            const data = await res.json();
            if (!data.success) {
                if (typeof showToast === 'function') showToast(data.error || t('system.self_service.save_failed'), 'error');
                return;
            }
            sspSetLogo(data.settings ? (data.settings.logo_path || '') : (remove ? '' : sspLogoPath));
            if (typeof showToast === 'function') showToast(t('system.self_service.logo_saved'), 'success');
        } catch (e) {
            if (typeof showToast === 'function') showToast(t('system.self_service.save_failed'), 'error');
        } finally {
            input.disabled = false;
            input.value = '';   // so picking the same file again still fires change
        }
    }

    document.getElementById('sspLogoFile').addEventListener('change', function () {
        if (this.files && this.files[0]) { sspUploadLogo(this.files[0], false); }
    });
    document.getElementById('sspLogoRemove').addEventListener('click', function () {
        sspUploadLogo(null, true);
    });

    function sspPaintPreview() {
        const header = document.getElementById('sspHeaderColour').value.trim();
        const table  = document.getElementById('sspTableColour').value.trim();
        const logo   = sspLogoPath;

        const bar = document.getElementById('sspPreviewBar');
        bar.style.background = /^#[0-9a-fA-F]{6}$/.test(header) ? header : '';

        [document.getElementById('sspPreviewTh1'), document.getElementById('sspPreviewTh2')].forEach(function (th) {
            th.style.background = /^#[0-9a-fA-F]{6}$/.test(table) ? table : '';
        });

        const img = document.getElementById('sspPreviewLogo');
        if (logo) { img.src = logo; img.style.display = ''; } else { img.style.display = 'none'; }

        const body = document.getElementById('sspPreviewBody');
        body.className = 'ssp-preview-body' + (sspPattern ? ' ssp-pat-' + sspPattern : '');
    }

    function sspPaint(s) {
        sspSetLogo(s.logo_path || '');
        document.getElementById('sspLogoPosition').value = s.logo_position || 'header';
        document.getElementById('sspHeaderColour').value = s.header_colour || '';
        document.getElementById('sspTableColour').value  = s.table_header_colour || '';
        if (s.header_colour) document.getElementById('sspHeaderColourPick').value = s.header_colour;
        if (s.table_header_colour) document.getElementById('sspTableColourPick').value = s.table_header_colour;
        document.getElementById('sspSelfClose').checked = !!s.allow_self_close;
        document.getElementById('sspMyAssets').checked  = !!s.show_my_assets;
        sspPattern = s.background_pattern || '';
        sspRenderPatterns();
        sspPaintPreview();
    }

    async function sspLoad() {
        try {
            const d = await (await fetch(SSP_API, { credentials: 'same-origin' })).json();
            if (d.success) { sspPaint(d.settings); return; }
        } catch (e) {}
        // ⚠️ An unloaded form must not be saveable. Both switches here default
        // to off, so saving a form that never loaded would quietly turn off
        // whatever the install had on - writing the guess back as fact.
        document.getElementById('sspSave').disabled = true;
        if (typeof showToast === 'function') showToast(t('system.self_service.load_failed'), 'error');
    }

    async function sspSave() {
        const btn = document.getElementById('sspSave');
        btn.disabled = true;
        try {
            const d = await (await fetch(SSP_API, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    logo_position:       document.getElementById('sspLogoPosition').value,
                    // logo_path is NOT sent here. The file rides in its own
                    // multipart request (sspUploadLogo); sending an empty
                    // string from this form would clear the logo every time
                    // somebody saved a colour.
                    header_colour:       document.getElementById('sspHeaderColour').value.trim(),
                    table_header_colour: document.getElementById('sspTableColour').value.trim(),
                    background_pattern:  sspPattern,
                    allow_self_close:    document.getElementById('sspSelfClose').checked,
                    show_my_assets:      document.getElementById('sspMyAssets').checked
                })
            })).json();
            if (!d.success) {
                if (typeof showToast === 'function') showToast(d.error || t('system.self_service.save_failed'), 'error');
                return;
            }
            sspPaint(d.settings);   // what is STORED, not what was sent
            const ok = document.getElementById('sspSaved');
            ok.style.display = '';
            setTimeout(function () { ok.style.display = 'none'; }, 2200);
        } catch (e) {
            if (typeof showToast === 'function') showToast(t('system.self_service.save_failed'), 'error');
        } finally {
            btn.disabled = false;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        sspBindColour('sspHeaderColour', 'sspHeaderColourPick');
        sspBindColour('sspTableColour', 'sspTableColourPick');
        // GH #151. A listener on the old logo path box stood here after the box
        // was replaced by the file picker (#1960). getElementById returned null,
        // the line threw, and sspLoad() below never ran: the form showed
        // defaults, and saving wrote those defaults over the real settings.
        // Nothing may sit between here and sspLoad() that can throw.
        sspLoad();
    });
    </script>
    <script src="../../assets/js/mobile.js?v=65"></script>
</body>
</html>
