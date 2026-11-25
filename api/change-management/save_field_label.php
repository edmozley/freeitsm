<?php
/**
 * API: give a change form field its own name, or take it away (GH #435).
 *
 * POST { key, label }   label '' (or null) = back to the standard name.
 *
 * Only the name shown changes - the field is the same field to the calendar, approvals,
 * templates, filters, the REST API and workflows. See includes/changes/field_labels.php.
 */
session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/changes/field_labels.php';
csrfRequireWriteMethod();
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');
requireCapabilityJson(Cap::CHANGES_FIELDS);   // the Fields tab of Changes -> Settings

try {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $key = (string)($in['key'] ?? '');
    if (!isset(CHANGE_FIELD_STANDARD_NAMES[$key])) throw new InvalidArgumentException(t('change-management.settings.label_not_renamable'));
    $label = trim(preg_replace('/\s+/u', ' ', (string)($in['label'] ?? '')));
    if (mb_strlen($label) > 100) throw new InvalidArgumentException(t('change-management.settings.label_too_long'));
    $conn = connectToDatabase();
    $st = $conn->prepare("UPDATE change_field_layout SET label = ? WHERE field_key = ?");
    $st->execute([$label !== '' ? $label : null, $key]);
    if ($st->rowCount() === 0 && !$conn->query("SELECT 1 FROM change_field_layout WHERE field_key = " . $conn->quote($key))->fetchColumn()) {
        // A field with no layout row yet sits in "Unplaced": it has to be placed first.
        throw new InvalidArgumentException(t('change-management.settings.label_place_first'));
    }
    echo json_encode(['success' => true, 'key' => $key, 'label' => $label !== '' ? $label : null,
                      'standard' => t(CHANGE_FIELD_STANDARD_NAMES[$key])]);
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('save_field_label: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => t('change-management.settings.label_save_failed')]);
}
