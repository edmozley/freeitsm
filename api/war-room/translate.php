<?php
/**
 * War room - translate messages into the reader's language (5.9.0).
 * wiki War-Room-Message-Actions-Design.
 *
 *   POST {channel_id, ids: [message ids]}
 *   -> {success, lang, items: {id: {lang, text|null}}}   text null = already in your language
 *
 * Only when an administrator has switched translation on (War room -> Settings ->
 * AI) and an AI provider is set up. The target is the caller's own FreeITSM
 * language - never one they send - and every message is checked against the
 * channel the caller can read. Translations are stored, so asking again is free.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/warroom.php';

header('Content-Type: application/json');

requireModuleAccessJson('war-room');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

try {
    $conn      = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    I18n::initFromSession();

    if (!warRoomTranslateEnabled($conn)) {
        echo json_encode(['success' => false, 'error' => 'not_enabled']);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $channelId = (int) ($input['channel_id'] ?? 0);
    $ids = is_array($input['ids'] ?? null) ? $input['ids'] : [];

    $lang  = I18n::getLocale();
    $items = warRoomTranslate($conn, $analystId, $channelId, $ids, $lang);
    echo json_encode(['success' => true, 'lang' => $lang, 'items' => (object) $items], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    // The provider could not be reached - during an outage, very likely and not a
    // bug. The page keeps showing the originals.
    echo json_encode(['success' => false, 'error' => 'provider_unreachable']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not translate']);
}
