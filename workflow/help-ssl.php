<?php
/**
 * Workflows — HTTPS / SSL certificate verification help page.
 *
 * Written because the failure it documents is (a) extremely common on a fresh
 * Windows/WAMP install, (b) not FreeITSM's fault, and (c) reported by cURL in
 * language that tells you what broke but not what to do:
 *
 *     SSL certificate problem: unable to get local issuer certificate
 *
 * webhookDiagnoseError() (includes/webhook_delivery.php) recognises that error
 * and deep-links straight here from the workflow editor's Send-test panel and
 * from the System > Webhooks delivery log.
 *
 * English-first, same as help-webhooks.php — the i18n rollout backfills the
 * other locales later.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) { header('Location: ../login.php'); exit; }

requireModuleAccess('workflow');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'workflow'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HTTPS certificate verification</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=11">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        /* Same shape as workflow/help.php and help-webhooks.php */
        .wfh-container { display: flex; height: calc(100vh - 48px); background: var(--surface-3, #f5f5f5); }
        .wfh-sidebar { width: 260px; background: var(--surface, #fff); border-right: 1px solid var(--border, #ddd); padding: 20px; display: flex; flex-direction: column; gap: 4px; flex-shrink: 0; overflow-y: auto; }
        .wfh-sidebar h3 { font-size: 12px; font-weight: 600; color: var(--text-dim, #888); text-transform: uppercase; letter-spacing: 0.5px; margin: 0 0 12px; }
        .wfh-nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 6px; font-size: 13px; color: var(--text-muted, #555); text-decoration: none; transition: background 0.15s, color 0.15s; }
        .wfh-nav-link:hover { background: var(--surface-3, #f5f5f5); color: var(--text, #333); }
        .wfh-nav-link.active { background: var(--wf-accent-soft, #fff7ed); color: var(--warning-text, #b45309); font-weight: 600; }
        .wfh-nav-num { display: flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; border-radius: 50%; background: var(--surface-3, #f5f5f5); color: var(--text-dim, #888); font-size: 11px; font-weight: 700; }
        .wfh-nav-link.active .wfh-nav-num { background: var(--wf-accent, #f59e0b); color: white; }
        .wfh-back { display: flex; align-items: center; gap: 8px; padding: 8px 12px; margin-bottom: 8px; font-size: 12.5px; color: var(--text-muted, #6b7280); text-decoration: none; border-radius: 6px; }
        .wfh-back:hover { background: var(--surface-3, #f5f5f5); color: var(--text, #374151); }

        .wfh-main { flex: 1; overflow-y: auto; padding: 24px 32px 60px; }
        .wf-help h2 { font-size: 22px; color: var(--text, #333); margin: 0 0 4px; }
        .wf-help p { color: var(--text-muted, #555); line-height: 1.6; }
        .wf-help .lede { font-size: 15px; color: var(--text, #444); }
        .wf-help h3 { margin: 34px 0 10px; font-size: 16px; color: var(--text, #333); padding-bottom: 6px; border-bottom: 1px solid var(--border-soft, #eee); scroll-margin-top: 20px; }
        .wf-help h3:first-of-type { margin-top: 22px; }
        .wf-help h4 { margin: 22px 0 6px; font-size: 14px; color: var(--text, #444); }
        .wf-help ul, .wf-help ol { color: var(--text-muted, #555); line-height: 1.7; padding-left: 22px; }
        .wf-help li { margin-bottom: 6px; }
        .wf-help code { background: var(--surface-3, #f4f4f4); padding: 1px 6px; border-radius: 3px; font-size: 12.5px; color: var(--warning-text, #b45309); }
        .wf-help pre { background: #263238; color: #eceff1; border-radius: 6px; padding: 14px 16px; font-size: 12.5px; line-height: 1.55; overflow-x: auto; margin: 14px 0; }
        .wf-help pre code { background: none; color: inherit; padding: 0; font-size: 12.5px; }
        .wf-help table { border-collapse: collapse; width: 100%; margin: 14px 0; font-size: 13px; }
        .wf-help table th, .wf-help table td { border: 1px solid var(--border, #e5e7eb); padding: 8px 10px; text-align: left; vertical-align: top; }
        .wf-help table th { background: var(--surface-2, #f9fafb); font-weight: 600; color: var(--text, #374151); }
        .wf-help .callout { background: var(--wf-accent-soft, #fff7ed); border-left: 3px solid var(--wf-accent, #f59e0b); padding: 10px 14px; margin: 14px 0; border-radius: 4px; font-size: 13.5px; color: var(--warning-text, #92400e); }
        .wf-help .callout strong { color: var(--warning-text, #78350f); }
        .wf-help .tip { background: #f0f9ff; border-left: 3px solid #0ea5e9; padding: 10px 14px; margin: 14px 0; border-radius: 4px; font-size: 13.5px; color: #075985; }
        .wf-help .tip code, .wf-help .callout code, .wf-help .danger code { background: rgba(255,255,255,0.55); }
        /* Red box — reserved for the genuinely dangerous advice. */
        .wf-help .danger { background: #fef2f2; border-left: 3px solid #dc2626; padding: 10px 14px; margin: 14px 0; border-radius: 4px; font-size: 13.5px; color: #991b1b; }
        .wf-help .danger strong { color: #7f1d1d; }
        .wf-help .steps-num { counter-reset: qs; list-style: none; padding-left: 0; }
        .wf-help .steps-num li { position: relative; padding: 2px 0 10px 40px; }
        .wf-help .steps-num li::before { counter-increment: qs; content: counter(qs); position: absolute; left: 0; top: 0; width: 26px; height: 26px; border-radius: 50%; background: var(--wf-accent, #f59e0b); color: #fff; font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; }
        [data-theme-mode="dark"] .wf-help .tip { background: #12263a; border-left-color: #38bdf8; color: #7dd3fc; }
        [data-theme-mode="dark"] .wf-help .danger { background: #2c1618; border-left-color: #f87171; color: #fca5a5; }
        [data-theme-mode="dark"] .wf-help .danger strong { color: #fecaca; }
        [data-theme-mode="dark"] .wf-help .tip code,
        [data-theme-mode="dark"] .wf-help .callout code,
        [data-theme-mode="dark"] .wf-help .danger code { background: rgba(0,0,0,0.30); }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wfh-container">
        <aside class="wfh-sidebar">
            <a href="help.php" class="wfh-back">&larr; Back to Workflows guide</a>
            <h3>HTTPS certificates</h3>
            <a href="#symptom"  class="wfh-nav-link active" data-section="symptom"><span class="wfh-nav-num">1</span> The error you saw</a>
            <a href="#what"     class="wfh-nav-link" data-section="what"><span class="wfh-nav-num">2</span> What verification is</a>
            <a href="#bundle"   class="wfh-nav-link" data-section="bundle"><span class="wfh-nav-num">3</span> What a CA bundle is</a>
            <a href="#why"      class="wfh-nav-link" data-section="why"><span class="wfh-nav-num">4</span> Why this happens</a>
            <a href="#fix"      class="wfh-nav-link" data-section="fix"><span class="wfh-nav-num">5</span> The fix</a>
            <a href="#verify"   class="wfh-nav-link" data-section="verify"><span class="wfh-nav-num">6</span> Check it worked</a>
            <a href="#offswitch" class="wfh-nav-link" data-section="offswitch"><span class="wfh-nav-num">7</span> Turning it off</a>
            <a href="#trouble"  class="wfh-nav-link" data-section="trouble"><span class="wfh-nav-num">8</span> Still failing?</a>
        </aside>

        <main class="wfh-main">
            <div class="tab-content active wf-help">
            <h2>HTTPS certificate verification</h2>
            <p class="lede">If a webhook failed with <em>&ldquo;unable to get local issuer certificate&rdquo;</em>, this page is for you. The short version: <strong>your webhook is probably fine.</strong> The server it runs on just hasn&rsquo;t been told which certificate authorities to trust, so it can&rsquo;t confirm it&rsquo;s really talking to Slack or Discord. It&rsquo;s a one-time server fix and takes a couple of minutes.</p>

            <!-- 1 -->
            <h3 id="symptom">1. The error you saw</h3>
            <p>In the workflow editor&rsquo;s <strong>Send test</strong> panel, or in the <strong>System &rarr; Webhooks</strong> delivery log, the failure reads something like:</p>
            <pre><code>Transport error: SSL certificate problem: unable to get local issuer certificate</code></pre>
            <p>Variations mean the same thing: <code>certificate verify failed</code>, <code>self-signed certificate in certificate chain</code>, <code>unable to get issuer certificate</code>.</p>
            <div class="callout"><strong>Read the rest of the panel before you change anything.</strong> If FreeITSM showed you the JSON it built under <em>Sent (sample data)</em>, then your workflow, your variables and your payload format all worked. Only the final network hop failed. You are much closer than the error makes it sound.</div>

            <!-- 2 -->
            <h3 id="what">2. What &ldquo;certificate verification&rdquo; actually is</h3>
            <p>When FreeITSM posts a webhook to <code>https://discord.com/…</code>, two separate things have to happen:</p>
            <ul>
                <li><strong>Encryption</strong> &mdash; scramble the traffic so nobody in between can read it.</li>
                <li><strong>Verification</strong> &mdash; make sure the server on the other end <em>really is</em> Discord, and not someone impersonating it.</li>
            </ul>
            <p>Encryption without verification is close to worthless: you&rsquo;d have a beautifully encrypted conversation with an impostor. So the second step matters as much as the first.</p>
            <p>Verification works like checking a passport. Discord presents a <strong>certificate</strong> saying &ldquo;I am discord.com&rdquo;. That certificate is signed by a <strong>certificate authority</strong> (CA) &mdash; an organisation whose job is vouching for identities. Your server checks the signature against its own list of authorities it trusts. If the signature traces back to a trusted authority, the connection proceeds. If it doesn&rsquo;t, the connection is refused.</p>
            <p>That refusal is exactly what you saw. Not &ldquo;this certificate is fake&rdquo;, but something more basic: <em>&ldquo;I have no list of authorities, so I can&rsquo;t check anybody&rsquo;s passport.&rdquo;</em></p>

            <!-- 3 -->
            <h3 id="bundle">3. What a &ldquo;CA bundle&rdquo; is, in plain English</h3>
            <p>A <strong>CA bundle</strong> is that list of trusted authorities. It is simply a text file &mdash; conventionally named <code>cacert.pem</code> &mdash; containing the public certificates of the roughly 120&ndash;150 organisations the world has agreed to trust to vouch for websites. Open it in Notepad and you&rsquo;ll see block after block of <code>-----BEGIN CERTIFICATE-----</code>.</p>
            <p>The one almost everybody uses is the <strong>Mozilla CA bundle</strong>: the same trust list that ships inside Firefox, extracted and republished in this convenient format by the curl project. Using it means your server trusts exactly who a mainstream web browser trusts &mdash; no more, no less.</p>
            <p>Some important reassurance about what it is <em>not</em>:</p>
            <ul>
                <li>It contains <strong>no secrets</strong>. These are public certificates. The file is safe to read, copy and back up.</li>
                <li>It is <strong>not specific to FreeITSM</strong>, or to webhooks, or to Discord. It&rsquo;s the same list every other program on your machine uses.</li>
                <li>It is <strong>not a licence or an account</strong>. It&rsquo;s free, and you just download it.</li>
            </ul>
            <p>It does go stale slowly &mdash; authorities are occasionally added or withdrawn &mdash; so re-downloading it once a year or so is good hygiene. Nothing breaks immediately if you don&rsquo;t.</p>

            <!-- 4 -->
            <h3 id="why">4. Why this happens (and why it isn&rsquo;t your fault)</h3>
            <p>On Linux, the operating system maintains a CA bundle and PHP finds it automatically. Most people never learn any of this exists.</p>
            <p><strong>On Windows, PHP ships with no CA bundle and no pointer to one.</strong> The two settings that would tell it where to look &mdash; <code>curl.cainfo</code> and <code>openssl.cafile</code> &mdash; are commented out in the default <code>php.ini</code>, and WAMP doesn&rsquo;t supply the file. So a stock Windows/WAMP install cannot make a verified HTTPS request <em>to anything</em>, out of the box.</p>
            <div class="callout"><strong>This is bigger than webhooks.</strong> The same missing bundle breaks every outbound HTTPS call FreeITSM makes: Slack and Teams webhooks, the AI provider calls, OAuth token refreshes, remote email APIs. Fixing it once fixes all of them &mdash; which is why it&rsquo;s worth doing properly rather than working around.</div>

            <!-- 5 -->
            <h3 id="fix">5. The fix</h3>
            <p>Two steps: put the bundle somewhere permanent, then tell PHP where it is.</p>

            <h4>Step 1 &mdash; download the Mozilla CA bundle</h4>
            <p>Get it from the curl project, which is its canonical home:</p>
            <pre><code>https://curl.se/ca/cacert.pem</code></pre>
            <p>Save it somewhere <strong>outside your versioned PHP folder</strong>, so that upgrading PHP doesn&rsquo;t delete it. A good choice on a standard WAMP install:</p>
            <pre><code>C:\wamp64\cacert.pem</code></pre>
            <p>Sanity-check it downloaded properly: the file should be roughly 200&nbsp;KB and full of <code>BEGIN CERTIFICATE</code> blocks. If it&rsquo;s a few hundred bytes of HTML, your browser saved an error page instead.</p>

            <h4>Step 2 &mdash; point PHP at it</h4>
            <p>You must edit <strong>both</strong> <code>php.ini</code> files. WAMP keeps two, and this is the single most common way to half-fix the problem:</p>
            <table>
                <tr><th>Which</th><th>Typical path</th><th>Why it matters</th></tr>
                <tr>
                    <td><strong>Apache&rsquo;s</strong></td>
                    <td><code>C:\wamp64\bin\apache\apache&lt;version&gt;\bin\php.ini</code></td>
                    <td>Used by everything you do in the browser &mdash; including the <strong>Send test</strong> button.</td>
                </tr>
                <tr>
                    <td><strong>The CLI one</strong></td>
                    <td><code>C:\wamp64\bin\php\php&lt;version&gt;\php.ini</code></td>
                    <td>Used by scheduled tasks &mdash; including the <strong>background webhook delivery worker</strong>. Miss this one and Send test passes while real deliveries keep failing.</td>
                </tr>
            </table>
            <p>In each file, find these two lines (they&rsquo;ll be commented out with a leading <code>;</code>), remove the semicolon, and set the path:</p>
            <pre><code>curl.cainfo = "C:/wamp64/cacert.pem"
openssl.cafile = "C:/wamp64/cacert.pem"</code></pre>
            <p>Use <strong>forward slashes</strong> and keep the quotes. Both settings are needed: <code>curl.cainfo</code> covers cURL (which is what webhooks use), and <code>openssl.cafile</code> covers PHP&rsquo;s other stream-based HTTPS calls.</p>

            <h4>Step 3 &mdash; restart Apache</h4>
            <p><code>php.ini</code> is read once at startup, so nothing changes until you restart. Left-click the WAMP tray icon &rarr; <strong>Restart All Services</strong>. (The CLI picks its ini up fresh on every run, so it needs no restart.)</p>

            <!-- 6 -->
            <h3 id="verify">6. Check it worked</h3>
            <p>Easiest check, in FreeITSM: open the workflow, select the <strong>Send a webhook</strong> action and press <strong>Send test</strong> again. You want <em>Delivered</em>, an <code>HTTP 204</code> (Discord) or <code>HTTP 200</code>, and the message to actually appear in your channel.</p>
            <p>To confirm the underlying setting rather than the symptom, drop this in your web root as <code>catest.php</code>, load it in a browser, and delete it afterwards:</p>
            <pre><code>&lt;?php
header('Content-Type: text/plain');
echo "curl.cainfo    = " . (ini_get('curl.cainfo')    ?: '(EMPTY - not set)') . "\n";
echo "openssl.cafile = " . (ini_get('openssl.cafile') ?: '(EMPTY - not set)') . "\n";

$ch = curl_init("https://curl.se/");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER =&gt; true, CURLOPT_TIMEOUT =&gt; 10]);
echo curl_exec($ch) === false
    ? "TLS: FAILED - " . curl_error($ch) . "\n"
    : "TLS: OK - certificate verified\n";</code></pre>
            <p>Both paths should be printed, and TLS should say OK. If the paths still show <em>EMPTY</em>, Apache is running on the old configuration &mdash; you either edited the wrong <code>php.ini</code> or haven&rsquo;t restarted.</p>
            <div class="tip"><strong>Don&rsquo;t forget the worker.</strong> A passing <strong>Send test</strong> only proves <em>Apache&rsquo;s</em> PHP is fixed. Real webhooks are delivered by the background worker running under the <strong>CLI</strong> PHP. If tests pass but live deliveries keep failing, you edited only one of the two <code>php.ini</code> files.</div>

            <!-- 7 -->
            <h3 id="offswitch">7. Turning verification off &mdash; and why you shouldn&rsquo;t</h3>
            <p>Sooner or later, searching this error online will suggest &ldquo;just disable SSL verification&rdquo;. It does make the error go away. Here is exactly what it costs.</p>
            <div class="danger"><strong>Disabling verification means FreeITSM stops checking who it is talking to.</strong> It will still encrypt the connection &mdash; but it will happily hand your ticket data, your customers&rsquo; email addresses and your webhook signing secret to <em>anyone</em> who manages to intercept the connection and claim to be Discord. That is precisely the attack (a &ldquo;man-in-the-middle&rdquo;) that certificate verification exists to prevent. You would be turning off the lock because you couldn&rsquo;t find the key.</div>
            <p>So, concretely, in FreeITSM:</p>
            <h4>Webhooks: there is no off switch, deliberately</h4>
            <p>The webhook transport always verifies (<code>CURLOPT_SSL_VERIFYPEER =&gt; true</code>, in <code>includes/webhook_delivery.php</code>) and offers no setting to change that. Webhooks carry record data out to third parties over the public internet; that is the worst possible place to stop checking identities. Install the CA bundle instead &mdash; it is a five-minute job and it is the actual fix.</p>
            <h4>AI providers: there is a toggle, with a warning attached</h4>
            <p>Each AI-powered module&rsquo;s settings page (Workflows, CMDB, Knowledge, Forms, RFP Builder, ticket reply cleanup) has a <strong>Verify SSL</strong> switch, and it ships <em>on</em>. It exists for one legitimate scenario: a corporate network that intercepts outbound TLS with its own inspection proxy, presenting a certificate signed by an <em>internal</em> authority your server has never heard of.</p>
            <p>Even then, turning verification off is the <em>lazy</em> answer. The correct one is to add your company&rsquo;s internal root certificate to the CA bundle &mdash; append it to <code>cacert.pem</code> &mdash; so your server trusts the proxy legitimately and keeps verifying everything else. Ask whoever runs your network for the root certificate; they will have it ready, because every other application on the network needs it too.</p>
            <div class="callout"><strong>A reasonable rule of thumb.</strong> Turning verification off is only ever defensible for a service <em>inside</em> your own network that you fully control, and even then it&rsquo;s a stopgap. For anything on the public internet &mdash; Slack, Discord, Teams, OpenAI, Anthropic &mdash; it is never the right answer. If it &ldquo;fixed&rdquo; your problem, what it actually did was hide it.</div>

            <!-- 8 -->
            <h3 id="trouble">8. Still failing?</h3>
            <table>
                <tr><th>What you see</th><th>What it usually means</th></tr>
                <tr>
                    <td>The check page still prints <code>(EMPTY - not set)</code></td>
                    <td>Apache is on the old config. Restart it &mdash; and make sure you edited Apache&rsquo;s <code>php.ini</code>, not just the CLI one. WAMP&rsquo;s tray menu (PHP &rarr; php.ini) opens the right file if you&rsquo;re unsure.</td>
                </tr>
                <tr>
                    <td><strong>Send test</strong> passes, but live deliveries fail</td>
                    <td>The classic half-fix: Apache&rsquo;s ini is done, the CLI one isn&rsquo;t. The background worker runs under the CLI PHP. Do both.</td>
                </tr>
                <tr>
                    <td><code>self-signed certificate in certificate chain</code></td>
                    <td>Something is intercepting your TLS &mdash; usually a corporate inspection proxy or an antivirus product that scans HTTPS. Append that product&rsquo;s root certificate to <code>cacert.pem</code>; don&rsquo;t disable verification.</td>
                </tr>
                <tr>
                    <td>Still <code>unable to get local issuer certificate</code></td>
                    <td>Check the path in <code>php.ini</code> actually points at the file (typos and back-slashes bite here), and that <code>cacert.pem</code> is a real bundle rather than a saved HTML error page.</td>
                </tr>
                <tr>
                    <td><code>Could not resolve host</code></td>
                    <td>Not a certificate problem at all &mdash; DNS. Check the URL for a typo and that the server can reach the internet.</td>
                </tr>
            </table>
            <p style="margin-top:22px;">Once a webhook delivers cleanly, the rest of the picture &mdash; signing, retries, dead-lettering and the delivery dashboard &mdash; is covered in the <a href="help-webhooks.php" style="color:var(--warning-text,#b45309); font-weight:600;">Webhooks guide</a>.</p>
            </div>
        </main>
    </div>

    <script>
    // Scroll-spy (identical to workflow/help.php).
    (function () {
        const helpMain = document.querySelector('.wfh-main');
        const navLinks = document.querySelectorAll('.wfh-nav-link[data-section]');
        const sections = [];
        navLinks.forEach(link => {
            const el = document.getElementById(link.dataset.section);
            if (el) sections.push({ id: link.dataset.section, el });
        });
        helpMain.addEventListener('scroll', function () {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0]?.id;
            for (const s of sections) { if (s.el.offsetTop - 200 <= scrollTop) current = s.id; }
            navLinks.forEach(link => link.classList.toggle('active', link.dataset.section === current));
        });
        navLinks.forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const el = document.getElementById(this.dataset.section);
                if (el) {
                    const containerTop = helpMain.getBoundingClientRect().top;
                    const elTop = el.getBoundingClientRect().top;
                    helpMain.scrollTo({ top: helpMain.scrollTop + (elTop - containerTop) - 20, behavior: 'smooth' });
                }
                navLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });
    })();
    </script>
</body>
</html>
