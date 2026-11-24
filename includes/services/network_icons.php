<?php
/**
 * NetworkIconService - icon libraries from icons.diagrams.net for the Network Mapper (GitHub #433).
 *
 * icons.diagrams.net is draw.io's catalogue of ~1,000 icon sets (IBM Carbon, Azure, Cisco,
 * Kubernetes, Font Awesome ...). An analyst browses it, previews a set, and IMPORTS it: the
 * icons are COPIED into network_icon_library_icons, so a diagram never depends on that site
 * staying up and opening a diagram never contacts it.
 *
 * 🔴 Outbound requests go to https://icons.diagrams.net ONLY - fetchSite() refuses any other
 * host, any other scheme and any redirect, so a library file that names another host cannot
 * turn this into a server-side request forgery. Icons arriving as data: URIs are decoded, not
 * fetched.
 *
 * 🔴 SVG is XML and can carry script. Every SVG is cleaned on import (cleanSvg: no script,
 * no foreignObject, no on* handlers, no outside references) AND icon.php serves it with a
 * sandbox CSP and nosniff - neither defence is trusted alone. Icons live in the database,
 * never in a served folder.
 *
 * robots.txt on that site disallows /libs/ for crawlers. This is not a crawler: a library is
 * fetched only when a person opens it, exactly as draw.io itself does.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../ssl.php';

class NetworkIconService
{
    public const SITE = 'https://icons.diagrams.net';
    private const MAX_LIBRARY_BYTES = 30 * 1024 * 1024;
    private const MAX_ICON_BYTES = 512 * 1024;
    private const CATALOGUE_TTL = 86400;
    private const LIBRARY_TTL = 3600;

    // ------------------------------------------------------------------ catalogue

    /**
     * Every set on the site: [{slug, title, count, group}], cached for a day. group is the
     * vendor heading ("carbon", "fortinet" ...) or '' for the long list of community sets.
     */
    public static function catalogue(bool $refresh = false): array
    {
        $cache = self::cachePath('catalogue.json');
        if (!$refresh && is_file($cache) && filemtime($cache) > time() - self::CATALOGUE_TTL) {
            $c = json_decode((string)file_get_contents($cache), true);
            if (is_array($c) && $c) return $c;
        }
        $html = self::fetchSite(self::SITE . '/', 3 * 1024 * 1024);
        $sets = [];
        $seen = [];
        $group = '';
        // Walk the page in order: an <h3> names the vendor group of the links after it; the
        // "Icon sets (N)" <h2> starts the community list.
        $re = '~<h2[^>]*>(.*?)</h2>|<h3[^>]*>(.*?)</h3>|<a href="/sets/([A-Za-z0-9_.-]+)">([^<]*)</a>\s*<span class="n">(\d+)</span>~s';
        preg_match_all($re, $html, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            if (($hit[1] ?? '') !== '') { $group = ''; continue; }
            if (($hit[2] ?? '') !== '') { $group = trim(html_entity_decode(strip_tags($hit[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')); continue; }
            $slug = $hit[3] ?? '';
            if ($slug === '' || isset($seen[$slug])) continue;
            $seen[$slug] = true;
            $sets[] = [
                'slug'  => $slug,
                'title' => trim(html_entity_decode($hit[4], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'count' => (int)$hit[5],
                'group' => $group,
            ];
        }
        if (!$sets) {
            throw new ServiceError('upstream', 'upstream_format', 'icons.diagrams.net answered, but no icon sets could be read from the page.');
        }
        @file_put_contents($cache, json_encode($sets));
        return $sets;
    }

    // ------------------------------------------------------------------ one library

    /**
     * One set, read from its library file: {slug, title, licence, author, author_url, source,
     * icons: [{title, tags, w, h, src}]}. src is the icon's address on icons.diagrams.net (or a
     * data: URI) - used for the preview, and by the import to copy the icon.
     */
    public static function library(string $slug): array
    {
        $slug = self::checkSlug($slug);
        $cache = self::cachePath('lib-' . $slug . '.xml');
        if (is_file($cache) && filemtime($cache) > time() - self::LIBRARY_TTL) {
            $xml = (string)file_get_contents($cache);
        } else {
            $xml = self::fetchSite(self::SITE . '/libs/' . rawurlencode($slug) . '/1/library.xml', self::MAX_LIBRARY_BYTES);
            @file_put_contents($cache, $xml);
        }
        $out = ['slug' => $slug, 'title' => $slug, 'licence' => '', 'author' => '', 'author_url' => '', 'source' => '', 'icons' => []];
        // <!-- Title | License: X | Author: Name (url) | Source: Y | Generated from ... -->
        if (preg_match('~<!--(.*?)-->~s', $xml, $c)) {
            foreach (array_map('trim', explode('|', $c[1])) as $i => $part) {
                if ($i === 0) { $out['title'] = $part; continue; }
                if (stripos($part, 'License:') === 0) $out['licence'] = trim(substr($part, 8));
                elseif (stripos($part, 'Author:') === 0) {
                    $a = trim(substr($part, 7));
                    if (preg_match('~^(.*?)\s*\((https?://[^)\s]+)\)\s*$~', $a, $am)) { $a = $am[1]; $out['author_url'] = $am[2]; }
                    $out['author'] = $a;
                } elseif (stripos($part, 'Source:') === 0) $out['source'] = trim(substr($part, 7));
            }
        }
        if (preg_match('~<mxlibrary(?:\s+title="([^"]*)")?\s*>(.*)</mxlibrary>~s', $xml, $lm)) {
            if (($lm[1] ?? '') !== '') $out['title'] = html_entity_decode($lm[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $entries = json_decode(html_entity_decode($lm[2], ENT_QUOTES | ENT_XML1, 'UTF-8'), true);
            if (!is_array($entries)) {
                throw new ServiceError('upstream', 'upstream_format', 'The library file for this set could not be read.');
            }
            foreach ($entries as $e) {
                if (!is_array($e)) continue;
                $src = self::entryImage($e);
                if ($src === null) continue;
                $out['icons'][] = [
                    'title' => mb_substr(trim((string)($e['title'] ?? '')), 0, 255),
                    'tags'  => mb_substr(trim((string)($e['tags'] ?? '')), 0, 1000),
                    'w'     => max(8, min(1024, (int)($e['w'] ?? 64) ?: 64)),
                    'h'     => max(8, min(1024, (int)($e['h'] ?? 64) ?: 64)),
                    'src'   => $src,
                ];
            }
        }
        return $out;
    }

    /** The picture of one library entry: an icons.diagrams.net https URL or a data: image URI, or null. */
    private static function entryImage(array $e): ?string
    {
        $candidates = [];
        if (isset($e['data']) && is_string($e['data'])) $candidates[] = $e['data'];
        if (isset($e['xml']) && is_string($e['xml'])) {
            $x = html_entity_decode($e['xml'], ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (preg_match('~image=(data:image/[a-z+.-]+,[A-Za-z0-9+/=%]+|https?://[^;"\s]+)~', $x, $im)) $candidates[] = $im[1];
        }
        foreach ($candidates as $c) {
            if (strpos($c, 'data:image/') === 0) return $c;
            if (self::isSiteUrl($c)) return $c;
        }
        return null;
    }

    // ------------------------------------------------------------------ import

    /**
     * Starts importing a set: one library row (status 'importing') with its licence and author.
     * Returns {id, total}. Importing a set that is already here is refused.
     */
    public static function importStart(PDO $conn, ActorContext $ctx, string $slug): array
    {
        $lib = self::library($slug);
        if (!$lib['icons']) {
            throw new ServiceError('validation', 'invalid_field', 'This set has no icons that can be imported.');
        }
        $chk = $conn->prepare("SELECT id, status FROM network_icon_libraries WHERE slug = ?");
        $chk->execute([$lib['slug']]);
        if ($row = $chk->fetch(PDO::FETCH_ASSOC)) {
            if ($row['status'] === 'ready') {
                throw new ServiceError('conflict', 'conflict', 'This set is already imported.');
            }
            // An import that was interrupted carries on where it stopped.
            return ['id' => (int)$row['id'], 'total' => count($lib['icons'])];
        }
        $conn->prepare(
            "INSERT INTO network_icon_libraries (slug, title, licence, author, author_url, source, icon_total, icon_count, status, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'importing', UTC_TIMESTAMP())"
        )->execute([
            $lib['slug'], mb_substr($lib['title'], 0, 255), mb_substr($lib['licence'], 0, 255),
            mb_substr($lib['author'], 0, 255), mb_substr($lib['author_url'], 0, 500), mb_substr($lib['source'], 0, 500),
            count($lib['icons']),
        ]);
        return ['id' => (int)$conn->lastInsertId(), 'total' => count($lib['icons'])];
    }

    /**
     * Copies the next batch of icons (from $offset) into the library. Downloads run in parallel.
     * Icons that cannot be fetched or are not a safe image are skipped, not fatal. Returns
     * {next, total, imported, skipped, done}.
     */
    public static function importBatch(PDO $conn, int $libraryId, int $offset, int $limit = 80): array
    {
        $row = self::loadLibraryRow($conn, $libraryId);
        $lib = self::library($row['slug']);
        $total = count($lib['icons']);
        $limit = max(1, min(200, $limit));
        $slice = array_slice($lib['icons'], max(0, $offset), $limit, true);

        // data: URIs decode in place; https URLs download together through curl_multi.
        $bodies = [];
        $mh = curl_multi_init();
        $handles = [];
        foreach ($slice as $i => $icon) {
            if (strpos($icon['src'], 'data:') === 0) {
                $bodies[$i] = self::decodeDataUri($icon['src']);
                continue;
            }
            $ch = self::curlFor($icon['src'], self::MAX_ICON_BYTES);
            $handles[$i] = $ch;
            curl_multi_add_handle($mh, $ch);
        }
        if ($handles) {
            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 1.0);
            } while ($running && $status === CURLM_OK);
            foreach ($handles as $i => $ch) {
                $body = curl_multi_getcontent($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $bodies[$i] = ($code === 200 && is_string($body) && strlen($body) <= self::MAX_ICON_BYTES) ? $body : null;
                curl_multi_remove_handle($mh, $ch);
            }
        }
        curl_multi_close($mh);

        $ins = $conn->prepare(
            "INSERT IGNORE INTO network_icon_library_icons (library_id, sort, title, tags, mime, data, w, h) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $imported = 0; $skipped = 0;
        foreach ($slice as $i => $icon) {
            $safe = self::safeImage($bodies[$i] ?? null);
            if ($safe === null) { $skipped++; continue; }
            $ins->bindValue(1, $libraryId, PDO::PARAM_INT);
            $ins->bindValue(2, (int)$i, PDO::PARAM_INT);
            $ins->bindValue(3, $icon['title']);
            $ins->bindValue(4, $icon['tags']);
            $ins->bindValue(5, $safe['mime']);
            $ins->bindValue(6, $safe['data'], PDO::PARAM_LOB);
            $ins->bindValue(7, $icon['w'], PDO::PARAM_INT);
            $ins->bindValue(8, $icon['h'], PDO::PARAM_INT);
            $ins->execute();
            $imported++;
        }
        $next = min($total, max(0, $offset) + $limit);
        $done = $next >= $total;
        $cnt = $conn->prepare("SELECT COUNT(*) FROM network_icon_library_icons WHERE library_id = ?");
        $cnt->execute([$libraryId]);
        $conn->prepare("UPDATE network_icon_libraries SET icon_count = ?, status = ? WHERE id = ?")
             ->execute([(int)$cnt->fetchColumn(), $done ? 'ready' : 'importing', $libraryId]);
        return ['next' => $next, 'total' => $total, 'imported' => $imported, 'skipped' => $skipped, 'done' => $done];
    }

    // ------------------------------------------------------------------ the install's libraries

    /** Imported libraries (newest first) with how many diagram shapes use each. */
    public static function libraries(PDO $conn): array
    {
        $rows = $conn->query(
            "SELECT l.id, l.slug, l.title, l.licence, l.author, l.author_url, l.source, l.icon_total, l.icon_count, l.status, l.created_datetime,
                    (SELECT COUNT(*) FROM network_diagram_shapes s JOIN network_icon_library_icons i ON i.id = s.icon_id WHERE i.library_id = l.id) AS used
               FROM network_icon_libraries l
           ORDER BY l.title"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            foreach (['id', 'icon_total', 'icon_count', 'used'] as $k) $r[$k] = (int)$r[$k];
        }
        return $rows;
    }

    /** Every icon of every ready library (no image data) - the Shapes palette reads this once. */
    public static function paletteIcons(PDO $conn): array
    {
        $rows = $conn->query(
            "SELECT i.id, i.library_id, i.title, i.tags, i.w, i.h
               FROM network_icon_library_icons i
               JOIN network_icon_libraries l ON l.id = i.library_id AND l.status = 'ready'
           ORDER BY i.library_id, i.sort"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            foreach (['id', 'library_id', 'w', 'h'] as $k) $r[$k] = (int)$r[$k];
        }
        return $rows;
    }

    /**
     * Removes a library and its icons. Shapes on diagrams that used them keep their place and
     * size but lose the picture (icon_id becomes NULL through the foreign key).
     */
    public static function deleteLibrary(PDO $conn, int $libraryId): void
    {
        self::loadLibraryRow($conn, $libraryId);
        $conn->beginTransaction();
        try {
            $conn->prepare("UPDATE network_diagram_shapes s JOIN network_icon_library_icons i ON i.id = s.icon_id SET s.icon_id = NULL WHERE i.library_id = ?")
                 ->execute([$libraryId]);
            $conn->prepare("DELETE FROM network_icon_library_icons WHERE library_id = ?")->execute([$libraryId]);
            $conn->prepare("DELETE FROM network_icon_libraries WHERE id = ?")->execute([$libraryId]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /** One icon's picture: {mime, data} or null. */
    public static function iconData(PDO $conn, int $iconId): ?array
    {
        $st = $conn->prepare("SELECT mime, data FROM network_icon_library_icons WHERE id = ?");
        $st->execute([$iconId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    // ------------------------------------------------------------------ safety

    /**
     * Accepts PNG, JPEG, GIF, WebP (checked by their bytes) and SVG (cleaned). Returns
     * {mime, data} or null for anything else.
     */
    public static function safeImage(?string $body): ?array
    {
        if ($body === null || $body === '' || strlen($body) > self::MAX_ICON_BYTES) return null;
        $head = substr($body, 0, 16);
        if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) return ['mime' => 'image/png', 'data' => $body];
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) return ['mime' => 'image/jpeg', 'data' => $body];
        if (strncmp($head, 'GIF8', 4) === 0) return ['mime' => 'image/gif', 'data' => $body];
        if (strncmp($head, 'RIFF', 4) === 0 && substr($body, 8, 4) === 'WEBP') return ['mime' => 'image/webp', 'data' => $body];
        $svg = self::cleanSvg($body);
        return $svg === null ? null : ['mime' => 'image/svg+xml', 'data' => $svg];
    }

    /**
     * An SVG with everything that can run or reach out removed: script, foreignObject,
     * iframe/embed/object, every on* attribute, and any href that is not a local #fragment or
     * a data:image. Returns null when it is not an SVG document.
     */
    public static function cleanSvg(string $body): ?string
    {
        if (stripos($body, '<svg') === false) return null;
        if (preg_match('~<!DOCTYPE|<!ENTITY~i', $body)) return null;   // no entity tricks
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $ok = $doc->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || !$doc->documentElement || strtolower($doc->documentElement->localName) !== 'svg') return null;
        $xp = new DOMXPath($doc);
        $bad = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'audio', 'video', 'handler', 'listener', 'set', 'animate'];
        foreach (iterator_to_array($xp->query('//*')) as $el) {
            if (!$el->parentNode) continue;   // already gone with a removed parent
            if (in_array(strtolower($el->localName), $bad, true)) {
                $el->parentNode->removeChild($el);
                continue;
            }
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->name);
                $val = trim($attr->value);
                if (strpos($name, 'on') === 0) { $el->removeAttribute($attr->nodeName); continue; }
                if ($name === 'href' || $name === 'xlink:href' || substr($name, -5) === ':href') {
                    if ($val !== '' && $val[0] !== '#' && stripos($val, 'data:image/') !== 0) $el->removeAttribute($attr->nodeName);
                    continue;
                }
                if (preg_match('~javascript:|url\(\s*["\']?\s*(?!#)~i', $val)) $el->removeAttribute($attr->nodeName);
            }
        }
        foreach (iterator_to_array($xp->query('//*[local-name()="style"]')) as $st) {
            // A <style> element may pull outside resources through @import / url(): drop those.
            $st->textContent = preg_replace('~@import[^;]*;|url\(\s*["\']?\s*(?!#)[^)]*\)~i', '', $st->textContent);
        }
        $out = $doc->saveXML($doc->documentElement);
        return ($out !== false && strlen($out) <= self::MAX_ICON_BYTES) ? $out : null;
    }

    // ------------------------------------------------------------------ internals

    private static function checkSlug(string $slug): string
    {
        $slug = trim($slug);
        if (!preg_match('~^[A-Za-z0-9_.-]{1,120}$~', $slug) || strpos($slug, '..') !== false) {
            throw new ServiceError('validation', 'invalid_field', 'That is not an icon set name.');
        }
        return $slug;
    }

    private static function loadLibraryRow(PDO $conn, int $id): array
    {
        $st = $conn->prepare("SELECT * FROM network_icon_libraries WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Icon library not found.');
        return $row;
    }

    /** True for an https URL on icons.diagrams.net - the only host this service talks to. */
    private static function isSiteUrl(string $url): bool
    {
        $p = parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && strtolower($p['host'] ?? '') === 'icons.diagrams.net'
            && !isset($p['user']) && !isset($p['pass']) && (!isset($p['port']) || (int)$p['port'] === 443);
    }

    /** A cURL handle for an icons.diagrams.net URL: https only, no redirects, a size cap. */
    private static function curlFor(string $url, int $maxBytes)
    {
        if (!self::isSiteUrl($url)) {
            throw new ServiceError('validation', 'invalid_field', 'Only icons.diagrams.net can be fetched.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_USERAGENT      => 'FreeITSM Network Mapper',
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) use ($maxBytes) { return $dlNow > $maxBytes ? 1 : 0; },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        if (function_exists('sslApplyCurl')) sslApplyCurl($ch);
        return $ch;
    }

    private static function fetchSite(string $url, int $maxBytes): string
    {
        $ch = self::curlFor($url, $maxBytes);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($body === false || $err !== '') {
            throw new ServiceError('upstream', 'upstream_unreachable', 'Could not reach icons.diagrams.net' . ($err !== '' ? ': ' . $err : '.'));
        }
        if ($code === 404) throw new ServiceError('not_found', 'not_found', 'That icon set was not found on icons.diagrams.net.');
        if ($code !== 200) throw new ServiceError('upstream', 'upstream_status', "icons.diagrams.net answered HTTP $code.");
        return (string)$body;
    }

    private static function decodeDataUri(string $uri): ?string
    {
        if (!preg_match('~^data:image/[a-z+.-]+(;base64)?,(.*)$~is', $uri, $m)) return null;
        $payload = $m[2];
        // draw.io writes data:image/svg+xml,<base64> without ";base64" - try base64 first.
        $b = base64_decode(strtr($payload, ' ', '+'), true);
        if ($b !== false && $b !== '') return $b;
        return rawurldecode($payload);
    }

    /** A per-install cache file in the system temp folder (the catalogue and library files). */
    private static function cachePath(string $name): string
    {
        $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'freeitsm-icon-cache-' . substr(md5(__DIR__), 0, 10);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        return $dir . DIRECTORY_SEPARATOR . preg_replace('~[^A-Za-z0-9_.-]~', '_', $name);
    }
}
