<?php
/**
 * Forms Module - Fill In a Form
 */
session_start();
require_once '../config.php';

$current_page = 'forms';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Fill Form</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .fill-container {
            flex: 1 1 100%;
            overflow-y: auto;
            background-color: #f5f7fa;
        }

        .fill-content {
            width: 100%;
            max-width: 860px;
            margin: 0 auto;
            padding: 30px 25px;
        }

        .fill-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            padding: 40px 50px;
            min-height: 600px;
            box-sizing: border-box;
        }

        .form-logo {
            display: block;
            max-width: 220px;
            height: auto;
            margin: 0 auto 28px;
        }

        .form-logo.align-left { margin: 0 auto 28px 0; }
        .form-logo.align-center { margin: 0 auto 28px; }
        .form-logo.align-right { margin: 0 0 28px auto; }

        .fill-title {
            font-size: 22px;
            font-weight: 600;
            color: #333;
            margin: 0 0 4px;
        }

        .fill-desc {
            font-size: 14px;
            color: #888;
            margin: 0 0 24px;
        }

        .form-field {
            margin-bottom: 18px;
        }

        .form-field label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 5px;
        }

        .form-field label .required-star {
            color: #d32f2f;
            margin-left: 2px;
        }

        .form-field input[type="text"],
        .form-field textarea,
        .form-field select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
            box-sizing: border-box;
        }

        .form-field input:focus,
        .form-field textarea:focus,
        .form-field select:focus {
            outline: none;
            border-color: #00897b;
            box-shadow: 0 0 0 2px rgba(0,137,123,0.1);
        }

        .form-field textarea {
            min-height: 80px;
            resize: vertical;
        }

        .form-field.checkbox-field {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-field.checkbox-field input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 0;
            cursor: pointer;
        }

        .form-field.checkbox-field label {
            margin-bottom: 0;
            cursor: pointer;
        }

        .form-field.has-error input,
        .form-field.has-error textarea,
        .form-field.has-error select {
            border-color: #d32f2f;
        }

        .field-error {
            font-size: 12px;
            color: #d32f2f;
            margin-top: 4px;
            display: none;
        }

        .form-field.has-error .field-error {
            display: block;
        }

        .form-actions {
            margin-top: 24px;
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 22px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-primary { background: #00897b; color: white; }
        .btn-primary:hover { background: #00695c; }
        .btn-secondary { background: #f5f7fa; color: #333; border: 1px solid #ddd; }
        .btn-secondary:hover { background: #eef0f2; }

        .submit-message {
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 14px;
            margin-top: 16px;
            display: none;
        }

        .submit-message.success {
            display: block;
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }

        .submit-message.error {
            display: block;
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .success-actions {
            margin-top: 14px;
            display: flex;
            gap: 8px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container fill-container">
        <div class="fill-content">
            <div class="fill-card" id="formCard">
                <p style="color:#888;text-align:center;padding:20px">Loading form...</p>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/forms/';
        let formData = null;
        let logoAlignment = 'center';

        document.addEventListener('DOMContentLoaded', async function() {
            // Load settings first, then form
            try {
                const sRes = await fetch(API_BASE + 'get_settings.php');
                const sData = await sRes.json();
                if (sData.success && sData.settings) {
                    logoAlignment = sData.settings.logo_alignment || 'center';
                }
            } catch (e) {}

            const params = new URLSearchParams(window.location.search);
            const id = params.get('id');
            if (id) {
                loadForm(id);
            } else {
                document.getElementById('formCard').innerHTML = '<p style="color:#c00;text-align:center">No form ID specified</p>';
            }
        });

        async function loadForm(id) {
            try {
                const res = await fetch(API_BASE + 'get_form.php?id=' + id);
                const data = await res.json();

                if (data.success) {
                    formData = data.form;
                    renderForm();
                } else {
                    document.getElementById('formCard').innerHTML = '<p style="color:#c00;text-align:center">' + esc(data.error) + '</p>';
                }
            } catch (e) {
                console.error(e);
            }
        }

        function renderForm() {
            const card = document.getElementById('formCard');
            const alignClass = 'align-' + logoAlignment;
            let html = `<img src="../assets/images/CompanyLogo.png" alt="Company Logo" class="form-logo ${alignClass}">`;
            html += `<h1 class="fill-title">${esc(formData.title)}</h1>`;
            if (formData.description) {
                html += `<p class="fill-desc">${esc(formData.description)}</p>`;
            }

            html += '<form id="fillForm" onsubmit="submitForm(event)">';

            formData.fields.forEach(f => {
                const req = f.is_required == 1;
                const reqStar = req ? '<span class="required-star">*</span>' : '';
                const reqAttr = req ? 'data-required="1"' : '';

                switch (f.field_type) {
                    case 'text':
                        html += `<div class="form-field" ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <input type="text" name="field_${f.id}" data-field-id="${f.id}">
                            <div class="field-error">This field is required</div>
                        </div>`;
                        break;
                    case 'textarea':
                        html += `<div class="form-field" ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <textarea name="field_${f.id}" data-field-id="${f.id}"></textarea>
                            <div class="field-error">This field is required</div>
                        </div>`;
                        break;
                    case 'checkbox':
                        html += `<div class="form-field checkbox-field" ${reqAttr}>
                            <input type="checkbox" name="field_${f.id}" data-field-id="${f.id}" id="cb_${f.id}">
                            <label for="cb_${f.id}">${esc(f.label)}${reqStar}</label>
                            <div class="field-error">This field is required</div>
                        </div>`;
                        break;
                    case 'dropdown':
                        const opts = f.options ? JSON.parse(f.options) : [];
                        html += `<div class="form-field" ${reqAttr}>
                            <label>${esc(f.label)}${reqStar}</label>
                            <select name="field_${f.id}" data-field-id="${f.id}">
                                <option value="">Select...</option>
                                ${opts.map(o => `<option value="${esc(o)}">${esc(o)}</option>`).join('')}
                            </select>
                            <div class="field-error">This field is required</div>
                        </div>`;
                        break;
                }
            });

            html += `<div class="form-actions">
                <button type="submit" class="btn btn-primary">Submit</button>
                <a href="./" class="btn btn-secondary">Cancel</a>
            </div>`;
            html += '</form>';
            html += '<div class="submit-message" id="submitMessage"></div>';

            card.innerHTML = html;
        }

        async function submitForm(e) {
            e.preventDefault();

            // Clear errors
            document.querySelectorAll('.form-field.has-error').forEach(el => el.classList.remove('has-error'));

            // Collect values
            const data = {};
            let valid = true;

            formData.fields.forEach(f => {
                const el = document.querySelector(`[data-field-id="${f.id}"]`);
                if (!el) return;

                let value;
                if (f.field_type === 'checkbox') {
                    value = el.checked ? '1' : '0';
                } else {
                    value = el.value.trim();
                }

                data[f.id] = value;

                // Validate required
                if (f.is_required == 1) {
                    const isEmpty = f.field_type === 'checkbox' ? !el.checked : !value;
                    if (isEmpty) {
                        el.closest('.form-field').classList.add('has-error');
                        valid = false;
                    }
                }
            });

            if (!valid) {
                showMsg('Please fill in all required fields', 'error');
                return;
            }

            try {
                const res = await fetch(API_BASE + 'submit_form.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ form_id: formData.id, data: data })
                });
                const result = await res.json();

                if (result.success) {
                    document.getElementById('fillForm').style.display = 'none';
                    const msgEl = document.getElementById('submitMessage');
                    msgEl.className = 'submit-message success';
                    msgEl.innerHTML = 'Form submitted successfully!' +
                        '<div class="success-actions">' +
                        '<a href="fill.php?id=' + formData.id + '" class="btn btn-primary">Submit Another</a>' +
                        '<a href="./" class="btn btn-secondary">Back to Forms</a>' +
                        '</div>';
                } else {
                    showMsg('Error: ' + result.error, 'error');
                }
            } catch (e) {
                showMsg('Failed to submit form', 'error');
            }
        }

        function showMsg(text, type) {
            const el = document.getElementById('submitMessage');
            el.textContent = text;
            el.className = 'submit-message ' + type;
        }

        function esc(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
