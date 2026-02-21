<?php
/**
 * API Endpoint: Verify a mail folder exists in a mailbox
 * POST: { "mailbox_id": N, "folder_name": "Processed" }
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$mailboxId = $data['mailbox_id'] ?? null;
$folderName = trim($data['folder_name'] ?? '');

if (!$mailboxId || $folderName === '') {
    echo json_encode(['success' => false, 'error' => 'Mailbox ID and folder name are required']);
    exit;
}

try {
    $conn = connectToDatabase();

    $sql = "SELECT * FROM target_mailboxes WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$mailboxId]);
    $mailbox = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mailbox) {
        echo json_encode(['success' => false, 'error' => 'Mailbox not found']);
        exit;
    }

    $mailbox = decryptMailboxRow($mailbox);

    if (empty($mailbox['token_data'])) {
        echo json_encode(['success' => false, 'error' => 'Mailbox is not authenticated']);
        exit;
    }

    $cleanedTokenData = preg_replace('/[\x00-\x1F\x7F]/', '', $mailbox['token_data']);
    $tokenData = json_decode($cleanedTokenData, true);

    if (!$tokenData || !isset($tokenData['access_token'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid token data']);
        exit;
    }

    // Refresh token if expired
    if (isset($tokenData['expires_at']) && $tokenData['expires_at'] < (time() + 300)) {
        if (!isset($tokenData['refresh_token'])) {
            echo json_encode(['success' => false, 'error' => 'Token expired. Please re-authenticate.']);
            exit;
        }

        $tokenUrl = 'https://login.microsoftonline.com/' . $mailbox['azure_tenant_id'] . '/oauth2/v2.0/token';
        $postData = [
            'client_id' => $mailbox['azure_client_id'],
            'client_secret' => $mailbox['azure_client_secret'],
            'refresh_token' => $tokenData['refresh_token'],
            'grant_type' => 'refresh_token',
            'scope' => $mailbox['oauth_scopes']
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo json_encode(['success' => false, 'error' => 'Failed to refresh token']);
            exit;
        }

        $newToken = json_decode($response, true);
        $tokenData['access_token'] = $newToken['access_token'];
        $tokenData['refresh_token'] = $newToken['refresh_token'] ?? $tokenData['refresh_token'];
        $tokenData['expires_at'] = time() + ($newToken['expires_in'] ?? 3600);

        $saveSql = "UPDATE target_mailboxes SET token_data = ? WHERE id = ?";
        $saveStmt = $conn->prepare($saveSql);
        $saveStmt->execute([json_encode($tokenData), $mailboxId]);
    }

    $accessToken = $tokenData['access_token'];

    // Query Graph API for the folder
    $graphUrl = 'https://graph.microsoft.com/v1.0/me/mailFolders?'
        . http_build_query(['$filter' => "displayName eq '" . str_replace("'", "''", $folderName) . "'"]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $graphUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, SSL_VERIFY_PEER);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, SSL_VERIFY_PEER ? 2 : 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        curl_close($ch);
        echo json_encode(['success' => false, 'error' => 'cURL error: ' . curl_error($ch)]);
        exit;
    }

    curl_close($ch);

    if ($httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => 'Graph API error. HTTP ' . $httpCode]);
        exit;
    }

    $result = json_decode($response, true);
    $folders = $result['value'] ?? [];

    if (empty($folders)) {
        echo json_encode([
            'success' => false,
            'error' => 'Folder "' . $folderName . '" not found in mailbox'
        ]);
    } else {
        $folder = $folders[0];
        echo json_encode([
            'success' => true,
            'folder' => [
                'id' => $folder['id'],
                'displayName' => $folder['displayName'],
                'totalItemCount' => $folder['totalItemCount'] ?? null,
                'unreadItemCount' => $folder['unreadItemCount'] ?? null,
            ]
        ]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
