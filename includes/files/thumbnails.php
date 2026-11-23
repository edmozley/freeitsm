<?php
/**
 * Files thumbnails (GH #424) - one small picture per stored fingerprint.
 *
 * WHAT A THUMBNAIL IS
 * -------------------
 * A WebP (JPEG where this PHP's GD has no WebP), at most MAX_SIDE pixels on its
 * longest side, typically 5-15 KB, in files_thumbnails.data. Keyed by the version's
 * SHA-256, so every copy of the same file shares one, a new version gets a new one,
 * and restoring an old version finds its old one again. Made ONCE:
 *
 *   - a picture: by the server with GD - at upload (api/files/upload.php, from the
 *     file still on local disk), or the first time it is asked for;
 *   - a PDF's first page: by a BROWSER (pdf.js), which sends the small picture -
 *     the uploader's at upload, or later someone with Upload on the folder. No PHP
 *     install can draw a PDF on its own.
 *
 * A file that cannot have one (too many pixels to decode safely, a format GD cannot
 * read, a damaged file) gets a row with that status and is set aside, so it is tried
 * once rather than on every folder open - and the backlog can reach zero.
 *
 * TRAP: a thumbnail a browser sends is NEVER stored as sent. acceptBrowser() decodes
 *   it with GD and encodes a fresh picture from the pixels, so nothing hidden in the
 *   file survives - and without GD it is refused. Callers check Upload on the folder
 *   first: somebody with View only must not be able to plant a misleading picture.
 *
 * TRAP: a watermarked folder shows no thumbnails (api/files/thumbnail.php) - a clean
 *   picture of the page would get round the watermark.
 *
 * @wiki Files-Thumbnails-And-Gallery-Design
 */
final class FilesThumbs
{
    public const MAX_SIDE = 256;
    /** Above this a picture is not decoded: a 20,000 x 20,000 PNG needs 1.6 GB as a bitmap. */
    public const MAX_PIXELS = 40000000;
    /** What a browser may send for a PDF's first page, before it is re-encoded. */
    public const BROWSER_MAX_BYTES = 150000;
    public const BROWSER_MAX_SIDE = 512;
    /** The largest PDF a browser is handed to draw: it is read whole into the browser's memory. */
    public const PDF_SOURCE_MAX_BYTES = 52428800;

    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const PDF_EXT = ['pdf'];

    /** Can this PHP make and re-encode pictures at all (the GD extension)? */
    public static function gd(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    /** Files -> Settings -> Thumbnails switch; on unless an administrator turned it off. */
    public static function enabled(PDO $conn): bool
    {
        return filesSetting($conn, 'thumbnails_enabled', '1') !== '0';
    }

    /** 'image', 'pdf' or null - which kind of thumbnail a file name can have. */
    public static function kind(string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, self::IMAGE_EXT, true)) return 'image';
        if (in_array($ext, self::PDF_EXT, true)) return 'pdf';
        return null;
    }

    /** Is the files_thumbnails table there (Database Verification run since the update)? */
    public static function ready(PDO $conn): bool
    {
        static $ok = null;
        if ($ok === null) {
            try { $conn->query('SELECT 1 FROM files_thumbnails LIMIT 0'); $ok = true; }
            catch (Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    /**
     * The status of each fingerprint that has a row: [sha256 => status].
     *
     * @param string[] $shas
     */
    public static function statuses(PDO $conn, array $shas): array
    {
        $shas = array_values(array_unique(array_filter($shas)));
        if (!$shas || !self::ready($conn)) return [];
        $out = [];
        foreach (array_chunk($shas, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $st = $conn->prepare("SELECT sha256, status FROM files_thumbnails WHERE sha256 IN ($in)");
            $st->execute($chunk);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['sha256']] = $r['status'];
        }
        return $out;
    }

    /**
     * What the folder listing tells the browser about one file's thumbnail:
     * 'ready' (fetch it), 'make' (a picture the server will make when asked),
     * 'draw' (a PDF with none - a browser may draw it), or null (none, show the icon).
     */
    public static function stateFor(?string $status, ?string $kind, bool $gd): ?string
    {
        if ($status === 'ready') return 'ready';
        if ($status !== null || $kind === null || !$gd) return null;   // set aside, or cannot be made here
        return $kind === 'image' ? 'make' : 'draw';
    }

    /**
     * Shrinks a picture file to a thumbnail. Returns ['status' => ready|too_large|unsupported|failed,
     * 'data', 'mime', 'width', 'height', 'detail'].
     */
    public static function fromImageFile(string $path): array
    {
        if (!self::gd()) return ['status' => 'unsupported', 'detail' => 'GD is not installed'];
        $info = @getimagesize($path);
        if (!$info || empty($info[0]) || empty($info[1])) return ['status' => 'failed', 'detail' => 'Not a picture this server can read'];
        [$w, $h, $type] = $info;
        if ($w * $h > self::MAX_PIXELS) return ['status' => 'too_large', 'detail' => "{$w} x {$h} pixels"];
        $img = null;
        switch ($type) {
            case IMAGETYPE_JPEG: $img = @imagecreatefromjpeg($path); break;
            case IMAGETYPE_PNG:  $img = @imagecreatefrompng($path); break;
            case IMAGETYPE_GIF:  $img = @imagecreatefromgif($path); break;
            case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null; break;
            default: return ['status' => 'unsupported', 'detail' => 'Not a JPEG, PNG, GIF or WebP'];
        }
        if (!$img) return ['status' => 'failed', 'detail' => 'The picture could not be decoded'];
        if ($type === IMAGETYPE_JPEG) $img = self::uprightJpeg($img, $path);
        return self::encode($img);
    }

    /**
     * Re-encodes a thumbnail a browser drew (a PDF's first page). Throws a RuntimeException
     * with a reason when it is not a small, real picture.
     */
    public static function fromBrowser(string $bytes): array
    {
        if (!self::gd()) throw new RuntimeException('This server cannot store thumbnails (GD is not installed).');
        if ($bytes === '' || strlen($bytes) > self::BROWSER_MAX_BYTES) throw new RuntimeException('The thumbnail is empty or too large.');
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) throw new RuntimeException('The thumbnail is not a picture.');
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::BROWSER_MAX_SIDE || $info[1] > self::BROWSER_MAX_SIDE) throw new RuntimeException('The thumbnail is the wrong size.');
        $img = @imagecreatefromstring($bytes);
        if (!$img) throw new RuntimeException('The thumbnail could not be read.');
        $r = self::encode($img);
        if ($r['status'] !== 'ready') throw new RuntimeException('The thumbnail could not be made.');
        return $r;
    }

    /** Scales a GD image to MAX_SIDE (never up) and encodes it - WebP, or JPEG on white without WebP. */
    private static function encode($img): array
    {
        $w = imagesx($img); $h = imagesy($img);
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $tw = max(1, (int)round($w * $scale)); $th = max(1, (int)round($h * $scale));
        $webp = function_exists('imagewebp');
        $out = imagecreatetruecolor($tw, $th);
        if ($webp) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        } else {
            imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // JPEG has no transparency
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        ob_start();
        $ok = $webp ? imagewebp($out, null, 75) : imagejpeg($out, null, 80);
        $data = (string)ob_get_clean();
        if (!$ok || $data === '') return ['status' => 'failed', 'detail' => 'The thumbnail could not be encoded'];
        return ['status' => 'ready', 'data' => $data, 'mime' => $webp ? 'image/webp' : 'image/jpeg', 'width' => $tw, 'height' => $th, 'detail' => null];
    }

    /** Turns a JPEG upright from its EXIF orientation, as phones store photos sideways and say so. */
    private static function uprightJpeg($img, string $path)
    {
        if (!function_exists('exif_read_data')) return $img;
        $exif = @exif_read_data($path);
        $o = (int)($exif['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($img, $rot, 0); if ($r) $img = $r; }
        return $img;
    }

    /** Stores a result for a fingerprint, replacing any earlier row. */
    public static function save(PDO $conn, string $sha, array $r, string $madeBy, ?int $madeById = null): void
    {
        if (!self::ready($conn) || !preg_match('/^[0-9a-f]{64}$/', $sha)) return;
        $st = $conn->prepare("INSERT INTO files_thumbnails (sha256, status, mime_type, width, height, data, made_by, made_by_id, detail, created_datetime)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                              ON DUPLICATE KEY UPDATE status = VALUES(status), mime_type = VALUES(mime_type), width = VALUES(width), height = VALUES(height),
                                  data = VALUES(data), made_by = VALUES(made_by), made_by_id = VALUES(made_by_id), detail = VALUES(detail), created_datetime = VALUES(created_datetime)");
        $st->bindValue(1, $sha);
        $st->bindValue(2, $r['status']);
        $st->bindValue(3, $r['mime'] ?? null);
        $st->bindValue(4, $r['width'] ?? null);
        $st->bindValue(5, $r['height'] ?? null);
        $st->bindValue(6, $r['data'] ?? null, isset($r['data']) ? PDO::PARAM_LOB : PDO::PARAM_NULL);
        $st->bindValue(7, $madeBy);
        $st->bindValue(8, $madeById);
        $st->bindValue(9, isset($r['detail']) ? mb_substr((string)$r['detail'], 0, 255) : null);
        $st->execute();
    }

    /**
     * Makes a picture's thumbnail from its stored version (read through the store to a
     * temporary file, so a large file never sits in memory). Returns the status saved.
     *
     * @param array $version  a files_versions row (storage_path, sha256)
     */
    public static function makeForVersion(PDO $conn, array $version): string
    {
        $sha = (string)($version['sha256'] ?? '');
        $key = (string)($version['storage_path'] ?? '');
        if ($sha === '' || !filesStorageKeyIsValid($key)) return 'failed';
        $tmp = tempnam(sys_get_temp_dir(), 'fth');
        try {
            filesStoreFor($conn, $key)->readTo($key, $tmp);
            $r = self::fromImageFile($tmp);
        } catch (StorageUnavailable $e) {
            return 'unavailable';   // not recorded: try again when the store is back
        } catch (Throwable $e) {
            $r = ['status' => 'failed', 'detail' => 'The stored file could not be read'];
        } finally {
            @unlink($tmp);
        }
        self::save($conn, $sha, $r, 'server');
        return $r['status'];
    }

    /** The stored row for a fingerprint, with its data, or null. */
    public static function row(PDO $conn, string $sha): ?array
    {
        if (!self::ready($conn)) return null;
        $st = $conn->prepare('SELECT * FROM files_thumbnails WHERE sha256 = ?');
        $st->execute([$sha]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Sends a ready thumbnail, cacheable for good - the address carries the fingerprint. */
    public static function serve(array $row): never
    {
        header('Content-Type: ' . ($row['mime_type'] ?: 'image/webp'));
        header('Content-Length: ' . strlen((string)$row['data']));
        header('Cache-Control: private, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        echo $row['data'];
        exit;
    }

    /**
     * Removes thumbnails no version anywhere still has - called after versions are deleted
     * for good (the recycle bin's purge, Delete permanently, version delete, overwrite).
     */
    public static function pruneOrphans(PDO $conn): int
    {
        if (!self::ready($conn)) return 0;
        try {
            return (int)$conn->exec("DELETE t FROM files_thumbnails t
                                       LEFT JOIN files_versions v ON v.sha256 = t.sha256
                                      WHERE v.id IS NULL");
        } catch (Throwable $e) {
            error_log('files thumbnails prune: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * The backlog, counted by fingerprint over the CURRENT versions of live files:
     * pictures and PDFs with no row yet, and the ones set aside.
     *
     * @param ?array $openFolders  folder ids the counting person can open (for the PDF split), or null
     * @return array{pictures:int, pdfs:int, pdfs_open:int, set_aside:array<string,int>, ready:int}
     */
    public static function backlog(PDO $conn, ?array $openFolders = null): array
    {
        $out = ['pictures' => 0, 'pdfs' => 0, 'pdfs_open' => 0, 'set_aside' => ['too_large' => 0, 'unsupported' => 0, 'failed' => 0], 'ready' => 0];
        if (!self::ready($conn)) return $out;
        $rows = $conn->query("SELECT i.name, i.folder_id, v.sha256, t.status
                                FROM files_items i
                                JOIN files_versions v ON v.id = i.current_version_id
                                JOIN files_folders f ON f.id = i.folder_id AND f.deleted_datetime IS NULL
                           LEFT JOIN files_thumbnails t ON t.sha256 = v.sha256
                               WHERE i.deleted_datetime IS NULL AND v.sha256 IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $seen = [];
        $open = $openFolders !== null ? array_flip($openFolders) : null;
        foreach ($rows as $r) {
            $kind = self::kind($r['name']);
            if ($kind === null) continue;
            $sha = $r['sha256'];
            if ($r['status'] === 'ready') { if (!isset($seen[$sha])) { $out['ready']++; $seen[$sha] = 1; } continue; }
            if ($r['status'] !== null) { if (!isset($seen[$sha])) { $out['set_aside'][$r['status']] = ($out['set_aside'][$r['status']] ?? 0) + 1; $seen[$sha] = 1; } continue; }
            if ($kind === 'image') { if (!isset($seen[$sha])) { $out['pictures']++; $seen[$sha] = 1; } continue; }
            // A PDF: counted once, as openable if ANY copy is in a folder this person can open.
            if (!isset($seen[$sha])) { $out['pdfs']++; $seen[$sha] = 0; }
            if ($open !== null && isset($open[(int)$r['folder_id']]) && $seen[$sha] === 0) { $out['pdfs_open']++; $seen[$sha] = 2; }
        }
        return $out;
    }

    /**
     * The next files with no thumbnail yet, for the Thumbnails tab's Clear: pictures
     * (made by the server) and PDFs in folders the person can open (drawn by their
     * browser). One item per fingerprint.
     *
     * @param string $kind  'image' or 'pdf'
     * @param int[] $openFolders  folder ids the person can open (used for PDFs)
     * @return array<int, array{item_id:int, sha256:string, name:string, storage_path:string, folder_id:int}>
     */
    public static function nextBatch(PDO $conn, string $kind, array $openFolders, int $limit = 10): array
    {
        if (!self::ready($conn)) return [];
        $rows = $conn->query("SELECT i.id AS item_id, i.name, i.folder_id, v.sha256, v.storage_path
                                FROM files_items i
                                JOIN files_versions v ON v.id = i.current_version_id
                                JOIN files_folders f ON f.id = i.folder_id AND f.deleted_datetime IS NULL
                           LEFT JOIN files_thumbnails t ON t.sha256 = v.sha256
                               WHERE i.deleted_datetime IS NULL AND v.sha256 IS NOT NULL AND t.id IS NULL
                            ORDER BY i.id")->fetchAll(PDO::FETCH_ASSOC);
        $open = array_flip($openFolders);
        $out = []; $seen = [];
        foreach ($rows as $r) {
            if (self::kind($r['name']) !== $kind || isset($seen[$r['sha256']])) continue;
            if ($kind === 'pdf' && !isset($open[(int)$r['folder_id']])) continue;
            $seen[$r['sha256']] = true;
            $r['item_id'] = (int)$r['item_id']; $r['folder_id'] = (int)$r['folder_id'];
            $out[] = $r;
            if (count($out) >= $limit) break;
        }
        return $out;
    }
}
