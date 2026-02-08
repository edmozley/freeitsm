<?php
/**
 * Reporting - Landing page with links to reporting areas
 */
session_start();
require_once '../config.php';

$current_page = 'reporting';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Reporting</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        .reporting-landing {
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

        .report-cards {
            display: flex;
            gap: 24px;
            justify-content: center;
        }

        .report-card {
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

        .report-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.12);
            border-color: #ca5010;
        }

        .report-card svg {
            color: #ca5010;
            margin-bottom: 16px;
        }

        .report-card h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            color: #333;
        }

        .report-card p {
            margin: 0;
            font-size: 13px;
            color: #888;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container reporting-landing">
        <div class="landing-content">
            <h2>Reporting</h2>
            <p class="subtitle">Choose a reporting area to get started</p>

            <div class="report-cards">
                <a href="logs/" class="report-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                        <polyline points="10 9 9 9 8 9"></polyline>
                    </svg>
                    <h3>System Logs</h3>
                    <p>View login attempts, email imports, and other system activity logs.</p>
                </a>

                <a href="tickets/" class="report-card">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                    <h3>Ticket Dashboards</h3>
                    <p>KPI dashboards for ticket performance, resolution times, and team workload.</p>
                </a>
            </div>
        </div>
    </div>
</body>
</html>
