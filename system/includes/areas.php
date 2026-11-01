<?php
/**
 * System admin areas — single source of truth.
 *
 * Each entry powers a card on the System landing page (system/index.php) and
 * the in-page search. Titles/descriptions/keywords are i18n keys (resolved via
 * t()), so all locales work; keywords carry search synonyms (e.g. "oidc" for
 * Single Sign-On) and fall back to English per-key like everything else.
 *
 * Icons are referenced by key and resolved to inline SVG in systemAreaIcon()
 * below — keeps the markup out of the data and the registry easy to scan.
 *
 * 'requires' (optional) gates an area behind a runtime condition the landing
 * page evaluates: 'multitenant' (hidden until a 2nd company exists) and
 * 'container' (only inside the Docker image), keeping the registry itself free
 * of DB lookups.
 *
 * @wiki System
 */

/**
 * Every card on the System landing page, in display order: icon key, url, the
 * i18n keys for title, description and search keywords, and an optional
 * 'requires' gate.
 *
 * @return array<int,array<string,string>> The ordered list of system areas. */
function getSystemAreas() {
    return [
        // Feature Bingo: every feature, a star for each one set up. First,
        // because it is where to start.
        [
            'icon'     => 'feature_bingo',
            'url'      => 'feature-bingo/',
            'title'    => 'system.landing.bingo_title',
            'desc'     => 'system.landing.bingo_desc',
            'keywords' => 'system.landing.bingo_keywords',
        ],
        [
            'icon'     => 'encryption',
            'url'      => 'encryption/',
            'title'    => 'system.landing.encryption_title',
            'desc'     => 'system.landing.encryption_desc',
            'keywords' => 'system.landing.encryption_keywords',
        ],
        // HTTPS for the container. Only shown inside Docker: anywhere else the
        // web server belongs to whoever runs it and nothing here could reach it.
        [
            'icon'     => 'docker',
            'url'      => 'docker/',
            'title'    => 'system.landing.docker_title',
            'desc'     => 'system.landing.docker_desc',
            'keywords' => 'system.landing.docker_keywords',
            'requires' => 'container',
        ],
        [
            'icon'     => 'analysts',
            'url'      => 'analysts/',
            'title'    => 'system.landing.analysts_title',
            'desc'     => 'system.landing.analysts_desc',
            'keywords' => 'system.landing.analysts_keywords',
        ],
        [
            'icon'     => 'teams',
            'url'      => 'teams/',
            'title'    => 'system.landing.teams_title',
            'desc'     => 'system.landing.teams_desc',
            'keywords' => 'system.landing.teams_keywords',
        ],
        [
            'icon'     => 'roles',
            'url'      => 'roles/',
            'title'    => 'system.landing.roles_title',
            'desc'     => 'system.landing.roles_desc',
            'keywords' => 'system.landing.roles_keywords',
        ],
        [
            'icon'     => 'modules',
            'url'      => 'modules/',
            'title'    => 'system.landing.modules_title',
            'desc'     => 'system.landing.modules_desc',
            'keywords' => 'system.landing.modules_keywords',
        ],
        [
            'icon'     => 'db_verify',
            'url'      => 'db-verify/',
            'title'    => 'system.landing.db_verify_title',
            'desc'     => 'system.landing.db_verify_desc',
            'keywords' => 'system.landing.db_verify_keywords',
        ],
        // Next to Database Verification on purpose: the search index is created
        // by it, and "I ran Verification, now what does search hold?" is the
        // question this screen answers.
        // What END USERS see about outages (#99). Under System rather than
        // Service Status because turning it on publishes incident titles to
        // the portal - a decision for whoever administers the install, not
        // for everybody who can raise an incident.
        [
            'icon'     => 'status_portal',
            'url'      => 'status-portal/',
            'title'    => 'system.landing.status_portal_title',
            'desc'     => 'system.landing.status_portal_desc',
            'keywords' => 'system.landing.status_portal_keywords',
        ],
        // How the portal LOOKS and what it LETS PEOPLE DO. Sits beside the
        // status portal and the portal profile: all three decide what an end
        // user meets, and an admin setting up a portal wants them together.
        [
            'icon'     => 'self_service',
            'url'      => 'self-service/',
            'title'    => 'system.landing.self_service_title',
            'desc'     => 'system.landing.self_service_desc',
            'keywords' => 'system.landing.self_service_keywords',
        ],
        // What people may change about THEMSELVES in the portal (#133). Beside
        // the status portal: both decide what the portal lets end users do.
        [
            'icon'     => 'portal_profile',
            'url'      => 'portal-profile/',
            'title'    => 'system.landing.portal_profile_title',
            'desc'     => 'system.landing.portal_profile_desc',
            'keywords' => 'system.landing.portal_profile_keywords',
        ],
        // Portal managers (discussion #62): who in the portal may see whose
        // tickets. With the other portal screens, because it decides what an end
        // user meets.
        [
            'icon'     => 'managers',
            'url'      => 'managers/',
            'title'    => 'system.landing.managers_title',
            'desc'     => 'system.landing.managers_desc',
            'keywords' => 'system.landing.managers_keywords',
        ],
        [
            'icon'     => 'search',
            'url'      => 'search/',
            'title'    => 'system.landing.search_title',
            'desc'     => 'system.landing.search_desc',
            'keywords' => 'system.landing.search_keywords',
        ],
        [
            'icon'     => 'colours',
            'url'      => 'colours/',
            'title'    => 'system.landing.colours_title',
            'desc'     => 'system.landing.colours_desc',
            'keywords' => 'system.landing.colours_keywords',
        ],
        [
            'icon'     => 'branding',
            'url'      => 'branding/',
            'title'    => 'system.landing.branding_title',
            'desc'     => 'system.landing.branding_desc',
            'keywords' => 'system.landing.branding_keywords',
        ],
        [
            'icon'     => 'security',
            'url'      => 'security/',
            'title'    => 'system.landing.security_title',
            'desc'     => 'system.landing.security_desc',
            'keywords' => 'system.landing.security_keywords',
        ],
        [
            'icon'     => 'sso',
            'url'      => 'sso/',
            'title'    => 'system.landing.sso_title',
            'desc'     => 'system.landing.sso_desc',
            'keywords' => 'system.landing.sso_keywords',
            // Directory sync from Microsoft Entra ID: users, groups, scheduling.
            'keywords_more' => 'system.landing.sso_keywords_directory',
        ],
        [
            'icon'     => 'api',
            'url'      => 'api/',
            'title'    => 'system.landing.api_title',
            'desc'     => 'system.landing.api_desc',
            'keywords' => 'system.landing.api_keywords',
        ],
        [
            'icon'     => 'webhooks',
            'url'      => 'webhooks/',
            'title'    => 'system.landing.webhooks_title',
            'desc'     => 'system.landing.webhooks_desc',
            'keywords' => 'system.landing.webhooks_keywords',
        ],
        [
            'icon'     => 'calendar_sync',
            'url'      => 'calendar-sync/',
            'title'    => 'system.landing.calsync_title',
            'desc'     => 'system.landing.calsync_desc',
            'keywords' => 'system.landing.calsync_keywords',
        ],
        [
            'icon'     => 'ai_thinking',
            'url'      => 'ai/',
            'title'    => 'system.landing.ai_title',
            'desc'     => 'system.landing.ai_desc',
            'keywords' => 'system.landing.ai_keywords',
        ],
        [
            'icon'     => 'integrations',
            'url'      => 'integrations/',
            'title'    => 'system.landing.integrations_title',
            'desc'     => 'system.landing.integrations_desc',
            'keywords' => 'system.landing.integrations_keywords',
        ],
        [
            'icon'     => 'date_formats',
            'url'      => 'date-formats/',
            'title'    => 'system.landing.date_formats_title',
            'desc'     => 'system.landing.date_formats_desc',
            'keywords' => 'system.landing.date_formats_keywords',
        ],
        [
            'icon'     => 'currencies',
            'url'      => 'currencies/',
            'title'    => 'system.landing.currencies_title',
            'desc'     => 'system.landing.currencies_desc',
            'keywords' => 'system.landing.currencies_keywords',
        ],
        [
            'icon'     => 'preferences',
            'url'      => 'preferences/',
            'title'    => 'system.landing.preferences_title',
            'desc'     => 'system.landing.preferences_desc',
            'keywords' => 'system.landing.preferences_keywords',
        ],
        // Preference templates (discussion #124): beside Preferences, which is
        // where a template's settings are set up before it is taken.
        [
            'icon'     => 'pref_templates',
            'url'      => 'preference-templates/',
            'title'    => 'system.landing.pref_templates_title',
            'desc'     => 'system.landing.pref_templates_desc',
            'keywords' => 'system.landing.pref_templates_keywords',
        ],
        [
            'icon'     => 'demo_data',
            'url'      => 'demo-data/',
            'title'    => 'system.landing.demo_data_title',
            'desc'     => 'system.landing.demo_data_desc',
            'keywords' => 'system.landing.demo_data_keywords',
        ],
        [
            'icon'     => 'debug_tools',
            'url'      => 'debug-tools/',
            'title'    => 'system.landing.debug_tools_title',
            'desc'     => 'system.landing.debug_tools_desc',
            'keywords' => 'system.landing.debug_tools_keywords',
        ],
        // The Engineering wiki: how FreeITSM is built, for developers. Replaced the
        // old System Wiki module (3.6.0) - tucked away here because a service desk
        // never needs it.
        [
            'icon'     => 'engineering_wiki',
            'url'      => 'engineering-wiki/',
            'title'    => 'system.landing.engineering_wiki_title',
            'desc'     => 'system.landing.engineering_wiki_desc',
            'keywords' => 'system.landing.engineering_wiki_keywords',
        ],
        [
            'icon'     => 'companies',
            'url'      => 'companies/',
            'title'    => 'system.landing.companies_title',
            'desc'     => 'system.landing.companies_desc',
            'keywords' => 'system.landing.companies_keywords',
        ],
        // Cost centres (GH #160). Beside Companies: every cost centre belongs to
        // one, and both are the organisation's own shape rather than a setting.
        [
            'icon'     => 'cost_centres',
            'url'      => 'cost-centres/',
            'title'    => 'system.landing.cost_centres_title',
            'desc'     => 'system.landing.cost_centres_desc',
            'keywords' => 'system.landing.cost_centres_keywords',
        ],
        [
            'icon'     => 'topology',
            'url'      => 'topology/',
            'title'    => 'system.landing.topology_title',
            'desc'     => 'system.landing.topology_desc',
            'keywords' => 'system.landing.topology_keywords',
        ],
        [
            'icon'     => 'orphaned',
            'url'      => 'orphaned-tickets/',
            'title'    => 'system.landing.orphaned_title',
            'desc'     => 'system.landing.orphaned_desc',
            'keywords' => 'system.landing.orphaned_keywords',
        ],
        [
            'icon'     => 'routing_test',
            'url'      => 'email-routing-test/',
            'title'    => 'system.landing.routing_test_title',
            'desc'     => 'system.landing.routing_test_desc',
            'keywords' => 'system.landing.routing_test_keywords',
            'requires' => 'multitenant',
        ],
        // Photo Album: just for fun - a webcam picture as ASCII art. Near the end
        // because, like Contributors, it configures nothing.
        [
            'icon'     => 'photo_album',
            'url'      => 'photo-album/',
            'title'    => 'system.landing.photo_album_title',
            'desc'     => 'system.landing.photo_album_desc',
            'keywords' => 'system.landing.photo_album_keywords',
        ],
        // Last on purpose: it configures nothing. It is here rather than in the
        // Help pages because the people listed are part of the product, and an
        // administrator poking round System is who will actually come across it.
        [
            'icon'     => 'contributors',
            'url'      => 'contributors/',
            'title'    => 'system.landing.contributors_title',
            'desc'     => 'system.landing.contributors_desc',
            'keywords' => 'system.landing.contributors_keywords',
        ],
    ];
}

/**
 * An area's search synonyms, resolved: 'keywords' plus the optional
 * 'keywords_more', each dropped when its key has no text (t() then returns the
 * key itself, which must never land in a search haystack).
 *
 * 'keywords_more' exists so a feature can add its own words without rewriting
 * an existing keywords string - changing existing English leaves every
 * translation of it silently out of date (CONTRIBUTING.md, rule 8).
 *
 * @param array $area  one entry of getSystemAreas()
 */
function systemAreaKeywords(array $area): string
{
    $out = [];
    foreach (['keywords', 'keywords_more'] as $k) {
        if (empty($area[$k])) continue;
        $kw = t($area[$k]);
        if ($kw !== $area[$k]) $out[] = $kw;
    }
    return implode(' ', $out);
}

/**
 * Return the inline SVG markup for an area icon key. Unknown keys render a
 * neutral square so a typo never breaks the page. Sizing/colour come from the
 * .system-card svg CSS rule, so no width/height/stroke colour is hard-set here.
 *
 * @param mixed $key  the icon key from an area's entry in getSystemAreas(); unknown keys draw a square
 */
function systemAreaIcon($key) {
    $icons = [
        // A star: Feature Bingo.
        'feature_bingo' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>',
        // One person above two: a reporting line (portal managers, #62).
        'managers' => '<circle cx="12" cy="5" r="2.5"></circle><circle cx="6" cy="18" r="2.5"></circle><circle cx="18" cy="18" r="2.5"></circle><path d="M12 7.5v4"></path><path d="M6 15.5v-2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"></path>',
        'status_portal' => '<path d="M3 11v2a1 1 0 0 0 1 1h3l5 4V6L7 10H4a1 1 0 0 0-1 1z"></path><path d="M16 9a4 4 0 0 1 0 6"></path><path d="M19 6.5a8 8 0 0 1 0 11"></path>',
        // A person with a pencil - "what they may edit about themselves".
        // A browser window with a brush: appearance plus behaviour, which is
        // what this screen owns.
        'self_service' => '<rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="M2 9h20"></path><path d="M13.5 14.5 16 12l2.5 2.5L16 17z"></path><path d="M6 13h4"></path><path d="M6 16h3"></path>',
        'portal_profile' => '<circle cx="9" cy="7" r="4"></circle><path d="M2 21v-2a4 4 0 0 1 4-4h5"></path><path d="M18.4 12.6a1.9 1.9 0 0 1 2.7 2.7L15 21.4l-3.6.9.9-3.6z"></path>',
        'encryption'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>',
        // A shipping container with a padlock on its door - "HTTPS for the container".
        'docker'      => '<path d="M2 7l10-4 10 4v10l-10 4-10-4z"></path><path d="M2 7l10 4 10-4"></path><path d="M12 11v10"></path><rect x="15" y="12" width="4" height="3.5" rx="0.5"></rect><path d="M15.8 12v-1a1.2 1.2 0 0 1 2.4 0v1"></path>',
        'modules'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'analysts'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="15 11 17 13 21 9"></polyline>',
        'teams'       => '<circle cx="8" cy="8" r="3.5"></circle><circle cx="17.5" cy="10" r="2.5"></circle><path d="M2 19v-1a5.5 5.5 0 0 1 11 0v1"></path><path d="M15 13.2A4 4 0 0 1 21 17v1"></path>',
        'roles'       => '<path d="M12 2l7 4v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"></path><path d="M9 12l2 2 4-4"></path>',
        'db_verify'   => '<ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>',
        // Two links of a chain — "this system joined to that one".
        'integrations' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>',
        'colours'     => '<circle cx="13.5" cy="6.5" r="2.5"></circle><circle cx="17.5" cy="10.5" r="2.5"></circle><circle cx="8.5" cy="7.5" r="2.5"></circle><circle cx="6.5" cy="12.5" r="2.5"></circle><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"></path>',
        'branding'    => '<path d="M12 19l7-7 3 3-7 7-3-3z"></path><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"></path><path d="M2 2l7.586 7.586"></path><circle cx="11" cy="11" r="2"></circle>',
        // A calendar with a clock in the corner — the two things this area formats.
        // Distinct from 'calendar_sync', which is a calendar with sync arrows.
        // A coin with a currency mark - GH #189.
        'currencies'  => '<circle cx="12" cy="12" r="10"></circle><path d="M15 8.5a3.5 3.5 0 0 0-6 1.5c0 3 6 2 6 5a3.5 3.5 0 0 1-6 1.5"></path><line x1="12" y1="5.5" x2="12" y2="7"></line><line x1="12" y1="17" x2="12" y2="18.5"></line>',
        'date_formats' => '<path d="M21 12V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h7"></path><line x1="3" y1="10" x2="21" y2="10"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="16" y1="2" x2="16" y2="6"></line><circle cx="17.5" cy="17.5" r="4.5"></circle><polyline points="17.5 15.5 17.5 17.5 19 18.6"></polyline>',
        'security'    => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>',
        'sso'         => '<path d="M15 7h3a5 5 0 0 1 5 5 5 5 0 0 1-5 5h-3m-6 0H6a5 5 0 0 1-5-5 5 5 0 0 1 5-5h3"></path><line x1="8" y1="12" x2="16" y2="12"></line>',
        'api'         => '<polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline>',
        'webhooks'    => '<path d="M18 16.98h-5.99c-1.66 0-3.01-1.34-3.01-3S10.34 11 12 11h.05"></path><path d="M8.5 9.5 12 6l3.5 3.5"></path><circle cx="18" cy="17" r="3"></circle><circle cx="6" cy="7" r="3"></circle><circle cx="15" cy="20" r="1.5" fill="currentColor" stroke="none"></circle>',
        'ai_thinking' => '<path d="M9.5 3A2.5 2.5 0 0 1 12 5.5v13a2.5 2.5 0 0 1-4.96.44A2.5 2.5 0 0 1 4 16.5a2.5 2.5 0 0 1-.9-4.34A2.5 2.5 0 0 1 4.6 7.2 2.5 2.5 0 0 1 7 4.5 2.5 2.5 0 0 1 9.5 3z"></path><path d="M14.5 3A2.5 2.5 0 0 0 12 5.5v13a2.5 2.5 0 0 0 4.96.44A2.5 2.5 0 0 0 20 16.5a2.5 2.5 0 0 0 .9-4.34 2.5 2.5 0 0 0-1.5-4.96A2.5 2.5 0 0 0 17 4.5 2.5 2.5 0 0 0 14.5 3z"></path>',
        'calendar_sync' => '<rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><polyline points="9 15 11 17 15 13"></polyline>',
        'pref_templates' => '<rect x="3" y="3" width="14" height="14" rx="2"></rect><path d="M7 21h12a2 2 0 0 0 2-2V7"></path><line x1="7" y1="8" x2="13" y2="8"></line><line x1="7" y1="12" x2="11" y2="12"></line>',
        'preferences' => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
        'demo_data'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line>',
        // An open book with code brackets on its pages - the Engineering wiki.
        'engineering_wiki' => '<path d="M2 4h7a3 3 0 0 1 3 3v13a2.5 2.5 0 0 0-2.5-2.5H2z"></path><path d="M22 4h-7a3 3 0 0 0-3 3v13a2.5 2.5 0 0 1 2.5-2.5H22z"></path><polyline points="6.5 9 4.8 10.8 6.5 12.6"></polyline><polyline points="17.5 9 19.2 10.8 17.5 12.6"></polyline>',
        'debug_tools' => '<path d="M8 2v4"></path><path d="M16 2v4"></path><rect x="3" y="6" width="18" height="15" rx="2"></rect><path d="M3 13h18"></path><path d="M9 17l2 2 4-4"></path>',
        'companies'   => '<path d="M3 21h18"></path><path d="M9 8h1"></path><path d="M9 12h1"></path><path d="M9 16h1"></path><path d="M14 8h1"></path><path d="M14 12h1"></path><path d="M14 16h1"></path><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path>',
        // A coin over a branching line: money, split into a hierarchy.
        // A camera: the Photo Album.
        'photo_album' => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path><circle cx="12" cy="13" r="4"></circle>',
        'cost_centres' => '<circle cx="12" cy="6" r="4"></circle><path d="M12 4.5v3"></path><path d="M12 10v4"></path><path d="M5 20v-3a3 3 0 0 1 3-3h8a3 3 0 0 1 3 3v3"></path><circle cx="5" cy="20" r="1"></circle><circle cx="19" cy="20" r="1"></circle><circle cx="12" cy="20" r="1"></circle><path d="M12 14v5"></path>',
        'routing_test'=> '<rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>',
        'topology'    => '<rect x="9" y="3" width="6" height="5" rx="1"></rect><rect x="3" y="16" width="6" height="5" rx="1"></rect><rect x="15" y="16" width="6" height="5" rx="1"></rect><path d="M12 8v4M6 16v-2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"></path>',
        // An award ribbon — thanks, not administration. Deliberately not the
        // same star used inside the cards, so the tile is not just a smaller
        // copy of what it opens.
        'contributors' => '<circle cx="12" cy="8" r="6"></circle><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"></path>',
        'orphaned'    => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
        'search'      => '<circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line>',
    ];
    $inner = $icons[$key] ?? '<rect x="3" y="3" width="18" height="18" rx="2"></rect>';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
}

/**
 * The settings inside each System card, for the landing page search (#2293).
 *
 * url => [ [anchor id, heading key], ... ]. The id is on the heading itself in that
 * page (<h3 id="system-name">), so a result links straight to it; the key is the
 * SAME translation key the page prints, so the search matches what the page shows,
 * in every language. A key starting "=" is a literal, for an English-only page.
 *
 * ⚠️ Add a heading here when you add one to a System page - nothing finds it
 * otherwise. tests/system-settings-search/run.php fails if an id listed here is
 * missing from its page, so a renamed or deleted heading cannot leave a dead link.
 */
function systemAreaSettings(): array
{
    return [
        'api/' => [
            ['base-url',  'system.api.base_url'],
            ['openapi',   'system.api.openapi'],
            ['mcp',       'system.api.mcp'],
            ['api-keys',  'system.api.keys'],
        ],
        'branding/' => [
            ['system-name',     'system.branding.system_name_heading'],
            ['back-link',       'system.branding.back_link_heading'],
            ['logo',            'system.branding.logo_heading'],
            ['start-page',      'system.branding.landing_heading'],
            ['files-desktop',   'system.branding.files_heading'],
            ['sign-in-screens', 'system.branding.login_heading'],
            ['header-text',     'system.branding.header_heading'],
            ['footer-text',     'system.branding.footer_heading'],
        ],
        'calendar-sync/' => [
            ['connections',   'system.calsync.conns_heading'],
            ['inbound',       'system.calsync.inbound_heading'],
            ['people',        'system.calsync.people_heading'],
            ['subscriptions', 'system.calsync.feed_heading'],
        ],
        'companies/' => [
            ['freemail', 'system.companies.freemail_title'],
        ],
        'currencies/' => [
            ['favourites', 'system.currencies.fav_heading'],
            ['format',     'system.currencies.format_heading'],
            ['exceptions', 'system.currencies.exc_heading'],
        ],
        'date-formats/' => [
            ['date-format', 'system.dateformat.date_heading'],
            ['time-format', 'system.dateformat.time_heading'],
        ],
        'docker/' => [
            ['certificate', 'system.docker.form_heading'],
        ],
        'managers/' => [
            ['portal',       'system.managers.on_heading'],
            ['source',       'system.managers.source_heading'],
            ['can',          'system.managers.can_heading'],
            ['confidential', 'system.managers.conf_heading'],
            ['notify',       'system.managers.notify_heading'],
            ['leavers',      'system.managers.leavers_heading'],
            ['who-may-edit', 'system.managers.edit_heading'],
            ['overview',     'system.managers.overview_heading'],
        ],
        'modules/' => [
            ['conflicts',    '=When grants conflict'],
            ['access-level', '=Access level'],
            ['checker',      '=Effective access checker'],
        ],
        'portal-profile/' => [
            ['fields',    'system.portal_profile.fields_heading'],
            ['directory', 'system.portal_profile.directory_heading'],
        ],
        'preferences/' => [
            ['language',       'system.preferences.language_heading'],
            ['timezone',       'system.preferences.timezone_heading'],
            ['date-format',    'system.preferences.dateformat_heading'],
            ['time-format',    'system.preferences.timeformat_heading'],
            ['money',          'system.preferences.money_heading'],
            ['task-view',      'system.preferences.task_view_heading'],
            ['search-panel',   'system.preferences.search_dismiss_heading'],
            ['cal-subtasks',   'system.preferences.cal_subtasks_heading'],
            ['board-subtasks', 'system.preferences.board_subtasks_heading'],
            ['start-page',     'system.preferences.landing_heading'],
            ['watchtower',     'watchtower.scope.impersonal_heading'],
            ['working-hours',  'system.preferences.workcal_heading'],
            ['notifications',  'system.preferences.notif_heading'],
            ['sound',          'system.preferences.sound_heading'],
            ['desktop',        'system.preferences.desktop_heading'],
            ['task-email',     'system.preferences.task_email_heading'],
            ['position',       'system.preferences.position_heading'],
            ['animation',      'system.preferences.animation_heading'],
            ['module-order',   'system.preferences.modorder_heading'],
            ['left-panels',    'system.preferences.panels_heading'],
            ['multi-select',   'system.preferences.multiselect_heading'],
            ['morning-checks', 'system.preferences.mc_heading'],
            ['my-details',     'system.preferences.details_heading'],
            ['signature',      'system.preferences.sig_heading'],
        ],
        'search/' => [
            ['index', 'system.search.index_heading'],
        ],
        'security/' => [
            ['self-registration', 'system.security.selfreg_heading'],
            ['trusted-devices',   'system.security.trusted_heading'],
            ['passwords',         'system.security.password_heading'],
            ['lockout',           'system.security.lockout_heading'],
            ['ip-bans',           'system.security.ipban_heading'],
            ['attachments',       'system.security.attachments_heading'],
        ],
        'self-service/' => [
            ['appearance', 'system.self_service.appearance_heading'],
            ['behaviour',  'system.self_service.behaviour_heading'],
        ],
        'sso/' => [
            ['global',    'system.sso.global_heading'],
            ['redirect',  'system.sso.redirect_heading'],
            ['providers', 'system.sso.providers_heading'],
        ],
        'status-portal/' => [
            ['panel', 'system.status_portal.panel_heading'],
        ],
        'webhooks/' => [
            ['data-protection', 'system.webhooks.dp_title'],
        ],
    ];
}
