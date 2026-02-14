<?php
/**
 * System - Landing page with links to system areas
 */
session_start();
require_once '../config.php';

$current_page = 'system';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - System</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .system-landing {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f7fa;
        }

        .landing-content {
            text-align: center;
            max-width: 700px;
        }

        .landing-content h2 {
            font-size: 24px;
            color: #333;
            margin: 0 0 8px 0;
        }

        .landing-content .subtitle {
            font-size: 14px;
            color: #888;
            margin: 0 0 40px 0;
        }

        .system-cards {
            display: flex;
            gap: 24px;
            justify-content: center;
        }

        .system-card {
            background: #fff;
            border-radius: 12px;
            padding: 40px 36px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            text-decoration: none;
            color: inherit;
            width: 280px;
            transition: transform 0.15s, box-shadow 0.15s;
            border: 2px solid transparent;
        }

        .system-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            border-color: #546e7a;
        }

        .system-card svg {
            color: #546e7a;
            margin-bottom: 16px;
        }

        .system-card h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            color: #333;
        }

        .system-card p {
            margin: 0;
            font-size: 13px;
            color: #888;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container system-landing">
        <div class="landing-content">
            <h2>System Administration</h2>
            <p class="subtitle">Configure system-level settings and access controls</p>

            <div class="system-cards">
                <a href="encryption/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                    <h3>Encryption</h3>
                    <p>Generate and manage the encryption key used to protect sensitive data such as API keys and credentials.</p>
                </a>

                <a href="modules/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                    <h3>Module Access</h3>
                    <p>Control which modules each analyst can access. Restrict visibility on the home screen and navigation menu.</p>
                </a>

                <a href="db-verify/" class="system-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <ellipse cx="12" cy="5" rx="9" ry="3"></ellipse>
                        <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path>
                        <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>
                    </svg>
                    <h3>Database Verify</h3>
                    <p>Check all tables and columns exist in the database. Automatically creates any that are missing.</p>
                </a>
            </div>
        </div>
    </div>
</body>
</html>
