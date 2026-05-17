<?php
/**
 * മലയാളം (ml) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'സംരക്ഷിക്കുക',
    'cancel'       => 'റദ്ദാക്കുക',
    'delete'       => 'ഇല്ലാതാക്കുക',
    'add'          => 'ചേർക്കുക',
    'edit'         => 'എഡിറ്റുചെയ്യുക',
    'close'        => 'അടയ്ക്കുക',
    'copy'         => 'പകർത്തുക',
    'copied'       => 'പകർത്തി',
    'retry'        => 'വീണ്ടും ശ്രമിക്കുക',
    'export'       => 'കയറ്റുമതി',
    'open'         => 'തുറക്കുക',
    'apply'        => 'പ്രയോഗിക്കുക',

    'yes'          => 'അതെ',
    'no'           => 'ഇല്ല',
    'ok'           => 'ശരി',
    'loading'      => 'ലോഡുചെയ്യുന്നു…',
    'saving'       => 'സംരക്ഷിക്കുന്നു…',
    'saved'        => 'സംരക്ഷിച്ചു',
    'unsaved'      => 'സംരക്ഷിച്ചിട്ടില്ല',
    'unsaved_changes' => 'സംരക്ഷിക്കാത്ത മാറ്റങ്ങൾ',
    'failed'       => 'പരാജയപ്പെട്ടു',

    'just_now'     => 'ഇപ്പോൾ തന്നെ',
    'today'        => 'ഇന്ന്',
    'yesterday'    => 'ഇന്നലെ',

    'required'     => 'ആവശ്യമാണ്',
    'optional'     => 'ഓപ്ഷണൽ',
    'select_one'   => 'തിരഞ്ഞെടുക്കുക…',
    'search'       => 'തിരയുക',

    'error_generic'       => 'എന്തോ കുഴപ്പം സംഭവിച്ചു.',
    'error_network'       => 'നെറ്റ്‌വർക്ക് പിശക്',
    'error_not_logged_in' => 'നിങ്ങൾ ലോഗിൻ ചെയ്യണം.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'സേവന കേന്ദ്രം',
        'browser_title'    => 'സേവന കേന്ദ്രം - ITSM',
        'welcome_heading'  => 'നിങ്ങൾക്ക് എന്താണ് ചെയ്യേണ്ടത്?',
        'welcome_subtitle' => 'ആരംഭിക്കാൻ ഒരു മൊഡ്യൂൾ തിരഞ്ഞെടുക്കുക',
        'footer'           => 'സേവന കേന്ദ്രം ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM മൊഡ്യൂളുകൾ',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'നിരീക്ഷണം',     'description' => 'എല്ലാ മൊഡ്യൂളുകൾക്കും ഏകീകൃത ശ്രദ്ധാ ഡാഷ്‌ബോർഡ്'],
        'tickets'        => ['name' => 'ടിക്കറ്റുകൾ',  'description' => 'പിന്തുണാ അഭ്യർത്ഥനകൾ, ഇമെയിലുകൾ, ഉപയോക്തൃ പ്രശ്നങ്ങൾ കൈകാര്യം ചെയ്യുക'],
        'assets'         => ['name' => 'ആസ്തികൾ',       'description' => 'IT ആസ്തികളും ഉപയോക്തൃ അസൈൻമെന്റുകളും ട്രാക്ക് ചെയ്യുക'],
        'knowledge'      => ['name' => 'അറിവ്',          'description' => 'അറിവ് അടിസ്ഥാന ലേഖനങ്ങൾ സൃഷ്ടിക്കുകയും കാണുകയും ചെയ്യുക'],
        'changes'        => ['name' => 'മാറ്റങ്ങൾ',     'description' => 'IT മാറ്റങ്ങൾ ആസൂത്രണം ചെയ്യുകയും ട്രാക്ക് ചെയ്യുകയും കൈകാര്യം ചെയ്യുകയും ചെയ്യുക'],
        'calendar'       => ['name' => 'കലണ്ടർ',        'description' => 'ഇവന്റുകൾ, അവസാന തീയതികൾ, ഷെഡ്യൂളുകൾ എന്നിവ ട്രാക്ക് ചെയ്യുക'],
        'morning-checks' => ['name' => 'പരിശോധനകൾ',    'description' => 'ദൈനംദിന ഇൻഫ്രാസ്ട്രക്ചർ പരിശോധനകൾ രേഖപ്പെടുത്തുക'],
        'reporting'      => ['name' => 'റിപ്പോർട്ടിംഗ്','description' => 'സിസ്റ്റം ലോഗുകളും അനലിറ്റിക്സും കാണുക'],
        'software'       => ['name' => 'സോഫ്റ്റ്‌വെയർ', 'description' => 'സോഫ്റ്റ്‌വെയർ ഇൻവെന്ററിയും ലൈസൻസിംഗും ബ്രൗസ് ചെയ്യുക'],
        'forms'          => ['name' => 'ഫോമുകൾ',       'description' => 'ഇഷ്ടാനുസൃത ഫോമുകൾ രൂപകൽപ്പന ചെയ്യുകയും സമർപ്പണങ്ങൾ കാണുകയും ചെയ്യുക'],
        'contracts'      => ['name' => 'കരാറുകൾ',      'description' => 'വിതരണക്കാർ, കോൺടാക്റ്റുകൾ, കരാറുകൾ എന്നിവ കൈകാര്യം ചെയ്യുക'],
        'service-status' => ['name' => 'സ്ഥിതി',         'description' => 'സേവന ആരോഗ്യം നിരീക്ഷിക്കുകയും സംഭവങ്ങൾ ട്രാക്ക് ചെയ്യുകയും ചെയ്യുക'],
        'wiki'           => ['name' => 'വിക്കി',         'description' => 'സ്വയം-സൃഷ്ടിച്ച കോഡ്‌ബേസ് ഡോക്യുമെന്റേഷൻ ബ്രൗസ് ചെയ്യുക'],
        'lms'            => ['name' => 'LMS',            'description' => 'SCORM കോഴ്സ് പ്ലെയറുമായി പഠന മാനേജ്മെന്റ് സിസ്റ്റം'],
        'process-mapper' => ['name' => 'പ്രക്രിയകൾ',    'description' => 'വിഷ്വൽ ഫ്ലോചാർട്ടും പ്രക്രിയ മാപ്പിംഗ് ടൂളും'],
        'tasks'          => ['name' => 'ജോലികൾ',       'description' => 'ജോലികൾ ട്രാക്ക് ചെയ്യാൻ കാൻബാൻ ബോർഡും ലിസ്റ്റ് കാഴ്ചയും'],
        'cmdb'           => ['name' => 'CMDB',           'description' => 'കോൺഫിഗറേഷൻ മാനേജ്മെന്റ് ഡാറ്റാബേസ്'],
        'network-mapper' => ['name' => 'നെറ്റ്‌വർക്ക്', 'description' => 'നെറ്റ്‌വർക്ക് ഡയഗ്രമുകൾ രൂപകൽപ്പന ചെയ്യുകയും രേഖപ്പെടുത്തുകയും ചെയ്യുക'],
        'system'         => ['name' => 'സിസ്റ്റം',      'description' => 'സിസ്റ്റം അഡ്മിനിസ്ട്രേഷനും കോൺഫിഗറേഷനും'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'പുതിയ ഇമെയിലുകൾക്കായി പരിശോധിക്കുക',
        'change_password' => 'പാസ്‌വേഡ് മാറ്റുക',
        'mfa'             => 'മൾട്ടി-ഫാക്ടർ പ്രാമാണീകരണം',
        'trusted_device'  => 'വിശ്വസനീയ ഉപകരണം',
        'logout'          => 'ലോഗ് ഔട്ട്',
        'logout_confirm'  => 'നിങ്ങൾക്ക് തീർച്ചയായും ലോഗ് ഔട്ട് ചെയ്യണോ?',
        'badge_off'       => 'ഓഫ്',
        'badge_on'        => 'ഓൺ',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'പാസ്‌വേഡ് മാറ്റുക',
        'current_password' => 'നിലവിലെ പാസ്‌വേഡ്',
        'new_password'     => 'പുതിയ പാസ്‌വേഡ്',
        'confirm_password' => 'പുതിയ പാസ്‌വേഡ് സ്ഥിരീകരിക്കുക',
        'submit'           => 'പാസ്‌വേഡ് മാറ്റുക',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'മൾട്ടി-ഫാക്ടർ പ്രാമാണീകരണം',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'മുമ്പത്തെ',
        'next'     => 'അടുത്തത്',
        'today'    => 'ഇന്ന്',

        'months' => [
            'january'   => 'ജനുവരി',
            'february'  => 'ഫെബ്രുവരി',
            'march'     => 'മാർച്ച്',
            'april'     => 'ഏപ്രിൽ',
            'may'       => 'മേയ്',
            'june'      => 'ജൂൺ',
            'july'      => 'ജൂലൈ',
            'august'    => 'ഓഗസ്റ്റ്',
            'september' => 'സെപ്റ്റംബർ',
            'october'   => 'ഒക്ടോബർ',
            'november'  => 'നവംബർ',
            'december'  => 'ഡിസംബർ',
        ],

        'weekdays' => [
            'monday'    => 'തിങ്കൾ',
            'tuesday'   => 'ചൊവ്വ',
            'wednesday' => 'ബുധൻ',
            'thursday'  => 'വ്യാഴം',
            'friday'    => 'വെള്ളി',
            'saturday'  => 'ശനി',
            'sunday'    => 'ഞായർ',
        ],
    ],
];
