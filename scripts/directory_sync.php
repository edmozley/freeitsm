<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Moved to cron/directory_sync.php, with the other scheduled tasks.
 *
 * 🔴 DO NOT DELETE. Release 1.8.0 told administrators to schedule
 * `php scripts/directory_sync.php --all`, and their cron entries and Windows
 * scheduled tasks still say exactly that. Removing this file would stop every
 * one of those imports without a word - the task would fail where nobody looks.
 * Same arguments, same output, same exit codes: it simply runs the real script.
 */
require __DIR__ . '/../cron/directory_sync.php';
