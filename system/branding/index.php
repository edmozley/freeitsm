<?php
/**
 * System - Branding Settings
 *
 * Organisation-wide branding: logo + default header/footer template slots.
 * These act as the fallback for any module that renders branded output
 * (currently Network Mapper's diagram header/footer; future PDF/PNG export
 * surfaces will read the same settings).
 */
session_start();
require_once '../../config.php';

$current_page = 'branding';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Branding</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/toast.js"></script>
    <style>
        .branding-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: 800px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 600;
            color: #333;
            margin: 0 0 6px 0;
        }

        .page-subtitle {
            font-size: 13px;
            color: #888;
            margin: 0 0 30px 0;
        }

        .settings-card {
            background: #fff;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            margin-bottom: 24px;
        }

        .settings-card h3 {
            font-size: 15px;
            font-weight: 600;
            color: #333;
            margin: 0 0 4px 0;
        }

        .settings-card .card-desc {
            font-size: 13px;
            color: #888;
            margin: 0 0 20px 0;
            line-height: 1.5;
        }

        /* Logo block */
        .logo-row {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-preview {
            width: 140px;
            height: 80px;
            border: 1px dashed #ccc;
            border-radius: 6px;
            background: #fafafa;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .logo-preview img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .logo-preview .no-logo {
            font-size: 11px;
            color: #aaa;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .logo-controls {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
        }

        .logo-controls .file-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .logo-controls input[type="file"] {
            font-size: 12px;
        }

        .logo-hint {
            font-size: 12px;
            color: #888;
            line-height: 1.5;
        }

        /* Slot grid */
        .slot-grid {
            display: grid;
            grid-template-columns: 80px 1fr 1fr 1fr;
            gap: 10px 12px;
            align-items: center;
        }

        .slot-grid .row-label {
            font-size: 13px;
            font-weight: 600;
            color: #444;
            text-align: right;
            padding-right: 4px;
        }

        .slot-grid .col-head {
            font-size: 11px;
            font-weight: 600;
            color: #888;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
        }

        .slot-input {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            font-family: inherit;
            box-sizing: border-box;
        }

        .slot-input:focus { outline: none; border-color: #06b6d4; }

        .info-note {
            background: #f5f7fa;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 14px 16px;
            font-size: 12px;
            color: #666;
            line-height: 1.6;
            margin-top: 16px;
        }

        .info-note strong { color: #333; }

        .info-note code {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 3px;
            padding: 1px 5px;
            font-size: 11px;
            color: #06b6d4;
            font-family: 'Consolas', 'Monaco', monospace;
        }

        .save-area {
            margin-top: 30px;
            display: flex;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.15s;
        }

        .btn-primary {
            background: #546e7a;
            color: #fff;
        }

        .btn-primary:hover { background: #455a64; }

        .btn-secondary {
            background: #fff;
            color: #555;
            border: 1px solid #ddd;
        }

        .btn-secondary:hover { background: #f5f7fa; }
        .btn-link {
            background: none;
            color: #c62828;
            padding: 4px 6px;
            font-size: 12px;
        }
        .btn-link:hover { text-decoration: underline; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="branding-container">
        <h1 class="page-title">Branding</h1>
        <p class="page-subtitle">Set the organisation logo and default header/footer text used on diagrams and exported documents</p>

        <form id="brandingForm" enctype="multipart/form-data">
            <!-- Logo -->
            <div class="settings-card">
                <h3>Company Logo</h3>
                <p class="card-desc">Used as the <code>{{logo}}</code> token in any header/footer slot. PNG, JPG, or SVG, max 2&nbsp;MB. SVG is recommended for crisp print and export.</p>
                <div class="logo-row">
                    <div class="logo-preview" id="logoPreview">
                        <span class="no-logo">No logo</span>
                    </div>
                    <div class="logo-controls">
                        <div class="file-row">
                            <input type="file" id="logoFile" name="logo" accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml">
                            <button type="button" class="btn btn-link" id="removeLogoBtn" style="display:none;">Remove</button>
                        </div>
                        <div class="logo-hint">Pick a file to replace the current logo. The new image is saved when you press Save.</div>
                    </div>
                </div>
            </div>

            <!-- Header slots -->
            <div class="settings-card">
                <h3>Header</h3>
                <p class="card-desc">Three slots rendered along the top of the page. Leave a slot blank to omit it.</p>
                <div class="slot-grid">
                    <div></div>
                    <div class="col-head">Left</div>
                    <div class="col-head">Centre</div>
                    <div class="col-head">Right</div>

                    <div class="row-label">Header</div>
                    <input type="text" class="slot-input" id="headerLeft" maxlength="200">
                    <input type="text" class="slot-input" id="headerCenter" maxlength="200">
                    <input type="text" class="slot-input" id="headerRight" maxlength="200">
                </div>
            </div>

            <!-- Footer slots -->
            <div class="settings-card">
                <h3>Footer</h3>
                <p class="card-desc">Three slots rendered along the bottom of the page.</p>
                <div class="slot-grid">
                    <div></div>
                    <div class="col-head">Left</div>
                    <div class="col-head">Centre</div>
                    <div class="col-head">Right</div>

                    <div class="row-label">Footer</div>
                    <input type="text" class="slot-input" id="footerLeft" maxlength="200">
                    <input type="text" class="slot-input" id="footerCenter" maxlength="200">
                    <input type="text" class="slot-input" id="footerRight" maxlength="200">
                </div>

                <div class="info-note">
                    <strong>Available tokens</strong> — these are replaced when the header/footer renders on a diagram or export:<br>
                    <code>{{logo}}</code> the company logo image
                    &nbsp;·&nbsp; <code>{{title}}</code> the diagram or document title
                    &nbsp;·&nbsp; <code>{{author}}</code> the author's name
                    &nbsp;·&nbsp; <code>{{version}}</code> the version label
                    &nbsp;·&nbsp; <code>{{modified}}</code> the last-modified date<br>
                    Mix tokens with plain text — e.g. <code>Author: {{author}}</code> renders as <em>Author: Ed Mozley</em>.
                </div>
            </div>

            <div class="save-area">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary" id="resetBtn">Reset to defaults</button>
            </div>
        </form>
    </div>

    <script>
    const API_BASE = '<?php echo $path_prefix; ?>api/system/';
    const PATH_PREFIX = '<?php echo $path_prefix; ?>';

    // Defaults match the get_branding.php fallback so the "Reset" button gives
    // the same values you'd see on a brand-new install.
    const DEFAULTS = {
        header_left:   '{{logo}}',
        header_center: '{{title}}',
        header_right:  '',
        footer_left:   'Author: {{author}}',
        footer_center: '{{version}}',
        footer_right:  'Modified: {{modified}}',
    };

    let currentLogoPath = null;
    let pendingRemoveLogo = false;

    async function loadBranding() {
        try {
            const resp = await fetch(API_BASE + 'get_branding.php');
            const data = await resp.json();
            if (!data.success) {
                showToast('Failed to load branding: ' + data.error, 'error');
                return;
            }
            const b = data.branding;
            document.getElementById('headerLeft').value = b.header_left || '';
            document.getElementById('headerCenter').value = b.header_center || '';
            document.getElementById('headerRight').value = b.header_right || '';
            document.getElementById('footerLeft').value = b.footer_left || '';
            document.getElementById('footerCenter').value = b.footer_center || '';
            document.getElementById('footerRight').value = b.footer_right || '';

            currentLogoPath = b.logo_path || null;
            renderLogoPreview();
        } catch (e) {
            showToast('Failed to load branding settings', 'error');
        }
    }

    function renderLogoPreview(localObjectUrl) {
        const preview = document.getElementById('logoPreview');
        const removeBtn = document.getElementById('removeLogoBtn');
        if (localObjectUrl) {
            preview.innerHTML = '<img src="' + localObjectUrl + '" alt="Logo preview">';
            removeBtn.style.display = 'inline-flex';
        } else if (currentLogoPath && !pendingRemoveLogo) {
            preview.innerHTML = '<img src="' + PATH_PREFIX + currentLogoPath + '" alt="Current logo">';
            removeBtn.style.display = 'inline-flex';
        } else {
            preview.innerHTML = '<span class="no-logo">No logo</span>';
            removeBtn.style.display = 'none';
        }
    }

    document.getElementById('logoFile').addEventListener('change', function(e) {
        const f = this.files[0];
        if (!f) return;
        if (f.size > 2 * 1024 * 1024) {
            showToast('Logo too large (max 2 MB)', 'error');
            this.value = '';
            return;
        }
        pendingRemoveLogo = false;
        renderLogoPreview(URL.createObjectURL(f));
    });

    document.getElementById('removeLogoBtn').addEventListener('click', function() {
        // Clear any picked file AND mark the stored logo for deletion on save.
        document.getElementById('logoFile').value = '';
        pendingRemoveLogo = true;
        renderLogoPreview();
    });

    document.getElementById('resetBtn').addEventListener('click', function() {
        document.getElementById('headerLeft').value   = DEFAULTS.header_left;
        document.getElementById('headerCenter').value = DEFAULTS.header_center;
        document.getElementById('headerRight').value  = DEFAULTS.header_right;
        document.getElementById('footerLeft').value   = DEFAULTS.footer_left;
        document.getElementById('footerCenter').value = DEFAULTS.footer_center;
        document.getElementById('footerRight').value  = DEFAULTS.footer_right;
        showToast('Slots reset to defaults — press Save to apply', 'info');
    });

    document.getElementById('brandingForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = this.querySelector('button[type="submit"]');
        btn.disabled = true;

        const fd = new FormData();
        fd.append('header_left',   document.getElementById('headerLeft').value);
        fd.append('header_center', document.getElementById('headerCenter').value);
        fd.append('header_right',  document.getElementById('headerRight').value);
        fd.append('footer_left',   document.getElementById('footerLeft').value);
        fd.append('footer_center', document.getElementById('footerCenter').value);
        fd.append('footer_right',  document.getElementById('footerRight').value);

        const logoInput = document.getElementById('logoFile');
        if (logoInput.files && logoInput.files[0]) {
            fd.append('logo', logoInput.files[0]);
        } else if (pendingRemoveLogo) {
            fd.append('remove_logo', '1');
        }

        try {
            const resp = await fetch(API_BASE + 'save_branding.php', {
                method: 'POST',
                body: fd
            });
            const data = await resp.json();
            if (data.success) {
                showToast('Branding saved', 'success');
                // Re-fetch so the preview reflects whatever's actually on disk now
                pendingRemoveLogo = false;
                logoInput.value = '';
                await loadBranding();
            } else {
                showToast('Error: ' + data.error, 'error');
            }
        } catch (err) {
            showToast('Failed to save branding', 'error');
        }
        btn.disabled = false;
    });

    loadBranding();
    </script>
</body>
</html>
