<?php
/**
 * हिन्दी (hi) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'सहेजें',
    'cancel'       => 'रद्द करें',
    'delete'       => 'हटाएँ',
    'add'          => 'जोड़ें',
    'edit'         => 'संपादित करें',
    'close'        => 'बंद करें',
    'copy'         => 'कॉपी करें',
    'copied'       => 'कॉपी हो गया',
    'retry'        => 'पुनः प्रयास करें',
    'export'       => 'निर्यात',
    'open'         => 'खोलें',
    'apply'        => 'लागू करें',

    'yes'          => 'हाँ',
    'no'           => 'नहीं',
    'ok'           => 'ठीक है',
    'loading'      => 'लोड हो रहा है…',
    'saving'       => 'सहेजा जा रहा है…',
    'saved'        => 'सहेजा गया',
    'unsaved'      => 'सहेजा नहीं गया',
    'unsaved_changes' => 'असहेजे परिवर्तन',
    'failed'       => 'विफल',

    'just_now'     => 'अभी अभी',
    'today'        => 'आज',
    'yesterday'    => 'कल',

    'required'     => 'आवश्यक',
    'optional'     => 'वैकल्पिक',
    'select_one'   => 'चुनें…',
    'search'       => 'खोजें',

    'error_generic'       => 'कुछ ग़लत हो गया।',
    'error_network'       => 'नेटवर्क त्रुटि',
    'error_not_logged_in' => 'आपको लॉग इन करना होगा।',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'सेवा डेस्क',
        'browser_title'    => 'सेवा डेस्क - ITSM',
        'welcome_heading'  => 'आप क्या करना चाहेंगे?',
        'welcome_subtitle' => 'शुरू करने के लिए एक मॉड्यूल चुनें',
        'footer'           => 'सेवा डेस्क ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM मॉड्यूल',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'निगरानी',     'description' => 'सभी मॉड्यूल के लिए एकीकृत अलर्ट डैशबोर्ड'],
        'tickets'        => ['name' => 'टिकट',        'description' => 'सहायता अनुरोध, ईमेल और उपयोगकर्ता समस्याओं का प्रबंधन करें'],
        'assets'         => ['name' => 'संपत्तियाँ',  'description' => 'IT संपत्तियों और उपयोगकर्ता आवंटनों को ट्रैक करें'],
        'knowledge'      => ['name' => 'ज्ञान',       'description' => 'ज्ञान आधार लेख बनाएँ और देखें'],
        'changes'        => ['name' => 'परिवर्तन',    'description' => 'IT परिवर्तनों की योजना, ट्रैकिंग और प्रबंधन करें'],
        'calendar'       => ['name' => 'कैलेंडर',     'description' => 'घटनाओं, समय-सीमाओं और शेड्यूल को ट्रैक करें'],
        'morning-checks' => ['name' => 'जाँच',        'description' => 'दैनिक अवसंरचना जाँच रिकॉर्ड करें'],
        'reporting'      => ['name' => 'रिपोर्टिंग',  'description' => 'सिस्टम लॉग और विश्लेषण देखें'],
        'software'       => ['name' => 'सॉफ़्टवेयर',  'description' => 'सॉफ़्टवेयर सूची और लाइसेंसिंग देखें'],
        'forms'          => ['name' => 'फ़ॉर्म',      'description' => 'कस्टम फ़ॉर्म डिज़ाइन करें और सबमिशन देखें'],
        'contracts'      => ['name' => 'अनुबंध',      'description' => 'आपूर्तिकर्ताओं, संपर्कों और अनुबंधों का प्रबंधन करें'],
        'service-status' => ['name' => 'स्थिति',      'description' => 'सेवा स्वास्थ्य की निगरानी करें और घटनाओं को ट्रैक करें'],
        'wiki'           => ['name' => 'विकि',        'description' => 'स्वतः उत्पन्न कोडबेस दस्तावेज़ देखें'],
        'lms'            => ['name' => 'LMS',         'description' => 'SCORM कोर्स प्लेयर के साथ शिक्षण प्रबंधन प्रणाली'],
        'process-mapper' => ['name' => 'प्रक्रियाएँ', 'description' => 'विज़ुअल फ़्लोचार्ट और प्रक्रिया मैपिंग टूल'],
        'tasks'          => ['name' => 'कार्य',       'description' => 'कार्यों को ट्रैक करने के लिए कानबन बोर्ड और सूची दृश्य'],
        'cmdb'           => ['name' => 'CMDB',        'description' => 'कॉन्फ़िगरेशन प्रबंधन डेटाबेस'],
        'network-mapper' => ['name' => 'नेटवर्क',     'description' => 'नेटवर्क आरेख डिज़ाइन और दस्तावेज़ करें'],
        'system'         => ['name' => 'सिस्टम',      'description' => 'सिस्टम प्रशासन और कॉन्फ़िगरेशन'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'नए ईमेल की जाँच करें',
        'change_password' => 'पासवर्ड बदलें',
        'mfa'             => 'बहु-कारक प्रमाणीकरण',
        'trusted_device'  => 'विश्वसनीय उपकरण',
        'logout'          => 'लॉग आउट',
        'logout_confirm'  => 'क्या आप वाकई लॉग आउट करना चाहते हैं?',
        'badge_off'       => 'बंद',
        'badge_on'        => 'चालू',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'पासवर्ड बदलें',
        'current_password' => 'वर्तमान पासवर्ड',
        'new_password'     => 'नया पासवर्ड',
        'confirm_password' => 'नए पासवर्ड की पुष्टि करें',
        'submit'           => 'पासवर्ड बदलें',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'बहु-कारक प्रमाणीकरण',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'पिछला',
        'next'     => 'अगला',
        'today'    => 'आज',

        'months' => [
            'january'   => 'जनवरी',
            'february'  => 'फ़रवरी',
            'march'     => 'मार्च',
            'april'     => 'अप्रैल',
            'may'       => 'मई',
            'june'      => 'जून',
            'july'      => 'जुलाई',
            'august'    => 'अगस्त',
            'september' => 'सितंबर',
            'october'   => 'अक्टूबर',
            'november'  => 'नवंबर',
            'december'  => 'दिसंबर',
        ],

        'weekdays' => [
            'monday'    => 'सोमवार',
            'tuesday'   => 'मंगलवार',
            'wednesday' => 'बुधवार',
            'thursday'  => 'गुरुवार',
            'friday'    => 'शुक्रवार',
            'saturday'  => 'शनिवार',
            'sunday'    => 'रविवार',
        ],
    ],
];
