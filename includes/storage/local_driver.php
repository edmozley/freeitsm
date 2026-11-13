<?php
/**
 * Stored bytes in a folder on the server: <root>/<key>. What FreeITSM has
 * always done; see driver.php for the interface.
 */

require_once __DIR__ . '/../uploads.php';

final class LocalDriver implements StorageDriver
{
    private string $root;
    private bool $prepared = false;

    /** @param string $root  absolute folder; created and protected on first write */
    public function __construct(string $root)
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    private function path(string $key): string
    {
        if ($key === '' || str_contains($key, '..') || str_contains($key, '\\') || $key[0] === '/' || str_contains($key, "\0")) {
            throw new InvalidArgumentException('Bad storage key.');
        }
        return $this->root . '/' . $key;
    }

    public function putFile(string $key, string $localPath, bool $move = false): void
    {
        $dest = $this->path($key);
        if (!$this->prepared) { uploadPrepareDir($this->root); $this->prepared = true; }
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new StorageUnavailable('The storage folder could not be created.');
        }
        // rename() fails across devices (a volume, another disk): fall back to copy.
        if ($move && @rename($localPath, $dest)) return;
        if (!@copy($localPath, $dest)) throw new StorageUnavailable('The server could not write to the storage folder.');
        if ($move) @unlink($localPath);
    }

    public function readRange(string $key, int $start, int $end, callable $sink): void
    {
        $p = $this->path($key);
        $fh = is_file($p) ? @fopen($p, 'rb') : false;
        if (!$fh) throw new StorageNotFound('Stored file not found.');
        try {
            fseek($fh, $start);
            $left = $end - $start + 1;
            while ($left > 0 && !feof($fh)) {
                $buf = fread($fh, (int)min(1024 * 1024, $left));
                if ($buf === false || $buf === '') break;
                $left -= strlen($buf);
                if ($sink($buf) === false) break;
            }
        } finally {
            fclose($fh);
        }
    }

    public function readTo(string $key, string $localPath): void
    {
        $p = $this->path($key);
        if (!is_file($p)) throw new StorageNotFound('Stored file not found.');
        if (!@copy($p, $localPath)) throw new StorageUnavailable('The stored file could not be read.');
    }

    public function size(string $key): ?int
    {
        $p = $this->path($key);
        clearstatcache(true, $p);
        return is_file($p) ? (int)filesize($p) : null;
    }

    public function delete(string $key): void
    {
        $p = $this->path($key);
        if (!is_file($p)) return;
        @unlink($p);
        clearstatcache(true, $p);
        // Windows refuses to delete a file something still has open (a download in
        // progress): say so, so the caller does not record it as gone.
        if (is_file($p)) throw new RuntimeException('The stored file could not be deleted (is it still open?).');
    }

    public function label(): string
    {
        return $this->root;
    }
}
