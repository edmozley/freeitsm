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
                    <label for="subject">Subject *</label>
                    <input type="text" id="subject" required placeholder="Brief summary of your issue" autofocus>
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
                <div class="form-actions">
                    <a href="index.php" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-submit" id="submitBtn">Submit</button>
                </div>
            </form>
        </div>
    </div>

    <script>
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
            const resp = await fetch('../api/self-service/create_ticket.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    subject: document.getElementById('subject').value.trim(),
                    priority: document.getElementById('priority').value,
                    description: document.getElementById('description').value.trim()
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
