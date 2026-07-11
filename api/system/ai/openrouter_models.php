<?php
/**
 * API: Return the (cached) OpenRouter model catalogue for the model picker.
 * GET ?refresh=1 forces a re-fetch.
 * Returns: { success, models:[{id,name,context_length,prompt_price,completion_price}], cached_at, stale }
 */
session_start(['read_and_close' => true]);
require_once '../../../config.php';
require_once '../../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../../includes/functions.php';
require_once '../../../includes/ai_provider.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();
    $result = aiProviderListOpenRouterModels($conn, !empty($_GET['refresh']));
    echo json_encode([
        'success'   => true,
        'models'    => $result['models'],
        'cached_at' => $result['cached_at'],
        'stale'     => $result['stale'],
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
