<?php
/**
 * System Help — Docker (HTTPS for the container).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'docker';
require __DIR__ . '/_top.php';
?>
<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this area does</h3>
            <p>Out of the box, the Docker image serves FreeITSM over plain HTTP, and browsers mark it <strong>Not secure</strong>. This page makes a certificate for the name people use to reach FreeITSM, such as <code>freeitsm.internal</code>, and tells you exactly what else to do to turn HTTPS on.</p>
        </div>
    </div>
    <p>The card only appears when FreeITSM is running in its Docker image. On any other install, your web server (Apache, IIS or nginx) handles HTTPS, and you set it up there.</p>
    <div class="help-note">If a reverse proxy such as Caddy, Traefik or nginx already sits in front of the container, let it handle HTTPS and ignore this page.</div>
</div>

<!-- 2. Why -->
<div class="help-section" id="why">
    <div class="help-section-header"><?php echo helpSectionNum('why'); ?>
        <div>
            <h3>Why not Let's Encrypt</h3>
        </div>
    </div>
    <p>Free public certificate services such as Let's Encrypt only sign names that exist on the public internet and that you can prove you own. Names like <code>freeitsm.internal</code> are reserved for private networks, so nobody owns them and no public service will sign them.</p>
    <p>So FreeITSM makes its own small <strong>certificate authority</strong>, uses it to sign the certificate, and you install the authority's certificate on your PCs once. After that, browsers trust FreeITSM like any other site.</p>
    <div class="help-note ok">The authority is restricted to the one name and IP you entered. Installing it on your PCs does not let it vouch for any other website, even if somebody got hold of its key.</div>
</div>

<!-- 3. Steps -->
<div class="help-section" id="steps">
    <div class="help-section-header"><?php echo helpSectionNum('steps'); ?>
        <div>
            <h3>Turning HTTPS on</h3>
        </div>
    </div>
    <div class="help-steps">
        <div class="help-step"><div class="help-step-num">1</div><div><strong>Type the name and IP.</strong> The name is what people will type in their browser. The IP is the address of the machine running Docker; if you reached the page by IP, it is already filled in. Press <strong>Generate</strong>.</div></div>
        <div class="help-step"><div class="help-step-num">2</div><div><strong>Point the name at the server.</strong> Add a DNS record on your network's DNS server, or a line in the hosts file on each PC. The page shows the exact record.</div></div>
        <div class="help-step"><div class="help-step-num">3</div><div><strong>Install the authority certificate on every PC.</strong> Press <strong>Download</strong> and follow the steps for Windows, Group Policy, Intune or Mac.</div></div>
        <div class="help-step"><div class="help-step-num">4</div><div><strong>Restart the container</strong> with <code>docker compose restart</code> in the folder that holds your <code>docker-compose.yml</code>. The page turns green once the container is using the certificate.</div></div>
        <div class="help-step"><div class="help-step-num">5</div><div><strong>Open FreeITSM over HTTPS.</strong> The standard <code>docker-compose.yml</code> publishes HTTPS on port 8443, so the address is <code>https://freeitsm.internal:8443/</code>. Change the port line to <code>"443:443"</code> to drop the <code>:8443</code>, if nothing else on the machine uses port 443.</div></div>
    </div>
    <div class="help-note warn"><strong>Installed before this page existed?</strong> If your <code>docker-compose.yml</code> has no <code>tls</code> volume, the page asks you to add a small <code>docker-compose.override.yml</code> first and run <code>docker compose up -d</code>. Without it, the next update would delete the certificate, and every PC would need a new one.</div>
</div>

<!-- 4. Changes -->
<div class="help-section" id="changes">
    <div class="help-section-header"><?php echo helpSectionNum('changes'); ?>
        <div>
            <h3>Changing the name or IP</h3>
        </div>
    </div>
    <p>Because the authority only covers one name and IP, changing either makes a <strong>new</strong> authority, and every PC needs the new one installed. The page asks you to confirm first.</p>
    <p>Pressing <strong>Generate</strong> again with the same name and IP keeps the existing authority, so the PCs need nothing new. Do this to renew the certificate before it expires (it lasts a little over two years).</p>
</div>

<!-- 5. Troubleshooting -->
<div class="help-section" id="trouble">
    <div class="help-section-header"><?php echo helpSectionNum('trouble'); ?>
        <div>
            <h3>Troubleshooting</h3>
        </div>
    </div>
    <ul>
        <li><strong>The HTTPS address does not load at all.</strong> Check that your <code>docker-compose.yml</code> publishes port 443, and that you restarted the container. Plain HTTP keeps working throughout, so you can always get back to this page.</li>
        <li><strong>"This site can't be reached" or the wrong server.</strong> The name is not pointing at the server yet. Check the DNS record or hosts file line.</li>
        <li><strong>"Your connection is not private".</strong> The authority certificate is not installed on that PC, or it went into the wrong store. It must be under <em>Trusted Root Certification Authorities</em>.</li>
        <li><strong>The page says the certificate files do not match.</strong> Press <strong>Generate</strong> to make a fresh pair. The container stays on plain HTTP until you do.</li>
    </ul>
    <div class="help-note">A broken or missing certificate never stops the container starting: it checks the files each time it starts and only turns HTTPS on when they are valid.</div>
</div>
<?php require __DIR__ . '/_bottom.php'; ?>
