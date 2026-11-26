<?php
/** Article-owned raster images. All reads go through the article's visibility rule. */
require_once __DIR__ . '/knowledge_transfer.php';
require_once __DIR__ . '/../uploads.php';
require_once __DIR__ . '/../service_context.php';   // throws ServiceError itself (tests/service-error-loaded.php)

class KnowledgeImageService
{
    /**
     * The default ceiling - and, before GH #417 (5.9.0), the only one: 10 MiB decoded.
     * The ceiling in force is maxBytes(), which an administrator chooses.
     */
    public const MAX_BYTES = 10485760;

    /**
     * GH #417: the sizes an administrator may choose, in MiB - a dropdown, never a typed
     * number. Bounded at 50 because the whole upload is held in memory several times over
     * (JSON body, decoded copy, stored copy: ~4.3x measured), so a bigger choice would
     * mostly be a way to exhaust PHP's memory.
     */
    public const SIZE_CHOICES_MB = [5, 10, 20, 25, 50];

    /** system_settings key holding the chosen size in MiB; absent or not a choice = the default. */
    public const SETTING_KEY = 'knowledge_image_max_mb';

    /** Allowance for the JSON around the base64 (filename, client_reference, quoting). */
    public const REQUEST_OVERHEAD_BYTES = 65536;

    /**
     * The largest decoded image this install accepts through the REST API, in bytes.
     * An upgrade, a missing row or a value that is not one of SIZE_CHOICES_MB gives the
     * 10 MiB default - so nothing changes until an administrator chooses otherwise.
     */
    public static function maxBytes(PDO $conn): int
    {
        try {
            $st = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
            $st->execute([self::SETTING_KEY]);
            $mb = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $mb = 0;
        }
        return in_array($mb, self::SIZE_CHOICES_MB, true) ? $mb * 1048576 : self::MAX_BYTES;
    }

    /** The longest content_base64 that can decode to $maxBytes or fewer. */
    public static function maxBase64Length(int $maxBytes): int
    {
        return 4 * (int)ceil($maxBytes / 3);
    }

    /**
     * A php.ini size ("8M", "128M", "1G", "-1", "0") in bytes; null = no limit.
     *
     * @param string $value  ini_get() of post_max_size or memory_limit
     */
    public static function iniBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1' || $value === '0') return null;
        $n = (int)$value;
        switch (strtoupper(substr($value, -1))) {
            case 'G': $n *= 1024;   // fall through
            case 'M': $n *= 1024;   // fall through
            case 'K': $n *= 1024;
        }
        return $n > 0 ? $n : null;
    }

    /**
     * What PHP should allow for an image of $maxBytes: memory to hold it ~5 times plus
     * headroom (measured ~4.3x on 10 and 20 MiB images: JSON body, decoded copy, stored
     * copy), and a post_max_size at least the request size.
     *
     * TRAP: post_max_size does NOT refuse a JSON body - PHP 8.4 still hands the whole body
     * to php://input (proved on mod_php, 21 Nov 2026). It only raises a startup warning, and
     * where display_errors is on that warning is printed before FreeITSM runs, which spoils
     * the reply (HTTP 200 and HTML before the JSON). So it is reported as "raise it", never
     * as "uploads are refused". FreeITSM reports these limits; it never changes them.
     *
     * @return array{request_needed:int, memory_needed:int, request_max:?int, memory_max:?int, within:bool}
     */
    public static function serverFit(int $maxBytes): array
    {
        $post = self::iniBytes((string)ini_get('post_max_size'));
        $mem  = self::iniBytes((string)ini_get('memory_limit'));
        $req  = self::maxBase64Length($maxBytes) + self::REQUEST_OVERHEAD_BYTES;
        $need = 5 * $maxBytes + 33554432;
        return ['request_needed' => $req, 'memory_needed' => $need, 'request_max' => $post, 'memory_max' => $mem,
            'within' => ($post === null || $post >= $req) && ($mem === null || $mem >= $need)];
    }

    /**
     * GET /knowledge/images/constraints (GH #417): everything a client needs so it does not
     * hard-code a size or a format.
     */
    public static function constraints(PDO $conn): array
    {
        $max = self::maxBytes($conn);
        $formats = [];
        foreach (UPLOAD_TYPES_IMAGE as $ext => $mimes) $formats[] = ['extension' => $ext, 'mime_types' => array_values($mimes)];
        $fit = self::serverFit($max);
        return [
            'max_bytes'          => $max,
            'default_max_bytes'  => self::MAX_BYTES,
            'max_base64_length'  => self::maxBase64Length($max),
            'encoding'           => 'base64',
            'formats'            => $formats,
            'request_max_bytes'  => $fit['request_max'],
            'within_server_limits' => $fit['within'],
        ];
    }

    /** The image_too_large message: the limit, never anything about the content. */
    /**
     * Refuses an article body with a NEW embedded picture (an <img src="data:...">) larger
     * than this installation's picture limit - the same limit an uploaded picture meets
     * (GH #423). A picture already in $previousHtml is left alone, so an article that holds
     * one from before can still be edited. Throws a 413 with the picture's position, type,
     * size and the limit in error.details.
     *
     * TRAP: pictures added in the article editor are embedded too, scaled down and capped at
     * 3 MB by assets/js/knowledge.js (KB_IMAGE_MAX_BYTES) - under the smallest choice (5 MB),
     * so this never refuses an editor save. If that cap or SIZE_CHOICES_MB changes, keep it so.
     *
     * @param string $html          the body being saved
     * @param string $previousHtml  the body it replaces ('' for a new article)
     */
    public static function assertEmbeddedWithinLimit(PDO $conn, string $html, string $previousHtml = ''): void
    {
        if (stripos($html, 'data:') === false) return;
        $max = self::maxBytes($conn);
        $before = [];
        foreach (self::embeddedPictures($previousHtml) as $p) $before[$p['hash']] = true;
        $n = 0;
        foreach (self::embeddedPictures($html) as $p) {
            $n++;
            if ($p['bytes'] <= $max || isset($before[$p['hash']])) continue;
            $e = new ServiceError('validation', 'embedded_image_too_large',
                'Embedded picture ' . $n . ' in body_html (' . $p['type'] . ', ' . $p['bytes'] . ' bytes, '
                . round($p['bytes'] / 1048576, 1) . ' MiB decoded) is larger than this installation\'s limit of '
                . $max . ' bytes (' . round($max / 1048576, 1) . ' MiB). Upload it with POST /knowledge/articles/{id}/images '
                . 'and use its URL in the article instead, or ask an administrator to raise the limit in Knowledge > Settings > Images. '
                . 'GET /knowledge/images/constraints returns the current limit.');
            $e->httpStatus = 413;
            $e->details = ['picture' => $n, 'type' => $p['type'], 'bytes' => $p['bytes'],
                           'max_bytes' => $max, 'max_base64_length' => self::maxBase64Length($max)];
            throw $e;
        }
    }

    /**
     * The pictures embedded in HTML as data: URLs, in order: [type, bytes decoded, hash].
     * The size is worked out from the base64 length, so a large picture is never decoded.
     */
    public static function embeddedPictures(string $html): array
    {
        if ($html === '' || stripos($html, 'data:') === false) return [];
        $out = [];
        preg_match_all('~<img\b[^>]*?\bsrc\s*=\s*(["\']?)\s*data:([^;,"\'\s>]*)((?:;[^,"\'\s>]*)*),([^"\'\s>]*)~i', $html, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $payload = $x[4];
            if (stripos($x[3], ';base64') !== false) {
                $clean = rtrim(preg_replace('~[^A-Za-z0-9+/=_-]~', '', $payload), '=');
                $bytes = intdiv(strlen($clean) * 3, 4);
            } else {
                $bytes = strlen(rawurldecode($payload));
            }
            $out[] = ['type' => strtolower($x[2] ?: 'text/plain'), 'bytes' => $bytes, 'hash' => sha1($payload)];
        }
        return $out;
    }

    public static function tooLargeMessage(int $maxBytes): string
    {
        return 'The image is larger than this installation\'s limit of ' . $maxBytes . ' bytes ('
            . round($maxBytes / 1048576, 1) . ' MiB) decoded. GET /knowledge/images/constraints returns the current limit; '
            . 'an administrator can change it in Knowledge > Settings > Images.';
    }

    public static function directory(): string
    {
        // Already persisted by the Docker uploads volume; never a public image directory.
        return dirname(__DIR__, 2) . '/uploads/knowledge';
    }

    public static function create(PDO $conn, ActorContext $ctx, int $articleId, array $in, ?array $upload = null): array
    {
        $article = KnowledgeTransferService::object($conn, $ctx, 'article', $articleId, true);
        if ((int)$article['is_archived']) throw new ServiceError('conflict', 'archived', 'Restore the article before uploading images.');
        if ($upload !== null) return self::createFromUpload($conn, $ctx, $articleId, $in, $upload);
        // GH #417: the ceiling is the administrator's choice (default 10 MiB), and a
        // too-large image gets its own stable code that states the limit.
        $max = self::maxBytes($conn);
        $encoded = $in['content_base64'] ?? null;
        if (!is_string($encoded)) {
            throw new ServiceError('validation', 'invalid_image', 'Supply the image as a base64 string in content_base64.');
        }
        if (strlen($encoded) > self::maxBase64Length($max)) {
            throw new ServiceError('validation', 'image_too_large', self::tooLargeMessage($max));
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '') {
            throw new ServiceError('validation', 'invalid_image', 'The image is empty or not valid base64.');
        }
        if (strlen($bytes) > $max) {
            throw new ServiceError('validation', 'image_too_large', self::tooLargeMessage($max));
        }
        if (!is_string($in['filename'] ?? null)) throw new ServiceError('validation', 'invalid_image', 'filename is required.');
        $stored = null;
        try {
            return KnowledgeTransferService::createOnce($conn, $ctx, 'image', $articleId, $in,
                static function () use ($conn, $ctx, $articleId, $in, $bytes, $max, &$stored) {
                    // Lock the parent, serializing image changes with article finalization.
                    $article = KnowledgeTransferService::object($conn, $ctx, 'article', $articleId, true);
                    if ((int)$article['is_archived']) throw new ServiceError('conflict', 'archived', 'Restore the article before uploading images.');
                    $stored = uploadStoreBytes($bytes, $in['filename'], self::directory(), ATTACHMENT_POLICY_DROP, UPLOAD_TYPES_IMAGE, $max);
                    if (!$stored['stored'] || $stored['quarantined']) {
                        throw new ServiceError('validation', 'invalid_image', 'Image refused: ' . $stored['reason']);
                    }
                    $conn->prepare('INSERT INTO knowledge_images (article_id, stored_name, original_name, mime_type, size_bytes, sha256, created_by_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$articleId, $stored['stored_name'], $stored['original_name'], $stored['mime'], $stored['size'], hash('sha256', $bytes), $ctx->actorId]);
                    $id = (int)$conn->lastInsertId();
                    knowledgeAuditLog($conn, 'article', $articleId, 'edit', $ctx->actorId, ['image_added' => $id]);
                    return ['id' => $id];
                });
        } catch (Throwable $e) {
            if ($stored && $stored['path'] && is_file($stored['path'])) unlink($stored['path']);
            throw $e;
        }
    }

    /**
     * GH #416 / #417: a picture from a REST upload (upload_id) instead of base64 - any size
     * up to the limit, without the base64 request's memory and body limits.
     *
     * Checked now, as the base64 route checks: the limit in force now, an image extension,
     * contents that really are that image (never SVG). The request itself is the
     * client_reference identity, as for base64 - so a retry sends the same upload_id; a used
     * upload answers only the retry of its own hand-over (same article, same reference).
     *
     * @param array $in      upload_id, filename (default: the upload's), client_reference
     * @param array $upload  a row from ApiUploadService::forHandOver() (purpose knowledge_image)
     */
    private static function createFromUpload(PDO $conn, ActorContext $ctx, int $articleId, array $in, array $upload): array
    {
        require_once __DIR__ . '/api_uploads.php';
        $reference = $in['client_reference'] ?? null;
        if ($upload['used_datetime'] !== null && !ApiUploadService::isRetryOf($upload, $articleId, $reference)) {
            throw ApiUploadService::usedError();
        }
        $max = self::maxBytes($conn);
        if ($upload['used_datetime'] === null && (int)$upload['size_bytes'] > $max) {
            throw new ServiceError('validation', 'image_too_large', self::tooLargeMessage($max));
        }
        if (array_key_exists('filename', $in) && !is_string($in['filename'])) throw new ServiceError('validation', 'invalid_image', 'filename must be a string.');
        $filename = (string)($in['filename'] ?? $upload['filename']);
        $stored = null;
        try {
            $result = KnowledgeTransferService::createOnce($conn, $ctx, 'image', $articleId, $in,
                static function () use ($conn, $ctx, $articleId, $upload, $filename, $max, $reference, &$stored) {
                    // Reached only when the reference names nothing yet: a used upload has nothing to add.
                    if ($upload['used_datetime'] !== null) throw ApiUploadService::usedError();
                    // Lock the parent, serializing image changes with article finalization.
                    $article = KnowledgeTransferService::object($conn, $ctx, 'article', $articleId, true);
                    if ((int)$article['is_archived']) throw new ServiceError('conflict', 'archived', 'Restore the article before uploading images.');
                    try {
                        $stored = uploadStoreLocalFile(ApiUploadService::path($upload), $filename, self::directory(), UPLOAD_TYPES_IMAGE, $max);
                    } catch (Exception $e) {
                        throw new ServiceError('validation', 'invalid_image', 'Image refused: ' . $e->getMessage());
                    }
                    $conn->prepare('INSERT INTO knowledge_images (article_id, stored_name, original_name, mime_type, size_bytes, sha256, created_by_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([$articleId, $stored['stored_name'], $stored['original_name'], $stored['mime'], $stored['size'], hash_file('sha256', $stored['path']), $ctx->actorId]);
                    $id = (int)$conn->lastInsertId();
                    ApiUploadService::markUsed($conn, $upload, $articleId, $id, $reference);
                    knowledgeAuditLog($conn, 'article', $articleId, 'edit', $ctx->actorId, ['image_added' => $id]);
                    return ['id' => $id];
                });
        } catch (Throwable $e) {
            if ($stored && $stored['path'] && is_file($stored['path'])) unlink($stored['path']);
            throw $e;
        }
        if (!$result['replayed']) ApiUploadService::afterUse($upload);
        return $result;
    }

    public static function load(PDO $conn, KnowledgeViewer $viewer, int $id, string $lifecycle = 'live'): array
    {
        $st = $conn->prepare('SELECT * FROM knowledge_images WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !knowledgeCanRead($conn, $viewer, (int)$row['article_id'], ['lifecycle' => $lifecycle])) {
            throw new ServiceError('not_found', 'not_found', 'Image not found.');
        }
        return $row;
    }

    public static function describe(array $row): array
    {
        return ['id' => (int)$row['id'], 'article_id' => (int)$row['article_id'],
            'filename' => $row['original_name'], 'mime_type' => $row['mime_type'], 'size_bytes' => (int)$row['size_bytes'],
            'sha256' => $row['sha256'],
            // BASE_URL makes this reference work when FreeITSM lives in a subfolder.
            'url' => BASE_URL . 'api/knowledge/image.php?id=' . (int)$row['id']];
    }

    public static function path(array $row): string
    {
        if (!preg_match('/^[0-9a-f]{32}\.(png|jpe?g|gif|webp|bmp|heic|heif)$/D', $row['stored_name'])) {
            throw new ServiceError('not_found', 'not_found', 'Image not found.');
        }
        $path = self::directory() . '/' . $row['stored_name'];
        if (!is_file($path)) throw new ServiceError('not_found', 'not_found', 'Image not found.');
        return $path;
    }

    /** No conditional caching: revoking article access must also revoke its images. */
    public static function serve(array $row): void
    {
        $path = self::path($row);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset(UPLOAD_TYPES_IMAGE[$ext])) throw new ServiceError('not_found', 'not_found', 'Image not found.');
        $stream = fopen($path, 'rb');
        if ($stream === false) throw new ServiceError('not_found', 'not_found', 'Image not found.');
        header('Content-Type: ' . UPLOAD_TYPES_IMAGE[$ext][0]);
        header('Content-Length: ' . fstat($stream)['size']);
        header('Content-Disposition: inline');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Security-Policy: default-src \'none\'; sandbox');
        fpassthru($stream);
        fclose($stream);
    }

    /** Delete only after authorization; stale retries cannot recreate a deleted image. */
    public static function delete(PDO $conn, ActorContext $ctx, int $articleId, int $id): bool
    {
        $row = KnowledgeTransferService::transaction($conn, static function () use ($conn, $ctx, $articleId, $id) {
            KnowledgeTransferService::object($conn, $ctx, 'article', $articleId, true);
            $st = $conn->prepare('SELECT * FROM knowledge_images WHERE id = ? AND article_id = ? FOR UPDATE');
            $st->execute([$id, $articleId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new ServiceError('not_found', 'not_found', 'Image not found.');
            $conn->prepare('DELETE FROM knowledge_images WHERE id = ?')->execute([$id]);
            knowledgeAuditLog($conn, 'article', $articleId, 'edit', $ctx->actorId, ['image_deleted' => $id]);
            return $row;
        });
        // A failed unlink leaves inaccessible bytes, never a readable stale URL.
        try { $path = self::path($row); }
        catch (ServiceError $e) { return true; }
        return !is_file($path) || unlink($path);
    }

    /** Called by every permanent article deletion path, including bin retention. */
    public static function purgeArticles(PDO $conn, array $articleIds): void
    {
        if (!$articleIds) return;
        $marks = implode(',', array_fill(0, count($articleIds), '?'));
        $st = $conn->prepare("SELECT * FROM knowledge_images WHERE article_id IN ($marks)");
        $st->execute($articleIds);
        $images = $st->fetchAll(PDO::FETCH_ASSOC);
        $conn->prepare("DELETE FROM knowledge_images WHERE article_id IN ($marks)")->execute($articleIds);
        foreach ($images as $image) {
            try { $path = self::path($image); }
            catch (ServiceError $e) { continue; } // Already missing bytes need no cleanup.
            if (!unlink($path)) error_log('[KnowledgeImageService] Failed to remove purged image ' . (int)$image['id']);
        }
    }
}
