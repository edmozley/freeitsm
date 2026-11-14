<?php
/**
 * System Help — Locations (GH #311).
 * What a location is, building the tree, companies and shared locations, where
 * locations are used, and the same list in Assets -> Settings.
 */
require __DIR__ . '/_init.php';

$helpSlug = 'locations';
require __DIR__ . '/_top.php';
?>

<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What a location is here</h3>
            <p>The places your organisation works from - sites, buildings, floors and rooms - kept as one tree for each company.</p>
        </div>
    </div>
    <p>A location is a name in a tree you shape yourself, as deep or as shallow as you like, and each branch can differ: <em>UK &rsaquo; London &rsaquo; Floor 2</em> beside a simple <em>Data centre</em>. You manage them on <strong>System &rarr; Locations</strong>.</p>
    <p>Locations started out in Assets, for saying where each piece of kit is. They describe the organisation rather than the kit, so they now live in System, where other parts of FreeITSM can use them too. Nothing moved in the database: it is the same list, <strong>still kept separately for each company</strong> (only locations you mark as shared are seen by every company), and every asset keeps its location.</p>
</div>

<!-- 2. Building the tree -->
<div class="help-section" id="tree">
    <div class="help-section-header"><?php echo helpSectionNum('tree'); ?>
        <div>
            <h3>Building the tree</h3>
            <p>Add, nest, rename, move and delete.</p>
        </div>
    </div>
    <ul>
        <li><strong>Add</strong> at the top of the page adds a top-level location. The <strong>+</strong> on any location adds one inside it, with the parent already chosen.</li>
        <li><strong>Edit</strong> (the pencil) renames a location or moves it under a different parent. A location cannot be moved inside itself or one of its own sub-locations, so those are not offered.</li>
        <li><strong>Delete</strong> (the bin) is refused while a location still has sub-locations - delete or move them first, so a whole branch cannot go by accident. Assets at a deleted location are left with no location.</li>
        <li>The arrow beside a location folds its branch away; the number beside it is how many sub-locations it has.</li>
    </ul>
</div>

<!-- 3. Companies -->
<div class="help-section" id="companies">
    <div class="help-section-header"><?php echo helpSectionNum('companies'); ?>
        <div>
            <h3>Companies and shared locations</h3>
            <p>Each company has its own locations, and some can be shared by all of them.</p>
        </div>
    </div>
    <p>On an install with more than one company, <strong>every location belongs to one company</strong>: a client's offices are its own. The tree shows the locations of the company chosen in the company switcher in the top bar, and a new location goes into that company.</p>
    <p>A location such as a data centre that several companies' kit sits in can be <strong>shared</strong>: tick <strong>Shared with every company</strong> when adding or editing it, and every company sees it alongside its own, with a <em>Shared</em> badge. A shared location's parent must be shared too.</p>
    <div class="help-note"><strong>Who can change a shared location.</strong> Only someone with access to every company can share a location, or change or delete a shared one - a change to it changes what every company sees. Anyone else can still add their own locations inside it. A location cannot be unshared while other companies' assets are still there.</div>
</div>

<!-- 4. Where used -->
<div class="help-section" id="used">
    <div class="help-section-header"><?php echo helpSectionNum('used'); ?>
        <div>
            <h3>Where locations are used</h3>
            <p>One list, picked from in several places.</p>
        </div>
    </div>
    <ul>
        <li><strong>Assets</strong> - each asset's <strong>Location</strong>, shown on the asset and in the Table, and settable from the right-click menu.</li>
        <li><strong>Forms</strong> - a <strong>Lookup</strong> field can search <strong>Locations</strong>, so a form can ask <em>"Which office are you in?"</em> and get a real location rather than whatever someone types. Each location shows as its full path, such as <em>UK &gt; London &gt; Floor 2</em>, so two rooms called Floor 2 cannot be confused. A person sees their own company's locations and the shared ones. Tick <strong>Customers can use this on the portal</strong> on the field to let customers pick one too.</li>
    </ul>
</div>

<!-- 5. Assets -->
<div class="help-section" id="assets">
    <div class="help-section-header"><?php echo helpSectionNum('assets'); ?>
        <div>
            <h3>The same list in Assets</h3>
            <p>Asset managers can keep looking after it where they always have.</p>
        </div>
    </div>
    <p><strong>Assets &rarr; Settings &rarr; Locations</strong> shows this same tree, with a note that it is now used across FreeITSM. A change made in either place shows in both.</p>
    <p>System is for administrators, while the Assets tab is open to anyone given the <strong>Manage locations</strong> permission in Assets (System &rarr; Roles). That is why the tab stayed: the people who kept the list before still can, without needing to be administrators.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
