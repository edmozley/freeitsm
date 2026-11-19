<?php
/** Article-owned raster images. All reads go through the article's visibility rule. */
require_once __DIR__ . '/knowledge_transfer.php';
require_once __DIR__ . '/../uploads.php';

class KnowledgeImageService
{
    public const MAX_BYTES = 10485760;

    public static function directory(): string
    {
        // Already persisted by the Docker uploads volume; never a public image directory.
        return dirname(__DIR__, 2) . '/uploads/knowledge';
    }

    public static function create(PDO $conn, ActorContext $ctx, int $articleId, array $in): array
    {
        $article = KnowledgeTransferService::object($conn, $ctx, 'article', $articleId, true);
        if ((int)$article['is_archived']) throw new ServiceError('conflict', 'archived', 'Restore the article before uploading images.');
        $encoded = $in['content_base64'] ?? null;
        if (!is_string($encoded) || strlen($encoded) > 4 * (int)ceil(self::MAX_BYTES / 3)) {
            throw new ServiceError('validation', 'invalid_image', 'Supply a base64 image of at most 10 MiB.');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new ServiceError('validation', 'invalid_image', 'The image is empty, too large or not valid base64.');
        }
        if (!is_string($in['filename'] ?? null)) throw new ServiceError('validation', 'invalid_image', 'filename is required.');
        $stored = null;
        try {
            return KnowledgeTransferService::createOnce($conn, $ctx, 'image', $articleId, $in,
                static function () use ($conn, $ctx, $articleId, $in, $bytes, &$stored) {
                    // Lock the parent, serializing image changes with article finalization.
                    $article = KnowledgeTransferService::object($conn, $ctx, 'article', $articleId, true);
                    if ((int)$article['is_archived']) throw new ServiceError('conflict', 'archived', 'Restore the article before uploading images.');
                    $stored = uploadStoreBytes($bytes, $in['filename'], self::directory(), ATTACHMENT_POLICY_DROP, UPLOAD_TYPES_IMAGE, self::MAX_BYTES);
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
