<?php
/**
 * Self-Service Portal - New Ticket
 */
session_start();
require_once '../config.php';
require_once 'includes/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Self-Service Portal - New Ticket</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { overflow: auto; height: auto; background: #f5f5f5; }

        .portal-header {
            background: #0078d4;
            color: white;
            padding: 0 24px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .portal-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            font-size: 15px;
        }
        .portal-brand img { height: 28px; filter: brightness(0) invert(1); }
        .portal-nav {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .portal-nav a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 6px 14px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.15s;
        }
        .portal-nav a:hover { background: rgba(255,255,255,0.15); color: white; }
        .portal-nav a.active { background: rgba(255,255,255,0.2); color: white; }
        .portal-user {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 13px;
        }
        .portal-user .user-name { color: rgba(255,255,255,0.9); }
        .portal-user a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 12px;
        }
        .portal-user a:hover { color: white; }

        .portal-layout {
            max-width: 700px;
            margin: 0 auto;
            padding: 28px 24px;
        }

        .page-title {
            font-size: 22px;
            font-weight: 600;
            color: #333;
            margin: 0 0 24px 0;
        }

        .form-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 28px;
        }

        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #333;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #0078d4;
            box-shadow: 0 0 0 2px rgba(0,120,212,0.1);
        }
        .form-group textarea {
            min-height: 150px;
            resize: vertical;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        .btn-submit {
            padding: 10px 24px;
            background: #0078d4;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-submit:hover { background: #005a9e; }
        .btn-submit:disabled { opacity: 0.7; cursor: not-allowed; }
        .btn-cancel {
            padding: 10px 24px;
            background: #f3f4f6;
            color: #333;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .btn-cancel:hover { background: #e5e7eb; }

        .error-message {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
            display: none;
        }
        .success-message {
            background: #d1fae5;
            color: #065f46;
            padding: 16px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #065f46;
            display: none;
        }
        .success-message a {
            color: #065f46;
            font-weight: 600;
        }

        /* Attachment dropzone */
        .dropzone {
            border: 2px dashed #ddd;
            border-radius: 6px;
            padding: 20px;
            text-align: center;
            color: #999;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .dropzone:hover { border-color: #0078d4; color: #0078d4; }
        .dropzone.dragover { border-color: #0078d4; background: #f0f7ff; color: #0078d4; }
        .dropzone-icon { font-size: 24px; margin-bottom: 6px; }
        .dropzone-browse { color: #0078d4; font-weight: 600; }

        .attachment-list { margin-top: 10px; }
        .attachment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 10px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            margin-bottom: 6px;
            font-size: 13px;
        }
        .attachment-item .file-info {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }
        .attachment-item .file-name {
            font-weight: 500;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .attachment-item .file-size { color: #999; white-space: nowrap; }
        .attachment-item .remove-btn {
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 18px;
            padding: 0 4px;
            line-height: 1;
        }
        .attachment-item .remove-btn:hover { color: #c33; }
    </style>
</head>
<body>
    <div class="portal-header">
        <div class="portal-brand">
            <img src="../assets/images/CompanyLogo.png" alt="Logo">
            <span>Self-Service Portal</span>
        </div>
        <nav class="portal-nav">
            <a href="index.php">Dashboard</a>
            <a href="new-ticket.php" class="active">New Ticket</a>
        </nav>
        <div class="portal-user">
            <span class="user-name"><?php echo htmlspecialchars($ss_user_name); ?></span>
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <div class="portal-layout">
        <h1 class="page-title">New Ticket</h1>

        <div class="error-message" id="errorMsg"></div>
        <div class="success-message" id="successMsg"></div>

        <div class="form-card" id="formCard">
            <form id="ticketForm" onsubmit="return handleSubmit(event)" autocomplete="off">
                <div class="form-group">
                    <label for="mailbox">Mailbox *</label>
                    <select id="mailbox" required>
                        <option value="">Loading...</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="subject">Subject *</label>
                    <input type="text" id="subject" required placeholder="Brief summary of your issue">
                </div>
                <div class="form-group">
                    <label for="priority">Priority</label>
                    <select id="priority">
                        <option value="Low">Low</option>
                        <option value="Normal" selected>Normal</option>
                        <option value="High">High</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" placeholder="Provide as much detail as possible about your issue..."></textarea>
                </div>
                <div class="form-group">
                    <label>Attachments</label>
                    <div class="dropzone" id="dropzone">
                        <div class="dropzone-icon">📎</div>
                        Drag and drop files here or <span class="dropzone-browse">browse</span>
                    </div>
                    <input type="file" id="fileInput" multiple style="display:none">
                    <div class="attachment-list" id="attachmentList"></div>
                </div>
                <div class="form-actions">
                    <a href="index.php" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-submit" id="submitBtn">Submit</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    let attachments = [];

    document.addEventListener('DOMContentLoaded', function() {
        loadMailboxes();
        initDropzone();
    });

    function initDropzone() {
        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('fileInput');

        dropzone.addEventListener('click', () => fileInput.click());

        dropzone.addEventListener('dragover', e => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
        dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
        dropzone.addEventListener('drop', e => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            addFiles(e.dataTransfer.files);
        });

        fileInput.addEventListener('change', () => {
            addFiles(fileInput.files);
            fileInput.value = '';
        });
    }

    function addFiles(files) {
        for (const file of files) {
            const duplicate = attachments.some(a => a.file.name === file.name && a.file.size === file.size);
            if (!duplicate) {
                attachments.push({ file });
            }
        }
        renderAttachments();
    }

    function removeAttachment(index) {
        attachments.splice(index, 1);
        renderAttachments();
    }

    function renderAttachments() {
        const list = document.getElementById('attachmentList');
        if (attachments.length === 0) { list.innerHTML = ''; return; }

        list.innerHTML = attachments.map((a, i) =>
            '<div class="attachment-item">' +
                '<div class="file-info">' +
                    '<span class="file-name">' + escapeHtml(a.file.name) + '</span>' +
                    '<span class="file-size">(' + formatFileSize(a.file.size) + ')</span>' +
                '</div>' +
                '<button type="button" class="remove-btn" onclick="removeAttachment(' + i + ')">&times;</button>' +
            '</div>'
        ).join('');
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function fileToBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result.split(',')[1]);
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    }

    async function loadMailboxes() {
        const select = document.getElementById('mailbox');
        try {
            const resp = await fetch('../api/self-service/get_mailboxes.php');
            const data = await resp.json();
            if (data.success && data.mailboxes.length > 0) {
                select.innerHTML = data.mailboxes.map(m =>
                    '<option value="' + m.id + '">' + escapeHtml(m.name) + ' (' + escapeHtml(m.target_mailbox) + ')</option>'
                ).join('');
                if (data.mailboxes.length === 1) {
                    select.value = data.mailboxes[0].id;
                }
            } else {
                select.innerHTML = '<option value="">No mailboxes available</option>';
            }
        } catch (err) {
            select.innerHTML = '<option value="">Failed to load mailboxes</option>';
        }
    }

    async function handleSubmit(e) {
        e.preventDefault();
        const btn = document.getElementById('submitBtn');
        const errEl = document.getElementById('errorMsg');
        const successEl = document.getElementById('successMsg');
        errEl.style.display = 'none';
        successEl.style.display = 'none';
        btn.disabled = true;
        btn.textContent = 'Submitting...';

        try {
            // Prepare attachments as base64
            const attachmentData = [];
            for (const a of attachments) {
                const content = await fileToBase64(a.file);
                attachmentData.push({ name: a.file.name, type: a.file.type, size: a.file.size, content });
            }

            const resp = await fetch('../api/self-service/create_ticket.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    mailbox_id: document.getElementById('mailbox').value || null,
                    subject: document.getElementById('subject').value.trim(),
                    priority: document.getElementById('priority').value,
                    description: document.getElementById('description').value.trim(),
                    attachments: attachmentData
                })
            });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('formCard').style.display = 'none';
                successEl.innerHTML = 'Ticket <strong>' + escapeHtml(data.ticket_number) + '</strong> has been created. ' +
                    '<a href="ticket.php?id=' + data.ticket_id + '">View ticket</a> or <a href="index.php">return to dashboard</a>.';
                successEl.style.display = 'block';
            } else {
                errEl.textContent = data.error;
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Submit';
            }
        } catch (err) {
            errEl.textContent = 'Failed to create ticket. Please try again.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Submit';
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
    </script>
</body>
</html>
