<?php
/**
 * ApiUploadService - a file sent to the REST API in pieces (GH #416, #417).
 *
 * Files used to reach the API in one request - a picture as base64 inside JSON, a
 * document as a multipart form - and so met limits the integration can neither see
 * nor change: PHP's upload_max_filesize and post_max_size, its memory, and any proxy's
 * body limit. Here a file goes up in pieces that fit under all of them, a dropped
 * connection resumes instead of starting again, and the server holds one piece at a
 * time. The Files module has worked this way for years (api/files/upload.php).
 *
 *   start  -> pieces (in order) -> complete -> handed to the record that wants it
 *
 * 🔑 A FINISHED UPLOAD IS NOT A DOCUMENT OR A PICTURE YET - it is bytes waiting. The
 * record that wants it takes it by id (POST /knowledge/articles/{id}/documents or
 * /images with upload_id), and only then is everything checked that the one-request
 * route checks: the record and the key's right to change it, the limit in force NOW,
 * the content's real type. The file is then copied into its store and the upload is
 * USED: it cannot be handed to a second record. For 24 hours it remembers what it
 * became, so a retry of the hand-over gets the same answer.
 *
 * 🔑 AN UPLOAD BELONGS TO THE KEY THAT STARTED IT. Every other key - even one acting
 * as the same analyst - gets 404. upload_id is 32 random hex characters, never a
 * counter, and the pieces' name on disk is a second random name.
 *
 * 🔑 NO FILLING THE DISK. At most MAX_UNFINISHED uploads per key that have not been
 * handed over, finished or not (a completed upload waiting for its hand-over counts:
 * the user's clarification on #416). Each is no bigger than its purpose allows, and
 * expires EXPIRY_HOURS after its last piece. Expired ones are deleted on every start
 * and by the nightly API tidy-up (cron/api_log_tidy.php).
 *
 * Pieces live in uploads/api-incoming (FILE_STORES 'api-incoming'), denied by every
 * web server; tests/web-exposure-guard.php and D009 check it.
 *
 * @wiki API-Uploads-and-Knowledge-Documents-Design
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../uploads.php';
require_once __DIR__ . '/../file_stores.php';

class ApiUploadService
{
    /** The largest piece: 4 MiB, or less where PHP's post_max_size is smaller. */
    public const CHUNK_BYTES = 4194304;

    /** Uploads one key may have that are not handed over yet, finished or not. */
    public const MAX_UNFINISHED = 10;

    /** An upload expires this long after its last piece - or, once used, after its hand-over. */
    public const EXPIRY_HOURS = 24;

    /** What a file can be for. Each record that takes uploads adds its purpose here. */
    public const PURPOSES = ['knowledge_image', 'knowledge_document'];

    private const STORE = 'api-incoming';

    /**
     * A purpose's limit and types, as they are NOW (an administrator may change both).
     *
     * @param string $purpose  one of PURPOSES - untrusted
     * @return array{max_bytes:int, types:array}
     * @throws ServiceError validation (invalid_purpose)
     */
    public static function purposeLimits(PDO $conn, string $purpose): array
    {
        if ($purpose === 'knowledge_image') {
            require_once __DIR__ . '/knowledge_images.php';
            return ['max_bytes' => KnowledgeImageService::maxBytes($conn), 'types' => UPLOAD_TYPES_IMAGE];
        }
        if ($purpose === 'knowledge_document') {
            return ['max_bytes' => attachmentMaxBytes($conn), 'types' => attachmentAllowedTypes($conn)];
        }
        throw new ServiceError('validation', 'invalid_purpose', 'purpose must be one of: ' . implode(', ', self::PURPOSES) . '.');
    }

    /** The largest piece this server takes: CHUNK_BYTES, or PHP's post_max_size where that is smaller. */
    public static function chunkBytes(): int
    {
        require_once __DIR__ . '/knowledge_images.php';
        $post = KnowledgeImageService::iniBytes((string)ini_get('post_max_size'));
        return $post === null ? self::CHUNK_BYTES : max(1, min(self::CHUNK_BYTES, $post));
    }

    /** GET /uploads/limits: every purpose's size and types, so a client never hard-codes them. */
    public static function limits(PDO $conn): array
    {
        $purposes = [];
        foreach (self::PURPOSES as $p) {
            $l = self::purposeLimits($conn, $p);
            $formats = [];
            foreach ($l['types'] as $ext => $mimes) $formats[] = ['extension' => (string)$ext, 'mime_types' => array_values($mimes)];
            $purposes[$p] = ['max_bytes' => $l['max_bytes'], 'formats' => $formats];
        }
        return ['chunk_bytes' => self::chunkBytes(), 'max_unfinished' => self::MAX_UNFINISHED,
                'expires_after_hours' => self::EXPIRY_HOURS, 'purposes' => $purposes];
    }

    /**
     * Start an upload. The purpose's size and extension are checked now, before a byte
     * is sent; the content's type is checked again at the hand-over.
     *
     * TRAP: the caller has checked the key's permission for this purpose (the API layer
     * owns permissions); this checks everything else.
     *
     * @param array $in  purpose, filename, size, sha256 (optional) - untrusted
     * @return array the upload row
     * @throws ServiceError validation, conflict (too_many_uploads)
     */
    public static function start(PDO $conn, int $apiKeyId, array $in): array
    {
        $purpose = is_string($in['purpose'] ?? null) ? $in['purpose'] : '';
        $limits  = self::purposeLimits($conn, $purpose);
        if (!is_string($in['filename'] ?? null) || trim($in['filename']) === '' || mb_strlen($in['filename']) > 255) {
            throw new ServiceError('validation', 'invalid_field', 'filename is required: 1 to 255 characters, with the file\'s extension.');
        }
        $filename = uploadCleanName($in['filename']);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' || !isset($limits['types'][$ext])) {
            throw new ServiceError('validation', 'invalid_type', 'That file type is not accepted for ' . $purpose . '. GET /uploads/limits lists the accepted types.');
        }
        $size = $in['size'] ?? null;
        if (!is_int($size) || $size < 1) throw new ServiceError('validation', 'invalid_field', 'size must be the file\'s size in bytes, at least 1.');
        if ($size > $limits['max_bytes']) {
            $e = new ServiceError('validation', 'too_large', 'The file is larger than this installation\'s limit for ' . $purpose . ' of '
                . $limits['max_bytes'] . ' bytes (' . uploadFormatBytes($limits['max_bytes']) . '). GET /uploads/limits returns the limits in force.');
            $e->httpStatus = 413;
            $e->details = ['max_bytes' => $limits['max_bytes']];
            throw $e;
        }
        $sha = $in['sha256'] ?? null;
        if ($sha !== null && (!is_string($sha) || !preg_match('/\A[0-9a-fA-F]{64}\z/', $sha))) {
            throw new ServiceError('validation', 'invalid_field', 'sha256 must be 64 hexadecimal characters.');
        }

        self::expire($conn);   // housekeeping as the next one starts - no scheduled job needed

        $dir = fileStoreDir(self::STORE);
        try { uploadPrepareDir($dir); }
        catch (Exception $e) { throw new ServiceError('conflict', 'storage_unavailable', 'The server could not prepare the upload folder.'); }
        $uploadId = 'u_' . bin2hex(random_bytes(16));
        $stored   = bin2hex(random_bytes(16)) . '.part';

        $conn->beginTransaction();
        try {
            // Serialize starts per key, so two at once cannot both slip under the limit.
            $conn->prepare('SELECT id FROM api_keys WHERE id = ? FOR UPDATE')->execute([$apiKeyId]);
            $st = $conn->prepare('SELECT COUNT(*) FROM api_uploads WHERE api_key_id = ? AND used_datetime IS NULL AND expires_datetime >= UTC_TIMESTAMP()');
            $st->execute([$apiKeyId]);
            if ((int)$st->fetchColumn() >= self::MAX_UNFINISHED) {
                throw new ServiceError('conflict', 'too_many_uploads', 'This key already has ' . self::MAX_UNFINISHED
                    . ' uploads that are not handed over yet. Hand one over, DELETE one, or let one expire, then start again.');
            }
            if (@file_put_contents($dir . '/' . $stored, '') === false) {
                throw new ServiceError('conflict', 'storage_unavailable', 'The server could not start writing the upload.');
            }
            $conn->prepare('INSERT INTO api_uploads (upload_id, api_key_id, purpose, filename, size_bytes, expected_sha256, stored_name, expires_datetime)
                            VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP() + INTERVAL ' . self::EXPIRY_HOURS . ' HOUR)')
                 ->execute([$uploadId, $apiKeyId, $purpose, $filename, $size, $sha === null ? null : strtolower($sha), $stored]);
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            if (is_file($dir . '/' . $stored)) @unlink($dir . '/' . $stored);
            throw $e;
        }
        return self::load($conn, $apiKeyId, $uploadId);
    }

    /**
     * One of this key's uploads. 404 for every other key, an unknown id and an expired upload alike.
     *
     * @param string $uploadId  from the URL or the request - untrusted
     */
    public static function load(PDO $conn, int $apiKeyId, string $uploadId, bool $forUpdate = false): array
    {
        if (!preg_match('/\Au_[0-9a-f]{32}\z/', $uploadId)) self::notFound();
        $st = $conn->prepare('SELECT * FROM api_uploads WHERE upload_id = ? AND api_key_id = ? AND expires_datetime >= UTC_TIMESTAMP()'
            . ($forUpdate ? ' FOR UPDATE' : ''));
        $st->execute([$uploadId, $apiKeyId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) self::notFound();
        return $row;
    }

    /**
     * Add the next piece, streamed from $stream to the file - never held whole.
     *
     * Pieces arrive in order: $offset must equal what has arrived, or it is 409
     * offset_mismatch with `received` in the details, so a client that lost track
     * carries on from there. A piece is never written twice. The last piece completes
     * the upload; with a sha256 given at the start, the file must match it.
     *
     * @param resource $stream         php://input
     * @param ?int     $contentLength  the request's Content-Length, when it sent one
     * @return array the upload row after the piece
     * @throws ServiceError not_found, conflict (offset_mismatch, upload_complete), validation (piece_too_large, size_exceeded, empty_piece, sha256_mismatch)
     */
    public static function append(PDO $conn, int $apiKeyId, string $uploadId, int $offset, $stream, ?int $contentLength): array
    {
        $chunk = self::chunkBytes();
        if ($contentLength !== null && $contentLength > $chunk) throw self::pieceTooLarge($chunk);

        $mismatch = null;
        $conn->beginTransaction();
        try {
            // Locked for the whole piece: a second request for the same upload waits, then sees the new offset.
            // A wait longer than innodb_lock_wait_timeout (MySQL error 1205) is a busy
            // upload, not a server fault - a 409 the client can act on, never a 500
            // (added when PR #447 was merged).
            try {
                $row = self::load($conn, $apiKeyId, $uploadId, true);
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1205) throw $e;
                throw new ServiceError('conflict', 'upload_busy', 'Another piece of this upload is being written. GET /uploads/{upload_id}, then send from its received.');
            }
            $received = (int)$row['received_bytes'];
            $size = (int)$row['size_bytes'];
            if ($row['used_datetime'] !== null || $received >= $size) {
                throw new ServiceError('conflict', 'upload_complete', 'This upload is complete. Hand it over with its upload_id.');
            }
            if ($offset !== $received) {
                $e = new ServiceError('conflict', 'offset_mismatch', 'The piece must start at offset ' . $received . ', what has arrived so far. Send from there.');
                $e->details = ['received' => $received, 'size' => $size];
                throw $e;
            }
            $room = $size - $received;
            if ($contentLength !== null && $contentLength > $room) throw self::sizeExceeded($size);

            $path = self::path($row);
            $fh = @fopen($path, 'c+b');
            if (!$fh) throw new ServiceError('conflict', 'storage_unavailable', 'The server could not write the upload.');
            try {
                // What a failed earlier piece left beyond `received` is not part of the file.
                ftruncate($fh, $received);
                fseek($fh, $received);
                $limit = min($chunk, $room);
                $written = stream_copy_to_stream($stream, $fh, $limit + 1);
                if ($written === false || $written === 0 || $written > $limit) {
                    ftruncate($fh, $received);
                    if ($written === false) throw new ServiceError('conflict', 'piece_failed', 'The piece could not be read. Send it again from offset ' . $received . '.');
                    if ($written === 0) throw new ServiceError('validation', 'empty_piece', 'The piece was empty. Send the file\'s bytes as the request body.');
                    throw $limit === $chunk ? self::pieceTooLarge($chunk) : self::sizeExceeded($size);
                }
                fflush($fh);
            } finally {
                fclose($fh);
            }
            $received += $written;

            if ($received < $size) {
                $conn->prepare('UPDATE api_uploads SET received_bytes = ?, expires_datetime = UTC_TIMESTAMP() + INTERVAL ' . self::EXPIRY_HOURS . ' HOUR WHERE id = ?')
                     ->execute([$received, $row['id']]);
            } else {
                $sha = hash_file('sha256', $path);   // streamed
                if ($row['expected_sha256'] !== null && !hash_equals((string)$row['expected_sha256'], (string)$sha)) {
                    // Nothing can be resumed from wrong bytes: the upload goes.
                    $conn->prepare('DELETE FROM api_uploads WHERE id = ?')->execute([$row['id']]);
                    $mismatch = $path;
                } else {
                    $conn->prepare('UPDATE api_uploads SET received_bytes = ?, sha256 = ?, completed_datetime = UTC_TIMESTAMP(),
                                           expires_datetime = UTC_TIMESTAMP() + INTERVAL ' . self::EXPIRY_HOURS . ' HOUR WHERE id = ?')
                         ->execute([$received, $sha, $row['id']]);
                }
            }
            $conn->commit();
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        if ($mismatch !== null) {
            @unlink($mismatch);
            throw new ServiceError('validation', 'sha256_mismatch', 'The assembled file does not match the sha256 given at the start, so the upload was discarded. Start it again.');
        }
        return self::load($conn, $apiKeyId, $uploadId);
    }

    /** Throw the upload away - its pieces, or what is kept of it after a hand-over. */
    public static function discard(PDO $conn, int $apiKeyId, string $uploadId): void
    {
        $row = self::load($conn, $apiKeyId, $uploadId);
        $conn->prepare('DELETE FROM api_uploads WHERE id = ?')->execute([$row['id']]);
        self::removeFile($row);
    }

    /**
     * An upload ready to be handed to a record for $purpose: complete, or already used
     * (the caller then decides whether this is a retry of that hand-over).
     *
     * @throws ServiceError not_found, validation (upload_purpose), conflict (upload_incomplete)
     */
    public static function forHandOver(PDO $conn, int $apiKeyId, string $uploadId, string $purpose): array
    {
        $row = self::load($conn, $apiKeyId, $uploadId);
        if ($row['purpose'] !== $purpose) {
            throw new ServiceError('validation', 'upload_purpose', 'This upload was started for ' . $row['purpose'] . ', not ' . $purpose . '. Start a new upload with the right purpose.');
        }
        if ($row['used_datetime'] === null && (int)$row['received_bytes'] < (int)$row['size_bytes']) {
            $e = new ServiceError('conflict', 'upload_incomplete', 'This upload has ' . (int)$row['received_bytes'] . ' of ' . (int)$row['size_bytes'] . ' bytes. Send the rest first.');
            $e->details = ['received' => (int)$row['received_bytes'], 'size' => (int)$row['size_bytes']];
            throw $e;
        }
        return $row;
    }

    /**
     * Is this request the retry of a used upload's own hand-over - the same record, the
     * same client_reference? Anything else that names a used upload is 409 upload_used:
     * an upload becomes one thing, once, and a retry stays bound to its original record.
     *
     * @param ?string $reference  the request's client_reference
     */
    public static function isRetryOf(array $row, int $parentId, $reference): bool
    {
        return $row['used_datetime'] !== null && (int)$row['used_parent_id'] === $parentId
            && $row['used_client_reference'] !== null && is_string($reference) && hash_equals((string)$row['used_client_reference'], $reference);
    }

    /** The answer for a used upload that is not a retry of its own hand-over. */
    public static function usedError(): ServiceError
    {
        return new ServiceError('conflict', 'upload_used', 'This upload has already been handed over. A retry needs the same record and the same client_reference; anything else needs a new upload.');
    }

    /**
     * Record the hand-over, in the caller's transaction, so the record and the receipt
     * are written together. Refuses if another request used it first.
     *
     * @param int     $parentId   the record it went to (the article)
     * @param int     $objectId   what it became (the document or picture)
     * @param ?string $reference  the hand-over's client_reference, for a retry to match
     */
    public static function markUsed(PDO $conn, array $row, int $parentId, int $objectId, $reference): void
    {
        $st = $conn->prepare('UPDATE api_uploads SET used_datetime = UTC_TIMESTAMP(), used_parent_id = ?, used_object_id = ?, used_client_reference = ?,
                                     expires_datetime = UTC_TIMESTAMP() + INTERVAL ' . self::EXPIRY_HOURS . ' HOUR
                               WHERE id = ? AND used_datetime IS NULL');
        $st->execute([$parentId, $objectId, is_string($reference) ? $reference : null, $row['id']]);
        if ($st->rowCount() !== 1) throw self::usedError();
    }

    /** After the hand-over committed: the pieces are not needed; the row stays as the receipt. */
    public static function afterUse(array $row): void
    {
        self::removeFile($row);
    }

    /** Absolute path of an upload's pieces. */
    public static function path(array $row): string
    {
        $name = (string)$row['stored_name'];
        if (!preg_match('/\A[0-9a-f]{32}\.part\z/', $name)) self::notFound();
        return fileStorePath(self::STORE, $name);
    }

    /**
     * Delete expired uploads, and pieces no upload names any more (a crash between
     * file and row, or a key deleted with its uploads).
     *
     * @param int $limit  most uploads to delete in one run
     * @return int uploads deleted
     */
    public static function expire(PDO $conn, int $limit = 200): int
    {
        try {
            $st = $conn->query('SELECT id, stored_name FROM api_uploads WHERE expires_datetime < UTC_TIMESTAMP() LIMIT ' . max(1, $limit));
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[ApiUploadService::expire] ' . $e->getMessage());   // before Database Verification
            return 0;
        }
        foreach ($rows as $row) {
            $conn->prepare('DELETE FROM api_uploads WHERE id = ?')->execute([$row['id']]);
            self::removeFile($row);
        }
        $dir = fileStoreDir(self::STORE);
        $files = glob($dir . '/*.part') ?: [];
        if ($files) {
            $known = array_flip($conn->query('SELECT stored_name FROM api_uploads')->fetchAll(PDO::FETCH_COLUMN));
            $cutoff = time() - 2 * self::EXPIRY_HOURS * 3600;
            foreach ($files as $f) {
                if (!isset($known[basename($f)]) && @filemtime($f) < $cutoff) @unlink($f);
            }
        }
        return count($rows);
    }

    /** One upload as the API shows it. */
    public static function describe(array $row): array
    {
        $complete = (int)$row['received_bytes'] >= (int)$row['size_bytes'];
        return [
            'upload_id'  => $row['upload_id'],
            'purpose'    => $row['purpose'],
            'filename'   => $row['filename'],
            'size'       => (int)$row['size_bytes'],
            'received'   => (int)$row['received_bytes'],
            'complete'   => $complete,
            'sha256'     => $row['sha256'],
            'chunk_bytes'=> self::chunkBytes(),
            'expires_at' => str_replace(' ', 'T', (string)$row['expires_datetime']) . 'Z',
            'used'       => $row['used_datetime'] === null ? null : [
                'parent_id' => (int)$row['used_parent_id'], 'object_id' => (int)$row['used_object_id'],
                'at' => str_replace(' ', 'T', (string)$row['used_datetime']) . 'Z',
            ],
        ];
    }

    private static function removeFile(array $row): void
    {
        try { $path = self::path($row); } catch (ServiceError $e) { return; }
        if (is_file($path)) @unlink($path);
    }

    private static function notFound(): never
    {
        throw new ServiceError('not_found', 'not_found', 'Upload not found. It may belong to another key or have expired (' . self::EXPIRY_HOURS . ' hours after its last piece).');
    }

    private static function pieceTooLarge(int $chunk): ServiceError
    {
        $e = new ServiceError('validation', 'piece_too_large', 'A piece can be at most ' . $chunk . ' bytes on this server (chunk_bytes). Send smaller pieces.');
        $e->httpStatus = 413;
        $e->details = ['chunk_bytes' => $chunk];
        return $e;
    }

    private static function sizeExceeded(int $size): ServiceError
    {
        return new ServiceError('validation', 'size_exceeded', 'That piece goes past the size given at the start (' . $size . ' bytes). Start a new upload for a different file.');
    }
}
