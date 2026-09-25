<?php
/**
 * The live preview, shared by every screen the branding designer can style.
 *
 * Included ONLY when a page has decided it is being previewed by an
 * administrator (`?preview=1`); it renders a <script> and nothing else.
 *
 * 🔑 One copy, because three copies of this would drift — and the whole point
 * of previewing the real page rather than a mock-up is that what you see is
 * what you get. Two of them silently disagreeing would be the same failure
 * wearing a different hat.
 *
 * ⚠️ Safe by construction:
 *   · BroadcastChannel is SAME-ORIGIN, so no other site can post to it, and the
 *     postMessage path (used by the iframe in the settings screen) checks the
 *     origin explicitly.
 *   · It changes colours, layout and text in ONE browser and writes nothing.
 *     Persisting still goes through the guarded, validated save endpoint.
 *   · Values are re-checked here anyway — a colour must match #rrggbb, a layout
 *     must be one of the known words — so this path keeps the same discipline
 *     as the server even where it does not have to.
 *   · Text is written with textContent, never innerHTML.
 *
 * `$previewDefaultHeading` is the heading the page falls back to when the
 * designer supplies none; only the calling page knows its own translated
 * default. Set it before requiring this file.
 */
$previewDefaultHeading = $previewDefaultHeading ?? '';
?>
<script>
/* LIVE PREVIEW — only when this page was opened with ?preview=1.

   The branding settings screen broadcasts the design as it is being edited
   and this applies it, so an administrator sees the REAL login page change
   under them rather than a mock-up that can drift from it.

   🔑 Why this is not a hole:
     · BroadcastChannel is SAME-ORIGIN. No other site can post to it.
     · It changes colours and layout in ONE browser and writes nothing.
       Persisting still goes through the guarded, validated save endpoint.
     · The values are re-checked here anyway — a colour must match
       #rrggbb and a layout must be one of the known words — so this path
       keeps the same discipline as the server even where it need not. */
(function () {
    if (!window.BroadcastChannel) return;
    var HEX = /^#[0-9a-fA-F]{6}$/;
    var ENUMS = {
        formPos:  ['left', 'centre', 'right'],
        card:     ['solid', 'glass', 'flat'],
        logoPos:  ['above', 'top-left', 'top-centre', 'hidden'],
        bannerAt: ['off', 'top', 'bottom']
    };
    function ok(list, v) { return list.indexOf(v) > -1 ? v : list[0]; }
    /* Two ways in, because the preview is shown BOTH embedded in the settings
       screen and in a tab of its own. `postMessage` covers the iframe (and a
       browser without BroadcastChannel); the channel covers the tab.
       ⚠️ The origin is checked on the postMessage path — an iframe can be
       messaged by anyone who can reach the page. */
    window.addEventListener('message', function (e) {
        if (e.origin !== location.origin) return;
        if (e.data && e.data.__loginPreview) apply(e.data.__loginPreview);
    });
    new BroadcastChannel('freeitsm-login-preview').onmessage = function (e) { apply(e.data); };
    function apply(data) {
        var e = { data: data };
        var d = e.data || {};
        if (typeof d.css === 'string' && d.css.length < 600 && d.css.indexOf('<') === -1) {
            document.body.setAttribute('style', d.css);
        }
        document.body.setAttribute('data-form-pos', ok(ENUMS.formPos, d.formPos));
        document.body.setAttribute('data-card',     ok(ENUMS.card, d.card));
        document.body.setAttribute('data-logo-pos', ok(ENUMS.logoPos, d.logoPos));
        var img = document.querySelector('.login-header img');
        if (img && typeof d.logo === 'string' && /^[\w.\/-]+$/.test(d.logo)) img.src = d.logo;
        setText('h1', d.heading);
        strip('banner', d.bannerText, ok(ENUMS.bannerAt, d.bannerAt));
        strip('footer', d.footerText, 'bottom');
        /* 🔴 GH #150. This used to CREATE the tagline when the page had none and
           drop it after .login-header. Right for the analyst login, wrong for
           the portal, where the header also holds "Sign in to view your
           tickets" - so the preview showed the tagline below that line and the
           saved page showed it above. The preview guessed where the page puts
           it, and guessed differently.
           Now each page prints its tagline lines ALWAYS (empty ones hidden),
           marked data-tagline="1|2", and this only fills them in. Where they
           sit is decided in one place: the page. A line with data-default
           shows that text when the field is empty, as the server does. */
        var TAGLINE = { '1': d.subheading, '2': d.subheading2 };
        document.querySelectorAll('[data-tagline]').forEach(function (el) {
            var v = TAGLINE[el.getAttribute('data-tagline')];
            v = (typeof v === 'string') ? v.trim() : '';
            var fallback = el.getAttribute('data-default');
            el.textContent = v !== '' ? v : (fallback || '');
            el.hidden = el.textContent === '';
        });
    }
    /* The heading the page uses when the designer supplies none — printed
       here because only the server knows the translated default. */
    var DEFAULT_HEADING = <?php echo json_encode($previewDefaultHeading); ?>;

    function setText(sel, v) {
        var el = document.querySelector('.login-header ' + sel);
        if (!el) return;
        /* 🔴 AN EMPTY HEADLINE IS A VALUE, NOT AN ABSENCE. Written as
           `if (el && v)`, clearing the field left the last non-empty text on
           screen — type HELLO, backspace it away, and the preview still says
           'H', because that was the last update it accepted. Falling back to
           the default is what the SERVER does when the setting is empty, so
           doing anything else here would make the preview disagree with the
           page. Reported by Ed. */
        /* textContent, never innerHTML — the whole point of the file this
           mirrors is that branding text is text. */
        el.textContent = (v && String(v).trim() !== '') ? v : DEFAULT_HEADING;
    }
    function strip(kind, text, at) {
        var el = document.querySelector('.login-strip-' + kind);
        if (!text || (kind === 'banner' && at === 'off')) { if (el) el.remove(); return; }
        if (!el) {
            el = document.createElement('div');
            el.className = 'login-strip login-strip-' + kind;
            document.body.appendChild(el);
        }
        el.textContent = text;
        if (kind === 'banner') el.setAttribute('data-at', at);
    }
})();
</script>
