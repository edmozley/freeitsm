<?php
/**
 * Apache Tika client — attachment extraction, tier 2 (discussion #53).
 *
 * The formats the built-in tier refuses (PDF, legacy Office, and anything that
 * needs OCR) are sent to ONE external service rather than parsed here. The
 * reasoning is on the wiki page Attachment-Text-Extraction-Developer-Guide §2,
 * and the short version is that a document parser handling input from anyone who
 * can email the service desk belongs in a container, not in the process holding
 * the database credentials.
 *
 * ⚠️ FREEITSM HOSTS NOTHING. This is a URL and a timeout. The administrator runs
 * Tika wherever suits them — a container, a JVM, another machine — and that is
 * deliberately not our business to start, stop or supervise.
 *
 * ⚠️ TIKA HAS NO AUTHENTICATION OF ANY KIND. Anything that can reach it will
 * parse whatever bytes it is sent, using a very large parser surface. The
 * documentation tells administrators to bind it to loopback or an internal
 * container network and never publish the port. This client cannot enforce that,
 * but the setting screen says it.
 */

require_once __DIR__ . '/extract.php';
require_once __DIR__ . '/../ssl.php';

/** Settings keys, all in `system_settings`. */
const TIKA_SETTING_URL     = 'tika_url';
const TIKA_SETTING_TIMEOUT = 'tika_timeout';

/** Sane bounds for the timeout, in seconds. OCR is slow; 5 minutes is the ceiling. */
const TIKA_TIMEOUT_DEFAULT = 60;
const TIKA_TIMEOUT_MIN     = 5;
const TIKA_TIMEOUT_MAX     = 300;

/**
 * Formats Tika is asked to handle. Deliberately a LIST rather than "send
 * everything Tika might manage": an unknown extension is more likely to be
 * something we have no business shipping to an extractor than a document.
 */
const TIKA_EXTENSIONS = [
    'pdf',
    'doc', 'xls', 'ppt',            // legacy Office
    'rtf', 'odt', 'ods', 'odp',     // RTF and OpenDocument
    'eml', 'msg',                   // forwarded mail
    'png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'gif',   // OCR
    'heic', 'webp',
];

/**
 * The configured endpoint, or '' when tier 2 is switched off.
 *
 * ⚠️ Configured and AVAILABLE are different states and the difference matters —
 * see tikaExtract(). Not configured means "these formats are unsupported, and we
 * say so"; configured-but-unreachable means "we still owe this file".
 */
function tikaUrl(PDO $conn): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([TIKA_SETTING_URL]);
        $v = (string)($st->fetchColumn() ?: '');
    } catch (Exception) { $v = ''; }
    return $cached = rtrim(trim($v), '/');
}

/**
 * Force a shorter timeout for the current process.
 *
 * ⚠️ Exists for the opportunistic drain, which runs INSIDE a request somebody is
 * waiting on. The configured timeout is sized for a scanned document on a cron —
 * a minute or more — and applying that to a page load means an analyst who
 * happened to open the wrong screen waits a minute for it. Set low, do one item,
 * set back.
 *
 * @param ?int $seconds  the timeout to force for this process, or null to go back to the setting; omit to only read it
 */
function tikaTimeoutOverride(?int $seconds = null): ?int {
    static $override = null;
    if (func_num_args() > 0) $override = $seconds;
    return $override;
}

/**
 * How many seconds to wait for Tika, from the override or the system setting, kept within
 * the allowed range.
 */
function tikaTimeout(PDO $conn): int {
    $forced = tikaTimeoutOverride();
    if ($forced !== null) return max(TIKA_TIMEOUT_MIN, min(TIKA_TIMEOUT_MAX, $forced));

    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $st->execute([TIKA_SETTING_TIMEOUT]);
        $v = (int)($st->fetchColumn() ?: TIKA_TIMEOUT_DEFAULT);
    } catch (Exception) { $v = TIKA_TIMEOUT_DEFAULT; }
    return max(TIKA_TIMEOUT_MIN, min(TIKA_TIMEOUT_MAX, $v ?: TIKA_TIMEOUT_DEFAULT));
}

/** Is tier 2 switched on at all? */
function tikaConfigured(PDO $conn): bool {
    return tikaUrl($conn) !== '';
}

/**
 * Would Tika be asked about this file, if configured?
 *
 * @param string $filename  the attachment's file name; only its extension is checked against TIKA_EXTENSIONS
 */
function tikaHandles(string $filename): bool {
    return in_array(attTextExtension($filename), TIKA_EXTENSIONS, true);
}

/**
 * Ask Tika for the text of a file.
 *
 * ⚠️ THE RETURN DISTINGUISHES THREE OUTCOMES, AND THAT IS THE POINT:
 *
 *   ['ok' => true,  'text' => '…']              it read the file
 *   ['ok' => false, 'retry' => true,  …]        Tika is unreachable or timed out
 *   ['ok' => false, 'retry' => false, …]        Tika answered and could not read it
 *
 * A caller must write `pending` for retry=true and `failed` for retry=false. If a
 * five-minute outage marked every PDF that arrived during it as `failed`, those
 * files would be permanently blacklisted and never looked at again — the index
 * would be quietly wrong forever and nothing would say so.
 *
 * @param string $path  the full path of the file on disk, already checked to be inside the attachments directory
 * @param string $filename  the original file name, sent to Tika as a hint for choosing a parser
 * @return array{ok:bool,text:string,retry:bool,error:string}
 */
function tikaExtract(PDO $conn, string $path, string $filename): array {
    $fail = fn(bool $retry, string $err) => ['ok' => false, 'text' => '', 'retry' => $retry, 'error' => $err];

    $base = tikaUrl($conn);
    if ($base === '') return $fail(false, 'not configured');
    if (!is_file($path) || !is_readable($path)) return $fail(false, 'unreadable file');

    $fh = @fopen($path, 'rb');
    if (!$fh) return $fail(false, 'could not open file');

    $ch = curl_init($base . '/tika');
    curl_setopt_array($ch, [
        // PUT the bytes. Tika's /tika endpoint takes the raw file body and
        // returns the text; there is no multipart wrapper to get wrong.
        CURLOPT_PUT            => true,
        CURLOPT_INFILE         => $fh,
        CURLOPT_INFILESIZE     => filesize($path),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => tikaTimeout($conn),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Accept: text/plain',
            // Tika sniffs the type itself, but the filename helps it choose a
            // parser when the bytes are ambiguous.
            'Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"',
        ],
    ]);
    // Every outbound handle goes through the shared SSL policy — see
    // includes/ssl.php. A Tika behind HTTPS is unusual but perfectly possible.
    if (function_exists('sslApplyCurl')) sslApplyCurl($ch);

    $body   = curl_exec($ch);
    $errNo  = curl_errno($ch);
    $errMsg = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    fclose($fh);

    // Transport failure: unreachable, refused, timed out. RETRYABLE.
    if ($errNo !== 0) return $fail(true, 'transport: ' . $errMsg);

    // 5xx is Tika having a bad time — also worth retrying.
    if ($status >= 500) return $fail(true, 'HTTP ' . $status);

    // 4xx means Tika understood and refused: an encrypted PDF, a corrupt file.
    // Retrying will not change its mind.
    if ($status >= 400) return $fail(false, 'HTTP ' . $status);

    if ($body === false) return $fail(true, 'empty response');

    return ['ok' => true, 'text' => attTextNormalise((string)$body), 'retry' => false, 'error' => ''];
}

/**
 * Is the service answering? Used by the settings screen's Test button, and
 * NOWHERE in the indexing path — a health check before every attachment would be
 * an extra HTTP round trip per file. There, the extraction attempt IS the check.
 *
 * @param string $overrideUrl  a Tika address to test instead of the saved one (the settings screen's unsaved value); '' uses the saved one
 * @return array{ok:bool,detail:string}
 */
function tikaPing(PDO $conn, string $overrideUrl = ''): array {
    $base = $overrideUrl !== '' ? rtrim(trim($overrideUrl), '/') : tikaUrl($conn);
    if ($base === '') return ['ok' => false, 'detail' => 'No address configured'];

    $ch = curl_init($base . '/version');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if (function_exists('sslApplyCurl')) sslApplyCurl($ch);

    $body   = curl_exec($ch);
    $errMsg = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($body === false || $errMsg !== '') return ['ok' => false, 'detail' => $errMsg ?: 'No response'];
    if ($status !== 200)                   return ['ok' => false, 'detail' => 'HTTP ' . $status];

    return ['ok' => true, 'detail' => trim((string)$body)];
}
