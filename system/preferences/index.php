<?php
/**
 * System - Preferences (per-browser + per-analyst settings)
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

$current_page = 'preferences';
$path_prefix = '../../';
$locales = I18n::getSupportedLocales();
$currentLocale = I18n::getLocale();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Preferences</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <script src="../../assets/js/toast.js"></script>
    <style>
        .prefs-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: 700px;
            margin: 0 auto;
            padding: 30px;
        }

        .prefs-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            padding: 30px;
        }

        .prefs-card h2 {
            margin: 0 0 6px 0;
            font-size: 20px;
            color: #333;
        }

        .prefs-card .subtitle {
            margin: 0 0 30px 0;
            font-size: 13px;
            color: #888;
        }

        .pref-section {
            margin-bottom: 32px;
        }

        .pref-section:last-child { margin-bottom: 0; }

        .pref-section h3 {
            margin: 0 0 6px 0;
            font-size: 15px;
            color: #333;
        }

        .pref-section p {
            margin: 0 0 16px 0;
            font-size: 13px;
            color: #666;
        }

        .position-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 4px;
            width: 192px;
            height: 128px;
            background: #f0f0f0;
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 8px;
        }

        .position-cell {
            background: #e0e0e0;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .position-cell:hover { background: #d0d0d0; }
        .position-cell.active { background: #546e7a; }
        .position-cell.active .position-dot { background: #fff; }

        .position-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #bbb;
            transition: all 0.15s;
        }

        .anim-toggle {
            display: flex;
            gap: 0;
            border: 2px solid #ddd;
            border-radius: 6px;
            overflow: hidden;
            width: fit-content;
        }

        .anim-option {
            padding: 8px 20px;
            font-size: 13px;
            font-weight: 500;
            color: #666;
            background: #f5f5f5;
            cursor: pointer;
            border: none;
            transition: all 0.15s;
        }

        .anim-option:not(:last-child) { border-right: 1px solid #ddd; }
        .anim-option:hover { background: #e8e8e8; }
        .anim-option.active { background: #546e7a; color: #fff; }

        .pref-language-select {
            font-size: 14px;
            padding: 8px 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            background: #fff;
            min-width: 240px;
            cursor: pointer;
        }
        .pref-language-select:focus { outline: none; border-color: #546e7a; }

        .pref-saving-hint {
            margin-left: 10px;
            font-size: 12px;
            color: #888;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .pref-saving-hint.show { opacity: 1; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="prefs-container">
        <div class="prefs-card">
            <h2>Preferences</h2>
            <p class="subtitle">Personal settings saved to this browser.</p>

            <div class="pref-section">
                <h3>Interface Language</h3>
                <p>The language used across the FreeITSM UI. Translations fall back to English for any strings not yet covered in your chosen language. Saved against your analyst account &mdash; reloads the page on change.</p>
                <select id="languageSelect" class="pref-language-select">
                    <?php foreach ($locales as $code => $native): ?>
                        <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $code === $currentLocale ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($native); ?> (<?php echo htmlspecialchars($code); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="pref-saving-hint" id="langSavingHint">Saving&hellip;</span>
            </div>

            <div class="pref-section">
                <h3>Notification Position</h3>
                <p>Choose where notifications appear on your screen.</p>
                <div class="position-grid" id="toastPositionGrid"></div>
            </div>

            <div class="pref-section">
                <h3>Animation Style</h3>
                <p>How notifications enter and exit the screen.</p>
                <div class="anim-toggle" id="animToggle">
                    <button class="anim-option" data-anim="slide">Slide</button>
                    <button class="anim-option" data-anim="fade">Fade</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const positions = [
            { key: 'top-left', label: 'Top left' },
            { key: 'top-center', label: 'Top centre' },
            { key: 'top-right', label: 'Top right' },
            { key: 'middle-left', label: 'Middle left' },
            { key: 'middle-center', label: 'Middle centre' },
            { key: 'middle-right', label: 'Middle right' },
            { key: 'bottom-left', label: 'Bottom left' },
            { key: 'bottom-center', label: 'Bottom centre' },
            { key: 'bottom-right', label: 'Bottom right' }
        ];

        const grid = document.getElementById('toastPositionGrid');
        const current = localStorage.getItem('toast_position') || 'bottom-right';

        positions.forEach(pos => {
            const cell = document.createElement('div');
            cell.className = 'position-cell' + (pos.key === current ? ' active' : '');
            cell.title = pos.label;
            cell.dataset.pos = pos.key;

            const dot = document.createElement('div');
            dot.className = 'position-dot';
            cell.appendChild(dot);

            cell.addEventListener('click', function() {
                localStorage.setItem('toast_position', pos.key);
                grid.querySelectorAll('.position-cell').forEach(c => c.classList.remove('active'));
                cell.classList.add('active');
                showToast('Notifications will appear here', 'info');
            });

            grid.appendChild(cell);
        });

        // Language dropdown — persists to user_preferences (key: interface_language).
        // On save we clear the session-cached locale via a no-op call against the same
        // session, then reload so PHP re-renders in the new language.
        const langSelect = document.getElementById('languageSelect');
        const langHint   = document.getElementById('langSavingHint');
        if (langSelect) {
            langSelect.addEventListener('change', async function() {
                const newLocale = langSelect.value;
                langHint.classList.add('show');
                try {
                    const r = await fetch('../../api/system/set_user_preference.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ key: 'interface_language', value: newLocale })
                    });
                    const d = await r.json();
                    if (d.success) {
                        // Reload so PHP picks up the new language for the whole UI.
                        window.location.reload();
                    } else {
                        langHint.classList.remove('show');
                        showToast(d.error || 'Failed to save language', 'error');
                    }
                } catch (e) {
                    langHint.classList.remove('show');
                    showToast('Failed to save language', 'error');
                }
            });
        }

        // Animation toggle
        const currentAnim = localStorage.getItem('toast_animation') || 'slide';
        document.querySelectorAll('.anim-option').forEach(btn => {
            if (btn.dataset.anim === currentAnim) btn.classList.add('active');
            btn.addEventListener('click', function() {
                localStorage.setItem('toast_animation', btn.dataset.anim);
                document.querySelectorAll('.anim-option').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                showToast('Preview: ' + btn.dataset.anim + ' animation', 'info');
            });
        });
    </script>
</body>
</html>
