<?php
/**
 * Self-Service Portal Registration Page
 */
session_start();

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
    <title>Self-Service Portal - Register</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
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
        .form-hint {
            font-size: 12px;
            color: #999;
            margin-top: 4px;
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
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #155724;
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
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="../assets/images/CompanyLogo.png" alt="Company Logo">
            <h1>Create Account</h1>
            <p>Register to access the self-service portal</p>
        </div>

        <div class="error-message" id="errorMsg"></div>
        <div class="success-message" id="successMsg"></div>

        <form id="registerForm" onsubmit="return handleRegister(event)">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" required autofocus autocomplete="email">
            </div>
            <div class="form-group">
                <label for="displayName">Full Name</label>
                <input type="text" id="displayName" required autocomplete="name">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" required minlength="8" autocomplete="new-password">
                <div class="form-hint">Minimum 8 characters</div>
            </div>
            <div class="form-group">
                <label for="confirmPassword">Confirm Password</label>
                <input type="password" id="confirmPassword" required minlength="8" autocomplete="new-password">
            </div>
            <button type="submit" class="login-button" id="registerBtn">Register</button>
        </form>

        <div class="login-links">
            <a href="login.php">Already have an account? Sign in</a>
        </div>
    </div>

    <script>
    async function handleRegister(e) {
        e.preventDefault();
        const btn = document.getElementById('registerBtn');
        const errEl = document.getElementById('errorMsg');
        const successEl = document.getElementById('successMsg');
        errEl.style.display = 'none';
        successEl.style.display = 'none';

        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirmPassword').value;

        if (password !== confirmPassword) {
            errEl.textContent = 'Passwords do not match';
            errEl.style.display = 'block';
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Creating account...';

        try {
            const resp = await fetch('../api/self-service/register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: document.getElementById('email').value.trim(),
                    display_name: document.getElementById('displayName').value.trim(),
                    password: password,
                    confirm_password: confirmPassword
                })
            });
            const data = await resp.json();
            if (data.success) {
                window.location.href = 'index.php';
            } else {
                errEl.textContent = data.error;
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.textContent = 'Register';
            }
        } catch (err) {
            errEl.textContent = 'Registration failed. Please try again.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.textContent = 'Register';
        }
    }
    </script>
</body>
</html>
