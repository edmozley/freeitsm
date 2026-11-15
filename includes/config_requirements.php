<?php
/**
 * What config.php is expected to define - the ONE list (GH #129).
 *
 * config.php is the operator's file: it is edited once and kept, so an upgrade
 * never adds a line to it. Debug tool D017 reads this list to tell an operator
 * whether their copy is missing anything, and tests/config-not-load-bearing.php
 * asserts the list matches both shipped templates (config.php and
 * docker/config.php) and that every "fallback" really has one in shipped code.
 * Add a constant to a template without adding it here and that test fails.
 *
 * 🔴 NOTHING HERE, AND NOTHING THAT READS IT, EVER PRINTS A VALUE. Names and
 * present/absent only - docker/config.php defines a real DB_PASSWORD.
 *
 * kind:
 *   required  - the app cannot work without it; there is no default.
 *   fallback  - shipped code supplies a default when config.php does not.
 *   optional  - off / unset is a normal, supported state.
 *
 * where:    which file normally defines it.
 * fallback: (fallback/optional) the shipped file that copes when it is absent.
 * without:  what happens when it is absent, in plain words.
 * add:      the line(s) to add, with a placeholder - never a real value.
 */

if (!function_exists('configRequirements')) {
    /**
     * Returns every constant config.php is expected to define, keyed by name, with its kind, where it lives, any fallback, what happens without it and the line to add.
     */
    function configRequirements(): array
    {
        $db = [
            'kind'    => 'required',
            'where'   => 'db_config.php (loaded by config.php from outside the web root)',
            'without' => 'No page can reach the database, so nothing works - including this tool.',
        ];
        return [
            'DB_SERVER'   => $db + ['add' => "define('DB_SERVER', 'localhost');"],
            'DB_NAME'     => $db + ['add' => "define('DB_NAME', 'freeitsm');"],
            'DB_USERNAME' => $db + ['add' => "define('DB_USERNAME', 'your-user');"],
            'DB_PASSWORD' => $db + ['add' => "define('DB_PASSWORD', 'your-password');"],
            'BASE_URL' => [
                'kind'     => 'fallback',
                'where'    => 'config.php',
                'fallback' => 'includes/base_url.php',
                'without'  => 'Worked out from where the app sits under the web root. Set it only if links point at the wrong path (for example behind a proxy that serves the app under a different folder).',
                'add'      => "define('BASE_URL', '/helpdesk/');",
            ],
            'SSL_VERIFY_PEER' => [
                'kind'     => 'fallback',
                'where'    => 'config.php',
                'fallback' => 'includes/ssl.php',
                'without'  => 'Certificate checking stays ON, which is the safe default.',
                'add'      => "define('SSL_VERIFY_PEER', true);",
            ],
            'SSL_CA_BUNDLE' => [
                'kind'     => 'fallback',
                'where'    => 'config.php',
                'fallback' => 'includes/ssl.php',
                'without'  => 'The certificate bundle is found automatically each time it is needed (php.ini, then includes/cacert.pem). Debug tool D006 shows which one wins.',
                'add'      => "require_once(__DIR__ . '/includes/ssl.php');\nif (!defined('SSL_CA_BUNDLE')) {\n    define('SSL_CA_BUNDLE', sslResolveCaBundle());\n}",
            ],
            'ENCRYPTION_KEY_PATH' => [
                'kind'     => 'optional',
                'where'    => 'config.php (or the ENCRYPTION_KEY_PATH environment variable)',
                'fallback' => 'includes/encryption.php',
                'without'  => 'The key is kept in the standard place outside the web root. Set it only to keep the key somewhere else.',
                'add'      => "define('ENCRYPTION_KEY_PATH', '/your/path/encryption_keys/freeitsm.key');",
            ],
            'TRUST_PROXY_HTTPS' => [
                'kind'     => 'optional',
                'where'    => 'config.php',
                'fallback' => 'includes/session_security.php',
                'without'  => 'Off. Turn it on only when a reverse proxy you control ends HTTPS in front of the app and is the only way in.',
                'add'      => "define('TRUST_PROXY_HTTPS', true);",
            ],
            // docker/config.php defines it when FREEITSM_DISPLAY_ERRORS=1 is in the
            // environment (#2611); a hand install puts it in db_config.php.
            'FREEITSM_DISPLAY_ERRORS' => [
                'kind'     => 'optional',
                'where'    => 'db_config.php (or FREEITSM_DISPLAY_ERRORS=1 in the environment on Docker)',
                'fallback' => 'config.php',
                'without'  => 'Off. Errors are logged, never shown in a page. Turn it on only on a development box: a warning printed into a page names server paths.',
                'add'      => "define('FREEITSM_DISPLAY_ERRORS', true);",
            ],
        ];
    }
}

if (!function_exists('configFileShape')) {
    /**
     * What a config file DECLARES, read with the tokeniser so commented-out
     * lines do not count. Returns names only: the constants it define()s and
     * the functions it declares. Never values.
     *
     * @param string $path  filesystem path of the PHP config file to read; unreadable gives readable => false
     */
    function configFileShape(string $path): array
    {
        $out = ['readable' => false, 'defines' => [], 'functions' => []];
        $src = @file_get_contents($path);
        if ($src === false) return $out;
        $out['readable'] = true;
        $toks = array_values(array_filter(token_get_all($src), function ($t) {
            return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
        }));
        $n = count($toks);
        for ($i = 0; $i < $n; $i++) {
            $t = $toks[$i];
            if (!is_array($t)) continue;
            if ($t[0] === T_STRING && strtolower($t[1]) === 'define'
                && ($toks[$i + 1] ?? null) === '('
                && is_array($toks[$i + 2] ?? null) && $toks[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING) {
                $out['defines'][] = trim($toks[$i + 2][1], "'\"");
            }
            if ($t[0] === T_FUNCTION && is_array($toks[$i + 1] ?? null) && $toks[$i + 1][0] === T_STRING) {
                $out['functions'][] = $toks[$i + 1][1];
            }
        }
        $out['defines']   = array_values(array_unique($out['defines']));
        $out['functions'] = array_values(array_unique($out['functions']));
        return $out;
    }
}

if (!function_exists('configRequirementsReport')) {
    /**
     * Compare the running state and the operator's config.php with the list.
     * Each row: name, kind, defined (bool), inConfig (bool), status
     * ('ok' | 'default' | 'problem'). Names and booleans only.
     *
     * @param string $configPath  filesystem path of the operator's config.php
     */
    function configRequirementsReport(string $configPath): array
    {
        $shape = configFileShape($configPath);
        $rows = [];
        $problems = 0;
        foreach (configRequirements() as $name => $req) {
            $defined  = defined($name);
            $inConfig = in_array($name, $shape['defines'], true);
            if ($req['kind'] === 'required') {
                $status = $defined ? 'ok' : 'problem';
            } else {
                $status = $inConfig ? 'ok' : 'default';
            }
            if ($status === 'problem') $problems++;
            $rows[] = ['name' => $name, 'kind' => $req['kind'], 'defined' => $defined,
                       'inConfig' => $inConfig, 'status' => $status];
        }
        $known = array_keys(configRequirements());
        return [
            'readable'  => $shape['readable'],
            'rows'      => $rows,
            'problems'  => $problems,
            'unknown'   => array_values(array_diff($shape['defines'], $known)),
            'functions' => $shape['functions'],
        ];
    }
}
