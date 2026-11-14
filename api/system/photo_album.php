<?php
/**
 * API: System -> Photo Album.
 *
 *   GET  ?action=list                 my pictures, newest first (thumbnails, no art)
 *   GET  ?action=get&id=N             one of my pictures, with its art and colours
 *   POST {action:'save', title, style, palette, width_chars, height_chars, art, colours?, thumbnail}
 *   POST {action:'delete', id}
 *
 * The webcam photo is turned into ASCII art in the browser and NEVER comes here:
 * only the art (text), its colours for the Colour palette, and a small JPEG of
 * the art for the album grid. Everything is personal - an analyst sees, opens
 * and deletes only their own, so every query is scoped by analyst_id.
 *
 * UI-only, with no REST API twin, so there is no duplication for a service
 * class to remove (Service-Layer-Architecture, "Watch out for #9").
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('system');

const PA_STYLES   = ['braille', 'classic', 'blocks'];
const PA_PALETTES = ['green', 'amber', 'paper', 'colour', 'cga', 'ega', 'vga'];
// The screens that carry a colour per character: Colour, and the old PC cards (5.2.0).
const PA_COLOUR_PALETTES = ['colour', 'cga', 'ega', 'vga'];
const PA_THUMB_MAX = 200 * 1024;   // bytes of JPEG, decoded

/**
 * Sends an HTTP status (200 by default) and {success: false, error} as JSON, then stops.
 *
 * @param string $message  the error shown to the user
 * @param int $status  the HTTP status to send
 */
function paFail(string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/**
 * Every character of a style's art must be one that style can produce.
 *
 * @param string $style  the picture's style: 'braille', 'blocks', or anything else for plain ASCII ('classic')
 */
function paStylePattern(string $style): string
{
    switch ($style) {
        case 'braille': return '/^[\x{2800}-\x{28FF}]+$/u';
        case 'blocks':  return '/^[ \x{2591}\x{2592}\x{2593}\x{2588}]+$/u';
        default:        return '/^[\x20-\x7E]+$/';
    }
}

try {
    $conn      = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $method    = $_SERVER['REQUEST_METHOD'];
    $json      = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];
    $action    = $method === 'POST' ? (string)($json['action'] ?? '') : (string)($_GET['action'] ?? '');

    // Before Database Verification has created the table, say so rather than fail.
    $ready = (bool)$conn->query("SHOW TABLES LIKE 'photo_album'")->fetchColumn();
    if (!$ready) {
        echo json_encode(['success' => true, 'ready' => false, 'photos' => []]);
        exit;
    }

    if ($method === 'GET' && $action === 'list') {
        $st = $conn->prepare(
            "SELECT id, title, style, palette, width_chars, height_chars, thumbnail, created_datetime
               FROM photo_album WHERE analyst_id = ? ORDER BY created_datetime DESC, id DESC"
        );
        $st->execute([$analystId]);
        $photos = array_map(fn($r) => [
            'id'           => (int)$r['id'],
            'title'        => $r['title'],
            'style'        => $r['style'],
            'palette'      => $r['palette'],
            'width_chars'  => (int)$r['width_chars'],
            'height_chars' => (int)$r['height_chars'],
            'thumbnail'    => $r['thumbnail'],
            'created'      => $r['created_datetime'],   // UTC; the browser shows it in local time
        ], $st->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['success' => true, 'ready' => true, 'photos' => $photos]);
        exit;
    }

    if ($method === 'GET' && $action === 'get') {
        $st = $conn->prepare("SELECT * FROM photo_album WHERE id = ? AND analyst_id = ?");
        $st->execute([(int)($_GET['id'] ?? 0), $analystId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) paFail('Picture not found.', 404);
        echo json_encode(['success' => true, 'photo' => [
            'id'           => (int)$r['id'],
            'title'        => $r['title'],
            'style'        => $r['style'],
            'palette'      => $r['palette'],
            'width_chars'  => (int)$r['width_chars'],
            'height_chars' => (int)$r['height_chars'],
            'art'          => $r['art'],
            'colours'      => $r['colours'],
            'created'      => $r['created_datetime'],
        ]]);
        exit;
    }

    if ($method === 'POST' && $action === 'save') {
        $title = trim((string)($json['title'] ?? ''));
        if ($title === '') paFail('Give the picture a title.');
        if (mb_strlen($title) > 150) paFail('The title can be up to 150 characters.');

        $style   = (string)($json['style'] ?? '');
        $palette = (string)($json['palette'] ?? '');
        if (!in_array($style, PA_STYLES, true))     paFail('Unknown style.');
        if (!in_array($palette, PA_PALETTES, true)) paFail('Unknown palette.');

        $w = (int)($json['width_chars'] ?? 0);
        $h = (int)($json['height_chars'] ?? 0);
        if ($w < 20 || $w > 400 || $h < 5 || $h > 400) paFail('The picture is an unexpected size.');

        // The art: exactly $h lines of exactly $w characters, all from the style's set.
        $art = str_replace("\r\n", "\n", (string)($json['art'] ?? ''));
        $lines = explode("\n", $art);
        if (count($lines) !== $h) paFail('The picture does not have the rows it says it has.');
        $pattern = paStylePattern($style);
        foreach ($lines as $line) {
            if (mb_strlen($line) !== $w || !preg_match($pattern, $line)) {
                paFail('The picture contains characters its style cannot make.');
            }
        }

        // Colours: 3 bytes per character, only (and always) for the colour screens (PA_COLOUR_PALETTES).
        $colours = null;
        if (in_array($palette, PA_COLOUR_PALETTES, true)) {
            $raw = base64_decode((string)($json['colours'] ?? ''), true);
            if ($raw === false || strlen($raw) !== $w * $h * 3) paFail('The colours do not match the picture.');
            $colours = base64_encode($raw);
        }

        // The album-grid image: a real JPEG (dithered art compresses badly as
        // PNG - one was 190KB), and a small one.
        $thumb = (string)($json['thumbnail'] ?? '');
        $prefix = 'data:image/jpeg;base64,';
        if (strncmp($thumb, $prefix, strlen($prefix)) !== 0) paFail('The preview image is missing.');
        $jpeg = base64_decode(substr($thumb, strlen($prefix)), true);
        if ($jpeg === false || strncmp($jpeg, "\xFF\xD8\xFF", 3) !== 0) paFail('The preview image is not a JPEG.');
        if (strlen($jpeg) > PA_THUMB_MAX) paFail('The preview image is too large.');

        $st = $conn->prepare(
            "INSERT INTO photo_album (analyst_id, title, style, palette, width_chars, height_chars, art, colours, thumbnail, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        );
        $st->execute([$analystId, $title, $style, $palette, $w, $h, $art, $colours, $prefix . base64_encode($jpeg)]);
        echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId()]);
        exit;
    }

    if ($method === 'POST' && $action === 'delete') {
        $st = $conn->prepare("DELETE FROM photo_album WHERE id = ? AND analyst_id = ?");
        $st->execute([(int)($json['id'] ?? 0), $analystId]);
        if ($st->rowCount() === 0) paFail('Picture not found.', 404);
        echo json_encode(['success' => true]);
        exit;
    }

    paFail('Unknown action.', 400);
} catch (Exception $e) {
    error_log('photo_album: ' . $e->getMessage());
    paFail('Something went wrong with the photo album.', 500);
}
