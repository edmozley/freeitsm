<?php
/**
 * Click-to-call: the Call button beside a phone number.
 *
 * Not 3CX-only, though its setting lives on System → Integrations → 3CX: the dial
 * address is whatever opens the analyst's own phone - 3CX's web client, tel: for a
 * desktop softphone, anything with {number} in it. It is opened on the analyst's
 * computer, where they are already signed in to it, so FreeITSM never needs to
 * know their extension.
 *
 * Small and dependency-free on purpose: the person page, Tickets → Users and the
 * ticket view read it, and none of them should load the 3CX library to draw a link.
 */

/** The Call button's look - shared with the JavaScript copy (phoneDialLink() in the ticket pages). */
const PHONE_DIAL_STYLE = 'margin-left:6px;font-size:var(--font-size-sm, 12px);font-weight:600;white-space:nowrap';

/**
 * The number part of a phone field: everything before the first letter, so
 * "+44 7700 900123 ext 12" is the number +44 7700 900123 and not one long run of
 * digits with the extension stuck on the end.
 */
function phoneNumberPart(?string $field): string
{
    preg_match('/^[^A-Za-z]*/', trim((string)$field), $m);
    return trim($m[0] ?? '');
}

/** Is this a dial address FreeITSM will put in a link? A web address or a phone scheme, with {number}. */
function phoneDialTemplateValid(string $tpl): bool
{
    return str_contains($tpl, '{number}') && preg_match('#^(https://|tel:|callto:|sip:)\S+$#i', $tpl) === 1;
}

/** The dial address, or '' when click-to-call is off (or the install predates it). */
function phoneDialTemplate(PDO $conn): string
{
    try {
        $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'phone_dial_url'");
        $s->execute();
        $tpl = (string)($s->fetchColumn() ?: '');
    } catch (Exception) {
        return '';
    }
    return phoneDialTemplateValid($tpl) ? $tpl : '';
}

/**
 * The link that dials $number, or '' (click-to-call off, or not a number). Only
 * digits and a leading + reach the link - anything else typed into the field
 * (spaces, brackets, an extension, a note) is never passed on.
 */
function phoneDialHref(string $template, ?string $number): string
{
    $n = phoneNumberPart($number);
    $digits = preg_replace('/\D+/', '', $n);
    if ($template === '' || strlen($digits) < 3) return '';
    $dial = (str_starts_with($n, '+') ? '+' : '') . $digits;
    return str_replace('{number}', rawurlencode($dial), $template);
}

/**
 * A phone number with its Call button, as HTML: the number escaped, and the
 * button only when click-to-call is on. A web dial address opens in a new tab;
 * tel: and the like hand straight over to the phone app.
 */
function phoneWithDialHtml(string $template, ?string $number): string
{
    $text = htmlspecialchars((string)$number, ENT_QUOTES, 'UTF-8');
    $href = phoneDialHref($template, $number);
    if ($href === '') return $text;
    $web = stripos($href, 'https://') === 0;
    // Styled inline so every page that shows a number gets the same button
    // without each of their stylesheets (and cache-busters) changing.
    return $text . ' <a class="phone-dial" style="' . PHONE_DIAL_STYLE . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
         . ($web ? ' target="_blank" rel="noopener"' : '')
         . ' title="' . htmlspecialchars(t('common.phone_dial.call_title', ['number' => (string)$number]), ENT_QUOTES, 'UTF-8') . '">'
         . htmlspecialchars(t('common.phone_dial.call'), ENT_QUOTES, 'UTF-8') . '</a>';
}
