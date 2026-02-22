<?php
/**
 * Self-Service Portal Authentication Guard
 * Include at the top of every authenticated self-service page (after session_start)
 */
if (!isset($_SESSION['ss_user_id'])) {
    header('Location: login.php');
    exit;
}

$ss_user_id = (int)$_SESSION['ss_user_id'];
$ss_user_name = $_SESSION['ss_user_name'] ?? 'User';
$ss_user_email = $_SESSION['ss_user_email'] ?? '';
