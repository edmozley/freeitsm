<?php
/**
 * FreeITSM — auth strings (hi).
 *
 * Keys mirror lang/en/auth.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php hi
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'browser_title' => 'सेवा डेस्क लॉगिन',
    'heading' => 'ITSM लॉगिन',
    'username' => 'उपयोगकर्ता नाम',
    'username_or_email' => 'उपयोगकर्ता नाम या ईमेल',
    'password' => 'पासवर्ड',
    'sign_in' => 'साइन इन करें',
    'forgot' => 'पासवर्ड भूल गए?',
    'email' => 'ईमेल',
    'email_placeholder' => 'you@example.com',
    'continue' => 'जारी रखें',
    'or' => 'या',
    'reveal_local_ldap' => 'उपयोगकर्ता नाम और पासवर्ड से साइन इन करें',
    'reveal_local_plain' => 'स्थानीय खाते से साइन इन करें',
    'mfa_heading' => 'सत्यापन',
    'mfa_prompt' => 'अपने ऑथेंटिकेटर ऐप से 6-अंकों का कोड दर्ज करें',
    'mfa_placeholder' => '------',
    'mfa_verify' => 'सत्यापित करें',
    'mfa_verifying' => 'सत्यापित किया जा रहा है...',
    'mfa_failed' => 'सत्यापन विफल रहा। कृपया पुनः प्रयास करें।',
    'mfa_cancel' => 'रद्द करें और लॉगिन पर वापस जाएं',
    'portal_link' => 'सेल्फ-सर्विस पोर्टल पर जाएं',
    'err_missing' => 'कृपया उपयोगकर्ता नाम और पासवर्ड दोनों दर्ज करें',
    'err_invalid' => 'अमान्य उपयोगकर्ता नाम या पासवर्ड',
    'err_exception' => 'लॉगिन त्रुटि: {message}',
    'err_throttled_hours_one' => 'बहुत अधिक विफल प्रयास। 1 घंटे में पुनः प्रयास करें।',
    'err_throttled_hours_many' => 'बहुत अधिक विफल प्रयास। {n} घंटों में पुनः प्रयास करें।',
    'err_throttled_minutes_one' => 'बहुत अधिक विफल प्रयास। 1 मिनट में पुनः प्रयास करें।',
    'err_throttled_minutes_many' => 'बहुत अधिक विफल प्रयास। {n} मिनट में पुनः प्रयास करें।',
    'err_locked_one' => 'खाता लॉक है। 1 मिनट में पुनः प्रयास करें।',
    'err_locked_many' => 'खाता लॉक है। {n} मिनट में पुनः प्रयास करें।',
    'err_sso_account' => 'यह खाता सिंगल साइन-ऑन (SSO) से साइन इन करता है। कृपया ऊपर दिया गया साइन इन बटन इस्तेमाल करें।',
    'err_not_analyst' => 'आपके खाते में एनालिस्ट एक्सेस नहीं है। कृपया सेल्फ-सर्विस पोर्टल का उपयोग करें।',
    'err_no_group' => 'आपका खाता किसी ऐसे ग्रुप का सदस्य नहीं है जो FreeITSM तक एक्सेस देता हो।',
    'js_need_email' => 'कृपया अपना ईमेल दर्ज करें।',
    'js_no_provider' => 'उस ईमेल के लिए कोई सिंगल साइन-ऑन (SSO) प्रोवाइडर सेट अप नहीं है। कृपया अपने एडमिन से संपर्क करें।',
];
