<?php
/** Pretty URL: /problem-management/new/ → open the module with the editor showing. */
session_start();
require_once __DIR__ . '/../../config.php';
header('Location: ' . BASE_URL . 'problem-management/?new=1');
exit;
