<?php
/**
 * System help — the topic registry.
 *
 * One entry per help page, keyed by slug. The slug matches the System area's
 * url in system/includes/areas.php (minus the trailing slash), which is also
 * the help page's filename: 'db-verify' => db-verify.php => the help for the
 * db-verify/ area.
 *
 * The registry owns:
 *   hero      — the page's H1 (English: System help is English-only, see _init.php)
 *   sub       — the hero standfirst
 *   sections  — the page's sections, in order. These drive BOTH the on-page
 *               sidebar nav (via _top.php, so a page never restates them) and
 *               the search index on index.php, which can deep-link to a section.
 *   terms     — extra English search synonyms, for words a person would type
 *               that appear in neither the title, the description nor a section.
 *
 * The card's icon, title and description are NOT repeated here — they come from
 * getSystemAreas() (translated), so a card and its help page cannot drift apart.
 * helpCards() joins the two; an area with no registry entry is simply not
 * offered a card, which is what stops the landing page linking to a help page
 * that does not exist.
 *
 * ADDING A HELP PAGE: add the entry here, then create <slug>.php which sets
 * $helpSlug and requires _top.php / _bottom.php. Nothing else to wire up — the
 * card, the search index and the sidebar all follow from this entry.
 */

/** @return array<string,array<string,mixed>> Help topics, keyed by slug. */
function getHelpTopics() {
    return [
        'encryption' => [
            'hero' => 'Encryption',
            'sub'  => 'Generate and look after the AES-256-GCM key that encrypts the sensitive credentials FreeITSM stores — vCenter logins, AI API keys and mailbox OAuth secrets.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'status',   'label' => 'Key status'],
                ['id' => 'setup',    'label' => 'Generating a key'],
                ['id' => 'whats',    'label' => "What's encrypted"],
                ['id' => 'backup',   'label' => 'Backups & recovery'],
            ],
            'terms' => 'aes key secret credentials at rest cipher rotate',
        ],
        'analysts' => [
            'hero' => 'Analysts',
            'sub'  => 'Create the people who work in FreeITSM, decide who is an administrator, control which companies and modules each of them can reach, put them into teams and reset their passwords.',
            'sections' => [
                ['id' => 'overview',  'label' => 'Overview'],
                ['id' => 'adding',    'label' => 'Adding an analyst'],
                ['id' => 'admin',     'label' => 'Administrators'],
                ['id' => 'signin',    'label' => 'Sign-in method'],
                ['id' => 'access',    'label' => 'Company & module access'],
                ['id' => 'teams',     'label' => 'Teams & departments'],
                ['id' => 'passwords', 'label' => 'Resetting a password'],
                ['id' => 'leavers',   'label' => 'Leavers'],
            ],
            'terms' => 'user staff agent account technician add person joiner leaver disable deactivate admin rights permissions'
                     . " can't see tickets cannot see tickets no tickets empty queue locked out of system",
        ],
        'teams' => [
            'hero' => 'Teams',
            'sub'  => 'Group analysts into teams — service desk, infrastructure, applications — then use the team to grant the whole group the departments, companies and modules they need in one place instead of person by person.',
            'sections' => [
                ['id' => 'overview',    'label' => 'Overview'],
                ['id' => 'creating',    'label' => 'Creating a team'],
                ['id' => 'departments', 'label' => 'Departments'],
                ['id' => 'members',     'label' => 'Members'],
                ['id' => 'companies',   'label' => 'Company access'],
                ['id' => 'modules',     'label' => 'Module access'],
                ['id' => 'deleting',    'label' => 'Deleting a team'],
            ],
            'terms' => 'group squad service desk membership queue visibility'
                     . " can't see tickets cannot see tickets no tickets empty queue missing departments",
        ],
        'roles' => [
            'hero' => 'Roles',
            'sub'  => 'Let someone who is not a System administrator manage a specific module\'s settings — a training lead who runs the LMS, say — without handing them the whole System module. A role grants settings capabilities; you assign it to analysts or whole teams.',
            'sections' => [
                ['id' => 'overview',      'label' => 'What roles are for'],
                ['id' => 'two-layers',    'label' => 'Access vs administration'],
                ['id' => 'creating',      'label' => 'Creating a role'],
                ['id' => 'assigning',     'label' => 'Assigning it'],
                ['id' => 'admins',        'label' => 'Administrators'],
                ['id' => 'notes',         'label' => 'Good to know'],
            ],
            'terms' => 'rbac role based access control permissions capabilities delegate grant manage settings lms manager who can change settings deny by default privilege',
        ],
        'modules' => [
            'hero' => 'Modules',
            'sub'  => 'Control which modules each analyst can open, by ticking the ones they need in a per-analyst access matrix.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'matrix',   'label' => 'The access matrix'],
                ['id' => 'effect',   'label' => 'What analysts see'],
                ['id' => 'notes',    'label' => 'Good to know'],
            ],
            'terms' => 'permissions hide show waffle licence enable disable feature',
        ],
        'db-verify' => [
            'hero' => 'Database verification',
            'sub'  => 'Check that every table, column, key and index FreeITSM expects is present — and create anything that is missing — without touching your existing data.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'checks',   'label' => 'What it checks'],
                ['id' => 'running',  'label' => 'Running a check'],
                ['id' => 'results',  'label' => 'Reading the results'],
                ['id' => 'pending',  'label' => 'Pending rows & Fix'],
            ],
            'terms' => 'schema migration upgrade after update missing table column sql repair',
        ],
        'colours' => [
            'hero' => 'Module colours',
            'sub'  => 'Give each module its own colour so its icon and headers stand out at a glance across FreeITSM.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'change',   'label' => 'Changing a colour'],
                ['id' => 'reset',    'label' => 'Resetting to default'],
                ['id' => 'notes',    'label' => 'Good to know'],
            ],
            'terms' => 'colors theme accent palette icon look',
        ],
        'branding' => [
            'hero' => 'Branding',
            'sub'  => 'Set your organisation logo and the default header/footer text that appears on branded output — diagrams and exported documents.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'logo',     'label' => 'Logo'],
                ['id' => 'slots',    'label' => 'Header & footer text'],
                ['id' => 'tokens',   'label' => 'Tokens'],
                ['id' => 'save',     'label' => 'Saving & resetting'],
            ],
            'terms' => 'logo brand company name letterhead export pdf watermark',
        ],
        'security' => [
            'hero' => 'Security',
            'sub'  => 'Harden local sign-in with trusted-device, password-expiry, account-lockout and IP-ban controls. These settings only affect local password accounts — people who sign in through an identity provider are governed by that provider instead.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'trusted',  'label' => 'Trusted device'],
                ['id' => 'password', 'label' => 'Password expiry'],
                ['id' => 'lockout',  'label' => 'Account lockout'],
                ['id' => 'ipban',    'label' => 'IP ban'],
            ],
            'terms' => 'brute force locked out failed attempts hardening rotation ban blocked',
        ],
        'sso' => [
            'hero' => 'Authentication',
            'sub'  => 'Choose how people sign in: single sign-on through an identity provider (Microsoft Entra, Google, Okta, Keycloak or any OpenID Connect provider), or against your LDAP / Active Directory. Local passwords keep working as a fallback.',
            'sections' => [
                ['id' => 'overview',   'label' => 'Overview'],
                ['id' => 'which',      'label' => 'Single or multi-company?'],
                ['id' => 'single',     'label' => 'Single-company setup'],
                ['id' => 'multi',      'label' => 'Multi-company (MSP) setup'],
                ['id' => 'ldap',       'label' => 'LDAP / Active Directory'],
                ['id' => 'ldap-setup', 'label' => 'Setting up a directory'],
                ['id' => 'ldap-groups','label' => 'Controlling access by group'],
                ['id' => 'ldap-faq',   'label' => 'LDAP troubleshooting'],
                ['id' => 'experience', 'label' => 'What people see'],
                ['id' => 'breakglass', 'label' => 'Break-glass & safety'],
                ['id' => 'faq',        'label' => 'Troubleshooting'],
            ],
            'terms' => 'oidc openid connect saml entra azure ad google okta keycloak login federation'
                     . ' ldap active directory domain controller dc bind base dn service account samba openldap'
                     . ' freeipa 389 memberof samaccountname objectguid starttls ldaps nested groups'
                     . " no such object cannot log in can't sign in invalid credentials directory",
        ],
        'api' => [
            'hero' => 'API',
            'sub'  => 'FreeITSM has a REST API so other systems — monitoring tools, scripts, portals, RMM platforms — can create and work tickets programmatically. You create keys here in System, decide exactly what each key may do, and test everything from the built-in documentation page.',
            'sections' => [
                ['id' => 'overview',    'label' => 'Overview'],
                ['id' => 'keys',        'label' => 'Creating keys'],
                ['id' => 'permissions', 'label' => 'Permissions'],
                ['id' => 'companies',   'label' => 'Company scope'],
                ['id' => 'using',       'label' => 'Using the API'],
                ['id' => 'docs',        'label' => 'Docs & testing'],
                ['id' => 'safety',      'label' => 'Good practice'],
            ],
            'terms' => 'rest json integration token bearer key endpoint curl script automation',
        ],
        'webhooks' => [
            'hero' => 'Webhooks',
            'sub'  => 'Push events out of FreeITSM to Slack, Teams, Discord or any URL. You build a webhook as an action inside a workflow — this page is the control room that shows whether they are actually being delivered.',
            'sections' => [
                ['id' => 'overview',  'label' => 'Overview'],
                ['id' => 'building',  'label' => 'Building a webhook'],
                ['id' => 'worker',    'label' => 'The delivery worker'],
                ['id' => 'log',       'label' => 'The delivery log'],
                ['id' => 'replay',    'label' => 'Replaying a delivery'],
                ['id' => 'retention', 'label' => 'Payload retention'],
                ['id' => 'security',  'label' => 'Signing & encryption'],
                ['id' => 'trouble',   'label' => 'Troubleshooting'],
            ],
            'terms' => 'slack teams discord outgoing http post notify integration hmac signature retry dead letter cron queue not sending',
        ],
        'preferences' => [
            'hero' => 'Preferences',
            'sub'  => 'Your own personal settings — interface language, where notifications pop up, how left-hand panels behave and a couple of display options. Each choice is saved to your account and follows you to any browser you sign in from.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'language', 'label' => 'Interface language'],
                ['id' => 'toasts',   'label' => 'Notifications'],
                ['id' => 'panels',   'label' => 'Left panels'],
                ['id' => 'display',  'label' => 'Display options'],
            ],
            'terms' => 'my settings personal language locale translate toast popup dark mode timezone',
        ],
        'demo-data' => [
            'hero' => 'Demo data',
            'sub'  => 'Fill a fresh FreeITSM with realistic sample data — analysts, tickets, assets, software, a CMDB and more — so you can evaluate every module without typing in your own records. Import each module on its own, and remove it again just as easily.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'whats',    'label' => "What's included"],
                ['id' => 'how',      'label' => 'How to import'],
                ['id' => 'remove',   'label' => 'Removing demo data'],
                ['id' => 'tips',     'label' => 'Tips & gotchas'],
            ],
            'terms' => 'sample test evaluation trial seed fake example populate',
        ],
        'debug-tools' => [
            'hero' => 'Debug tools',
            'sub'  => 'A catalogue of self-contained diagnostics that probe a failing flow and return a plain-text report you can copy straight back to support.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'how',      'label' => 'How to run one'],
                ['id' => 'tools',    'label' => 'The diagnostics'],
                ['id' => 'safety',   'label' => 'When to use them'],
            ],
            'terms' => 'diagnostics troubleshoot broken not working support report logs test',
        ],
        'companies' => [
            'hero' => 'Companies',
            'sub'  => 'Run several separate client companies from one FreeITSM install — keeping each one\'s people, tickets and inbound email apart — or just one, invisibly, if that\'s all you need.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'adding',   'label' => 'Adding companies'],
                ['id' => 'email',    'label' => 'Routing inbound email'],
                ['id' => 'senders',  'label' => 'Personal & free email'],
                ['id' => 'summary',  'label' => 'How email reaches a company'],
            ],
            'terms' => 'tenant msp client customer multi-tenancy separate organisation domain',
        ],
        'topology' => [
            'hero' => 'Topology',
            'sub'  => 'A read-only map of how your install actually fits together — every company with its mailboxes, domains, sign-in providers and analysts underneath it. The fastest way to answer "is this set up the way I think it is?"',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'reading',  'label' => 'Reading the tree'],
                ['id' => 'company',  'label' => "What's under a company"],
                ['id' => 'global',   'label' => 'Global & shared'],
                ['id' => 'using',    'label' => 'What to check for'],
                ['id' => 'notes',    'label' => 'Good to know'],
            ],
            'terms' => 'map overview tree structure relationships diagram graph audit review who has access mailbox domain',
        ],
        'orphaned-tickets' => [
            'hero' => 'Orphaned tickets',
            'sub'  => 'Delete a department and its tickets do not vanish — they keep pointing at a department that no longer exists, which hides them from every queue. This page finds those tickets and puts them back somewhere real.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'cause',    'label' => 'How tickets get orphaned'],
                ['id' => 'reading',  'label' => 'Reading the list'],
                ['id' => 'fixing',   'label' => 'Reassigning tickets'],
                ['id' => 'bulk',     'label' => 'Fixing them in bulk'],
                ['id' => 'notes',    'label' => 'Good to know'],
            ],
            'terms' => 'missing lost hidden stuck invisible disappeared deleted department cannot find ticket reassign fix broken',
        ],
        'email-routing-test' => [
            'hero' => 'Email routing test',
            'sub'  => 'A safe dry-run tool: type in a sender address (and pick a mailbox) and see which company an inbound email would land in — and exactly which rule decided it. Nothing is created or sent.',
            'sections' => [
                ['id' => 'overview', 'label' => 'Overview'],
                ['id' => 'use',      'label' => 'Running a test'],
                ['id' => 'trace',    'label' => 'Reading the trace'],
                ['id' => 'single',   'label' => 'Single-company installs'],
            ],
            'terms' => 'wrong company inbound mail dry run simulate which tenant sender domain rule',
        ],
    ];
}

/**
 * One topic by slug, or null. Slug comes from the page ($helpSlug) or the URL.
 *
 * @return array<string,mixed>|null
 */
function getHelpTopic($slug) {
    $topics = getHelpTopics();
    return $topics[$slug] ?? null;
}

/**
 * The help cards for the landing page: every System area that HAS a help page,
 * in the same order as the System landing page itself.
 *
 * Joining on the area registry (rather than restating icons/titles here) means
 * a card's title, description and icon always match the area it documents, and
 * an area with no help page yet is skipped rather than linked to a 404.
 *
 * @param bool $multiTenant Whether a 2nd company exists (gates 'multitenant' areas).
 * @return array<int,array<string,mixed>> Cards with a resolved title/desc/keywords/sections.
 */
function helpCards($multiTenant = false) {
    $topics = getHelpTopics();
    $cards  = [];

    foreach (getSystemAreas() as $area) {
        if (($area['requires'] ?? '') === 'multitenant' && !$multiTenant) continue;

        $slug = rtrim($area['url'], '/');
        if (!isset($topics[$slug])) continue;   // no help page written yet — no card

        $topic   = $topics[$slug];
        $cards[] = [
            'slug'     => $slug,
            'href'     => $slug . '.php',
            'icon'     => $area['icon'],
            'title'    => t($area['title']),
            'desc'     => t($area['desc']),
            'keywords' => t($area['keywords']),
            'sub'      => $topic['sub'],
            'sections' => $topic['sections'],
            'terms'    => $topic['terms'] ?? '',
        ];
    }

    return $cards;
}

/**
 * The text a card is matched against when someone searches. Lower-cased and
 * flattened so the caller can do a single substring test: title, description,
 * the area's own keyword synonyms, this page's standfirst, its section labels
 * and any extra search terms.
 */
function helpCardHaystack(array $card) {
    $parts = [
        $card['title'], $card['desc'], $card['keywords'],
        $card['sub'], $card['terms'],
    ];
    foreach ($card['sections'] as $s) $parts[] = $s['label'];

    return strtolower(strip_tags(implode(' ', $parts)));
}

/**
 * System areas that still have no help page — the gap this registry exists to
 * make visible. Shown as a quiet footnote on the landing page rather than
 * hidden, so a new area doesn't quietly ship undocumented.
 *
 * @return array<int,string> Area titles (translated).
 */
function helpTopicsMissing($multiTenant = false) {
    $topics  = getHelpTopics();
    $missing = [];

    foreach (getSystemAreas() as $area) {
        if (($area['requires'] ?? '') === 'multitenant' && !$multiTenant) continue;
        if (!isset($topics[rtrim($area['url'], '/')])) $missing[] = t($area['title']);
    }

    return $missing;
}
