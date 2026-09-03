<?php
/**
 * Database connection options — the app's own, never the operator's.
 *
 * 🔴 WHY THIS FILE EXISTS AT ALL. dbConnectionOptions() was born in config.php
 * (GH #126, #1446) and that broke every install on the planet — GH #129, HTTP 500
 * on every page, empty body. config.php is NOT ours. It ships as a template with a
 * developer's own db_config.php path baked in, so every operator edits it once and
 * then keeps their copy across upgrades; the Docker image goes further and copies
 * docker/config.php straight over it. Pulling the new code therefore brought the
 * eleven CALLERS of dbConnectionOptions() and left the DEFINITION behind, and PHP
 * met an undefined function before it could open a single connection.
 *
 * 🔑 THE RULE THIS ENCODES: nothing the app must execute may live in config.php.
 * That file is for VALUES the operator chooses — credentials, paths, switches.
 * Behaviour lives in includes/, which upgrades with the product. A function
 * defined in config.php is invisible to every install that kept its own.
 *
 * The definition is guarded by function_exists() rather than declared outright,
 * because installs upgrading from #1446 still have the copy in their config.php,
 * which every caller loads first. Theirs wins; this fills the hole for everyone
 * else. Remove the guard only once no supported install can still carry that copy.
 *
 * ---------------------------------------------------------------------------
 *
 * 🔴 THE DATABASE CONNECTION'S OWN CLOCK — read this before opening a PDO by hand.
 *
 * Every connection in the product MUST be opened with these options.
 *
 * MySQL evaluates `NOW()`, `CURRENT_TIMESTAMP` and `CURDATE()` in the CONNECTION'S
 * SESSION TIME ZONE, which defaults to `SYSTEM` — the server's own clock. That is
 * not UTC unless somebody happened to set the server to UTC, and FreeITSM stores
 * every instant in UTC.
 *
 * The two met in GH #126. A note's INSERT did not name `created_datetime`, so the
 * column's `DEFAULT CURRENT_TIMESTAMP` filled it in with a LOCAL WALL CLOCK, which
 * the screen then converted as if it were a UTC instant — putting every note the
 * server's own offset into the future. Two hours in Vienna, one in the UK in
 * summer, and nothing at all on a server running UTC, which is why it survived.
 *
 * 🔑 That was not one bug. A sweep of the schema against every INSERT found 302
 * statements letting one of 272 auto-stamped columns fire, across 220 tables. This
 * line fixes all of them at once, and every one written from here on: with the
 * session pinned to UTC, `CURRENT_TIMESTAMP` and `UTC_TIMESTAMP()` are the same
 * instant, so a forgotten column can no longer be wrong.
 *
 * ⚠️ INIT_COMMAND rather than an `exec()` after connecting, because it re-runs on
 * an automatic reconnect. A SET issued once by hand is lost the moment the client
 * silently reopens the socket, and nothing announces that it has.
 *
 * ⚠️ WHAT THIS DOES NOT DO: existing rows are untouched. Nothing records which of
 * the 302 routes wrote a given row, and the tables hold rows from both, so a
 * migration would have to guess — and every wrong guess moves a row that was
 * already right. Same call as GH #116.
 *
 * ⚠️ AND THE ONE THING TO WATCH: a few columns are stored as NAIVE WALL CLOCKS on
 * purpose — change windows, scheduled work, PIR actuals — so that "2pm" reads 2pm
 * for everybody. Those must be compared against a wall clock, never against this
 * connection's now. See includes/timezone.php and the Timezones-and-Time-Handling
 * page; the queries that do it are marked.
 */
if (!function_exists('dbConnectionOptions')) {
    function dbConnectionOptions(): array
    {
        return [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00'",
        ];
    }
}
