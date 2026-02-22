<?php
/**
 * Self-Service Portal Login Page
 */
session_start();

// Already logged in - redirect to dashboard
if (isset($_SESSION['ss_user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Self-Service Portal - Login</title>
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
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header img {
            width: 250px;
            height: auto;
            margin-bottom: 25px;
        }
        .login-header h1 {
            color: #333;
            font-size: 24px;
            margin-bottom: 6px;
        }
        .login-header p {
            color: #888;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
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
        .login-button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .login-button:hover { transform: translateY(-2px); }
        .login-button:active { transform: translateY(0); }
        .login-button:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
        .login-links {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }
        .login-links a {
            color: #667eea;
            text-decoration: none;
        }
        .login-links a:hover { text-decoration: underline; }
        .login-links .divider {
            color: #ccc;
            margin: 0 8px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="../assets/images/CompanyLogo.png" alt="Company Logo">
            <h1>Self-Service Portal</h1>
            <p>Sign in to view your tickets</p>
        </div>

        <div class="error-message" id="errorMsg"></div>

        <form id="loginForm" onsubmit="return handleLogin(event)">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" required autofocus autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="login-button" id="loginBtn">Sign In</button>
        </form>

        <div class="login-links">
            <a href="register.php">Create an account</a>
            <span class="divider">|</span>
            <a href="../login.php">Analyst login</a>
        </div>
    </div>

    <script>
    async function handleLogin(e) {
        e.preventDefault();
        const btn = document.getElementById('loginBtn');
        const errEl = document.getElementById('errorMsg');
        errEl.style.display = 'none';
        btn.disabled = true;
        btn.textContent = 'Signing in...';

        try {
            const resp = await fetch('../api/self-service/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: document.getElementById('email').value.trim(),
                    password: document.getElementById('password').value
                })
            });
            const data = await resp.json();
            if (data.success) {
                window.location.href = 'index.php';
            } else {
                errEl.textContent = data.error;
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Sign In';
            }
        } catch (err) {
            errEl.textContent = 'Login failed. Please try again.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Sign In';
        }
    }
    </script>
</body>
</html>
