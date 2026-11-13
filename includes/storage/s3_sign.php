<?php
/**
 * AWS Signature Version 4 for S3-compatible storage, and how an object key
 * becomes a URL. Pure functions: no network, no settings, no database.
 *
 * FreeITSM vendors no AWS SDK (see VENDOR.md), so this is the whole of the
 * signing: header-signed requests with the payload's SHA-256 always present
 * (some providers refuse UNSIGNED-PAYLOAD over plain http). It is enough for
 * AWS S3, Backblaze B2, Wasabi, Cloudflare R2, MinIO, RustFS and the rest.
 *
 * tests/storage-s3-signing.php pins it to the AWS test-suite vector.
 */

/**
 * Signs one request. Returns $headers (names lower-cased) plus x-amz-date,
 * x-amz-content-sha256 (S3 only) and authorization.
 *
 * @param string $method         GET, PUT, HEAD, DELETE, POST
 * @param string $url            the full URL, path already encoded (s3ObjectUrl() does that)
 * @param array  $headers        headers to send and sign, e.g. ['host' => ..., 'range' => ...]
 * @param string $payloadSha256  hex SHA-256 of the body ('' body = e3b0c442...)
 * @param string $service        's3' for storage; anything else only for the test vector
 * @param int    $now            UNIX time of the request
 */
function s3SignRequest(string $method, string $url, array $headers, string $payloadSha256,
                       string $accessKeyId, string $secret, string $region, string $service, int $now): array
{
    $h = [];
    foreach ($headers as $k => $v) $h[strtolower(trim((string)$k))] = trim(preg_replace('/\s+/', ' ', (string)$v));
    $amzDate = gmdate('Ymd\THis\Z', $now);
    $day     = substr($amzDate, 0, 8);
    $h['x-amz-date'] = $amzDate;
    if ($service === 's3') $h['x-amz-content-sha256'] = $payloadSha256;
    ksort($h, SORT_STRING);

    $parts = parse_url($url);
    $path  = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
    $query = [];
    if (($parts['query'] ?? '') !== '') {
        foreach (explode('&', $parts['query']) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $query[] = [rawurlencode(rawurldecode($k)), rawurlencode(rawurldecode($v))];
        }
        usort($query, fn($a, $b) => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));
    }
    $canonQuery = implode('&', array_map(fn($p) => $p[0] . '=' . $p[1], $query));

    $canonHeaders = '';
    foreach ($h as $k => $v) $canonHeaders .= $k . ':' . $v . "\n";
    $signed = implode(';', array_keys($h));

    $canonical = implode("\n", [strtoupper($method), $path, $canonQuery, $canonHeaders, $signed, $payloadSha256]);
    $scope     = "$day/$region/$service/aws4_request";
    $toSign    = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);

    $k = hash_hmac('sha256', $day, 'AWS4' . $secret, true);
    $k = hash_hmac('sha256', $region, $k, true);
    $k = hash_hmac('sha256', $service, $k, true);
    $k = hash_hmac('sha256', 'aws4_request', $k, true);
    $sig = hash_hmac('sha256', $toSign, $k);

    $h['authorization'] = "AWS4-HMAC-SHA256 Credential=$accessKeyId/$scope, SignedHeaders=$signed, Signature=$sig";
    return $h;
}

/**
 * The URL of an object.
 *
 * @param array $cfg        endpoint, bucket, path_style (bool)
 * @param string $objectKey the full key inside the bucket, prefix included; '' = the bucket itself
 * @param array $query      query parameters, e.g. ['uploads' => ''] or ['partNumber' => 1, 'uploadId' => ...]
 */
function s3ObjectUrl(array $cfg, string $objectKey, array $query = []): string
{
    $endpoint = s3NormaliseEndpoint((string)$cfg['endpoint']);
    $bucket   = (string)$cfg['bucket'];
    $p        = parse_url($endpoint);
    $scheme   = $p['scheme'];
    // A dotted bucket name does not match a provider's *.host wildcard certificate.
    $pathStyle = !empty($cfg['path_style']) || ($scheme === 'https' && str_contains($bucket, '.'));
    $key = implode('/', array_map('rawurlencode', explode('/', $objectKey)));

    if ($pathStyle) {
        $url = $endpoint . '/' . rawurlencode($bucket) . ($key !== '' ? '/' . $key : '');
    } else {
        $host = $bucket . '.' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $url  = $scheme . '://' . $host . ($p['path'] ?? '') . '/' . $key;
    }
    if ($query) {
        $q = [];
        foreach ($query as $k => $v) $q[] = rawurlencode((string)$k) . '=' . rawurlencode((string)$v);
        $url .= '?' . implode('&', $q);
    }
    return $url;
}

/** A key prefix as typed -> '' or 'a/b/' (no leading slash, one trailing, no empty segments). */
function s3NormalisePrefix(string $prefix): string
{
    $segs = array_values(array_filter(explode('/', str_replace('\\', '/', trim($prefix))), fn($s) => $s !== ''));
    return $segs ? implode('/', $segs) . '/' : '';
}

/**
 * An endpoint as typed -> scheme://host[:port][/path] without a trailing slash.
 * Scheme and host are lower-cased and a default port (:443 on https, :80 on http)
 * is dropped: curl leaves it out of the Host header it sends, so a signed ":443"
 * would never match what the provider checks.
 */
function s3NormaliseEndpoint(string $endpoint): string
{
    $e = rtrim(trim($endpoint), '/');
    if (!preg_match('#^(https?)://(\[[0-9a-f:.]+\]|[^/\s:\[\]]+)(?::(\d+))?(/.*)?$#i', $e, $m)) {
        throw new InvalidArgumentException('The endpoint must start with http:// or https://');
    }
    $scheme = strtolower($m[1]);
    $port   = $m[3] ?? '';
    if (($scheme === 'https' && $port === '443') || ($scheme === 'http' && $port === '80')) $port = '';
    return $scheme . '://' . strtolower($m[2]) . ($port !== '' ? ':' . $port : '') . rtrim($m[4] ?? '', '/');
}
