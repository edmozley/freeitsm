<?php
/**
 * Forgot Password page
 * Accepts username or email and sends a password reset link via the configured mailbox.
 */
session_start();

// Already logged in
if (isset($_SESSION['analyst_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }

        .header {
            text-align: center;
            margin-bottom: 24px;
        }

        .header .icon {
            width: 48px;
            height: 48px;
            color: #667eea;
            margin-bottom: 16px;
        }

        .header h1 {
            color: #333;
            font-size: 22px;
            margin-bottom: 8px;
        }

        .header p {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #333;
            font-weight: 500;
            font-size: 13px;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .msg {
            padding: 10px 14px;
            border-radius: 5px;
            margin-bottom: 16px;
            font-size: 13px;
            display: none;
            line-height: 1.5;
        }

        .msg.error {
            display: block;
            background: #fee;
            color: #c33;
            border-left: 4px solid #c33;
        }

        .msg.success {
            display: block;
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }

        .submit-btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .submit-btn:hover { transform: translateY(-2px); }
        .submit-btn:active { transform: translateY(0); }
        .submit-btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 16px;
            color: #999;
            text-decoration: none;
            font-size: 13px;
        }

        .back-link:hover { color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <svg class="icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
            <h1>Forgot Password</h1>
            <p>Enter your username or email address and we'll send you a link to reset your password.</p>
        </div>

        <div id="msg" class="msg"></div>

        <div id="formFields">
            <div class="form-group">
                <label for="identifier">Username or Email</label>
                <input type="text" id="identifier" autofocus autocomplete="username">
            </div>

            <button type="button" class="submit-btn" id="submitBtn" onclick="requestReset()">Send Reset Link</button>
        </div>

        <a href="login.php" class="back-link">Back to login</a>
    </div>

    <script>
    document.getElementById('identifier').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') requestReset();
    });

    async function requestReset() {
        const identifier = document.getElementById('identifier').value.trim();
        const msgEl = document.getElementById('msg');
        const btn = document.getElementById('submitBtn');

        msgEl.className = 'msg';
        msgEl.style.display = 'none';

        if (!identifier) {
            msgEl.className = 'msg error';
            msgEl.textContent = 'Please enter your username or email address.';
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Sending...';

        try {
            const resp = await fetch('api/auth/request_password_reset.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ identifier: identifier })
            });
            const data = await resp.json();

            if (data.success) {
                msgEl.className = 'msg success';
                msgEl.textContent = data.message;
                document.getElementById('formFields').style.display = 'none';
            } else {
                msgEl.className = 'msg error';
                msgEl.textContent = data.error;
                btn.disabled = false;
                btn.textContent = 'Send Reset Link';
            }
        } catch (e) {
            msgEl.className = 'msg error';
            msgEl.textContent = 'Something went wrong. Please try again.';
            btn.disabled = false;
            btn.textContent = 'Send Reset Link';
        }
    }
    </script>
</body>
</html>
