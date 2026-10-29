<?php
/**
 * Pictures inside a ticket note (GH #224).
 *
 * A note stays PLAIN TEXT. A picture pasted into the note box is stored as a
 * document on the note - the same store, limits and access checks as the note's
 * Files - and the text carries a marker where it sits: [image:<document id>].
 * The analyst view and the portal turn a marker into the picture
 * (assets/js/note-images.js); everything else that reads note_text (AI, search,
 * the REST API, Case report, merges) keeps reading text, and sees the marker.
 *
 * TRAP: a marker is only ever drawn as a picture when that document is linked
 * to THAT note. Anyone can type "[image:123]"; it must never pull in a file
 * from another ticket. The renderers check against the note's own documents.
 *
 * Why not HTML notes: ~25 places read note_text as text, and the reply editor
 * puts a pasted picture into the body as base64 - fine for an email, wrong for
 * a database row that search and AI prompts also read.
 */

require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/documents.php';

/** The marker a saved note carries for a picture. */
const NOTE_IMAGE_MARKER_RE = '/\[image:(\d+)\]/';

/** Pasted pictures only - never SVG, which can carry script. */
const NOTE_IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'];

/** At most this many pictures in one note: a paste loop should not fill the disk. */
const NOTE_IMAGE_MAX = 20;

/**
 * The note as text for somebody who cannot see the pictures - the shared-note
 * email, which goes to an inbox where a marker would mean nothing.
 */
function noteImagesPlainText(string $text): string
{
    return preg_replace(NOTE_IMAGE_MARKER_RE, '[image]', $text);
}

/**
 * The pasted pictures of a multipart note save, as [key => one $_FILES entry].
 * The browser sends `images[]` with a matching `image_keys[]`; the key is what
 * its marker in the text says ("new-1").
 */
function noteImagesFromRequest(): array
{
    $f = $_FILES['images'] ?? null;
    $keys = $_POST['image_keys'] ?? [];
    if (!$f || !is_array($f['name'] ?? null) || !is_array($keys)) return [];
    $out = [];
    foreach ($f['name'] as $i => $name) {
        $key = (string) ($keys[$i] ?? '');
        if (!preg_match('/^new-\d+$/', $key)) continue;
        $out[$key] = [
            'name' => $name, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '',
            'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0,
        ];
    }
    return $out;
}

/**
 * Store the pictures and create their document rows, BEFORE the note exists.
 *
 * Validated and written first so that a refused picture refuses the whole save:
 * the note is not created, nothing is emailed, and the analyst still has their
 * text. Returns [key => document id]; the caller links them to the note once it
 * has an id, or calls noteImagesDiscard() if creating the note fails.
 *
 * @throws Exception with a message safe to show (the upload layer's own reasons)
 */
function noteImagesStore(PDO $conn, int $analystId, array $images, ?int $tenantId): array
{
    if (count($images) > NOTE_IMAGE_MAX) {
        throw new Exception('A note can hold at most ' . NOTE_IMAGE_MAX . ' pictures.');
    }
    $dir = dirname(__DIR__) . '/uploads/' . DOCUMENT_STORAGE_DIR;
    $stored = [];
    try {
        foreach ($images as $key => $file) {
            $s = uploadStoreFile($file, $dir, attachmentAllowedTypes($conn));
            $stored[$key] = $s;
            if (!in_array($s['mime'], NOTE_IMAGE_MIMES, true)) {
                throw new Exception($s['original_name'] . ' is not a picture.');
            }
        }
        $ids = [];
        $ins = $conn->prepare(
            "INSERT INTO documents
                (kind, title, storage_key, original_name, mime_type, size_bytes, content_hash, tenant_id, uploaded_by_id)
             VALUES (?,?,?,?,?,?,?,?,?)"
        );
        foreach ($stored as $key => $s) {
            $ins->execute([
                DOCUMENT_KIND_FILE, mb_substr($s['original_name'], 0, 255), documentStorageKey($s['stored_name']),
                $s['original_name'], $s['mime'], $s['size'], @hash_file('sha256', $s['path']) ?: null,
                $tenantId, $analystId,
            ]);
            $ids[$key] = (int) $conn->lastInsertId();
        }
        return $ids;
    } catch (Throwable $e) {
        if (!empty($ids)) noteImagesDiscard($conn, $ids);
        foreach ($stored as $s) {
            if (is_file($s['path'])) @unlink($s['path']);
        }
        throw $e;
    }
}

/** Remove pictures stored by noteImagesStore() whose note was never created. */
function noteImagesDiscard(PDO $conn, array $docIds): void
{
    if (!$docIds) return;
    $in = implode(',', array_fill(0, count($docIds), '?'));
    $q = $conn->prepare("SELECT storage_key FROM documents WHERE id IN ($in)");
    $q->execute(array_values($docIds));
    $keys = $q->fetchAll(PDO::FETCH_COLUMN);
    $conn->prepare("DELETE FROM documents WHERE id IN ($in)")->execute(array_values($docIds));
    foreach ($keys as $k) {
        $p = documentStoragePath((string) $k);
        if (is_file($p)) @unlink($p);
    }
}

/** The note's text with each browser key ("[image:new-1]") swapped for its document id. */
function noteImagesRewriteMarkers(string $text, array $docIds): string
{
    foreach ($docIds as $key => $id) {
        $text = str_replace('[image:' . $key . ']', '[image:' . $id . ']', $text);
    }
    return $text;
}
