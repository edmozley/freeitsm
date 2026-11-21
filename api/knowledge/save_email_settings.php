<?php
/**
 * API Endpoint: Save knowledge email settings
 *
 * Despite the name this writes THREE tabs' worth of settings — the Email tab's SMTP
 * credentials, the Recycle bin's retention, and (by a legacy path) the AI and OpenAI API
 * keys. Each is a separate permission, so a single guard at the top of the file could
 * never be right: it would have to cover three audiences at once.
 *
 * So authorisation is PER KEY, against the keys this request will actually write, using
 * the same helper and ownership map as every other shared settings writer (#829). The map
 * derives from knowledge/settings/manifest.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/settings_keys.php';
require_once '../../includes/encryption.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('knowledge');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$settings = $input['settings'] ?? null;

if (!$settings) {
    echo json_encode(['success' => false, 'error' => 'Settings required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Only write the email settings if this request is actually SAVING them.
    //
    // These used to be built unconditionally, with a default for every absent field. But
    // this endpoint also serves the Recycle bin tab, whose form posts nothing except
    // recycle_bin_days — so saving the retention period silently rewrote the entire email
    // configuration to its defaults: method back to 'disabled', the mailbox unset, the SMTP
    // host blanked. Article sharing would just stop working, with nothing to show why.
    $emailFields = [
        'knowledge_email_method'          => ['email_method',     'disabled'],
        'knowledge_email_smtp_host'       => ['smtp_host',        ''],
        'knowledge_email_smtp_port'       => ['smtp_port',        '587'],
        'knowledge_email_smtp_encryption' => ['smtp_encryption',  'tls'],
        'knowledge_email_smtp_auth'       => ['smtp_auth',        'yes'],
        'knowledge_email_smtp_username'   => ['smtp_username',    ''],
        'knowledge_email_smtp_from_email' => ['smtp_from_email',  ''],
        'knowledge_email_smtp_from_name'  => ['smtp_from_name',   ''],
        'knowledge_email_mailbox_id'      => ['mailbox_id',       ''],
    ];

    $settingsToSave = [];
    // The email form always posts email_method, so that's the marker for "this is an email save".
    if (array_key_exists('email_method', $settings)) {
        foreach ($emailFields as $dbKey => [$field, $default]) {
            $settingsToSave[$dbKey] = $settings[$field] ?? $default;
        }
    }

    // Only update password if provided (not empty) — encrypted at rest, like the two
    // AI keys below. ⚠️ This used to store it raw while its neighbours were wrapped, so
    // saving the form put plaintext back into a column Database Verify had encrypted;
    // the next verify re-encrypted it and sending broke again until someone re-saved.
    // A setting that is encrypted on one path and not the other oscillates rather than
    // failing, which is why it survived review.
    // isMaskedNoChangeValue(), not !empty(): get_email_settings.php now returns the
    // password masked, so a save that did not touch the field posts back "****abcd" —
    // and !empty() would happily store the mask as the new password.
    if (!isMaskedNoChangeValue($settings['smtp_password'] ?? null)) {
        $settingsToSave['knowledge_email_smtp_password'] = encryptValue($settings['smtp_password']);
    }

    // AI API key (saved separately, not prefixed with knowledge_email_) - encrypted at rest
    if (isset($settings['ai_api_key']) && !empty($settings['ai_api_key'])) {
        $settingsToSave['knowledge_ai_api_key'] = encryptValue($settings['ai_api_key']);
    }

    // OpenAI API key for embeddings - encrypted at rest
    if (isset($settings['openai_api_key']) && !empty($settings['openai_api_key'])) {
        $settingsToSave['knowledge_openai_api_key'] = encryptValue($settings['openai_api_key']);
    }

    // Recycle bin retention days
    if (isset($settings['recycle_bin_days'])) {
        $days = max(0, min(999, (int)$settings['recycle_bin_days']));
        $settingsToSave['knowledge_recycle_bin_days'] = (string)$days;
    }

    // GH #417: the largest image the API accepts - one of the dropdown's sizes, nothing typed.
    if (isset($settings['image_max_mb'])) {
        require_once '../../includes/services/knowledge_images.php';
        $mb = (int)$settings['image_max_mb'];
        if (!in_array($mb, KnowledgeImageService::SIZE_CHOICES_MB, true)) {
            echo json_encode(['success' => false, 'error' => 'Choose one of the listed sizes.']);
            exit;
        }
        $settingsToSave[KnowledgeImageService::SETTING_KEY] = (string)$mb;
    }

    // Authorise EVERY key this request would write, BEFORE writing any of them — so a
    // partly-permitted save is refused whole rather than half-applied. A key no tab claims
    // is refused outright.
    $analystId = (int) $_SESSION['analyst_id'];
    foreach (array_keys($settingsToSave) as $key) {
        if (!analystCanWriteSettingKey($conn, $analystId, $key)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error'   => 'You do not have permission to change this setting: ' . $key,
            ]);
            exit;
        }
    }

    // Use UPDATE/INSERT upsert pattern
    foreach ($settingsToSave as $key => $value) {
        // Try to update first
        $updateSql = "UPDATE system_settings SET setting_value = ?, updated_datetime = UTC_TIMESTAMP() WHERE setting_key = ?";
        $stmt = $conn->prepare($updateSql);
        $stmt->execute([$value, $key]);

        // If no rows affected, insert
        if ($stmt->rowCount() === 0) {
            $insertSql = "INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP())";
            $stmt = $conn->prepare($insertSql);
            $stmt->execute([$key, $value]);
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

?>
