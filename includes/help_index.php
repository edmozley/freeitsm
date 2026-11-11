<?php
/**
 * The help assistant's index and word search (wiki Help-Assistant-Design, step 2).
 *
 * BUILD: every help page is rendered once per language (scripts/help_render_page.php,
 * one process per page) and its sections - the house-style
 * <div class="help-section" id="..."> blocks - are stored in help_sections. The
 * table is only a cache: the pages and language files stay the truth.
 *
 * SEARCH: helpSearch() ranks sections for a question with BM25, the classic
 * search-engine scoring - rare words count for more, a word in a title counts
 * extra, a long section does not win just by being long. The ranking itself,
 * helpRank(), is pure (arrays in, arrays out) so it is tested without a database.
 *
 * Vectors (step 4) and the AI (step 5) build on this; nothing here needs a key.
 */

require_once __DIR__ . '/i18n.php';

/**
 * Help pages never indexed: the self-service portal's guide and Help Centre are
 * for requesters, not analysts (the assistant is for analysts - a decision on wiki
 * Help-Assistant-Design). Named here so leaving them out is on purpose; a page with
 * no requireModuleAccess() is skipped anyway.
 */
const HELP_INDEX_EXCLUDE = ['self-service/help.php', 'self-service/help-centre.php'];

/**
 * Help pages the folder scan cannot find on its own. The integrations guide is
 * ONE page for every provider, chosen by ?provider= (it redirects without one),
 * and System-only. Tika is left out: the page has no Tika sections and would show
 * the issue-tracker ones - System help -> Integrations covers it.
 */
const HELP_INDEX_EXTRA = [
    ['page' => 'system/integrations/help.php?provider=jira',        'module' => 'system'],
    ['page' => 'system/integrations/help.php?provider=azuredevops', 'module' => 'system'],
    ['page' => 'system/integrations/help.php?provider=slack',       'module' => 'system'],
    ['page' => 'system/integrations/help.php?provider=msteams',     'module' => 'system'],
    ['page' => 'system/integrations/help.php?provider=threecx',     'module' => 'system'],
    ['page' => 'system/integrations/help.php?provider=zabbix',      'module' => 'system'],
];

/** A title word counts this many times over a body word. */
const HELP_TITLE_WEIGHT = 3;

/** The score of a section in the module the asker is in is multiplied by this - a tie-breaker, not a filter. */
const HELP_MODULE_BOOST = 1.15;

// ─── The pages ───────────────────────────────────────────────────────────────

/**
 * Every help page and the module that guards it - the module key from the page's
 * own requireModuleAccess('...'), or 'system' for System help (admins only).
 *
 * @param string $root  the FreeITSM folder
 * @return list<array{page:string,module:string}>  pages relative to $root, sorted
 */
function helpIndexPages(string $root): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $pages = [];
    // A module's help, one or two folders down (contracts/rfp-builder/help.php).
    foreach (array_merge(glob("$root/*/help*.php") ?: [], glob("$root/*/*/help*.php") ?: []) as $f) {
        $rel = substr(str_replace('\\', '/', $f), strlen($root) + 1);
        if (in_array($rel, HELP_INDEX_EXCLUDE, true)) continue;
        $src = (string)file_get_contents($f);
        if (!preg_match("/requireModuleAccess\\(\\s*'([a-z0-9_-]+)'/", $src, $m)) continue;   // not a module help page
        $pages[] = ['page' => $rel, 'module' => $m[1]];
    }
    foreach (glob("$root/system/help/*.php") ?: [] as $f) {
        $base = basename($f);
        if ($base[0] === '_' || $base === 'index.php') continue;
        $pages[] = ['page' => 'system/help/' . $base, 'module' => 'system'];
    }
    foreach (HELP_INDEX_EXTRA as $p) {
        if (is_file("$root/" . strtok($p['page'], '?'))) $pages[] = $p;
    }
    usort($pages, fn($a, $b) => strcmp($a['page'], $b['page']));
    return $pages;
}

/**
 * An analyst the renderer can sign in as: active, an administrator, with every
 * module - so each page's own gate lets it through. Nothing is written for them.
 */
function helpIndexRenderAnalyst(PDO $conn): ?int
{
    $ids = $conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND COALESCE(is_active, 1) = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        if (getAnalystAllowedModules($conn, (int)$id) === null) return (int)$id;
    }
    return null;
}

/**
 * Render one page in one language through scripts/help_render_page.php.
 *
 * @param string $php  the PHP command-line program
 * @return ?list<array{id:string,title:string,text:string,cap:?string,skip:bool}>  null when the render failed
 */
function helpIndexRenderPage(string $root, string $php, string $page, string $locale, int $analystId): ?array
{
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg("$root/scripts/help_render_page.php") . ' '
         . escapeshellarg($page) . ' ' . escapeshellarg($locale) . ' ' . (int)$analystId;
    $out = [];
    exec($cmd . ' 2>' . (stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'NUL' : '/dev/null'), $out, $code);
    $last = '';
    for ($i = count($out) - 1; $i >= 0; $i--) { if (trim($out[$i]) !== '') { $last = $out[$i]; break; } }
    $json = json_decode($last, true);
    return (is_array($json) && !empty($json['ok'])) ? $json['sections'] : null;
}

/**
 * The languages worth indexing besides English: those analysts have chosen as
 * their interface language (Preferences), and the install's default. An index in
 * a language nobody uses is wasted work.
 *
 * @return list<string>
 */
function helpIndexLocalesInUse(PDO $conn): array
{
    $have = $conn->query("SELECT DISTINCT preference_value FROM user_preferences WHERE preference_key = 'interface_language'")->fetchAll(PDO::FETCH_COLUMN);
    $have[] = I18n::DEFAULT_LOCALE;
    return array_values(array_filter(array_unique($have), fn($l) => $l !== 'en' && array_key_exists($l, I18n::getSupportedLocales())));
}

/** The PHP command-line program to render pages with. */
function helpIndexPhp(): string
{
    if (PHP_SAPI === 'cli' && PHP_BINARY) return PHP_BINARY;
    require_once __DIR__ . '/intune.php';   // intuneFindPhpExe(): the web server's PHP is not a CLI one
    return intuneFindPhpExe(null);
}

// ─── Reading a rendered page ─────────────────────────────────────────────────

/**
 * The sections of a rendered help page. A section is the house-style markup every
 * help page already uses (wiki Help-Page-House-Style):
 *
 *   <div class="help-section" id="settings" [data-cap="TICKETS_SETTINGS"] [data-help-search="off"]>
 *       ... <h3>Title</h3> ... the text ...
 *   </div>
 *
 * - id: the anchor the answer links to
 * - title: its first h3
 * - text: one line per block - paragraph, card, step, note, list item, table row -
 *   joined by a blank line, so the search can cut it into passages without splitting
 *   a card (helpPassages()). The number badge, scripts and styles are left out.
 * - cap: data-cap, the capability (Cap enum) the section describes, or null
 * - skip: data-help-search="off" - never indexed
 *
 * @param string $html  the whole rendered page
 * - intro: true for the one extra entry for the page's introduction (id '')
 *
 * @return list<array{id:string,title:string,text:string,cap:?string,skip:bool,intro:bool}>
 */
function helpRenderSections(string $html): array
{
    if (trim($html) === '') return [];
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $out = [];
    foreach ($xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " help-section ")][@id]') as $sec) {
        /** @var DOMElement $sec */
        foreach (iterator_to_array($xp->query('.//script|.//style|.//*[contains(concat(" ", normalize-space(@class), " "), " help-section-num ")]', $sec)) as $drop) {
            $drop->parentNode->removeChild($drop);
        }
        $h3 = $xp->query('.//h3', $sec)->item(0);
        $blocks = [];
        $buf = '';
        helpRenderBlocks($sec, $blocks, $buf);
        if (trim($buf) !== '') $blocks[] = $buf;
        $blocks = array_values(array_filter(array_map(fn($b) => trim(preg_replace('/\s+/u', ' ', $b)), $blocks), fn($b) => $b !== ''));
        $out[] = [
            'id'    => $sec->getAttribute('id'),
            'title' => $h3 ? trim(preg_replace('/\s+/u', ' ', helpRenderText($h3))) : '',
            'text'  => implode("\n\n", $blocks),
            'cap'   => $sec->getAttribute('data-cap') !== '' ? $sec->getAttribute('data-cap') : null,
            'skip'  => $sec->getAttribute('data-help-search') === 'off',
            'intro' => false,
        ];
    }
    // The page's introduction - its .help-hero banner and any .help-lede paragraph
    // outside the sections ("the one sentence that sets up a whole guide", house
    // style). Not a section, but often the best answer to "what is X?". It links to
    // the top of the page: id ''.
    $notInSection = '[not(ancestor::div[contains(concat(" ", normalize-space(@class), " "), " help-section ")])]';
    $hero = $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " help-hero ")]' . $notInSection)->item(0);
    $titleNode = $hero ? $xp->query('.//h1|.//h2', $hero)->item(0)
                       : $xp->query('//*[contains(concat(" ", normalize-space(@class), " "), " help-main ")]//h2' . $notInSection)->item(0);
    $intro = [];
    if ($hero) foreach ($xp->query('.//p', $hero) as $p) $intro[] = helpRenderText($p);
    foreach ($xp->query('//p[contains(concat(" ", normalize-space(@class), " "), " help-lede ")]' . $notInSection) as $p) $intro[] = helpRenderText($p);
    $intro = array_values(array_filter(array_map(fn($b) => trim(preg_replace('/\s+/u', ' ', $b)), $intro), fn($b) => $b !== ''));
    $title = $titleNode ? trim(preg_replace('/\s+/u', ' ', helpRenderText($titleNode))) : '';
    if ($intro && $title !== '') {
        $out[] = ['id' => '', 'title' => $title, 'text' => implode("\n\n", $intro), 'cap' => null, 'skip' => false, 'intro' => true];
    }
    return $out;
}

/**
 * Walk a section, cutting its text into blocks: each paragraph, heading, list item,
 * table row, card, step and note is one block; loose text joins the block being built.
 *
 * @param list<string> $blocks  collected blocks, appended to
 * @param string $buf  loose text waiting to become a block
 */
function helpRenderBlocks(DOMNode $node, array &$blocks, string &$buf): void
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) { $buf .= ' ' . $child->nodeValue; continue; }
        if (!$child instanceof DOMElement) continue;
        $tag = strtolower($child->tagName);
        $cls = ' ' . $child->getAttribute('class') . ' ';
        $isBlock = in_array($tag, ['p', 'li', 'tr', 'h3', 'h4', 'h5', 'pre', 'blockquote', 'dt', 'dd'], true)
            || preg_match('/ help-(card|step|note|flow-step|tip|warning) /', $cls);
        if ($isBlock) {
            if (trim($buf) !== '') { $blocks[] = $buf; $buf = ''; }
            $blocks[] = helpRenderText($child);
        } else {
            helpRenderBlocks($child, $blocks, $buf);
        }
    }
}

/** An element's text with a space between its pieces - textContent would glue a card's title to its body. */
function helpRenderText(DOMNode $node): string
{
    $parts = [];
    foreach ((new DOMXPath($node->ownerDocument))->query('.//text()', $node) as $t) $parts[] = $t->nodeValue;
    return implode(' ', $parts);
}

// ─── Building ────────────────────────────────────────────────────────────────

/**
 * Build (or refresh) the index for the given languages. English is always built,
 * first: a section in another language is only stored when its text differs from
 * the English - an untranslated section would just be a second copy.
 *
 * A section whose text is unchanged keeps its row (and its vector); a changed one
 * is updated; one that has gone from its page is removed. A page whose module is
 * switched off for the install is skipped, and its rows left alone - nobody can
 * open it, so the search never returns them.
 *
 * @param list<string> $locales  extra languages, e.g. ['de']
 * @param ?callable $log  fn(string $line) for progress
 * @return array{pages:int,sections:int,added:int,changed:int,removed:int,failed:list<string>}
 */
function helpIndexBuild(PDO $conn, array $locales = [], ?callable $log = null): array
{
    $log ??= fn(string $s) => null;
    $root = dirname(__DIR__);
    $analyst = helpIndexRenderAnalyst($conn);
    if ($analyst === null) throw new RuntimeException('No active administrator with every module to render the help pages as.');
    $php = helpIndexPhp();
    $locales = array_values(array_unique(array_merge(['en'], array_filter($locales, fn($l) => $l !== 'en' && array_key_exists($l, I18n::getSupportedLocales())))));
    $stats = ['pages' => 0, 'sections' => 0, 'added' => 0, 'changed' => 0, 'removed' => 0, 'failed' => []];

    $existing = $conn->prepare("SELECT anchor, text_hash, cap, module FROM help_sections WHERE page = ? AND locale = ?");
    $upsert = $conn->prepare(
        "INSERT INTO help_sections (section_key, locale, page, anchor, module, cap, title, body, text_hash, built_datetime)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE module = VALUES(module), cap = VALUES(cap), title = VALUES(title), body = VALUES(body),
             text_hash = VALUES(text_hash), built_datetime = UTC_TIMESTAMP()"
    );
    $remove = $conn->prepare("DELETE FROM help_sections WHERE page = ? AND locale = ? AND anchor = ?");

    $pages = helpIndexPages($root);
    foreach ($pages as $p) {
        if ($p['module'] !== 'system' && !moduleIsEnabled($conn, $p['module'])) { $log("skip {$p['page']} (module off)"); continue; }
        $stats['pages']++;
        $english = [];
        foreach ($locales as $locale) {
            $sections = helpIndexRenderPage($root, $php, $p['page'], $locale, $analyst);
            if ($sections === null) { $stats['failed'][] = "{$p['page']} [$locale]"; $log("FAILED {$p['page']} [$locale]"); continue; }
            $existing->execute([$p['page'], $locale]);
            $had = [];
            foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $r) $had[$r['anchor']] = $r;
            $kept = [];
            foreach ($sections as $s) {
                // id '' is only allowed for the page's introduction, which links to the top of the page.
                if ($s['skip'] || ($s['id'] === '' && empty($s['intro'])) || $s['text'] === '') continue;
                if ($locale === 'en') $english[$s['id']] = $s['text'];
                elseif (($english[$s['id']] ?? null) === $s['text']) continue;   // untranslated: English already covers it
                $hash = hash('sha256', $s['title'] . "\n" . $s['text']);
                $kept[$s['id']] = true;
                $old = $had[$s['id']] ?? null;
                if ($old && $old['text_hash'] === $hash && $old['cap'] === $s['cap'] && $old['module'] === $p['module']) continue;
                $upsert->execute([$p['page'] . ($s['id'] !== '' ? '#' . $s['id'] : ''), $locale, $p['page'], $s['id'], $p['module'], $s['cap'],
                                  mb_substr($s['title'], 0, 255), $s['text'], $hash]);
                $stats[$old ? 'changed' : 'added']++;
            }
            foreach (array_keys($had) as $anchor) {
                if (!isset($kept[$anchor])) { $remove->execute([$p['page'], $locale, $anchor]); $stats['removed']++; }
            }
            if ($locale === 'en') $stats['sections'] += count($kept);
        }
        $log(sprintf('%-45s %3d sections', $p['page'], count($english)));
    }
    // A page that no longer exists takes its sections with it.
    $known = array_column($pages, 'page');
    $gone = $conn->query("SELECT DISTINCT page FROM help_sections")->fetchAll(PDO::FETCH_COLUMN);
    foreach (array_diff($gone, $known) as $page) {
        $st = $conn->prepare("DELETE FROM help_sections WHERE page = ?");
        $st->execute([$page]);
        $stats['removed'] += $st->rowCount();
    }
    return $stats;
}

// ─── Searching ───────────────────────────────────────────────────────────────

/** English words too common to say anything about a question. */
const HELP_STOPWORDS = ['a','an','and','are','as','at','be','but','by','can','could','do','does','for','from','get','has','have','how',
    'i','if','in','into','is','it','its','me','my','no','not','of','on','or','so','that','the','their','them','then','there','these',
    'this','to','was','we','what','when','where','which','who','why','will','with','would','you','your','i\'m','am','did','should',
    'want','need','make','use','using','way','there','any','some','all','one','about','just','only','also'];

/**
 * A text as search words: lower case, letters and digits only, common English
 * words dropped, a light English plural/suffix trim (departments -> department,
 * categories -> category, ticketing -> ticket). Non-English words are only lower-cased.
 *
 * @return list<string>
 */
function helpTokens(string $text): array
{
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($words as $w) {
        if (mb_strlen($w) < 2 || in_array($w, HELP_STOPWORDS, true)) continue;
        $out[] = helpStem($w);
    }
    return $out;
}

/**
 * The light English trim helpTokens() applies to one lower-case word - a cut-down
 * Porter stemmer. It only has to be CONSISTENT (the question and the help go
 * through the same rules), not produce real words:
 *
 *   departments -> department   categories -> category   statuses -> status
 *   adding, added, adds -> add  settings -> set          routing, route -> rout
 *   creating, created, create -> creat                   breached -> breach
 */
function helpStem(string $w): string
{
    if (!preg_match('/^[a-z]+$/', $w) || strlen($w) < 4) return $w;
    // Plurals.
    if (preg_match('/ies$/', $w)) $w = substr($w, 0, -3) . 'y';                          // categories -> category
    elseif (preg_match('/(uses|sses|ches|shes|xes)$/', $w)) $w = substr($w, 0, -2);       // statuses -> status
    elseif (str_ends_with($w, 's') && !preg_match('/(ss|us|is)$/', $w)) $w = substr($w, 0, -1);   // not class, status, analysis
    // -ing and -ed, keeping at least three letters; a doubled consonant left behind is undoubled (adding -> add).
    foreach (['ing', 'ed'] as $suffix) {
        if (str_ends_with($w, $suffix) && strlen($w) - strlen($suffix) >= 3) {
            $w = substr($w, 0, -strlen($suffix));
            if (strlen($w) > 3 && preg_match('/([bcdfgkmnprtvz])\1$/', $w)) $w = substr($w, 0, -1);   // setting -> set, but add stays add
            break;
        }
    }
    // A final silent e, so create / created / creating agree.
    if (strlen($w) > 4 && str_ends_with($w, 'e')) $w = substr($w, 0, -1);
    return $w;
}

/**
 * Rank sections for a question with BM25 (k1 1.2, b 0.75). Pure: no database.
 *
 * @param list<array{section_key:string,title:string,body:string,module:string}> $sections
 * @param string $question  the question, as typed
 * @param ?string $currentModule  the module the asker is in - a small boost
 * @param int $limit  how many to return
 * @return list<array>  the sections given, best first, each with 'score' and 'snippet'; none when nothing matched
 */
function helpRank(array $sections, string $question, ?string $currentModule = null, int $limit = 5): array
{
    $q = array_values(array_unique(helpTokens($question)));
    if (!$q || !$sections) return [];

    // Score PASSAGES, not whole sections: a long section (Tickets -> Settings is
    // ~10,000 characters of cards) would otherwise bury the one card that answers,
    // because BM25 rightly stops a section winning just by being long. A section
    // then scores as its best passage.
    $docs = [];
    $df = array_fill_keys($q, 0);
    $totalLen = 0;
    foreach ($sections as $i => $s) {
        $titleTokens = helpTokens($s['title']);
        foreach (helpPassages($s['body']) as $text) {
            $tokens = array_merge(...array_fill(0, HELP_TITLE_WEIGHT, $titleTokens), ...[helpTokens($text)]);
            $tf = array_count_values($tokens);
            $docs[] = ['section' => $i, 'text' => $text, 'tf' => $tf, 'len' => count($tokens)];
            $totalLen += count($tokens);
            foreach ($q as $t) if (isset($tf[$t])) $df[$t]++;
        }
    }
    $n = count($docs);
    $avg = $totalLen / max(1, $n);
    $k1 = 1.2; $b = 0.75;
    $best = [];   // section index => [score, passage text]
    foreach ($docs as $d) {
        $score = 0.0;
        foreach ($q as $t) {
            $f = $d['tf'][$t] ?? 0;
            if (!$f) continue;
            $idf = log(1 + ($n - $df[$t] + 0.5) / ($df[$t] + 0.5));
            $score += $idf * ($f * ($k1 + 1)) / ($f + $k1 * (1 - $b + $b * $d['len'] / $avg));
        }
        if ($score > ($best[$d['section']][0] ?? 0.0)) $best[$d['section']] = [$score, $d['text']];
    }
    $scored = [];
    foreach ($best as $i => [$score, $passage]) {
        $s = $sections[$i];
        if ($currentModule !== null && ($s['module'] ?? null) === $currentModule) $score *= HELP_MODULE_BOOST;
        $scored[] = $s + ['score' => round($score, 4), 'snippet' => helpSnippet($passage, $q), 'passage' => $passage];   // passage: what an AI reads (step 5)
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, $limit);
}

/**
 * A section's body as passages of about 700 characters: its blocks (one per
 * paragraph, card, step - blank-line separated by the renderer) packed in order,
 * never splitting a block.
 *
 * @return list<string>
 */
function helpPassages(string $body, int $size = 700): array
{
    $out = [];
    $cur = '';
    foreach (preg_split('/\n\s*\n/', $body) ?: [] as $block) {
        $block = trim($block);
        if ($block === '') continue;
        if ($cur !== '' && mb_strlen($cur) + 2 + mb_strlen($block) > $size) { $out[] = $cur; $cur = ''; }   // + the blank line joining them
        $cur = $cur === '' ? $block : "$cur\n\n$block";
    }
    if ($cur !== '') $out[] = $cur;
    return $out ?: [$body];
}

/**
 * About 240 characters of a section around the first question word it contains.
 *
 * @param list<string> $queryTokens  the question's search words
 */
function helpSnippet(string $body, array $queryTokens): string
{
    $lower = mb_strtolower($body);
    $at = null;
    foreach ($queryTokens as $t) {
        $p = mb_strpos($lower, $t);
        if ($p !== false && ($at === null || $p < $at)) $at = $p;
    }
    $start = max(0, ($at ?? 0) - 80);
    $snip = mb_substr($body, $start, 240);
    return ($start > 0 ? '…' : '') . trim($snip) . (mb_strlen($body) > $start + 240 ? '…' : '');
}

/**
 * The sections an analyst may be answered from, in their language: for each
 * section, its row in $locale where there is one, else its English row. Modules
 * they cannot open are left out. Rows marked with a capability are kept - the
 * answer then says who can do it (step 5) - but carry 'cap'.
 *
 * @return list<array{section_key:string,page:string,anchor:string,module:string,cap:?string,title:string,body:string,locale:string}>
 */
function helpSectionsFor(PDO $conn, int $analystId, string $locale = 'en'): array
{
    $st = $conn->prepare("SELECT id, section_key, locale, page, anchor, module, cap, title, body FROM help_sections WHERE locale IN ('en', ?)");
    $st->execute([$locale]);
    $by = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($by[$r['section_key']]) || $r['locale'] === $locale) $by[$r['section_key']] = $r;
    }
    $can = [];
    $out = [];
    foreach ($by as $r) {
        $m = $r['module'];
        $can[$m] ??= analystCanAccessModule($conn, $analystId, $m);
        if ($can[$m]) $out[] = $r;
    }
    return $out;
}

/**
 * Search the help for an analyst: the best sections for their question, in their
 * language, from modules they can open.
 *
 * @param ?string $currentModule  the module they are in
 */
function helpSearch(PDO $conn, int $analystId, string $question, string $locale = 'en', ?string $currentModule = null, int $limit = 5): array
{
    return helpRank(helpSectionsFor($conn, $analystId, $locale), $question, $currentModule, $limit);
}
