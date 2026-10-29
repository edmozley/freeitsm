<?php
/**
 * RBAC Layer 2 — the capability registry.
 *
 * A capability is an administrative permission: "may this analyst configure X?"
 * See docs/design/rbac.md. This file owns WHAT capabilities exist. includes/rbac.php
 * owns WHO holds them and enforces it.
 *
 * ---------------------------------------------------------------------------
 * WHY AN ENUM AND NOT STRINGS
 * ---------------------------------------------------------------------------
 * Phase 3 gives every settings TAB its own capability — around 70 of them, guarded
 * at a couple of hundred call sites. At that scale a bare string key is dangerous,
 * because of how it fails:
 *
 *     requireCapabilityJson('lms.mange');    // <- typo
 *
 * That does not error. It fails CLOSED, exactly as designed: a permanent, polite
 * 403 that reads like a policy decision. Worse, analystHasCapability() short-circuits
 * true for is_admin BEFORE it looks at the string — so every test the maintainer runs
 * passes, and the only people who see it are users, who assume they simply aren't
 * allowed and ask to be made an admin. A typo quietly becomes a privilege escalation.
 *
 *     requireCapabilityJson(Cap::LMS_MANGE); // <- Error: Undefined constant Cap::LMS_MANGE
 *     requireCapabilityJson('lms.manage');   // <- TypeError: must be of type Cap, string given
 *
 * Same typo, opposite failure: loud, immediate, at the call site, on the first request
 * that touches the line — and it fires for the admin too, because the error happens
 * before the is_admin bypass can hide it. Since 4.0 (PHP 8.1 floor) Cap is an ENUM, so
 * the second line fails too: every guard is typed Cap and refuses a string outright -
 * the one thing the old class constants could not do.
 *
 * ---------------------------------------------------------------------------
 * TRAP: BARE Cap::X ONLY AS A GUARD'S ARGUMENT. EVERYWHERE ELSE, capKey(Cap::X).
 * ---------------------------------------------------------------------------
 * An enum is not a string. Echoed, or used as an array key, it errors loudly - fine.
 * But COMPARED with a string (===, in_array, a switch) it is silently false: a check
 * quietly denies, or quietly skips a safeguard. So the rule is mechanical:
 *
 *     requireCapabilityJson(Cap::TICKETS_SLA);              // a guard takes the enum
 *     'cap' => capKey(Cap::TICKETS_SLA),                      // data holds the string
 *     data-capability="<?= capKey(Cap::TICKETS_SLA) ?>"      // so does HTML
 *     requireCapabilityJson(Cap::from($tab['cap']));         // string back to enum: loud on a bad key
 *
 * Manifests, registries and the database all hold strings; Cap::fromKey() is the
 * trust boundary for anything a person or the database supplied. tests/cap-enum-usage.php
 * checks every Cap:: in the codebase against this rule.
 *
 * The string-keyed cap*() helpers below remain for keys that arrive as strings; the
 * enum's own methods (label(), module(), isSensitive(), forModule()...) read them.
 */

/**
 * The capability keys. THE TYPE. Every guard call site names one of these constants.
 *
 * Naming: '<module>.<area>', where <area> is normally the settings tab it governs, and
 * '<module>.manage' is the per-module umbrella (holding it satisfies every capability
 * in that module — see capExpandUmbrellas()).
 *
 * One constant per settings tab, added as each module is converted. The DETAILS of each
 * capability — its module, its description, whether it is sensitive, which stored settings
 * it guards — are NOT here: they live in that module's settings manifest, and everything
 * else derives from it. These constants are only the names, because a name is the one
 * thing that has to exist in the code for a typo to be a fatal error rather than a silent
 * 403. capSelfCheck() proves every constant below is claimed by exactly one manifest.
 */
enum Cap: string
{
    // ---- LMS ---------------------------------------------------------------
    case LMS_MANAGE = 'lms.manage';
    // Competency tests hold candidates' names, emails and scores - people who
    // are not staff - so they are their own, sensitive grant, not part of LMS_MANAGE.
    case LMS_TESTS  = 'lms.competency_tests';

    // ---- Asset Management --------------------------------------------------
    // One per settings tab. The point of the split is visible here: VCENTER and
    // INTUNE hold integration credentials, and sit on the same tab bar as TYPES,
    // which is a lookup list. A single 'assets.manage' could not tell them apart.
    case ASSETS_MANAGE    = 'assets.manage';      // umbrella
    case ASSETS_TYPES     = 'assets.types';
    case ASSETS_STATUSES  = 'assets.statuses';
    case ASSETS_LOCATIONS = 'assets.locations';
    case ASSETS_SUPPLIERS = 'assets.suppliers';
    case ASSETS_FIELDS    = 'assets.fields';      // designing custom asset fields
    case ASSETS_IMPORT    = 'assets.import';      // bulk-importing assets from a file
    case ASSETS_WARRANTY  = 'assets.warranty';
    case ASSETS_LEASING   = 'assets.leasing';     // lease outcomes (3.5.0) - shared by every company
    case ASSETS_HANDOVER  = 'assets.handover';    // designing the handover document
    case ASSETS_VCENTER   = 'assets.vcenter';     // credentials
    case ASSETS_INTUNE    = 'assets.intune';      // credentials
    case ASSETS_PROXMOX   = 'assets.proxmox';     // Proxmox VE servers, credentials and syncs
    case ASSETS_VCLOUD    = 'assets.vcloud';      // VMware Cloud Director servers, credentials and syncs
    // GH #199: Hyper-V connections - mints the collector's API key and decides identity changes.
    case ASSETS_HYPERV    = 'assets.hyperv';
    // PR #164. Numbering and the label designer are separate grants: whoever
    // designs a sticker is not necessarily allowed to change how every future
    // asset is numbered.
    case ASSETS_RECONCILIATION = 'assets.reconciliation';
    case ASSETS_TAGS           = 'assets.tags';
    case ASSETS_LABELS         = 'assets.labels';

    // ---- Contracts ---------------------------------------------------------
    case CONTRACTS_MANAGE             = 'contracts.manage';              // umbrella
    case CONTRACTS_SUPPLIER_TYPES     = 'contracts.supplier_types';
    case CONTRACTS_SUPPLIER_STATUSES  = 'contracts.supplier_statuses';
    case CONTRACTS_CONTRACT_STATUSES  = 'contracts.contract_statuses';
    case CONTRACTS_PAYMENT_SCHEDULES  = 'contracts.payment_schedules';
    case CONTRACTS_CONTRACT_TERMS     = 'contracts.contract_terms';
    case CONTRACTS_RFP_DEPARTMENTS    = 'contracts.rfp_departments';
    case CONTRACTS_RFP_AI             = 'contracts.rfp_ai';              // AI provider + API key
    // contracts.customer_visibility retired in 3.5.0: a contract belongs to a company now, and that decides who sees it.

    // ---- Change Management -------------------------------------------------
    case CHANGES_MANAGE     = 'changes.manage';      // umbrella
    case CHANGES_FIELDS     = 'changes.fields';
    case CHANGES_STATUSES   = 'changes.statuses';
    case CHANGES_PRIORITIES = 'changes.priorities';
    case CHANGES_TYPES      = 'changes.types';
    case CHANGES_IMPACTS    = 'changes.impacts';
    case CHANGES_APPROVALS  = 'changes.approvals';   // the approval rules (#180) - sensitive: they govern the audit trail
    // Everyday work, not a settings tab (#180 Part 3) - see the manifest's 'actions'.
    case CHANGES_CHOOSE_APPROVERS = 'changes.choose_approvers';
    case CHANGES_TEMPLATES         = 'changes.templates';           // write standard-change templates (#180 §8)
    case CHANGES_TEMPLATES_APPROVE = 'changes.templates_approve';   // everyday work: approve a template version

    // ---- Tasks -------------------------------------------------------------
    case TASKS_MANAGE     = 'tasks.manage';       // umbrella
    case TASKS_STATUSES   = 'tasks.statuses';
    case TASKS_PRIORITIES = 'tasks.priorities';
    case TASKS_CALENDAR   = 'tasks.calendar';
    case TASKS_CARD       = 'tasks.card';
    case TASKS_TAGS       = 'tasks.tags';
    case TASKS_TIME       = 'tasks.time';
    case TASKS_INVOLVED   = 'tasks.involved';    // who else is on a task (GH #89)
    case TASKS_CHECKLISTS = 'tasks.checklists';  // checklists on tasks on/off (discussion #138)
    // Repeating task evidence (#181). EVIDENCE is the settings tab - what deleting an
    // occurrence and changing a finished checklist do, and who may run the report.
    // EVIDENCE_REPORT is everyday work: running the evidence report and its exports.
    // Unrestricted until that tab says otherwise, so an upgrade changes nothing - and
    // with Read only access to Tasks it makes an Auditor role.
    case TASKS_EVIDENCE        = 'tasks.evidence';
    case TASKS_EVIDENCE_REPORT = 'tasks.evidence_report';
    // GH #190: read a task occurrence's form answers WITHOUT access to Forms - how a
    // Read only auditor sees them and nothing else in Forms. Always restricted: off
    // unless a role grants it, so an upgrade gives nobody anything new.
    case TASKS_FORM_ANSWERS    = 'tasks.form_answers';

    // ---- Reporting: background job health (#182) ----------------------------
    // Not a settings tab - Reporting has no settings - so both are everyday-work
    // permissions that always apply. VIEW opens Reporting -> System logs ->
    // Background jobs; its messages name mailboxes, directories and servers, so it
    // is not everybody's with Reporting. SETTINGS changes what a job is expected to
    // do - an interval, "not used here" - which can silence an alarm: sensitive.
    case REPORTING_JOB_HEALTH   = 'reporting.job_health';
    case REPORTING_JOB_SETTINGS = 'reporting.job_settings';

    // ---- Knowledge ---------------------------------------------------------
    case KNOWLEDGE_MANAGE      = 'knowledge.manage';       // umbrella
    case KNOWLEDGE_EMAIL       = 'knowledge.email';        // SMTP credentials
    case KNOWLEDGE_AI          = 'knowledge.ai';           // AI provider + API key
    case KNOWLEDGE_EMBEDDINGS  = 'knowledge.embeddings';   // spends money re-embedding
    case KNOWLEDGE_RECYCLE_BIN = 'knowledge.recycle_bin';

    // ---- CMDB --------------------------------------------------------------
    case CMDB_MANAGE             = 'cmdb.manage';              // umbrella
    case CMDB_CLASSES            = 'cmdb.classes';             // the CI schema itself
    case CMDB_RELATIONSHIP_TYPES = 'cmdb.relationship_types';
    case CMDB_AI                 = 'cmdb.ai';                  // AI provider + API key
    // GH #201: which asset details a linked CI's Asset card shows. Narrows a view only -
    // it can never show an asset to someone who cannot open it. Not sensitive.
    case CMDB_ASSET_CARD         = 'cmdb.asset_card';
    // GH #199 Part C: how "If this failed" treats a member with no recorded state.
    case CMDB_IMPACT             = 'cmdb.impact';

    // ---- Morning Checks ----------------------------------------------------
    case MORNING_CHECKS_MANAGE   = 'morning_checks.manage';    // umbrella
    case MORNING_CHECKS_CHECKS   = 'morning_checks.checks';
    case MORNING_CHECKS_STATUSES = 'morning_checks.statuses';
    // Grouping and routing (discussion #64). Separate from CHECKS because it
    // decides who the round is POINTED at — a team-shape decision — rather than
    // what the round consists of. Neither grants the right to complete a check:
    // that is plain module access and deliberately stays that way.
    case MORNING_CHECKS_GROUPS   = 'morning_checks.groups';

    // ---- Problem Management ------------------------------------------------
    case PROBLEMS_MANAGE     = 'problems.manage';      // umbrella
    case PROBLEMS_STATUSES   = 'problems.statuses';
    case PROBLEMS_PRIORITIES = 'problems.priorities';
    case PROBLEMS_AI         = 'problems.ai';          // AI provider + API key

    // ---- Watchtower --------------------------------------------------------
    // Watchtower reads every other module and owns nothing, so these govern the
    // VIEW only: which cards are drawn, and which statuses and priorities feed
    // each count. Neither grants any access to the modules being counted — the
    // cards already show only what the analyst may see.
    case WATCHTOWER_MANAGE = 'watchtower.manage';    // umbrella
    case WATCHTOWER_CARDS  = 'watchtower.cards';     // which cards appear
    case WATCHTOWER_COUNTS = 'watchtower.counts';    // what each count is counting

    // ---- Service Status ----------------------------------------------------
    case SERVICE_STATUS_MANAGE   = 'service_status.manage';    // umbrella
    case SERVICE_STATUS_SERVICES = 'service_status.services';
    case SERVICE_STATUS_STATUSES = 'service_status.statuses';
    case SERVICE_STATUS_IMPACTS  = 'service_status.impacts';
    // Uptime reporting (discussion #59): the measurement window, and whether
    // customers see the figures. Separate from IMPACTS because the rule that
    // decides WHAT counts as downtime lives on the impact level itself — this
    // one governs how it is reported, which is a publishing decision.
    case SERVICE_STATUS_UPTIME   = 'service_status.uptime';

    // ---- War room ----------------------------------------------------------
    // Using the war room is plain module access — in an outage you do not want
    // a permission between an analyst and the only working chat. This gates the
    // one administrative decision: how long messages are kept.
    case WAR_ROOM_MANAGE = 'war_room.manage';                  // umbrella

    // ---- Calendar ----------------------------------------------------------
    case CALENDAR_MANAGE     = 'calendar.manage';      // umbrella
    case CALENDAR_CATEGORIES = 'calendar.categories';

    // ---- Forms -------------------------------------------------------------
    case FORMS_MANAGE = 'forms.manage';    // umbrella
    case FORMS_LAYOUT = 'forms.layout';
    case FORMS_AI     = 'forms.ai';        // AI provider + API key
    case FORMS_ACCESS      = 'forms.access';       // who may design forms (#180)
    // Everyday work, not a settings tab (#180 Part 3) - see the manifest's 'actions'.
    case FORMS_DESIGN      = 'forms.design';
    case FORMS_COLLECTIONS = 'forms.collections';   // create / close collections — closing can stop forms accepting submissions

    // ---- Process Mapper ----------------------------------------------------
    case PROCESS_MAPPER_MANAGE     = 'process_mapper.manage';    // umbrella
    case PROCESS_MAPPER_STEP_TYPES = 'process_mapper.step_types';

    // ---- Workflow ----------------------------------------------------------
    case WORKFLOW_MANAGE  = 'workflow.manage';     // umbrella
    case WORKFLOW_AI      = 'workflow.ai';         // AI provider + API key
    case WORKFLOW_FORMATS = 'workflow.formats';

    // ---- Software ----------------------------------------------------------
    case SOFTWARE_MANAGE   = 'software.manage';     // umbrella
    case SOFTWARE_API_KEYS = 'software.api_keys';   // mints credentials
    // Where licence renewals surface — the dashboard, the calendar, or both (#1551).
    // NOT sensitive: it decides where a date is SHOWN, never who may see it.
    case SOFTWARE_RENEWALS = 'software.renewals';

    // ---- Domains (#154) ----------------------------------------------------
    // Four very different grants. STATUSES is a list of words. ALERTS decides who is
    // emailed and whether tickets/tasks are raised. MONITORING switches on outbound
    // lookups against registries, DNS, crt.sh. AUTH_CODES is the dangerous one: a
    // registrar auth code is the secret that lets whoever holds it transfer the
    // domain away, so seeing or changing one is its own sensitive capability.
    case DOMAINS_MANAGE     = 'domains.manage';       // umbrella
    case DOMAINS_STATUSES   = 'domains.statuses';
    case DOMAINS_CERT_TYPES = 'domains.cert_types';   // the list of certificate types
    case DOMAINS_ALERTS     = 'domains.alerts';
    case DOMAINS_MONITORING = 'domains.monitoring';
    case DOMAINS_AUTH_CODES = 'domains.auth_codes';   // reveal / change transfer secrets
    // SERVICE_STATUS decides whether a domain's problems raise incidents on the
    // Service Status page, which customers read - sensitive for that reason.
    case DOMAINS_SERVICE_STATUS = 'domains.service_status';

    // ---- Files -------------------------------------------------------------
    // ⚠️ None of these lets anybody READ a file. Files is pessimistic: content is
    // reached only through a grant on the folder (files_permissions). FOLDERS is
    // the break-glass: it creates top-level folders and can TAKE OWNERSHIP of any
    // folder - give itself Full control, loudly audited - which is how an orphaned
    // folder is recovered. That makes it the most sensitive grant in the module.
    // STORAGE moves where the bytes live on disk and caps upload size.
    case FILES_MANAGE  = 'files.manage';    // umbrella
    case FILES_FOLDERS = 'files.folders';
    case FILES_STORAGE = 'files.storage';

    // ---- Projects (3.2.0) --------------------------------------------------
    // GENERAL decides who may create and change projects - the module's own
    // permission rules - so it is sensitive. HEALTH tunes the traffic light;
    // ROLES and RAID are lists of words and scales. TEMPLATES saves a project
    // as a template and looks after the list - shared by every company.
    case PROJECTS_MANAGE    = 'projects.manage';      // umbrella; also may change and delete ANY project
    case PROJECTS_GENERAL   = 'projects.general';
    case PROJECTS_HEALTH    = 'projects.health';
    case PROJECTS_ROLES     = 'projects.roles';
    case PROJECTS_RAID      = 'projects.raid';
    case PROJECTS_TEMPLATES = 'projects.templates';
    // BUDGET sets the currency, how labour is costed and the hourly rates -
    // including each analyst's, which is close to pay: sensitive.
    case PROJECTS_BUDGET    = 'projects.budget';
    // AI chooses the provider, model and key the AI project manager uses - the
    // key is a secret that spends the organisation's money.
    case PROJECTS_AI        = 'projects.ai';

    // ---- Starters & leavers (4.6.0) -----------------------------------------
    // TEMPLATES decides what happens for a starter, mover or leaver - the steps,
    // who does each and when - so it shapes everybody's work. MANAGE also lets
    // the holder cancel or reopen any case.
    case STARTERS_MANAGE    = 'starters.manage';
    case STARTERS_TEMPLATES = 'starters.templates';

    // ---- Tickets -----------------------------------------------------------
    // The module the whole per-tab design was argued FOR. Fourteen tabs, and they are
    // emphatically not the same kind of thing: MAILBOXES holds the OAuth credentials and
    // inbound mail routing — whoever has it can redirect or read the company's email —
    // and it sits on the same tab bar as TICKET_TYPES, which is a list of words.
    case TICKETS_MANAGE          = 'tickets.manage';           // umbrella
    case TICKETS_DEPARTMENTS     = 'tickets.departments';
    case TICKETS_TICKET_TYPES    = 'tickets.ticket_types';
    case TICKETS_TICKET_ORIGINS  = 'tickets.ticket_origins';
    // Categories, sub-categories and resolution codes — plus the three switches
    // deciding whether those fields appear on a ticket at all (#1540). One tab,
    // because all of it is the same kind of thing: lists of words used to classify.
    case TICKETS_CATEGORIES      = 'tickets.categories';
    // What happens when a ticket is closed with mandatory SOP steps still
    // outstanding — warn and record, or refuse (PR #141).
    case TICKETS_CHECKLISTS      = 'tickets.checklists';
    // Which ticket fields must be filled before a ticket may close, and what
    // happens when they are not - warn, warn and email, or refuse.
    case TICKETS_MANDATORY_FIELDS = 'tickets.mandatory_fields';
    // Design the custom fields a ticket type carries (#179) - what an analyst and
    // a requester are asked when a ticket is logged, and what must be filled to close.
    case TICKETS_CUSTOM_FIELDS   = 'tickets.custom_fields';
    case TICKETS_STATUSES        = 'tickets.statuses';
    case TICKETS_PRIORITIES      = 'tickets.priorities';
    case TICKETS_SLA             = 'tickets.sla';
    case TICKETS_ROTA_LOCATIONS  = 'tickets.rota_locations';
    case TICKETS_ROTA            = 'tickets.rota';
    case TICKETS_MAILBOXES       = 'tickets.mailboxes';        // OAuth credentials + mail routing
    case TICKETS_MESSAGING       = 'tickets.messaging';        // Twilio/Meta credentials
    case TICKETS_WEBCHAT         = 'tickets.webchat';          // website chat widgets
    case TICKETS_EMAIL_TEMPLATES = 'tickets.email_templates';
    case TICKETS_REPLY_TEMPLATES = 'tickets.reply_templates';  // SHARED canned responses only
    case TICKETS_MERGE           = 'tickets.merge';            // install-wide merge policy
    case TICKETS_NUMBERING       = 'tickets.numbering';        // how ticket numbers are made
    case TICKETS_GENERAL         = 'tickets.general';
    case TICKETS_PRIVACY         = 'tickets.privacy';          // what requesters see of their own ticket
    case TICKETS_REPLY_CLEANUP   = 'tickets.reply_cleanup';    // AI provider + API key
    case TICKETS_CSAT            = 'tickets.csat';
    case TICKETS_INDEXING        = 'tickets.indexing';         // how attachment text gets read

    // ---------------------------------------------------------------------------
    // What a capability knows about itself. The details live in the module's settings
    // manifest (see capRegistry()); these read them. The string-keyed cap*() functions
    // below stay, because keys read from the database arrive as strings.
    // ---------------------------------------------------------------------------

    /** The description shown in System -> Roles. */
    public function label(): string { return capLabel($this->value); }

    /** The module this capability administers (a Layer 1 module slug), or null. */
    public function module(): ?string { return capModule($this->value); }

    /** Does granting this need a second thought? Badged in the Roles picker. */
    public function isSensitive(): bool { return capIsSensitive($this->value); }

    /** Is this its module's umbrella ('<module>.manage')? */
    public function isUmbrella(): bool { return capIsUmbrella($this->value); }

    /** Is this an everyday-work permission (a manifest 'action') rather than a settings tab? */
    public function isEveryday(): bool { return capIsEveryday($this->value); }

    /**
     * Every capability belonging to a module.
     *
     * @param string $module  a Layer 1 module slug, e.g. 'assets'
     * @return array<int,Cap>
     */
    public static function forModule(string $module): array
    {
        return array_values(array_filter(array_map([self::class, 'tryFrom'], capsForModule($module))));
    }

    /**
     * Resolve a key from OUTSIDE the code - a database row, a form post - through
     * capAliases(), or null if the code no longer defines it. The trust boundary:
     * use this, not from(), on anything a person or the database supplied.
     *
     * @param string $key  an untrusted capability key
     */
    public static function fromKey(string $key): ?self
    {
        $resolved = capFromKey($key);
        return $resolved === null ? null : self::tryFrom($resolved);
    }

    /**
     * The case with this NAME ('TICKETS_SLA'), or null - for tools that read
     * "Cap::TICKETS_SLA" out of source code, such as debug tool D005.
     *
     * @param string $name  a case name, e.g. 'TICKETS_SLA'
     */
    public static function tryFromName(string $name): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) return $case;
        }
        return null;
    }
}

/**
 * A capability's stored key - 'tickets.sla' for Cap::TICKETS_SLA.
 *
 * TRAP: write capKey(Cap::X), never Cap::X->value. Reading a property straight off a
 * class constant is PHP 8.0 syntax, so on an older PHP every settings page and
 * manifest holding it fails to PARSE - and a file that cannot be parsed never reaches
 * includes/php_version_guard.php to say "this needs PHP 8.1". capKey(Cap::X) parses
 * on any PHP 7, and does exactly the same on 8.1. The PHP floor CI job checks it.
 *
 * @param Cap $cap  the capability
 */
function capKey(Cap $cap): string
{
    return $cap->value;
}

/**
 * Every module's settings manifest, loaded once.
 *
 * ---------------------------------------------------------------------------
 * THE MANIFEST IS THE SINGLE DECLARATION
 * ---------------------------------------------------------------------------
 * A module declares its settings tabs — and therefore its capabilities — in exactly one
 * file: <module>/settings/manifest.php. Everything else is DERIVED from that:
 *
 *   capRegistry()      the capabilities, their module, label and sensitivity
 *   capModules()       the module list for the Roles picker
 *   settingKeyOwners() who may write each shared system_settings key
 *   the tab bar        rendered from the same list (includes/settings_manifest.php)
 *
 * The earlier design had all four written out by hand and a self-check to catch them
 * drifting apart. Needing that check WAS the bug: four copies of one fact, kept in step
 * by discipline. They can't disagree now, because there is only one of them.
 *
 * The Cap:: constants above are the one thing that cannot be derived — they are the type,
 * and their whole value is that a typo at a call site is a fatal error rather than a
 * silent 403. capSelfCheck() proves every constant is claimed by exactly one manifest.
 *
 * Discovery is a glob, so adding a module means adding its manifest — nothing to register.
 *
 * @return array<int,array> the manifests, in module order
 */
function settingsManifests(): array
{
    static $manifests = null;
    if ($manifests !== null) return $manifests;

    $manifests = [];
    foreach (glob(__DIR__ . '/../*/settings/manifest.php') ?: [] as $path) {
        $m = require $path;
        if (is_array($m) && !empty($m['module'])) $manifests[] = $m;
    }
    usort($manifests, fn($a, $b) => strcmp($a['module'], $b['module']));
    return $manifests;
}

/**
 * The manifest for one module, or null.
 *
 * @param string $module  a Layer 1 module slug, e.g. 'assets', as named in its settings/manifest.php
 */
function settingsManifestFor(string $module): ?array
{
    foreach (settingsManifests() as $m) {
        if ($m['module'] === $module) return $m;
    }
    return null;
}

/**
 * Modules that own capabilities, and how to name them in the Roles picker.
 * DERIVED from the manifests. Keys are the Layer 1 module slugs.
 */
function capModules(): array
{
    $out = [];
    foreach (settingsManifests() as $m) {
        $out[$m['module']] = $m['label'] ?? $m['module'];
    }
    return $out;
}

/**
 * The capability registry — DERIVED from the manifests.
 *
 * A tab's 'cap' becomes a capability; its 'grant' is the description shown in the Roles
 * picker; 'sensitive' badges it. The module's 'umbrella' becomes the "manage everything
 * here" capability. Tabs declaring 'cap' => null are personal preferences and contribute
 * nothing — they are not administration and there is nothing to grant.
 *
 * @return array<string,array{module:string,umbrella:bool,sensitive:bool,label:string}>
 */
function capRegistry(): array
{
    static $registry = null;
    if ($registry !== null) return $registry;

    $registry = [];
    foreach (settingsManifests() as $m) {
        $module = $m['module'];

        if (!empty($m['umbrella']['cap'])) {
            $registry[$m['umbrella']['cap']] = [
                'module'    => $module,
                'umbrella'  => true,
                'sensitive' => !empty($m['umbrella']['sensitive']),
                'label'     => $m['umbrella']['grant'] ?? ('Manage everything in ' . ($m['label'] ?? $module) . ' settings'),
            ];
        }

        foreach ($m['tabs'] ?? [] as $tab) {
            $cap = $tab['cap'] ?? null;
            if ($cap === null) continue;               // a personal preference — nothing to grant
            if (isset($registry[$cap])) continue;      // already declared (e.g. a tab reusing the umbrella)
            $registry[$cap] = [
                'module'    => $module,
                'umbrella'  => false,
                'sensitive' => !empty($tab['sensitive']),
                'label'     => $tab['grant'] ?? $tab['id'],
            ];
        }

        // Everyday work (#180 Part 3): permissions that are not a settings tab - design a
        // form, choose a change's approvers. Each names the module setting that makes it
        // bite ('restrict'), because a deny-by-default permission added to an existing
        // install would take the ability from everybody who has it today. See
        // analystMayAct() in includes/rbac.php.
        foreach ($m['actions'] ?? [] as $action) {
            $cap = $action['cap'] ?? null;
            if ($cap === null || isset($registry[$cap])) continue;
            $registry[$cap] = [
                'module'    => $module,
                'umbrella'  => false,
                'everyday'  => true,
                'sensitive' => !empty($action['sensitive']),
                'label'     => $action['grant'] ?? $cap,
                'restrict'  => $action['restrict'] ?? null,   // ['setting' => key, 'value' => v]; null = always restricted
            ];
        }
    }
    return $registry;
}

/**
 * Retired keys -> their current replacement.
 *
 * A capability key appears in exactly one place in code (its Cap:: constant), so renaming
 * one is a one-line edit — but grants already in rbac_role_capabilities still hold the OLD
 * string. Map it here and those grants keep working; omit it and they are silently dropped
 * (which is safe, but surprising). Applied in capFromKey().
 */
function capAliases(): array
{
    return [
        // 'assets.vcentre' => capKey(Cap::ASSETS_VCENTER),   // a string: aliases are data
    ];
}

// ---------------------------------------------------------------------------
// Accessors. Named for the enum methods they will become — see the header.
// ---------------------------------------------------------------------------

/** Every declared capability key. Enum twin: Capability::cases(). */
function capAll(): array
{
    return array_keys(capRegistry());
}

/**
 * Is this a capability the code defines? Enum twin: Capability::tryFrom() !== null.
 *
 * @param string $key  a capability key, e.g. 'assets.manage'
 */
function capExists(string $key): bool
{
    return isset(capRegistry()[$key]);
}

/**
 * Resolve a key that came from OUTSIDE the code (a DB row, a form post) to a known
 * capability, or null if the code no longer defines it. Enum twin: Capability::tryFrom().
 *
 * This is the trust boundary: everything read from rbac_role_capabilities goes through
 * here, so a stale or hand-inserted key can never grant anything.
 *
 * @param string $key  a capability key read from outside the code (rbac_role_capabilities or a form post) - untrusted
 */
function capFromKey(string $key): ?string
{
    if (capExists($key)) return $key;
    $aliases = capAliases();
    if (isset($aliases[$key]) && capExists($aliases[$key])) return $aliases[$key];
    return null;
}

/**
 * Human description, shown in System -> Roles. Enum twin: $cap->label().
 *
 * @param string $key  a capability key; an unknown one is returned as its own label
 */
function capLabel(string $key): string
{
    return capRegistry()[$key]['label'] ?? $key;
}

/**
 * The module this capability administers. Enum twin: $cap->module().
 *
 * @param string $key  a capability key; an unknown one gives null
 */
function capModule(string $key): ?string
{
    return capRegistry()[$key]['module'] ?? null;
}

/**
 * Display name for a module slug. Enum twin: $module->label().
 *
 * @param string $module  a Layer 1 module slug; an unknown one is returned as its own label
 */
function capModuleLabel(string $module): string
{
    return capModules()[$module] ?? $module;
}

/**
 * Does granting this need a second thought? Enum twin: $cap->isSensitive().
 *
 * @param string $key  a capability key; an unknown one is not sensitive
 */
function capIsSensitive(string $key): bool
{
    return (bool) (capRegistry()[$key]['sensitive'] ?? false);
}

/**
 * Every capability belonging to a module. Enum twin: Capability::forModule().
 *
 * @param string $module  a Layer 1 module slug, e.g. 'assets'
 */
function capsForModule(string $module): array
{
    $out = [];
    foreach (capRegistry() as $key => $meta) {
        if ($meta['module'] === $module) $out[] = $key;
    }
    return $out;
}

/**
 * The module's umbrella capability ('<module>.manage'), or null if it has none.
 *
 * @param string $module  a Layer 1 module slug, e.g. 'assets'
 */
function capUmbrella(string $module): ?string
{
    foreach (capRegistry() as $key => $meta) {
        if ($meta['module'] === $module && !empty($meta['umbrella'])) return $key;
    }
    return null;
}

/**
 * Expand umbrella grants: holding '<module>.manage' means holding every capability in
 * that module.
 *
 * This is what makes ~70 capabilities usable — an "Asset Administrator" role is one tick,
 * not seven. It also keeps roles stable over time: add a tab next year and umbrella-holders
 * pick it up automatically, while a role that ticked individual tabs does NOT. That is the
 * safe direction, and it is why deny-by-default survives the convenience.
 *
 * @param array<int,string> $held capability keys granted directly by roles
 * @return array<int,string> those keys plus everything their umbrellas imply
 */
function capExpandUmbrellas(array $held): array
{
    $expanded = $held;
    foreach ($held as $key) {
        $meta = capRegistry()[$key] ?? null;
        if ($meta && !empty($meta['umbrella'])) {
            $expanded = array_merge($expanded, capsForModule($meta['module']));
        }
    }
    return array_values(array_unique($expanded));
}

/**
 * The registry shaped for the System -> Roles picker: grouped by module, labelled,
 * with the umbrella first so "manage everything here" reads as the headline choice.
 *
 * Generated from capRegistry(), so adding a constant + a registry entry makes the
 * tick-box appear. There is no second list to keep in step.
 */
function capGroups(): array
{
    $groups = [];
    foreach (capModules() as $module => $moduleLabel) {
        $caps = capsForModule($module);
        if (!$caps) continue;

        // Umbrella first, then everyday work, then the settings tabs alphabetically — a
        // stable, readable order, and the everyday permissions where a role-builder looks.
        $umbrella = capUmbrella($module);
        usort($caps, function ($a, $b) use ($umbrella) {
            if ($a === $umbrella) return -1;
            if ($b === $umbrella) return 1;
            $ea = capIsEveryday($a); $eb = capIsEveryday($b);
            if ($ea !== $eb) return $ea ? -1 : 1;
            return strcmp($a, $b);
        });

        $entries = [];
        foreach ($caps as $key) {
            $entries[$key] = [
                'label'     => capLabel($key),
                'sensitive' => capIsSensitive($key),
                'umbrella'  => $key === $umbrella,
                'everyday'  => capIsEveryday($key),
            ];
        }
        $groups[$module] = ['label' => $moduleLabel, 'capabilities' => $entries];
    }
    return $groups;
}

/**
 * Self-check: the things derivation CANNOT rule out.
 *
 * Most of what this used to check is now impossible by construction — the registry, the
 * module list and the setting-key map are all derived from the manifests, so they cannot
 * disagree with them. What's left is the seam that derivation doesn't cross: the Cap::
 * constants are hand-written (they have to be — they're the type), so a constant can
 * still be declared and then never claimed by any manifest.
 *
 * That is not a cosmetic problem. An unclaimed constant is a capability nobody can be
 * granted, so any guard using it 403s everyone except administrators — permanently, and
 * invisibly to the administrator, who bypasses the check. It is exactly the failure this
 * whole file exists to prevent, arriving by a different door.
 *
 * @return array<int,string> human-readable problems; empty means healthy
 */
function capSelfCheck(): array
{
    $problems = [];
    $registry = capRegistry();

    // Every Cap:: case must be claimed by some manifest.
    foreach (Cap::cases() as $case) {
        if (!isset($registry[$case->value])) {
            $problems[] = "Cap::{$case->name} ('{$case->value}') is not claimed by any settings manifest — it can never be granted, so any guard using it will 403 everyone but administrators, silently.";
        }
    }

    // A manifest must not invent a capability with no case behind it — no guard could
    // then name it, because guards take a Cap and refuse a string.
    $declared = array_flip(array_map(fn(Cap $c) => $c->value, Cap::cases()));
    foreach ($registry as $key => $meta) {
        if (!isset($declared[$key])) {
            $problems[] = "A manifest declares capability '{$key}', but there is no Cap:: constant for it — guards would have to name it as a string.";
        }
        if (empty($meta['label'])) {
            $problems[] = "Capability '{$key}' has no description ('grant') — the Roles picker would show the raw key.";
        }
    }

    // One umbrella per module, or capUmbrella() is ambiguous.
    foreach (array_keys(capModules()) as $module) {
        $umbrellas = array_values(array_filter(capsForModule($module), 'capIsUmbrella'));
        if (count($umbrellas) > 1) {
            $problems[] = "Module '{$module}' declares more than one umbrella capability (" . implode(', ', $umbrellas) . ").";
        }
    }

    // An alias must point at a capability that still exists, or the grants it rescues are dropped anyway.
    foreach (capAliases() as $old => $new) {
        if (!capExists($new)) {
            $problems[] = "Alias '{$old}' points at '{$new}', which is not a declared capability.";
        }
    }

    // Every setting key a manifest claims must be enforced by settings_keys.php, and with
    // the SAME capability — otherwise the tab's save is either unguarded or refused.
    if (function_exists('settingKeyOwner')) {
        foreach (settingsManifests() as $m) {
            foreach ($m['tabs'] ?? [] as $tab) {
                foreach ($tab['setting_keys'] ?? [] as $key) {
                    $owner = settingKeyOwner($key);
                    if ($owner === null) {
                        $problems[] = "Tab '{$m['module']}/{$tab['id']}' claims setting key '{$key}', but nothing owns it — saving that tab would be refused.";
                    } elseif (($owner['cap'] ?? null) !== ($tab['cap'] ?? null)) {
                        $problems[] = "Setting key '{$key}' is guarded by '" . var_export($owner['cap'] ?? null, true) . "' but its tab '{$m['module']}/{$tab['id']}' requires '" . var_export($tab['cap'] ?? null, true) . "'.";
                    }
                }
            }
        }
    }

    return $problems;
}

/**
 * Is this an everyday-work permission (a manifest 'action') rather than a settings tab?
 *
 * @param string $key  a capability key
 */
function capIsEveryday(string $key): bool
{
    return (bool) (capRegistry()[$key]['everyday'] ?? false);
}

/**
 * The switch that makes an everyday-work permission bite: ['setting' => key,
 * 'value' => the value meaning "restricted"], or null when it always applies.
 *
 * @param string $key  an everyday-work capability key
 */
function capActionRestriction(string $key): ?array
{
    return capRegistry()[$key]['restrict'] ?? null;
}

/**
 * Is this capability its module's umbrella?
 *
 * @param string $key  a capability key; an unknown one is not an umbrella
 */
function capIsUmbrella(string $key): bool
{
    return (bool) (capRegistry()[$key]['umbrella'] ?? false);
}
