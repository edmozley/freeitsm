<?php
/**
 * मराठी (mr) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'जतन करा',
    'cancel'       => 'रद्द करा',
    'delete'       => 'हटवा',
    'add'          => 'जोडा',
    'edit'         => 'संपादित करा',
    'close'        => 'बंद',
    'copy'         => 'कॉपी',
    'copied'       => 'कॉपी झाले',
    'retry'        => 'पुन्हा प्रयत्न करा',
    'export'       => 'निर्यात',
    'open'         => 'उघडा',
    'apply'        => 'लागू करा',

    'yes'          => 'होय',
    'no'           => 'नाही',
    'ok'           => 'ठीक',
    'loading'      => 'लोड होत आहे…',
    'saving'       => 'जतन करत आहे…',
    'saved'        => 'जतन केले',
    'unsaved'      => 'जतन केलेले नाही',
    'unsaved_changes' => 'जतन न केलेले बदल',
    'failed'       => 'अयशस्वी',

    'just_now'     => 'आत्ताच',
    'today'        => 'आज',
    'yesterday'    => 'काल',

    'required'     => 'आवश्यक',
    'optional'     => 'पर्यायी',
    'select_one'   => 'निवडा…',
    'search'       => 'शोधा',

    'error_generic'       => 'काहीतरी चूक झाली.',
    'error_network'       => 'नेटवर्क त्रुटी',
    'error_not_logged_in' => 'तुम्हाला लॉग इन करावे लागेल.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'सेवा डेस्क',
        'browser_title'    => 'सेवा डेस्क - ITSM',
        'welcome_heading'  => 'तुम्हाला काय करायचे आहे?',
        'welcome_subtitle' => 'सुरू करण्यासाठी एक मॉड्यूल निवडा',
        'footer'           => 'सेवा डेस्क ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM मॉड्यूल',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'निरीक्षण',     'description' => 'सर्व मॉड्यूलसाठी एकत्रित लक्ष डॅशबोर्ड'],
        'tickets'        => ['name' => 'तिकिटे',       'description' => 'समर्थन विनंत्या, ईमेल आणि वापरकर्ता समस्या व्यवस्थापित करा'],
        'assets'         => ['name' => 'मालमत्ता',     'description' => 'IT मालमत्ता आणि वापरकर्ता वाटप ट्रॅक करा'],
        'knowledge'      => ['name' => 'ज्ञान',        'description' => 'ज्ञान आधार लेख तयार करा आणि पहा'],
        'changes'        => ['name' => 'बदल',          'description' => 'IT बदलांची योजना, ट्रॅकिंग आणि व्यवस्थापन करा'],
        'calendar'       => ['name' => 'दिनदर्शिका',   'description' => 'कार्यक्रम, अंतिम मुदती आणि वेळापत्रके ट्रॅक करा'],
        'morning-checks' => ['name' => 'तपासणी',       'description' => 'दैनिक पायाभूत सुविधा तपासणी नोंदवा'],
        'reporting'      => ['name' => 'अहवाल',        'description' => 'सिस्टम लॉग आणि विश्लेषण पहा'],
        'software'       => ['name' => 'सॉफ्टवेअर',    'description' => 'सॉफ्टवेअर यादी आणि परवाने पहा'],
        'forms'          => ['name' => 'फॉर्म',        'description' => 'सानुकूल फॉर्म डिझाइन करा आणि सबमिशन पहा'],
        'contracts'      => ['name' => 'करार',         'description' => 'पुरवठादार, संपर्क आणि करार व्यवस्थापित करा'],
        'service-status' => ['name' => 'स्थिती',       'description' => 'सेवा आरोग्याचे निरीक्षण करा आणि घटना ट्रॅक करा'],
        'wiki'           => ['name' => 'विकी',         'description' => 'स्वयंचलित तयार केलेले कोडबेस दस्तऐवज पहा'],
        'lms'            => ['name' => 'LMS',          'description' => 'SCORM कोर्स प्लेयरसह शिक्षण व्यवस्थापन प्रणाली'],
        'process-mapper' => ['name' => 'प्रक्रिया',    'description' => 'दृश्य फ्लोचार्ट आणि प्रक्रिया मॅपिंग साधन'],
        'tasks'          => ['name' => 'कार्ये',       'description' => 'कार्ये ट्रॅक करण्यासाठी कानबन बोर्ड आणि यादी दृश्य'],
        'cmdb'           => ['name' => 'CMDB',         'description' => 'कॉन्फिगरेशन व्यवस्थापन डेटाबेस'],
        'network-mapper' => ['name' => 'नेटवर्क',      'description' => 'नेटवर्क आकृत्या डिझाइन आणि दस्तऐवजीकरण करा'],
        'system'         => ['name' => 'सिस्टम',       'description' => 'सिस्टम प्रशासन आणि कॉन्फिगरेशन'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'नवीन ईमेलसाठी तपासा',
        'change_password' => 'पासवर्ड बदला',
        'mfa'             => 'बहु-घटक प्रमाणीकरण',
        'trusted_device'  => 'विश्वसनीय डिव्हाइस',
        'logout'          => 'लॉग आउट',
        'logout_confirm'  => 'तुम्हाला नक्की लॉग आउट करायचे आहे का?',
        'badge_off'       => 'बंद',
        'badge_on'        => 'चालू',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'पासवर्ड बदला',
        'current_password' => 'सध्याचा पासवर्ड',
        'new_password'     => 'नवीन पासवर्ड',
        'confirm_password' => 'नवीन पासवर्डची पुष्टी करा',
        'submit'           => 'पासवर्ड बदला',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'बहु-घटक प्रमाणीकरण',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'मागील',
        'next'     => 'पुढील',
        'today'    => 'आज',

        'months' => [
            'january'   => 'जानेवारी',
            'february'  => 'फेब्रुवारी',
            'march'     => 'मार्च',
            'april'     => 'एप्रिल',
            'may'       => 'मे',
            'june'      => 'जून',
            'july'      => 'जुलै',
            'august'    => 'ऑगस्ट',
            'september' => 'सप्टेंबर',
            'october'   => 'ऑक्टोबर',
            'november'  => 'नोव्हेंबर',
            'december'  => 'डिसेंबर',
        ],

        'weekdays' => [
            'monday'    => 'सोमवार',
            'tuesday'   => 'मंगळवार',
            'wednesday' => 'बुधवार',
            'thursday'  => 'गुरुवार',
            'friday'    => 'शुक्रवार',
            'saturday'  => 'शनिवार',
            'sunday'    => 'रविवार',
        ],
    ],
];
