<?php
/**
 * Self-Service Portal Logout
 * Clears self-service session vars only (preserves any analyst session)
 */
session_start();
unset($_SESSION['ss_user_id']);
unset($_SESSION['ss_user_email']);
unset($_SESSION['ss_user_name']);
header('Location: login.php');
exit;
