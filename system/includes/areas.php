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
 * page evaluates: currently only 'multitenant' (hidden until a 2nd company
 * exists), keeping the registry itself free of DB lookups.
 */

/** @return array<int,array<string,string>> The ordered list of system areas. */
function getSystemAreas() {
    return [
        [
            'icon'     => 'encryption',
            'url'      => 'encryption/',
            'title'    => 'system.landing.encryption_title',
            'desc'     => 'system.landing.encryption_desc',
            'keywords' => 'system.landing.encryption_keywords',
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
            'icon'     => 'preferences',
            'url'      => 'preferences/',
            'title'    => 'system.landing.preferences_title',
            'desc'     => 'system.landing.preferences_desc',
            'keywords' => 'system.landing.preferences_keywords',
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
        [
            'icon'     => 'companies',
            'url'      => 'companies/',
            'title'    => 'system.landing.companies_title',
            'desc'     => 'system.landing.companies_desc',
            'keywords' => 'system.landing.companies_keywords',
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
    ];
}

/**
 * Return the inline SVG markup for an area icon key. Unknown keys render a
 * neutral square so a typo never breaks the page. Sizing/colour come from the
 * .system-card svg CSS rule, so no width/height/stroke colour is hard-set here.
 */
function systemAreaIcon($key) {
    $icons = [
        'encryption'  => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>',
        'modules'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'analysts'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="15 11 17 13 21 9"></polyline>',
        'teams'       => '<circle cx="8" cy="8" r="3.5"></circle><circle cx="17.5" cy="10" r="2.5"></circle><path d="M2 19v-1a5.5 5.5 0 0 1 11 0v1"></path><path d="M15 13.2A4 4 0 0 1 21 17v1"></path>',
        'db_verify'   => '<ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"></path><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"></path>',
        'colours'     => '<circle cx="13.5" cy="6.5" r="2.5"></circle><circle cx="17.5" cy="10.5" r="2.5"></circle><circle cx="8.5" cy="7.5" r="2.5"></circle><circle cx="6.5" cy="12.5" r="2.5"></circle><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"></path>',
        'branding'    => '<path d="M12 19l7-7 3 3-7 7-3-3z"></path><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"></path><path d="M2 2l7.586 7.586"></path><circle cx="11" cy="11" r="2"></circle>',
        'security'    => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>',
        'sso'         => '<path d="M15 7h3a5 5 0 0 1 5 5 5 5 0 0 1-5 5h-3m-6 0H6a5 5 0 0 1-5-5 5 5 0 0 1 5-5h3"></path><line x1="8" y1="12" x2="16" y2="12"></line>',
        'api'         => '<polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline>',
        'webhooks'    => '<path d="M18 16.98h-5.99c-1.66 0-3.01-1.34-3.01-3S10.34 11 12 11h.05"></path><path d="M8.5 9.5 12 6l3.5 3.5"></path><circle cx="18" cy="17" r="3"></circle><circle cx="6" cy="7" r="3"></circle><circle cx="15" cy="20" r="1.5" fill="currentColor" stroke="none"></circle>',
        'preferences' => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
        'demo_data'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line>',
        'debug_tools' => '<path d="M8 2v4"></path><path d="M16 2v4"></path><rect x="3" y="6" width="18" height="15" rx="2"></rect><path d="M3 13h18"></path><path d="M9 17l2 2 4-4"></path>',
        'companies'   => '<path d="M3 21h18"></path><path d="M9 8h1"></path><path d="M9 12h1"></path><path d="M9 16h1"></path><path d="M14 8h1"></path><path d="M14 12h1"></path><path d="M14 16h1"></path><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"></path>',
        'routing_test'=> '<rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>',
        'topology'    => '<rect x="9" y="3" width="6" height="5" rx="1"></rect><rect x="3" y="16" width="6" height="5" rx="1"></rect><rect x="15" y="16" width="6" height="5" rx="1"></rect><path d="M12 8v4M6 16v-2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2"></path>',
        'orphaned'    => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
    ];
    $inner = $icons[$key] ?? '<rect x="3" y="3" width="18" height="18" rx="2"></rect>';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
}
