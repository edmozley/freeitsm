<?php
/**
 * Safe extraction of an uploaded SCORM package.
 *
 * WHY THIS EXISTS
 * ---------------
 * A SCORM package is a zip of static files, and it is extracted into
 * lms/content/<id>/ — a directory INSIDE the webroot, which Apache serves and
 * will happily execute PHP from. ZipArchive::extractTo() writes whatever the zip
 * contains, wherever the zip says. Left unguarded that gives anyone with the LMS
 * module three separate ways to own the server:
 *
 *   1. Ship a `.php` file in the package. It lands in a web-served directory and
 *      runs as the web server — reading config.php, the database, the AES key,
 *      every stored mailbox credential. LMS access becomes root access.
 *   2. Name an entry `../../../includes/functions.php` ("zip-slip") and overwrite
 *      any file in the app, escaping the content directory entirely.
 *   3. Ship a zip bomb — a few KB that expands to fill the disk.
 *
 * So we never hand the archive to extractTo(). Every entry is inspected first,
 * against an allowlist of things a course can legitimately be made of, and the
 * whole upload is refused if any single entry fails. Refusing the package
 * outright (rather than skipping the bad file) is deliberate: a SCORM package
 * with a shell in it is not a course with a problem, it is an attack.
 *
 * Defence in depth: lmsHardenContentDir() also drops an .htaccess that disables
 * PHP execution for the content tree, so even a future hole in the checks above
 * does not become code execution. `.htaccess` is itself on the deny list, so a
 * package cannot ship one to undo this.
 */

/** Ceilings. Generous for real courses, fatal to a zip bomb. */
const LMS_MAX_UNCOMPRESSED_BYTES = 524288000;   // 500 MB total, uncompressed
const LMS_MAX_ENTRIES            = 5000;
const LMS_MAX_RATIO              = 200;         // uncompressed:compressed, per entry

/**
 * What a course is allowed to be made of: markup, styling, script, media, fonts.
 * An allowlist, not a deny list — the set of dangerous extensions is open-ended
 * (php, phtml, phar, cgi, pl, asp, jsp…) and depends on the server's handlers,
 * so enumerating the safe set is the only version of this that stays correct.
 */
const LMS_ALLOWED_EXTENSIONS = [
    // markup / data
    'html', 'htm', 'xml', 'xsd', 'dtd', 'json', 'txt', 'csv', 'vtt', 'srt', 'map',
    // styling / script
    'css', 'js', 'mjs',
    // images
    'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'bmp', 'ico', 'cur',
    // audio / video
    'mp3', 'mp4', 'm4a', 'm4v', 'wav', 'ogg', 'oga', 'ogv', 'webm', 'flac',
    // fonts
    'woff', 'woff2', 'ttf', 'otf', 'eot',
    // documents a course might link to
    'pdf',
];

/**
 * Check every entry in the archive. Returns the entries that should be written
 * (directories excluded), or throws with a message naming the offending file.
 *
 * @throws Exception if the package is unsafe or oversized.
 * @return array<int,string> Entry names, safe to extract.
 */
function lmsValidatePackage(ZipArchive $zip): array
{
    if ($zip->numFiles > LMS_MAX_ENTRIES) {
        throw new Exception('That package contains ' . $zip->numFiles . ' files, which is more than a course should need (limit ' . LMS_MAX_ENTRIES . ').');
    }

    $totalBytes = 0;
    $files      = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat === false) throw new Exception('The package could not be read — it may be corrupt.');

        $name = $stat['name'];

        // Reject anything that tries to escape the destination directory, or that
        // hides a traversal inside an encoded/odd path. Note ZipArchive gives us
        // forward slashes, but a hand-crafted zip can contain backslashes.
        if ($name === '' || strpos($name, "\0") !== false) {
            throw new Exception('The package contains an entry with an invalid name.');
        }
        $normalised = str_replace('\\', '/', $name);
        if (
            $normalised[0] === '/' ||                       // absolute
            preg_match('#^[a-zA-Z]:#', $normalised) ||      // C:\…
            preg_match('#(^|/)\.\.(/|$)#', $normalised)     // any .. segment
        ) {
            throw new Exception('The package tries to write outside its own folder (' . htmlspecialchars($name) . '). It has been rejected.');
        }

        // Directory entries carry no content and need no extension check.
        if (substr($normalised, -1) === '/') continue;

        // Extension allowlist. A file with no extension is refused too — a course
        // has no use for one, and the server's handler rules decide what it means.
        $ext = strtolower(pathinfo($normalised, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, LMS_ALLOWED_EXTENSIONS, true)) {
            throw new Exception('The package contains a file FreeITSM will not host: ' . htmlspecialchars($name)
                . '. A SCORM package should only hold course content (HTML, CSS, JavaScript, images, media and fonts).');
        }

        // Zip-bomb guards: per-entry ratio, and the total once expanded.
        $size = (int)$stat['size'];
        $comp = max(1, (int)$stat['comp_size']);
        if ($size / $comp > LMS_MAX_RATIO && $size > 1048576) {
            throw new Exception('The package looks like a zip bomb (' . htmlspecialchars($name) . ' expands over ' . LMS_MAX_RATIO . 'x). It has been rejected.');
        }

        $totalBytes += $size;
        if ($totalBytes > LMS_MAX_UNCOMPRESSED_BYTES) {
            throw new Exception('That package expands to more than ' . round(LMS_MAX_UNCOMPRESSED_BYTES / 1048576) . ' MB, which is larger than FreeITSM will accept.');
        }

        $files[] = $name;
    }

    if (!$files) throw new Exception('That ZIP file is empty.');

    return $files;
}

/**
 * Extract only the entries that passed validation, one at a time.
 *
 * extractTo($dir) with no second argument is what we are avoiding: it writes
 * everything, including anything validation would have rejected. Passing the
 * vetted list means the set of files on disk is exactly the set we approved.
 *
 * @throws Exception on any write failure.
 */
function lmsExtractPackage(ZipArchive $zip, string $destDir, array $files): void
{
    if (!$zip->extractTo($destDir, $files)) {
        throw new Exception('The package could not be unpacked.');
    }
}

/**
 * Stop the content tree ever executing code, whatever ends up in it.
 *
 * Belt and braces behind the allowlist: if a future change (or a bug) lets a
 * script through, Apache still refuses to run it. Written once, next to the
 * courses. Needs AllowOverride to be on — which is why it is the SECOND line of
 * defence and not the first.
 */
function lmsHardenContentDir(string $contentRoot): void
{
    $htaccess = $contentRoot . '/.htaccess';
    if (file_exists($htaccess)) return;

    $rules = <<<'HTACCESS'
# Course content is uploaded data, not application code. Nothing in this tree may
# ever execute — this is defence in depth behind the extension allowlist in
# includes/lms_package.php. Do not remove.
php_flag engine off

<IfModule mod_mime.c>
    RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .cgi .pl .py .asp .aspx .jsp .sh
    RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phar
    AddType text/plain .php .phtml .phar .cgi .pl .py .sh
</IfModule>

<FilesMatch "\.(php[0-9]?|phtml|phar|cgi|pl|py|asp|aspx|jsp|sh|htaccess)$">
    Require all denied
</FilesMatch>
HTACCESS;

    if (!is_dir($contentRoot)) @mkdir($contentRoot, 0755, true);
    @file_put_contents($htaccess, $rules);
}
