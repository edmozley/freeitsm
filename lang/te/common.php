<?php
/**
 * తెలుగు (te) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'భద్రపరచు',
    'cancel'       => 'రద్దు',
    'delete'       => 'తొలగించు',
    'add'          => 'జోడించు',
    'edit'         => 'సవరించు',
    'close'        => 'మూసివేయి',
    'copy'         => 'కాపీ',
    'copied'       => 'కాపీ చేయబడింది',
    'retry'        => 'మళ్ళీ ప్రయత్నించు',
    'export'       => 'ఎగుమతి',
    'open'         => 'తెరువు',
    'apply'        => 'వర్తింపజేయి',

    'yes'          => 'అవును',
    'no'           => 'కాదు',
    'ok'           => 'సరే',
    'loading'      => 'లోడ్ అవుతోంది…',
    'saving'       => 'భద్రపరుస్తోంది…',
    'saved'        => 'భద్రపరచబడింది',
    'unsaved'      => 'భద్రపరచబడలేదు',
    'unsaved_changes' => 'భద్రపరచబడని మార్పులు',
    'failed'       => 'విఫలమైంది',

    'just_now'     => 'ఇప్పుడే',
    'today'        => 'ఈ రోజు',
    'yesterday'    => 'నిన్న',

    'required'     => 'అవసరం',
    'optional'     => 'ఐచ్ఛికం',
    'select_one'   => 'ఎంచుకోండి…',
    'search'       => 'శోధన',

    'error_generic'       => 'ఏదో తప్పు జరిగింది.',
    'error_network'       => 'నెట్‌వర్క్ లోపం',
    'error_not_logged_in' => 'మీరు లాగిన్ చేయాలి.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'సేవా కేంద్రం',
        'browser_title'    => 'సేవా కేంద్రం - ITSM',
        'welcome_heading'  => 'మీరు ఏమి చేయాలనుకుంటున్నారు?',
        'welcome_subtitle' => 'ప్రారంభించడానికి ఒక మాడ్యూల్‌ను ఎంచుకోండి',
        'footer'           => 'సేవా కేంద్రం ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM మాడ్యూల్స్',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'పర్యవేక్షణ',   'description' => 'అన్ని మాడ్యూల్‌లకు ఏకీకృత దృష్టి డాష్‌బోర్డ్'],
        'tickets'        => ['name' => 'టికెట్లు',     'description' => 'మద్దతు అభ్యర్థనలు, ఇమెయిల్‌లు మరియు వినియోగదారు సమస్యలను నిర్వహించండి'],
        'assets'         => ['name' => 'ఆస్తులు',      'description' => 'IT ఆస్తులు మరియు వినియోగదారు కేటాయింపులను ట్రాక్ చేయండి'],
        'knowledge'      => ['name' => 'జ్ఞానం',       'description' => 'జ్ఞాన స్థావరం వ్యాసాలను సృష్టించండి మరియు బ్రౌజ్ చేయండి'],
        'changes'        => ['name' => 'మార్పులు',     'description' => 'IT మార్పులను ప్రణాళిక చేయండి, ట్రాక్ చేయండి మరియు నిర్వహించండి'],
        'calendar'       => ['name' => 'క్యాలెండర్',   'description' => 'ఈవెంట్‌లు, గడువులు మరియు షెడ్యూల్‌లను ట్రాక్ చేయండి'],
        'morning-checks' => ['name' => 'తనిఖీలు',      'description' => 'రోజువారీ మౌలిక సదుపాయాల తనిఖీలను నమోదు చేయండి'],
        'reporting'      => ['name' => 'రిపోర్టింగ్',  'description' => 'సిస్టమ్ లాగ్‌లు మరియు విశ్లేషణలను చూడండి'],
        'software'       => ['name' => 'సాఫ్ట్‌వేర్',  'description' => 'సాఫ్ట్‌వేర్ జాబితా మరియు లైసెన్సింగ్‌ను బ్రౌజ్ చేయండి'],
        'forms'          => ['name' => 'ఫారాలు',      'description' => 'అనుకూల ఫారాలను రూపొందించండి మరియు సమర్పణలను చూడండి'],
        'contracts'      => ['name' => 'ఒప్పందాలు',   'description' => 'సరఫరాదారులు, పరిచయాలు మరియు ఒప్పందాలను నిర్వహించండి'],
        'service-status' => ['name' => 'స్థితి',       'description' => 'సేవా ఆరోగ్యాన్ని పర్యవేక్షించండి మరియు సంఘటనలను ట్రాక్ చేయండి'],
        'wiki'           => ['name' => 'వికీ',         'description' => 'స్వయంచాలకంగా రూపొందించిన కోడ్‌బేస్ డాక్యుమెంటేషన్‌ను బ్రౌజ్ చేయండి'],
        'lms'            => ['name' => 'LMS',          'description' => 'SCORM కోర్సు ప్లేయర్‌తో అభ్యాస నిర్వహణ వ్యవస్థ'],
        'process-mapper' => ['name' => 'ప్రక్రియలు',   'description' => 'దృశ్య ఫ్లోచార్ట్ మరియు ప్రక్రియ మ్యాపింగ్ సాధనం'],
        'tasks'          => ['name' => 'పనులు',        'description' => 'పనులను ట్రాక్ చేయడానికి కాన్బన్ బోర్డ్ మరియు జాబితా వీక్షణ'],
        'cmdb'           => ['name' => 'CMDB',         'description' => 'కాన్ఫిగరేషన్ నిర్వహణ డేటాబేస్'],
        'network-mapper' => ['name' => 'నెట్‌వర్క్',   'description' => 'నెట్‌వర్క్ రేఖాచిత్రాలను రూపొందించండి మరియు డాక్యుమెంట్ చేయండి'],
        'system'         => ['name' => 'సిస్టమ్',      'description' => 'సిస్టమ్ నిర్వహణ మరియు కాన్ఫిగరేషన్'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'కొత్త ఇమెయిల్‌ల కోసం తనిఖీ చేయండి',
        'change_password' => 'పాస్‌వర్డ్‌ను మార్చండి',
        'mfa'             => 'బహుళ-కారక ప్రామాణీకరణ',
        'trusted_device'  => 'విశ్వసనీయ పరికరం',
        'logout'          => 'లాగ్ అవుట్',
        'logout_confirm'  => 'మీరు ఖచ్చితంగా లాగ్ అవుట్ చేయాలనుకుంటున్నారా?',
        'badge_off'       => 'ఆఫ్',
        'badge_on'        => 'ఆన్',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'పాస్‌వర్డ్‌ను మార్చండి',
        'current_password' => 'ప్రస్తుత పాస్‌వర్డ్',
        'new_password'     => 'కొత్త పాస్‌వర్డ్',
        'confirm_password' => 'కొత్త పాస్‌వర్డ్‌ను నిర్ధారించండి',
        'submit'           => 'పాస్‌వర్డ్‌ను మార్చండి',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'బహుళ-కారక ప్రామాణీకరణ',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'మునుపటి',
        'next'     => 'తదుపరి',
        'today'    => 'ఈ రోజు',

        'months' => [
            'january'   => 'జనవరి',
            'february'  => 'ఫిబ్రవరి',
            'march'     => 'మార్చి',
            'april'     => 'ఏప్రిల్',
            'may'       => 'మే',
            'june'      => 'జూన్',
            'july'      => 'జూలై',
            'august'    => 'ఆగస్టు',
            'september' => 'సెప్టెంబర్',
            'october'   => 'అక్టోబర్',
            'november'  => 'నవంబర్',
            'december'  => 'డిసెంబర్',
        ],

        'weekdays' => [
            'monday'    => 'సోమవారం',
            'tuesday'   => 'మంగళవారం',
            'wednesday' => 'బుధవారం',
            'thursday'  => 'గురువారం',
            'friday'    => 'శుక్రవారం',
            'saturday'  => 'శనివారం',
            'sunday'    => 'ఆదివారం',
        ],
    ],
];
