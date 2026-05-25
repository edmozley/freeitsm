<?php
/**
 * Forms Settings - Configure forms module settings
 */
session_start();
require_once '../../config.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Forms Settings</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: 900px;
            margin: 0 auto;
            padding: 30px;
        }

        /* Teal theme for tabs */
        .tab:hover { color: #00897b; }
        .tab.active { color: #00897b; border-bottom-color: #00897b; }

        .section-header h2 {
            margin: 0 0 8px;
            font-size: 18px;
            color: #333;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: #333;
        }

        .form-group small {
            display: block;
            margin-top: 4px;
            color: #888;
            font-size: 12px;
        }

        .alignment-options {
            display: flex;
            gap: 12px;
            max-width: 420px;
        }

        .alignment-option {
            flex: 1;
            padding: 16px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.15s;
            background: #fafafa;
        }

        .alignment-option:hover {
            border-color: #80cbc4;
            background: #f0f7f6;
        }

        .alignment-option.selected {
            border-color: #00897b;
            background: #e0f2f1;
        }

        .alignment-option svg {
            display: block;
            margin: 0 auto 6px;
            color: #666;
        }

        .alignment-option.selected svg {
            color: #00897b;
        }

        .alignment-option span {
            font-size: 13px;
            font-weight: 500;
            color: #666;
        }

        .alignment-option.selected span {
            color: #00897b;
            font-weight: 600;
        }

        .logo-preview {
            margin-top: 20px;
            padding: 20px;
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
        }

        .logo-preview-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #999;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .logo-preview img {
            display: block;
            max-width: 200px;
            height: auto;
            transition: margin 0.2s;
        }

        .logo-preview img.align-left { margin: 0 auto 0 0; }
        .logo-preview img.align-center { margin: 0 auto; }
        .logo-preview img.align-right { margin: 0 0 0 auto; }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .btn-primary { background: #00897b; color: white; }
        .btn-primary:hover { background: #00695c; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="layout" onclick="switchTab('layout')">Layout</button>
        </div>

        <!-- Layout Tab -->
        <div class="tab-content active" id="layout-tab">
            <div class="section-header">
                <h2>Layout Settings</h2>
            </div>
            <p style="color: #666; margin-bottom: 24px;">Configure how forms appear when users fill them in and in the form preview.</p>

            <div class="form-group">
                <label>Logo Alignment</label>
                <small>Controls the position of the company logo on forms.</small>
                <div class="alignment-options" style="margin-top: 10px;">
                    <div class="alignment-option" data-align="left" onclick="selectAlignment('left')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="17" y1="10" x2="3" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="17" y1="18" x2="3" y2="18"></line></svg>
                        <span>Left</span>
                    </div>
                    <div class="alignment-option selected" data-align="center" onclick="selectAlignment('center')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="10" x2="6" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="18" y1="18" x2="6" y2="18"></line></svg>
                        <span>Centre</span>
                    </div>
                    <div class="alignment-option" data-align="right" onclick="selectAlignment('right')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="21" y1="10" x2="7" y2="10"></line><line x1="21" y1="6" x2="3" y2="6"></line><line x1="21" y1="14" x2="3" y2="14"></line><line x1="21" y1="18" x2="7" y2="18"></line></svg>
                        <span>Right</span>
                    </div>
                </div>
            </div>

            <div class="logo-preview">
                <div class="logo-preview-label">Preview</div>
                <img id="logoPreview" src="../../assets/images/CompanyLogo.png" alt="Company Logo" class="align-center">
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" onclick="saveSettings()">Save Settings</button>
            </div>
        </div>
    </div>

    <!-- Toast notification -->
    <div class="toast" id="toast"></div>

    <script>
        const API_BASE = '../../api/forms/';
        let currentAlignment = 'center';

        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();
        });

        function selectAlignment(align) {
            currentAlignment = align;
            document.querySelectorAll('.alignment-option').forEach(el => el.classList.remove('selected'));
            document.querySelector(`.alignment-option[data-align="${align}"]`).classList.add('selected');
            // Update preview
            const img = document.getElementById('logoPreview');
            img.className = 'align-' + align;
        }

        async function loadSettings() {
            try {
                const res = await fetch(API_BASE + 'get_settings.php');
                const data = await res.json();
                if (data.success && data.settings) {
                    const align = data.settings.logo_alignment || 'center';
                    selectAlignment(align);
                }
            } catch (e) {
                console.error(e);
            }
        }

        async function saveSettings() {
            try {
                const res = await fetch(API_BASE + 'save_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: { logo_alignment: currentAlignment } })
                });
                const data = await res.json();

                if (data.success) {
                    showToast('Settings saved');
                } else {
                    showToast('Error: ' + data.error, true);
                }
            } catch (e) {
                showToast('Failed to save settings', true);
            }
        }

        function showToast(message, isError) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast' + (isError ? ' toast-error' : '');
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }
    </script>
</body>
</html>
