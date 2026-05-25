<?php
/**
 * System - Module Colours
 * Customise the colour theme for each module
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

$current_page = 'colours';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Module Colours</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/toast.js"></script>
    <style>
        .colours-container {
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

        .module-row {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 14px 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            margin-bottom: 10px;
        }

        .module-preview {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .module-preview svg {
            width: 20px;
            height: 20px;
            color: #fff;
        }

        .module-name {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            width: 120px;
            flex-shrink: 0;
        }

        .colour-field {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .colour-field label {
            font-size: 12px;
            color: #888;
            min-width: 60px;
        }

        .colour-picker-wrap {
            position: relative;
            width: 36px;
            height: 36px;
            border-radius: 6px;
            overflow: hidden;
            border: 2px solid #e0e0e0;
            cursor: pointer;
        }

        .colour-picker-wrap input[type="color"] {
            position: absolute;
            top: -4px;
            left: -4px;
            width: 44px;
            height: 44px;
            border: none;
            cursor: pointer;
            padding: 0;
        }

        .colour-hex {
            width: 80px;
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 12px;
            font-family: monospace;
            text-transform: uppercase;
        }

        .colour-hex:focus { outline: none; border-color: #546e7a; }

        .btn-reset {
            background: none;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 6px 10px;
            font-size: 12px;
            color: #888;
            cursor: pointer;
            white-space: nowrap;
            margin-left: auto;
            flex-shrink: 0;
        }

        .btn-reset:hover { border-color: #999; color: #555; }

        .save-area {
            margin-top: 24px;
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
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        @media (max-width: 700px) {
            .module-row { flex-wrap: wrap; }
            .module-name { width: 100%; }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="colours-container">
        <h1 class="page-title">Module Colours</h1>
        <p class="page-subtitle">Customise the colour theme for each module across headers, icons, and the home screen</p>

        <div id="moduleList"></div>

        <div class="save-area">
            <button type="button" class="btn btn-primary" id="saveBtn" onclick="saveColours()">Save</button>
        </div>
    </div>

    <script>
    const API_BASE = '<?php echo $path_prefix; ?>api/settings/';

    const defaults = <?php
        require_once '../../includes/module-colors.php';
        echo json_encode($defaultModuleColors);
    ?>;

    const moduleInfo = <?php echo json_encode(
        array_map(function($m) { return ['name' => $m['name'], 'icon' => $m['icon']]; }, $modules)
    ); ?>;

    let currentColors = {};

    function renderModules() {
        const container = document.getElementById('moduleList');
        container.innerHTML = '';

        for (const [key, info] of Object.entries(moduleInfo)) {
            const colors = currentColors[key] || defaults[key] || ['#666666', '#444444'];
            const row = document.createElement('div');
            row.className = 'module-row';
            row.id = 'row-' + key;
            row.innerHTML = `
                <div class="module-preview" id="preview-${key}" style="background: linear-gradient(135deg, ${colors[0]}, ${colors[1]})">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${info.icon}</svg>
                </div>
                <div class="module-name">${info.name}</div>
                <div class="colour-field">
                    <label>Primary</label>
                    <div class="colour-picker-wrap">
                        <input type="color" value="${colors[0]}" data-module="${key}" data-index="0" onchange="onColourChange(this)" oninput="onColourChange(this)">
                    </div>
                    <input type="text" class="colour-hex" value="${colors[0]}" data-module="${key}" data-index="0" onchange="onHexChange(this)">
                </div>
                <div class="colour-field">
                    <label>Secondary</label>
                    <div class="colour-picker-wrap">
                        <input type="color" value="${colors[1]}" data-module="${key}" data-index="1" onchange="onColourChange(this)" oninput="onColourChange(this)">
                    </div>
                    <input type="text" class="colour-hex" value="${colors[1]}" data-module="${key}" data-index="1" onchange="onHexChange(this)">
                </div>
                <button type="button" class="btn-reset" onclick="resetModule('${key}')">Reset</button>
            `;
            container.appendChild(row);
        }
    }

    function onColourChange(input) {
        const key = input.dataset.module;
        const idx = parseInt(input.dataset.index);
        if (!currentColors[key]) currentColors[key] = [...(defaults[key] || ['#666666', '#444444'])];
        currentColors[key][idx] = input.value;

        // Sync hex input
        const row = document.getElementById('row-' + key);
        const hexInputs = row.querySelectorAll('.colour-hex');
        hexInputs[idx].value = input.value;

        updatePreview(key);
    }

    function onHexChange(input) {
        const key = input.dataset.module;
        const idx = parseInt(input.dataset.index);
        let val = input.value.trim();
        if (!val.startsWith('#')) val = '#' + val;
        if (!/^#[0-9a-fA-F]{6}$/.test(val)) return;

        if (!currentColors[key]) currentColors[key] = [...(defaults[key] || ['#666666', '#444444'])];
        currentColors[key][idx] = val;
        input.value = val;

        // Sync colour picker
        const row = document.getElementById('row-' + key);
        const pickers = row.querySelectorAll('input[type="color"]');
        pickers[idx].value = val;

        updatePreview(key);
    }

    function updatePreview(key) {
        const colors = currentColors[key] || defaults[key];
        const preview = document.getElementById('preview-' + key);
        preview.style.background = `linear-gradient(135deg, ${colors[0]}, ${colors[1]})`;
    }

    function resetModule(key) {
        const def = defaults[key];
        if (!def) return;
        currentColors[key] = [...def];

        const row = document.getElementById('row-' + key);
        const pickers = row.querySelectorAll('input[type="color"]');
        const hexes = row.querySelectorAll('.colour-hex');
        pickers[0].value = def[0];
        pickers[1].value = def[1];
        hexes[0].value = def[0];
        hexes[1].value = def[1];

        updatePreview(key);
    }

    async function saveColours() {
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;

        const settings = {};
        for (const [key, colors] of Object.entries(currentColors)) {
            settings['module_color_' + key] = colors[0] + ',' + colors[1];
        }

        try {
            const resp = await fetch(API_BASE + 'save_system_settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings })
            });
            const data = await resp.json();
            if (data.success) {
                showToast('Module colours saved', 'success');
            } else {
                showToast('Error: ' + data.error, 'error');
            }
        } catch (e) {
            showToast('Failed to save colours', 'error');
        }

        btn.disabled = false;
    }

    async function loadColours() {
        try {
            const resp = await fetch(API_BASE + 'get_system_settings.php');
            const data = await resp.json();
            if (data.success) {
                for (const [key, val] of Object.entries(data.settings)) {
                    if (key.startsWith('module_color_')) {
                        const moduleKey = key.substring('module_color_'.length);
                        const parts = val.split(',');
                        if (parts.length === 2) {
                            currentColors[moduleKey] = [parts[0].trim(), parts[1].trim()];
                        }
                    }
                }
            }
        } catch (e) {
            console.error('Failed to load colours', e);
        }

        // Fill any modules not in DB with defaults
        for (const key of Object.keys(moduleInfo)) {
            if (!currentColors[key]) {
                currentColors[key] = [...(defaults[key] || ['#666666', '#444444'])];
            }
        }

        renderModules();
    }

    loadColours();
    </script>
</body>
</html>
