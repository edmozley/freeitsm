<?php
/**
 * Ticket Dashboards - KPI reporting for tickets (coming soon)
 */
session_start();
require_once '../../config.php';

$current_page = 'tickets';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Ticket Dashboards</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .coming-soon-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f7fa;
        }

        .coming-soon-card {
            text-align: center;
            background: #fff;
            border-radius: 12px;
            padding: 60px 80px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }

        .coming-soon-card svg {
            color: #ca5010;
            margin-bottom: 20px;
        }

        .coming-soon-card h2 {
            margin: 0 0 10px 0;
            font-size: 22px;
            color: #333;
        }

        .coming-soon-card p {
            margin: 0;
            font-size: 14px;
            color: #888;
            max-width: 360px;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="main-container coming-soon-container">
        <div class="coming-soon-card">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="20" x2="18" y2="10"></line>
                <line x1="12" y1="20" x2="12" y2="4"></line>
                <line x1="6" y1="20" x2="6" y2="14"></line>
            </svg>
            <h2>Ticket Dashboards</h2>
            <p>KPI dashboards and reporting for ticket performance, resolution times, and team workload will be available here soon.</p>
        </div>
    </div>
</body>
</html>
