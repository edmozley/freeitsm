<?php
/**
 * API Endpoint: Test SMTP connection
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$host = $input['smtp_host'] ?? '';
$port = $input['smtp_port'] ?? 587;
$encryption = $input['smtp_encryption'] ?? 'tls';
$authRequired = ($input['smtp_auth'] ?? 'yes') === 'yes';
$username = $input['smtp_username'] ?? '';
$password = $input['smtp_password'] ?? '';

if (empty($host)) {
    echo json_encode(['success' => false, 'error' => 'SMTP hostname is required']);
    exit;
}

try {
    // Build connection string based on encryption type
    $errno = 0;
    $errstr = '';
    $timeout = 10;

    if ($encryption === 'ssl') {
        $connectHost = 'ssl://' . $host;
    } elseif ($encryption === 'tls') {
        $connectHost = $host; // TLS uses STARTTLS after connecting
    } else {
        $connectHost = $host;
    }

    // Try to connect
    $socket = @fsockopen($connectHost, $port, $errno, $errstr, $timeout);

    if (!$socket) {
        echo json_encode([
            'success' => false,
            'error' => "Could not connect to $host:$port - $errstr (Error $errno)"
        ]);
        exit;
    }

    // Read server greeting
    $response = fgets($socket, 512);
    if (strpos($response, '220') !== 0) {
        fclose($socket);
        echo json_encode([
            'success' => false,
            'error' => "Unexpected server response: $response"
        ]);
        exit;
    }

    // Send EHLO
    fwrite($socket, "EHLO localhost\r\n");
    $response = '';
    while ($line = fgets($socket, 512)) {
        $response .= $line;
        if (substr($line, 3, 1) === ' ') break;
    }

    // If TLS, try STARTTLS
    if ($encryption === 'tls') {
        fwrite($socket, "STARTTLS\r\n");
        $response = fgets($socket, 512);
        if (strpos($response, '220') !== 0) {
            fclose($socket);
            echo json_encode([
                'success' => false,
                'error' => "STARTTLS failed: $response"
            ]);
            exit;
        }

        // Enable TLS
        $cryptoResult = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$cryptoResult) {
            fclose($socket);
            echo json_encode([
                'success' => false,
                'error' => "Could not enable TLS encryption"
            ]);
            exit;
        }

        // Send EHLO again after TLS
        fwrite($socket, "EHLO localhost\r\n");
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
    }

    // If authentication is required, try to authenticate
    if ($authRequired && !empty($username) && !empty($password)) {
        fwrite($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 512);

        if (strpos($response, '334') === 0) {
            fwrite($socket, base64_encode($username) . "\r\n");
            $response = fgets($socket, 512);

            if (strpos($response, '334') === 0) {
                fwrite($socket, base64_encode($password) . "\r\n");
                $response = fgets($socket, 512);

                if (strpos($response, '235') !== 0) {
                    fclose($socket);
                    echo json_encode([
                        'success' => false,
                        'error' => "Authentication failed. Check username and password."
                    ]);
                    exit;
                }
            }
        }
    }

    // Send QUIT
    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    echo json_encode([
        'success' => true,
        'message' => 'SMTP connection test successful'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
