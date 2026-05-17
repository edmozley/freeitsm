<?php
/**
 * தமிழ் (ta) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'சேமி',
    'cancel'       => 'ரத்து',
    'delete'       => 'நீக்கு',
    'add'          => 'சேர்',
    'edit'         => 'திருத்து',
    'close'        => 'மூடு',
    'copy'         => 'நகலெடு',
    'copied'       => 'நகலெடுக்கப்பட்டது',
    'retry'        => 'மீண்டும் முயற்சி',
    'export'       => 'ஏற்றுமதி',
    'open'         => 'திற',
    'apply'        => 'பயன்படுத்து',

    'yes'          => 'ஆம்',
    'no'           => 'இல்லை',
    'ok'           => 'சரி',
    'loading'      => 'ஏற்றப்படுகிறது…',
    'saving'       => 'சேமிக்கப்படுகிறது…',
    'saved'        => 'சேமிக்கப்பட்டது',
    'unsaved'      => 'சேமிக்கப்படவில்லை',
    'unsaved_changes' => 'சேமிக்கப்படாத மாற்றங்கள்',
    'failed'       => 'தோல்வி',

    'just_now'     => 'இப்போதே',
    'today'        => 'இன்று',
    'yesterday'    => 'நேற்று',

    'required'     => 'தேவை',
    'optional'     => 'விருப்பத்தேர்வு',
    'select_one'   => 'தேர்வு செய்க…',
    'search'       => 'தேடு',

    'error_generic'       => 'ஏதோ தவறு நடந்துள்ளது.',
    'error_network'       => 'பிணைய பிழை',
    'error_not_logged_in' => 'நீங்கள் உள்நுழைய வேண்டும்.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'சேவை மையம்',
        'browser_title'    => 'சேவை மையம் - ITSM',
        'welcome_heading'  => 'நீங்கள் என்ன செய்ய விரும்புகிறீர்கள்?',
        'welcome_subtitle' => 'தொடங்க ஒரு பகுதியைத் தேர்ந்தெடுக்கவும்',
        'footer'           => 'சேவை மையம் ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM பகுதிகள்',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'கண்காணிப்பு',  'description' => 'அனைத்து பகுதிகளுக்கும் ஒருங்கிணைந்த கவன டாஷ்போர்டு'],
        'tickets'        => ['name' => 'டிக்கெட்டுகள்','description' => 'ஆதரவு கோரிக்கைகள், மின்னஞ்சல்கள் மற்றும் பயனர் சிக்கல்களை நிர்வகிக்கவும்'],
        'assets'         => ['name' => 'சொத்துகள்',    'description' => 'IT சொத்துகள் மற்றும் பயனர் ஒதுக்கீடுகளைக் கண்காணிக்கவும்'],
        'knowledge'      => ['name' => 'அறிவுத்தளம்',  'description' => 'அறிவுத்தள கட்டுரைகளை உருவாக்கி உலாவவும்'],
        'changes'        => ['name' => 'மாற்றங்கள்',   'description' => 'IT மாற்றங்களை திட்டமிடவும், கண்காணிக்கவும், நிர்வகிக்கவும்'],
        'calendar'       => ['name' => 'நாட்காட்டி',   'description' => 'நிகழ்வுகள், காலக்கெடுக்கள் மற்றும் அட்டவணைகளைக் கண்காணிக்கவும்'],
        'morning-checks' => ['name' => 'சரிபார்ப்புகள்','description' => 'தினசரி உள்கட்டமைப்பு சரிபார்ப்புகளை பதிவு செய்யவும்'],
        'reporting'      => ['name' => 'அறிக்கைகள்',   'description' => 'கணினி பதிவுகள் மற்றும் பகுப்பாய்வுகளைப் பார்க்கவும்'],
        'software'       => ['name' => 'மென்பொருள்',   'description' => 'மென்பொருள் பட்டியல் மற்றும் உரிமங்களை உலாவவும்'],
        'forms'          => ['name' => 'படிவங்கள்',    'description' => 'தனிப்பயன் படிவங்களை வடிவமைத்து சமர்ப்பிப்புகளைப் பார்க்கவும்'],
        'contracts'      => ['name' => 'ஒப்பந்தங்கள்', 'description' => 'வழங்குநர்கள், தொடர்புகள் மற்றும் ஒப்பந்தங்களை நிர்வகிக்கவும்'],
        'service-status' => ['name' => 'நிலை',         'description' => 'சேவை ஆரோக்கியத்தைக் கண்காணித்து சம்பவங்களைப் பதிவு செய்யவும்'],
        'wiki'           => ['name' => 'விக்கி',       'description' => 'தானாக உருவாக்கப்பட்ட குறியீட்டு ஆவணங்களை உலாவவும்'],
        'lms'            => ['name' => 'LMS',          'description' => 'SCORM பாட பிளேயருடன் கற்றல் மேலாண்மை அமைப்பு'],
        'process-mapper' => ['name' => 'செயல்முறைகள்', 'description' => 'காட்சி ஓட்ட விளக்கப்படம் மற்றும் செயல்முறை மேப்பிங் கருவி'],
        'tasks'          => ['name' => 'பணிகள்',       'description' => 'பணிகளைக் கண்காணிக்க கான்பான் பலகை மற்றும் பட்டியல் காட்சி'],
        'cmdb'           => ['name' => 'CMDB',         'description' => 'கட்டமைப்பு மேலாண்மை தரவுத்தளம்'],
        'network-mapper' => ['name' => 'பிணையம்',      'description' => 'பிணைய வரைபடங்களை வடிவமைக்கவும் ஆவணப்படுத்தவும்'],
        'system'         => ['name' => 'கணினி',        'description' => 'கணினி நிர்வாகம் மற்றும் கட்டமைப்பு'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'புதிய மின்னஞ்சல்களைச் சரிபார்க்கவும்',
        'change_password' => 'கடவுச்சொல்லை மாற்று',
        'mfa'             => 'பல-காரணி அங்கீகாரம்',
        'trusted_device'  => 'நம்பகமான சாதனம்',
        'logout'          => 'வெளியேறு',
        'logout_confirm'  => 'நீங்கள் நிச்சயமாக வெளியேற விரும்புகிறீர்களா?',
        'badge_off'       => 'அணைக்க',
        'badge_on'        => 'இயக்கு',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'கடவுச்சொல்லை மாற்று',
        'current_password' => 'தற்போதைய கடவுச்சொல்',
        'new_password'     => 'புதிய கடவுச்சொல்',
        'confirm_password' => 'புதிய கடவுச்சொல்லை உறுதிப்படுத்து',
        'submit'           => 'கடவுச்சொல்லை மாற்று',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'பல-காரணி அங்கீகாரம்',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'முந்தைய',
        'next'     => 'அடுத்து',
        'today'    => 'இன்று',

        'months' => [
            'january'   => 'ஜனவரி',
            'february'  => 'பிப்ரவரி',
            'march'     => 'மார்ச்',
            'april'     => 'ஏப்ரல்',
            'may'       => 'மே',
            'june'      => 'ஜூன்',
            'july'      => 'ஜூலை',
            'august'    => 'ஆகஸ்ட்',
            'september' => 'செப்டம்பர்',
            'october'   => 'அக்டோபர்',
            'november'  => 'நவம்பர்',
            'december'  => 'டிசம்பர்',
        ],

        'weekdays' => [
            'monday'    => 'திங்கள்',
            'tuesday'   => 'செவ்வாய்',
            'wednesday' => 'புதன்',
            'thursday'  => 'வியாழன்',
            'friday'    => 'வெள்ளி',
            'saturday'  => 'சனி',
            'sunday'    => 'ஞாயிறு',
        ],
    ],
];
