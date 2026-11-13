<?php
/**
 * Stored bytes in S3-compatible object storage: s3://<bucket>/<prefix><store>/<key>.
 * See driver.php for the interface and s3_sign.php for the signing.
 *
 * Plain curl, no SDK. Every request signs the real SHA-256 of its body. Files
 * over the multipart threshold (64 MiB) go up in parts, so the 5 GB limit of a
 * single PUT never applies and a failed part is retried on its own.
 *
 * Timeouts are deliberately short where nobody should wait: 5 s to connect,
 * 30 s for small requests. A transfer has no total limit (a 4 GB file takes as
 * long as it takes) but is abandoned after 60 s of no progress.
 */

require_once __DIR__ . '/s3_sign.php';

final class S3Driver implements StorageDriver
{
    private const EMPTY_SHA = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
    private const MIB = 1048576;

    private array $cfg;
    private string $base;     // <prefix><store>/
    private int $threshold;
    private int $firstByteTimeout;

    /**
     * @param array  $cfg        endpoint, region, bucket, access_key_id, secret, prefix, path_style
     * @param string $store      'files' - objects go under <prefix>files/
     * @param int    $multipartThreshold  files larger than this go up in parts
     * @param int    $firstByteTimeout    seconds a request may wait with nothing sent or received
     *                                    before it gives up (a store that accepts and then hangs)
     */
    public function __construct(array $cfg, string $store, int $multipartThreshold = 64 * self::MIB, int $firstByteTimeout = 15)
    {
        $this->firstByteTimeout = max(1, $firstByteTimeout);
        $this->cfg = [
            'endpoint'      => s3NormaliseEndpoint((string)($cfg['endpoint'] ?? '')),
            'region'        => trim((string)($cfg['region'] ?? '')) ?: 'us-east-1',
            'bucket'        => trim((string)($cfg['bucket'] ?? '')),
            'access_key_id' => trim((string)($cfg['access_key_id'] ?? '')),
            'secret'        => (string)($cfg['secret'] ?? ''),
            'path_style'    => !empty($cfg['path_style']),
        ];
        $this->base = s3NormalisePrefix((string)($cfg['prefix'] ?? '')) . trim($store, '/') . '/';
        $this->threshold = max(5 * self::MIB, $multipartThreshold);
    }

    private function objectKey(string $key): string
    {
        if ($key === '' || str_contains($key, '..') || str_contains($key, '\\') || $key[0] === '/' || str_contains($key, "\0")) {
            throw new InvalidArgumentException('Bad storage key.');
        }
        return $this->base . $key;
    }

    public function label(): string
    {
        return 's3://' . $this->cfg['bucket'] . '/' . $this->base;
    }

    // ── Operations ──

    public function putFile(string $key, string $localPath, bool $move = false): void
    {
        $ok   = $this->objectKey($key);
        $size = @filesize($localPath);
        if ($size === false) throw new StorageUnavailable('The file to store could not be read.');
        if ($size > $this->threshold) {
            $this->putMultipart($ok, $localPath, (int)$size);
        } else {
            $this->call('PUT', $ok, [], [], ['file' => $localPath, 'offset' => 0, 'length' => (int)$size]);
        }
        if ($move) @unlink($localPath);
    }

    public function readRange(string $key, int $start, int $end, callable $sink): void
    {
        // Exactly bytes $start..$end, whatever the store sends: one (or a proxy in front
        // of it) that ignores Range answers 200 with the whole object, and the caller
        // has already promised the browser a Content-Length for the range.
        $want = $end - $start + 1;
        $sent = 0;
        $skip = null;
        $this->call('GET', $this->objectKey($key), [], ['range' => "bytes=$start-$end"], null,
            function (string $c, int $status) use (&$sent, &$skip, $want, $start, $sink) {
                if ($skip === null) $skip = $status === 206 ? 0 : $start;
                if ($skip > 0) {
                    $n = min($skip, strlen($c));
                    $c = (string)substr($c, $n);
                    $skip -= $n;
                    if ($c === '') return true;
                }
                $c = (string)substr($c, 0, $want - $sent);
                $sent += strlen($c);
                if ($c !== '' && $sink($c) === false) return false;
                return $sent < $want;   // false once complete: stop reading the rest
            });
    }

    public function readTo(string $key, string $localPath): void
    {
        $fh = @fopen($localPath, 'wb');
        if (!$fh) throw new StorageUnavailable('A temporary file could not be written.');
        $refused = false;
        try {
            $this->call('GET', $this->objectKey($key), [], [], null, function (string $c) use ($fh, &$refused) {
                if (fwrite($fh, $c) === strlen($c)) return true;
                $refused = true;
                return false;
            });
            // fwrite is buffered: a full disk can also show up only on close.
            if (!fflush($fh)) $refused = true;
            if ($refused) throw new StorageUnavailable('The local copy could not be written (is the disk full?).');
        } catch (Throwable $e) {
            fclose($fh);
            if (is_file($localPath)) @unlink($localPath);   // never a device or anything else
            throw $e;
        }
        fclose($fh);
    }

    public function size(string $key): ?int
    {
        try {
            $r = $this->call('HEAD', $this->objectKey($key));
        } catch (StorageNotFound) {
            return null;
        }
        return isset($r['headers']['content-length']) ? (int)$r['headers']['content-length'] : null;
    }

    public function delete(string $key): void
    {
        try {
            $this->call('DELETE', $this->objectKey($key));
        } catch (StorageNotFound) {
            // already gone
        }
    }

    private function putMultipart(string $objectKey, string $localPath, int $size): void
    {
        $partSize = max(min(16 * self::MIB, $this->threshold), (int)ceil($size / 9000));
        $partSize = (int)(ceil($partSize / self::MIB) * self::MIB);

        $r = $this->call('POST', $objectKey, ['uploads' => ''], [], ['body' => '']);
        if (!preg_match('#<UploadId>([^<]+)</UploadId>#', $r['body'], $m)) {
            throw new StorageUnavailable('The storage did not start the upload.');
        }
        $uploadId = html_entity_decode($m[1], ENT_XML1);
        try {
            $parts = [];
            for ($n = 1, $off = 0; $off < $size; $n++, $off += $partSize) {
                $len = min($partSize, $size - $off);
                $pr  = $this->call('PUT', $objectKey, ['partNumber' => $n, 'uploadId' => $uploadId], [],
                                   ['file' => $localPath, 'offset' => $off, 'length' => $len]);
                $etag = $pr['headers']['etag'] ?? '';
                if ($etag === '') throw new StorageUnavailable("The storage did not confirm part $n.");
                $parts[$n] = $etag;
            }
            $xml = '<CompleteMultipartUpload>';
            foreach ($parts as $n => $etag) {
                $xml .= '<Part><PartNumber>' . $n . '</PartNumber><ETag>' . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
            }
            $xml .= '</CompleteMultipartUpload>';
            $cr = $this->call('POST', $objectKey, ['uploadId' => $uploadId], ['content-type' => 'application/xml'], ['body' => $xml]);
            // CompleteMultipartUpload can answer 200 with an <Error> body.
            if (str_contains($cr['body'], '<Error>')) throw new StorageUnavailable($this->errorText(200, $cr['body'], ''));
        } catch (Throwable $e) {
            try { $this->call('DELETE', $objectKey, ['uploadId' => $uploadId]); } catch (Throwable) {}
            throw $e;
        }
    }

    // ── The wire ──

    /**
     * One signed request, retried twice (0.5 s, then 2 s) on a timeout or 5xx when
     * nothing has been handed to the sink yet.
     *
     * @param ?array    $body  null, ['body' => string] or ['file' => path, 'offset' => int, 'length' => int]
     * @param ?callable $sink  receives a 2xx body in chunks instead of it being buffered
     * @return array ['status' => int, 'headers' => array (lower-case names), 'body' => string]
     */
    private function call(string $method, string $objectKey, array $query = [], array $headers = [], ?array $body = null, ?callable $sink = null): array
    {
        $delays = [500000, 2000000];
        for ($try = 0; ; $try++) {
            $delivered = false;
            try {
                return $this->once($method, $objectKey, $query, $headers, $body, $sink, $delivered);
            } catch (StorageUnavailable $e) {
                $retryable = $e->getCode() === 1;
                if (!$retryable || $delivered || $try >= count($delays)) throw $e;
                usleep($delays[$try]);
            }
        }
    }

    private function once(string $method, string $objectKey, array $query, array $headers, ?array $body, ?callable $sink, bool &$delivered): array
    {
        $url  = s3ObjectUrl($this->cfg, $objectKey, $query);
        $p    = parse_url($url);
        $host = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');

        $fh = null;
        $hash = self::EMPTY_SHA;
        $length = 0;
        if ($body !== null && isset($body['file'])) {
            $fh = @fopen($body['file'], 'rb');
            if (!$fh) throw new StorageUnavailable('The file to store could not be read.');
            $length = (int)$body['length'];
            fseek($fh, (int)$body['offset']);
            $ctx = hash_init('sha256');
            $left = $length;
            while ($left > 0 && ($buf = fread($fh, (int)min(self::MIB, $left))) !== false && $buf !== '') {
                hash_update($ctx, $buf);
                $left -= strlen($buf);
            }
            $hash = hash_final($ctx);
            fseek($fh, (int)$body['offset']);
        } elseif ($body !== null) {
            $hash = hash('sha256', (string)$body['body']);
            $length = strlen((string)$body['body']);
        }

        $signed = s3SignRequest($method, $url, ['host' => $host] + $headers, $hash, $this->cfg['access_key_id'],
                                $this->cfg['secret'], $this->cfg['region'], 's3', time());
        $out = ['Expect:'];
        foreach ($signed as $k => $v) if ($k !== 'host') $out[] = "$k: $v";
        if ($body !== null) $out[] = 'content-length: ' . $length;

        $status = 0;
        $respHeaders = [];
        $buffer = '';
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $out,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_CAINFO         => __DIR__ . '/../cacert.pem',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$status, &$respHeaders) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) { $status = (int)$m[1]; $respHeaders = []; }
                elseif (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use (&$status, &$buffer, $sink, &$delivered) {
                if ($sink !== null && $status >= 200 && $status < 300) {
                    $delivered = true;
                    return $sink($chunk, $status) === false ? 0 : strlen($chunk);
                }
                if (strlen($buffer) < 1048576) $buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($method === 'HEAD') $opts[CURLOPT_NOBODY] = true;
        // CompleteMultipartUpload may legitimately take minutes on a big file before it
        // answers: no first-byte limit and no total limit for it, only "no progress".
        $completing = $method === 'POST' && isset($query['uploadId']);
        $big = $fh !== null || $sink !== null || $completing;
        if ($big) {
            $opts[CURLOPT_LOW_SPEED_LIMIT] = 1;
            $opts[CURLOPT_LOW_SPEED_TIME]  = 60;
        } else {
            $opts[CURLOPT_TIMEOUT] = 30;
        }
        if (!$completing) {
            // A store that accepts the connection and then says nothing: give up after
            // $firstByteTimeout with nothing sent or received, so the person gets a 503
            // in seconds rather than the web server's own timeout page.
            // The clock restarts whenever upload bytes move, so a big upload is never cut
            // off - only the wait for an answer once everything has been sent.
            $lastMove = microtime(true);
            $lastUl = 0;
            $limit = $this->firstByteTimeout;
            $opts[CURLOPT_NOPROGRESS] = false;
            $opts[CURLOPT_XFERINFOFUNCTION] = function ($c, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$status, &$lastMove, &$lastUl, $limit) {
                if ($ulNow != $lastUl) { $lastUl = $ulNow; $lastMove = microtime(true); }
                return ($status === 0 && $dlNow == 0 && microtime(true) - $lastMove > $limit) ? 1 : 0;
            };
        }
        if ($fh !== null) {
            $left = $length;
            $opts[CURLOPT_UPLOAD]       = true;
            $opts[CURLOPT_INFILESIZE]   = $length;
            $opts[CURLOPT_READFUNCTION] = function ($c, $in, $max) use ($fh, &$left) {
                if ($left <= 0) return '';
                $buf = fread($fh, (int)min($max, $left));
                if ($buf === false) return '';
                $left -= strlen($buf);
                return $buf;
            };
        } elseif ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = (string)$body['body'];
        }
        curl_setopt_array($ch, $opts);
        $okRun = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err   = curl_error($ch);
        // Never connected (refused, dropped, DNS): the store is down - retrying only
        // makes the person wait three connect timeouts for the same answer.
        $connected = (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
        curl_close($ch);
        if ($fh) fclose($fh);

        // The sink asked to stop (a closed browser): not an error.
        if ($errNo === CURLE_WRITE_ERROR && $delivered) {
            return ['status' => $status, 'headers' => $respHeaders, 'body' => ''];
        }
        if ($okRun === false || $errNo !== 0) {
            $retry = $connected && in_array($errNo, [CURLE_OPERATION_TIMEDOUT, CURLE_GOT_NOTHING, CURLE_RECV_ERROR, CURLE_SEND_ERROR], true);
            throw new StorageUnavailable('Storage could not be reached: ' . $err, $retry ? 1 : 0);
        }
        if ($status >= 200 && $status < 300) {
            return ['status' => $status, 'headers' => $respHeaders, 'body' => $buffer];
        }
        $code = preg_match('#<Code>([^<]+)</Code>#', $buffer, $m) ? $m[1] : '';
        if ($status === 404 && $code !== 'NoSuchBucket' && $code !== 'NoSuchUpload') {
            throw new StorageNotFound('Stored file not found.');
        }
        throw new StorageUnavailable($this->errorText($status, $buffer, $code), $status >= 500 ? 1 : 0);
    }

    private function errorText(int $status, string $body, string $code): string
    {
        $msg = preg_match('#<Message>([^<]*)</Message>#', $body, $m) ? html_entity_decode($m[1], ENT_XML1) : '';
        if ($code === '' && preg_match('#<Code>([^<]+)</Code>#', $body, $c)) $code = $c[1];
        $what = trim($code . ($msg !== '' ? ': ' . $msg : ''));
        return 'Storage refused the request (HTTP ' . $status . ($what !== '' ? ', ' . $what : '') . ').';
    }
}
