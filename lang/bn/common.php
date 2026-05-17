<?php
/**
 * বাংলা (bn) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'সংরক্ষণ',
    'cancel'       => 'বাতিল',
    'delete'       => 'মুছে ফেলুন',
    'add'          => 'যোগ করুন',
    'edit'         => 'সম্পাদনা',
    'close'        => 'বন্ধ',
    'copy'         => 'কপি',
    'copied'       => 'কপি হয়েছে',
    'retry'        => 'পুনরায় চেষ্টা',
    'export'       => 'রপ্তানি',
    'open'         => 'খুলুন',
    'apply'        => 'প্রয়োগ',

    'yes'          => 'হ্যাঁ',
    'no'           => 'না',
    'ok'           => 'ঠিক আছে',
    'loading'      => 'লোড হচ্ছে…',
    'saving'       => 'সংরক্ষণ করা হচ্ছে…',
    'saved'        => 'সংরক্ষিত',
    'unsaved'      => 'অসংরক্ষিত',
    'unsaved_changes' => 'অসংরক্ষিত পরিবর্তন',
    'failed'       => 'ব্যর্থ',

    'just_now'     => 'এইমাত্র',
    'today'        => 'আজ',
    'yesterday'    => 'গতকাল',

    'required'     => 'আবশ্যক',
    'optional'     => 'ঐচ্ছিক',
    'select_one'   => 'নির্বাচন…',
    'search'       => 'অনুসন্ধান',

    'error_generic'       => 'কিছু একটা ভুল হয়েছে।',
    'error_network'       => 'নেটওয়ার্ক ত্রুটি',
    'error_not_logged_in' => 'আপনাকে লগ ইন করতে হবে।',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'সার্ভিস ডেস্ক',
        'browser_title'    => 'সার্ভিস ডেস্ক - ITSM',
        'welcome_heading'  => 'আপনি কী করতে চান?',
        'welcome_subtitle' => 'শুরু করতে একটি মডিউল নির্বাচন করুন',
        'footer'           => 'সার্ভিস ডেস্ক ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM মডিউল',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'পর্যবেক্ষণ',    'description' => 'সমস্ত মডিউল জুড়ে ঐক্যবদ্ধ মনোযোগ ড্যাশবোর্ড'],
        'tickets'        => ['name' => 'টিকেট',         'description' => 'সহায়তার অনুরোধ, ইমেল এবং ব্যবহারকারীর সমস্যা পরিচালনা করুন'],
        'assets'         => ['name' => 'সম্পদ',         'description' => 'IT সম্পদ এবং ব্যবহারকারীর বরাদ্দ ট্র্যাক করুন'],
        'knowledge'      => ['name' => 'জ্ঞান',         'description' => 'জ্ঞানভাণ্ডার নিবন্ধ তৈরি এবং দেখুন'],
        'changes'        => ['name' => 'পরিবর্তন',      'description' => 'IT পরিবর্তনের পরিকল্পনা, ট্র্যাক ও পরিচালনা করুন'],
        'calendar'       => ['name' => 'ক্যালেন্ডার',   'description' => 'ইভেন্ট, সময়সীমা এবং সময়সূচী ট্র্যাক করুন'],
        'morning-checks' => ['name' => 'যাচাই',         'description' => 'দৈনিক অবকাঠামো যাচাই রেকর্ড করুন'],
        'reporting'      => ['name' => 'রিপোর্টিং',     'description' => 'সিস্টেম লগ এবং বিশ্লেষণ দেখুন'],
        'software'       => ['name' => 'সফটওয়্যার',    'description' => 'সফটওয়্যার ইনভেন্টরি এবং লাইসেন্সিং দেখুন'],
        'forms'          => ['name' => 'ফর্ম',          'description' => 'কাস্টম ফর্ম ডিজাইন করুন এবং জমা দেওয়া দেখুন'],
        'contracts'      => ['name' => 'চুক্তি',        'description' => 'সরবরাহকারী, যোগাযোগ এবং চুক্তি পরিচালনা করুন'],
        'service-status' => ['name' => 'স্ট্যাটাস',     'description' => 'পরিষেবার স্বাস্থ্য নিরীক্ষণ করুন এবং ঘটনা ট্র্যাক করুন'],
        'wiki'           => ['name' => 'উইকি',          'description' => 'স্বয়ংক্রিয়ভাবে উৎপন্ন কোডবেস ডকুমেন্টেশন দেখুন'],
        'lms'            => ['name' => 'LMS',           'description' => 'SCORM কোর্স প্লেয়ার সহ লার্নিং ম্যানেজমেন্ট সিস্টেম'],
        'process-mapper' => ['name' => 'প্রক্রিয়া',    'description' => 'ভিজ্যুয়াল ফ্লোচার্ট এবং প্রক্রিয়া ম্যাপিং সরঞ্জাম'],
        'tasks'          => ['name' => 'কাজ',           'description' => 'কাজ ট্র্যাক করার জন্য কানবান বোর্ড এবং তালিকা দৃশ্য'],
        'cmdb'           => ['name' => 'CMDB',          'description' => 'কনফিগারেশন ম্যানেজমেন্ট ডেটাবেস'],
        'network-mapper' => ['name' => 'নেটওয়ার্ক',    'description' => 'নেটওয়ার্ক ডায়াগ্রাম ডিজাইন এবং ডকুমেন্ট করুন'],
        'system'         => ['name' => 'সিস্টেম',       'description' => 'সিস্টেম প্রশাসন এবং কনফিগারেশন'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'নতুন ইমেল যাচাই করুন',
        'change_password' => 'পাসওয়ার্ড পরিবর্তন',
        'mfa'             => 'বহু-উপাদান প্রমাণীকরণ',
        'trusted_device'  => 'বিশ্বস্ত ডিভাইস',
        'logout'          => 'লগ আউট',
        'logout_confirm'  => 'আপনি কি নিশ্চিত যে লগ আউট করতে চান?',
        'badge_off'       => 'বন্ধ',
        'badge_on'        => 'চালু',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'পাসওয়ার্ড পরিবর্তন',
        'current_password' => 'বর্তমান পাসওয়ার্ড',
        'new_password'     => 'নতুন পাসওয়ার্ড',
        'confirm_password' => 'নতুন পাসওয়ার্ড নিশ্চিত করুন',
        'submit'           => 'পাসওয়ার্ড পরিবর্তন',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'বহু-উপাদান প্রমাণীকরণ',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'পূর্ববর্তী',
        'next'     => 'পরবর্তী',
        'today'    => 'আজ',

        'months' => [
            'january'   => 'জানুয়ারি',
            'february'  => 'ফেব্রুয়ারি',
            'march'     => 'মার্চ',
            'april'     => 'এপ্রিল',
            'may'       => 'মে',
            'june'      => 'জুন',
            'july'      => 'জুলাই',
            'august'    => 'আগস্ট',
            'september' => 'সেপ্টেম্বর',
            'october'   => 'অক্টোবর',
            'november'  => 'নভেম্বর',
            'december'  => 'ডিসেম্বর',
        ],

        'weekdays' => [
            'monday'    => 'সোমবার',
            'tuesday'   => 'মঙ্গলবার',
            'wednesday' => 'বুধবার',
            'thursday'  => 'বৃহস্পতিবার',
            'friday'    => 'শুক্রবার',
            'saturday'  => 'শনিবার',
            'sunday'    => 'রবিবার',
        ],
    ],
];
