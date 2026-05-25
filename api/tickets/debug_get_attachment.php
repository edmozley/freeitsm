<?php
/**
 * DEBUG: Show attachment path info
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Debug: Get Attachment</h1>";
echo "<h2>Request Parameters</h2>";
echo "<pre>";
echo "GET params: " . print_r($_GET, true);
echo "</pre>";

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo "<p style='color:red;'>ERROR: Not authenticated</p>";
    exit;
}

echo "<p style='color:green;'>Authenticated as analyst_id: " . $_SESSION['analyst_id'] . "</p>";

// Get attachment identifier
$attachmentId = $_GET['id'] ?? null;
$contentId = $_GET['cid'] ?? null;
$emailId = $_GET['email_id'] ?? null;

echo "<h2>Parsed Parameters</h2>";
echo "<ul>";
echo "<li>attachmentId: " . ($attachmentId ?? 'NULL') . "</li>";
echo "<li>contentId: " . ($contentId ?? 'NULL') . "</li>";
echo "<li>emailId: " . ($emailId ?? 'NULL') . "</li>";
echo "</ul>";

if (!$attachmentId && !$contentId) {
    echo "<p style='color:red;'>ERROR: Attachment ID or Content-ID required</p>";
    exit;
}

try {
    $conn = connectToDatabase();
    echo "<p style='color:green;'>Database connected successfully</p>";

    // Build query based on lookup method
    if ($attachmentId) {
        $sql = "SELECT id, email_id, filename, content_type, file_path, file_size, content_id
                FROM email_attachments WHERE id = ?";
        $params = [$attachmentId];
    } else {
        $sql = "SELECT id, email_id, filename, content_type, file_path, file_size, content_id
                FROM email_attachments WHERE content_id = ?";
        $params = [$contentId];

        if ($emailId) {
            $sql .= " AND email_id = ?";
            $params[] = $emailId;
        }
    }

    echo "<h2>Database Query</h2>";
    echo "<pre>SQL: " . htmlspecialchars($sql) . "</pre>";
    echo "<pre>Params: " . print_r($params, true) . "</pre>";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "<h2>Database Result</h2>";
    if (!$attachment) {
        echo "<p style='color:red;'>ERROR: Attachment not found in database</p>";

        // Show all attachments for this email_id if provided
        if ($emailId) {
            echo "<h3>All attachments for email_id $emailId:</h3>";
            $stmt2 = $conn->prepare("SELECT id, email_id, filename, content_type, file_path, content_id FROM email_attachments WHERE email_id = ?");
            $stmt2->execute([$emailId]);
            $allAttachments = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            echo "<pre>" . print_r($allAttachments, true) . "</pre>";
        }
        exit;
    }

    echo "<pre>" . print_r($attachment, true) . "</pre>";

    // Build full file path
    echo "<h2>File Path Calculation</h2>";
    echo "<ul>";
    echo "<li>__DIR__: " . __DIR__ . "</li>";
    echo "<li>dirname(__DIR__): " . dirname(__DIR__) . "</li>";
    echo "<li>dirname(dirname(__DIR__)): " . dirname(dirname(__DIR__)) . "</li>";
    echo "</ul>";

    $filePath = dirname(dirname(__DIR__)) . '/tickets/attachments/' . $attachment['file_path'];

    echo "<h2>Full File Path</h2>";
    echo "<pre>" . htmlspecialchars($filePath) . "</pre>";

    echo "<h2>File Check</h2>";
    if (file_exists($filePath)) {
        echo "<p style='color:green;'>File EXISTS</p>";
        echo "<p>Actual file size: " . filesize($filePath) . " bytes</p>";
        echo "<p>Database file_size: " . $attachment['file_size'] . " bytes</p>";
    } else {
        echo "<p style='color:red;'>File NOT FOUND</p>";

        // Check if directory exists
        $dir = dirname($filePath);
        echo "<p>Directory: " . htmlspecialchars($dir) . "</p>";
        echo "<p>Directory exists: " . (is_dir($dir) ? 'YES' : 'NO') . "</p>";

        // List files in the directory if it exists
        if (is_dir($dir)) {
            echo "<h3>Files in directory:</h3>";
            echo "<pre>";
            $files = scandir($dir);
            foreach ($files as $file) {
                echo htmlspecialchars($file) . "\n";
            }
            echo "</pre>";
        }
    }

} catch (Exception $e) {
    echo "<p style='color:red;'>ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
