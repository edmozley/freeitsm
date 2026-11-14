<?php
/**
 * System Help - Assets whose Intune user differs (GH #335).
 */
require __DIR__ . '/_init.php';
$helpSlug = 'intune-user-mismatch';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What this list is</h3>
            <p>Every asset that is <strong>held by somebody here</strong> while a linked Intune device names <strong>somebody else</strong> as its primary user. People are matched by their Entra object id, never by name.</p>
        </div>
    </div>
    <p>It is in <strong>System &rarr; Maintenance</strong>, whose card says how many there are. The list only reads: open an asset to see, on its <strong>Intune</strong> tab, every primary user its device has had and what FreeITSM did about each change.</p>
</div>

<!-- 2. Why they differ -->
<div class="help-section" id="why">
    <div class="help-section-header"><?php echo helpSectionNum('why'); ?>
        <div>
            <h3>Why they differ</h3>
        </div>
    </div>
    <ul>
        <li><strong>The device was handed on in Intune</strong>, and nothing moved the asset here: <em>Follow changes of primary user</em> is off, or the holder here was chosen by a person.</li>
        <li><strong>A person corrected the holder here.</strong> Intune's primary user is often just whoever enrolled the device, so the person here may well be right - and stays.</li>
        <li><strong>Intune's primary user has not been imported</strong> from Microsoft Entra ID, so nobody here can be them. The list says so.</li>
    </ul>
</div>

<!-- 3. Settling them -->
<div class="help-section" id="fixing">
    <div class="help-section-header"><?php echo helpSectionNum('fixing'); ?>
        <div>
            <h3>Settling them</h3>
        </div>
    </div>
    <p>Hand the asset to the right person on the asset itself, as any handover. Or, in <strong>Assets &rarr; Settings &rarr; Intune</strong>, switch on <strong>Follow changes of primary user</strong>: the next sync moves each asset Intune itself gave to somebody on to its device's new primary user, recorded in the custody trail.</p>
    <div class="help-note"><strong>A holder chosen by a person is never moved by Intune.</strong> Those stay on this list until a person decides.</div>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
