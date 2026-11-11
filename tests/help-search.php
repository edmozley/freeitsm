<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The help assistant's index and word search (includes/help_index.php, wiki
 * Help-Assistant-Design step 2).
 *
 * Part 1, no database: reading sections out of rendered help HTML (blocks, cards
 * not glued to their bodies, data-cap, data-help-search="off"), the search words
 * (stop words, the light English trim), passages, and BM25 - including the case
 * that made passages necessary: one card in a very long section.
 *
 * Part 2, FREEITSM_TEST_DATABASE (a throwaway install, after Database Verify):
 * - builds the index in English, German and Spanish through the real renderer
 * - the contract: every help page gives sections, each with a title
 * - an unchanged rebuild changes nothing
 * - a ZZ analyst with only Tickets gets only Tickets sections (removed in finally)
 * - THE QUESTION SET: realistic questions, each with the section(s) that answer
 *   it, scored as "is a right section in the top 5". Plain questions and exact
 *   names must reach their floor; vague questions and other languages are
 *   reported, not failed - they are what vectors (step 4) and the AI (step 5) are
 *   for, and their numbers are the baseline those steps must beat.
 *
 *   php tests/help-search.php
 *   FREEITSM_TEST_DATABASE=<the throwaway db> php tests/help-search.php
 *
 * @wiki Help-Assistant-Design
 */
chdir(__DIR__ . '/..');
require_once 'includes/help_index.php';

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $what\n"; }
    else     { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? " - $detail" : '') . "\n"; }
}

// ─── Part 1 ─────────────────────────────────────────────────────────────────
echo "Reading sections from a rendered page\n";
$html = '<html><body><nav>Not a section</nav>
  <div class="help-section" id="settings" data-cap="TICKETS_SETTINGS">
    <div class="help-section-header"><span class="help-section-num">10</span><div><h3>Settings</h3><p>Tune the module.</p></div></div>
    <div class="help-cards"><div class="help-card"><strong>Departments</strong><span>Create departments like IT.</span></div>
    <div class="help-card"><strong>Types</strong><span>Incident or request.</span></div></div>
    <script>var x = 1;</script>
  </div>
  <div class="help-section" id="hidden" data-help-search="off"><h3>Internal</h3><p>Skip me.</p></div>
  <div class="help-section"><h3>No id</h3></div></body></html>';
$secs = helpRenderSections($html);
check('only sections with an id are read', count($secs) === 2, json_encode(array_column($secs, 'id')));
check('the title is the h3; the number badge is not in it', ($secs[0]['title'] ?? '') === 'Settings');
check('each card is one block, its title not glued to its body',
    str_contains($secs[0]['text'] ?? '', "Departments Create departments like IT.") && substr_count($secs[0]['text'] ?? '', "\n\n") >= 3, json_encode($secs[0]['text'] ?? ''));
check('scripts and the number badge are left out', !str_contains($secs[0]['text'] ?? '', 'var x') && !str_contains($secs[0]['text'] ?? '', '10'));
check('data-cap is carried', ($secs[0]['cap'] ?? null) === 'TICKETS_SETTINGS');
check('data-help-search="off" is marked to skip', ($secs[1]['skip'] ?? false) === true);

echo "\nSearch words\n";
check('stop words go, plurals and -ing are trimmed', helpTokens('How do I create ticketing departments?') === ['creat', 'ticket', 'department'], json_encode(helpTokens('How do I create ticketing departments?')));
check('statuses and status agree; categories -> category', helpStem('statuses') === 'status' && helpStem('categories') === 'category' && helpStem('status') === 'status');
$same = fn(string ...$w) => count(array_unique(array_map('helpStem', $w))) === 1;
check('word forms meet: add/adding/added, create/created/creating, route/routing, breach/breached, setting/settings',
    $same('add', 'adding', 'added', 'adds') && $same('create', 'created', 'creating', 'creates') && $same('route', 'routing', 'routes')
    && $same('breach', 'breached', 'breaches') && $same('setting', 'settings'));
check('...but class, analysis and business are left alone', helpStem('class') === 'class' && helpStem('analysis') === 'analysis' && helpStem('business') === 'business');
check('non-English words are only lower-cased', helpTokens('Abteilung Részleget') === ['abteilung', 'részleget']);

echo "\nPassages and ranking\n";
$long = implode("\n\n", array_map(fn($i) => "Card $i: something about priorities, statuses, categories and service levels for tickets number $i.", range(1, 40)))
      . "\n\nDepartments Create departments like IT, HR and Finance. Each department becomes a folder in the inbox.";
$p = helpPassages($long);
$blocks = explode("\n\n", $long);
$whole = true;
foreach ($p as $x) foreach (explode("\n\n", $x) as $part) if (!in_array($part, $blocks, true)) $whole = false;
check('a long section becomes several passages, each made of whole blocks', count($p) > 3 && $whole && count(explode("\n\n", implode("\n\n", $p))) === count($blocks));
check('...about 700 characters each', max(array_map('mb_strlen', $p)) <= 700);
$sections = [
    ['section_key' => 'a#settings', 'title' => 'Settings', 'body' => $long, 'module' => 'tickets'],
    ['section_key' => 'b#teams', 'title' => 'Teams', 'body' => 'A team member sees ticket folders for the departments the team is linked to.', 'module' => 'system'],
    ['section_key' => 'c#other', 'title' => 'Other', 'body' => 'Nothing relevant here at all, only assets.', 'module' => 'assets'],
];
$r = helpRank($sections, 'how do I create a department');
check('one card in a very long section still wins - passages, not whole sections', ($r[0]['section_key'] ?? '') === 'a#settings', json_encode(array_column($r, 'score', 'section_key')));
check('...with that card as the snippet', str_contains($r[0]['snippet'] ?? '', 'Create departments'));
check('a section with none of the words is not returned', !in_array('c#other', array_column($r, 'section_key'), true));
$tie = [['section_key' => 'x', 'title' => 'Folders', 'body' => 'folders folders', 'module' => 'tickets'],
        ['section_key' => 'y', 'title' => 'Folders', 'body' => 'folders folders', 'module' => 'files']];
check('the module the asker is in breaks a tie', (helpRank($tie, 'folders', 'files')[0]['section_key'] ?? '') === 'y');
check('a question of only stop words finds nothing', helpRank($sections, 'how do I do it') === []);

// ─── Part 2 ─────────────────────────────────────────────────────────────────
$expected = getenv('FREEITSM_TEST_DATABASE');
if ($expected) {
    require_once 'config.php';
    require_once 'includes/functions.php';
    $conn = connectToDatabase();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
        echo "\n  (skipped the database checks: config.php does not use $expected)\n";
    } elseif (!$conn->query("SHOW TABLES LIKE 'help_sections'")->fetch()) {
        check('help_sections exists - run Database Verify first', false);
    } else {
        echo "\nBuilding the index (English, German, Spanish)\n";
        $t = microtime(true);
        $stats = helpIndexBuild($conn, ['de', 'es']);
        printf("  (%d pages, %d sections, %.0fs)\n", $stats['pages'], $stats['sections'], microtime(true) - $t);
        check('every help page rendered', !$stats['failed'], implode(', ', $stats['failed']));
        $pages = array_column(helpIndexPages(getcwd()), 'page');
        $have = $conn->query("SELECT DISTINCT page FROM help_sections WHERE locale = 'en'")->fetchAll(PDO::FETCH_COLUMN);
        $off = array_filter(helpIndexPages(getcwd()), fn($p) => $p['module'] !== 'system' && !moduleIsEnabled($conn, $p['module']));
        $missing = array_diff($pages, $have, array_column($off, 'page'));
        check('the contract: every help page gives at least one section', !$missing, implode(', ', $missing));
        check('every section has a title', (int)$conn->query("SELECT COUNT(*) FROM help_sections WHERE title = ''")->fetchColumn() === 0);
        check('German sections are stored where the text is German',
            (int)$conn->query("SELECT COUNT(*) FROM help_sections WHERE locale = 'de'")->fetchColumn() > 100);
        check('...and untranslated System help is not copied as "German"',
            (int)$conn->query("SELECT COUNT(*) FROM help_sections WHERE locale = 'de' AND page LIKE 'system/help/%'")->fetchColumn() === 0);
        $again = helpIndexBuild($conn, ['de', 'es']);
        check('an unchanged rebuild changes nothing', $again['added'] + $again['changed'] + $again['removed'] === 0, json_encode($again));

        echo "\nOnly modules the analyst can open\n";
        $zz = null;
        try {
            $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_modules) VALUES (?, '!', 'ZZ help search', ?, 1, 0, 0)")
                 ->execute(['zzhelp' . bin2hex(random_bytes(3)), 'zzhelp@example.invalid']);
            $zz = (int)$conn->lastInsertId();
            $conn->prepare("INSERT INTO analyst_modules (analyst_id, module_key) VALUES (?, 'tickets')")->execute([$zz]);
            $mods = array_unique(array_column(helpSectionsFor($conn, $zz), 'module'));
            check('an analyst with only Tickets is searched over Tickets help only', $mods === ['tickets'], json_encode(array_values($mods)));
            check('...and a System question finds nothing for them',
                !array_filter(helpSearch($conn, $zz, 'reset an analyst password'), fn($s) => $s['module'] === 'system'));
            $adminMods = array_unique(array_column(helpSectionsFor($conn, helpIndexRenderAnalyst($conn)), 'module'));
            check('control: an administrator is searched over every module, System included', count($adminMods) > 15 && in_array('system', $adminMods, true));
        } finally {
            if ($zz) {
                $conn->prepare("DELETE FROM analyst_modules WHERE analyst_id = ?")->execute([$zz]);
                $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$zz]);
            }
        }

        echo "\nThe question set (a right section in the top 5)\n";
        $admin = helpIndexRenderAnalyst($conn);
        $known = array_flip($conn->query("SELECT section_key FROM help_sections WHERE locale = 'en'")->fetchAll(PDO::FETCH_COLUMN));
        $T = 'tickets/help.php#'; $S = 'system/help/';
        // [kind, locale, question, the module asked from (or null), right answers...]
        $questions = [
            ['plain', 'en', 'how do I create a ticketing department', null, $T . 'settings', $S . 'teams.php#departments'],
            ['plain', 'en', "reset an analyst's password", null, $S . 'analysts.php#passwords'],
            ['plain', 'en', 'add a new analyst', null, $S . 'analysts.php#adding'],
            ['plain', 'en', 'import assets from a spreadsheet', null, 'asset-management/help-custom-fields.php#import'],
            ['plain', 'en', 'print QR code labels for our laptops', null, 'asset-management/help.php#asset-labels'],
            ['plain', 'en', 'what is a CAB and when do I need one', null, 'change-management/help.php#cab'],
            ['plain', 'en', 'how do I do a root cause analysis', null, 'problem-management/help.php#rca'],
            ['plain', 'en', 'difference between a problem and an incident', null, 'problem-management/help.php#vs-incidents'],
            ['plain', 'en', 'reopen a closed ticket when the requester replies', null, $T . 'settings'],
            ['plain', 'en', 'email someone when an SLA is breached', null, 'tickets/help-sla.php#breach-notifications'],
            ['plain', 'en', 'connect a Microsoft 365 mailbox', null, 'tickets/help-mailbox-auth.php#azure-setup', 'tickets/help-mailbox-auth.php#add-mailbox', 'tickets/help-mailbox-auth.php#overview'],
            ['plain', 'en', 'collect email from a Google Workspace mailbox', null, 'tickets/help-mailbox-auth.php#google'],
            ['plain', 'en', 'add a signature to email templates', null, 'tickets/help-email-templates.php#signatures'],
            ['plain', 'en', 'which merge codes can I use in a template', null, 'tickets/help-email-templates.php#merge-codes'],
            ['plain', 'en', 'send a customer satisfaction survey', null, $T . 'csat'],
            ['plain', 'en', 'make a ticket confidential', null, $T . 'confidential'],
            ['plain', 'en', 'connect WhatsApp to tickets', null, $T . 'whatsapp'],
            ['plain', 'en', 'show my tickets in my Outlook calendar', null, 'tickets/help-calendar-sync.php#connection', 'tickets/help-calendar-sync.php#choices', 'tickets/help-calendar-sync.php#overview', 'tickets/help-calendar-sync.php#switch-on'],
            ['plain', 'en', 'get a knowledge article approved before it is published', null, 'knowledge/help.php#review-workflow'],
            ['plain', 'en', 'who can see a knowledge article', null, 'knowledge/help.php#permissions'],
            ['plain', 'en', 'ask AI a question about the knowledge base', null, 'knowledge/help.php#ask-ai'],
            ['plain', 'en', 'run a workflow when a ticket is created', null, 'workflow/help.php#triggers', 'workflow/help.php#anatomy', 'workflow/help.php#templates'],
            ['plain', 'en', 'post to Slack when a ticket comes in', null, 'workflow/help-webhooks.php#quickstart', $S . 'integrations.php#slack'],
            ['plain', 'en', 'sign webhook payloads with HMAC', null, 'workflow/help-webhooks.php#signing', $S . 'webhooks.php#security'],
            ['plain', 'en', 'repeat a task every week', null, 'tasks/help.php#repeats'],
            ['plain', 'en', 'upload a SCORM course', null, 'lms/help.php#scorm', 'lms/help.php#uploading'],
            ['plain', 'en', 'remind people to finish their training', null, 'lms/help.php#reminders'],
            ['plain', 'en', 'track software licences', null, 'software/help.php#licences'],
            ['plain', 'en', 'how is project health worked out', null, 'projects/help.php#health'],
            ['plain', 'en', 'compare a project budget with actual spend', null, 'projects/help.php#budget'],
            ['plain', 'en', 'warn me before a domain certificate expires', null, 'domains/help.php#certs', 'domains/help.php#alerts'],
            ['plain', 'en', 'find look-alike phishing domains', null, 'domains/help.php#lookalikes'],
            ['plain', 'en', 'who can open a file in Files', null, 'files/help.php#permissions'],
            ['plain', 'en', 'draw a network diagram', null, 'network-mapper/help.php#creating', 'network-mapper/help.php#overview'],
            ['plain', 'en', 'create an API key', null, $S . 'api.php#keys'],
            ['plain', 'en', 'connect an AI assistant over MCP', null, $S . 'api.php#mcp'],
            ['plain', 'en', 'turn on HTTPS in Docker', null, $S . 'docker.php#steps'],
            ['plain', 'en', 'back up the encryption key', null, $S . 'encryption.php#backup'],
            ['plain', 'en', 'lock an account after failed sign-ins', null, $S . 'security.php#lockout'],
            ['plain', 'en', 'restrict which modules an analyst can use', null, $S . 'modules.php#matrix', $S . 'analysts.php#access', $S . 'teams.php#modules'],
            ['plain', 'en', 'change my interface language', null, $S . 'preferences.php#language'],
            ['plain', 'en', 'run database verification after upgrading', null, $S . 'db-verify.php#running', $S . 'db-verify.php#overview'],
            ['plain', 'en', 'remove the demo data', null, $S . 'demo-data.php#remove'],
            ['plain', 'en', "route email from a customer's domain to their company", null, $S . 'companies.php#email'],
            ['plain', 'en', 'raise a Jira issue from a ticket', null, 'system/integrations/help.php?provider=jira#raise', 'system/integrations/help.php?provider=jira#overview'],
            ['plain', 'en', 'turn Zabbix alerts into incidents', null, 'system/integrations/help.php?provider=zabbix#overview', 'system/integrations/help.php?provider=zabbix#mediatype', 'system/integrations/help.php?provider=zabbix#action'],
            ['plain', 'en', 'score supplier responses to a tender', null, 'contracts/rfp-builder/help.php#p5', 'contracts/rfp-builder/help.php#multi-analyst'],
            ['vague', 'en', 'someone left the company - what happens to their tickets', null, $S . 'analysts.php#leavers', 'starters/help.php#leaving'],
            ['vague', 'en', "my colleague can't see the Assets menu", null, $S . 'modules.php#effect', $S . 'modules.php#matrix', $S . 'analysts.php#access', $S . 'teams.php#modules'],
            ['vague', 'en', 'my boss wants a weekly PDF of how we are doing', null, 'reporting/help.php#report-packs'],
            ['vague', 'en', 'the clock on a ticket keeps running over the weekend', null, 'tickets/help-sla.php#building-blocks', 'tickets/help-sla.php#behaviour-settings'],
            ['vague', 'en', 'tickets nobody is looking after', null, $S . 'orphaned-tickets.php#overview', $S . 'orphaned-tickets.php#cause'],
            ['vague', 'en', 'stop the little pop-ups in the corner', null, $S . 'preferences.php#toasts'],
            ['exact', 'en', 'Feature Bingo', null, $S . 'feature-bingo.php#overview'],
            ['exact', 'en', 'Warbot', 'war-room', 'war-room/help.php#warbot'],
            ['exact', 'en', 'MoSCoW', null, 'projects/help.php#scope'],
            ['exact', 'en', 'RACI', null, 'projects/help.php#raci'],
            ['exact', 'en', 'Enforce approvals', null, 'change-management/help.php#evidence'],
            ['exact', 'en', 'CardDAV', null, $S . 'sso.php#carddav', $S . 'sso.php#carddav-setup', 'tickets/help-calendar-sync.php#caldav'],
            ['lang', 'de', 'Wie erstelle ich eine Abteilung?', null, $T . 'settings', $S . 'teams.php#departments'],
            ['lang', 'de', 'Wie sende ich eine Zufriedenheitsumfrage?', null, $T . 'csat'],
            ['lang', 'es', '¿Cómo envío una encuesta de satisfacción?', null, $T . 'csat'],
            ['lang', 'es', 'Restablecer la contraseña de un analista', null, $S . 'analysts.php#passwords'],
            ['lang', 'en', 'Hogyan hozzak létre jegykezelő részleget?', null, $T . 'settings', $S . 'teams.php#departments'],
            ['lang', 'en', 'Jak zresetować hasło analityka?', null, $S . 'analysts.php#passwords'],
        ];
        $byKind = [];
        $sectionsCache = [];
        foreach ($questions as [$kind, $locale, $q, $module]) {
            $right = array_slice($questions[array_search([$kind, $locale, $q], array_map(fn($x) => array_slice($x, 0, 3), $questions), true)], 4);
            foreach ($right as $k) if (!isset($known[$k])) check("the question set names a real section: $k", false);
            $sectionsCache[$locale] ??= helpSectionsFor($conn, $admin, $locale);
            $got = array_column(helpRank($sectionsCache[$locale], $q, $module, 5), 'section_key');
            $hit = (bool)array_intersect($right, $got);
            $byKind[$kind][] = $hit;
            if (!$hit) echo "    miss ($kind): $q\n      got: " . implode(', ', array_slice($got, 0, 3)) . "\n";
        }
        $floor = ['plain' => 0.80, 'exact' => 0.80];
        foreach ($byKind as $kind => $hits) {
            $rate = array_sum($hits) / count($hits);
            $line = sprintf('%-6s %2d/%2d in the top 5 (%3.0f%%)', $kind, array_sum($hits), count($hits), $rate * 100);
            if (isset($floor[$kind])) check("$line - floor " . ($floor[$kind] * 100) . '%', $rate >= $floor[$kind]);
            else echo "  [INFO] $line - baseline for vectors and the AI to beat\n";
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
