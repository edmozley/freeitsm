<?php
/**
 * Recording anything — the custom asset fields and import guide.
 *
 * Its OWN page rather than a section on asset-management/help.php, because the
 * feature is genuinely large: a field catalogue, sets, two ways to attach them,
 * a manual add, a four-step importer and a holding area. Folded into the main
 * guide it would have been the longest section by a distance and buried the
 * inventory-script material everybody arrives for.
 *
 * Same shell, sidebar, scroll-spy and accent as help.php — see
 * [[project_help_page_house_style]]: the house style is the shared help.css
 * primitives (.help-container / .help-sidebar / .help-section /
 * .help-list / .help-note), not a bespoke layout per page.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}

requireModuleAccess('assets');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'asset-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('asset-management.help.cf_page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=66">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--am-accent);
            --accent-hover: var(--am-accent-hover);
            --accent-soft:  var(--am-accent-soft);
            --on-accent:    var(--am-on-accent);
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('asset-management.help.cf_guide')); ?></h3>
            <a href="#why" class="help-nav-link active" data-section="why"><?php echo htmlspecialchars(t('asset-management.help.cf_nav_why')); ?></a>
            <a href="#idea" class="help-nav-link" data-section="idea"><?php echo htmlspecialchars(t('asset-management.help.cf_nav_idea')); ?></a>
            <a href="#making" class="help-nav-link" data-section="making"><?php echo htmlspecialchars(t('asset-management.help.cf_nav_making')); ?></a>
            <a href="#sets" class="help-nav-link" data-section="sets"><?php echo htmlspecialchars(t('asset-management.help.cf_nav_sets')); ?></a>
            <a href="#one-asset" class="help-nav-link" data-section="one-asset"><?php echo htmlspecialchars(t('asset-management.help.cf_nav_one_asset')); ?></a>
            <a href="#adding" class="help-nav-link" data-section="adding"><?php echo htmlspecialchars(t('asset-management.help.cf_nav_adding')); ?></a>
            <a href="#import" class="help-nav-link" data-section="import"><?php echo htmlspecialchars(t('asset-management.help.cf_nav_import')); ?></a>
            <a href="#matching" class="help-nav-link" data-section="matching"><?php echo htmlspecialchars(t('asset-management.help.cf_nav_matching')); ?></a>
            <a href="#attention" class="help-nav-link" data-section="attention"><?php echo htmlspecialchars(t('asset-management.help.cf_nav_attention')); ?></a>
            <a href="#using" class="help-nav-link" data-section="using"><?php echo htmlspecialchars(t('asset-management.help.cf_nav_using')); ?></a>
            <a href="#gotchas" class="help-nav-link" data-section="gotchas"><?php echo htmlspecialchars(t('asset-management.help.cf_nav_gotchas')); ?></a>
        </div>

        <div class="help-main" id="helpMain">
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('asset-management.help.cf_hero_title')); ?></h2>
                <p><?php echo t('asset-management.help.cf_hero_subtitle'); ?></p>
            </div>

            <div class="help-content">

                <!-- 1. Why -->
                <div class="help-section" id="why">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.cf_nav_why')); ?></h3>
                    </div>
                    <p>The built-in asset details &mdash; CPU, memory, BIOS, operating system &mdash; describe a computer, because that is what the inventory script reports. A printer, a monitor, a headset or a television has none of those, and the things that <em>do</em> matter about it (screen size, resolution, whether it is wireless) have nowhere to go.</p>
                    <p>Custom fields are how you record those things. You decide what gets recorded and against which kinds of asset. Adding a field takes effect immediately: no database change, no downtime, and nothing already recorded is touched.</p>
                    <p class="help-note">Everything here lives under <strong>Settings &rarr; Custom fields</strong> and <strong>Settings &rarr; Import</strong>. Both are separate permissions, so you can let somebody import a spreadsheet without letting them redesign what an asset records.</p>
                </div>

                <!-- 2. The idea -->
                <div class="help-section" id="idea">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.cf_nav_idea')); ?></h3>
                    </div>
                    <p>Three things, and it is worth getting them straight before you start &mdash; the rest follows easily once they click:</p>
                    <div class="help-list">
                        <div><strong>A field</strong> &mdash; one thing you want to record. &ldquo;Resolution&rdquo;, &ldquo;Screen size&rdquo;, &ldquo;Wireless&rdquo;. Defined <strong>once</strong> for the whole system.</div>
                        <div><strong>A field set</strong> &mdash; a bundle of fields you attach in one go. &ldquo;Screen&rdquo; might hold Screen size and Resolution.</div>
                        <div><strong>An asset type</strong> &mdash; Television, Monitor, Printer. You attach <em>sets</em> to types.</div>
                    </div>
                    <p style="margin-top: 14px;">So: a field goes in a set, and a set goes on a type.</p>
                    <p><strong>The important part</strong> is that a field is defined once and can appear in as many sets, on as many types, as you like. &ldquo;Resolution&rdquo; on a webcam is the <em>same field</em> as on a television &mdash; which is what makes it possible to search for a resolution and find both, or export one column covering every screen you own. Fourteen separate &ldquo;Resolution&rdquo; fields could never be looked at together.</p>
                    <p class="help-note"><strong>Settings &rarr; Custom fields</strong> opens with <em>How it all fits together</em> &mdash; a read-only picture of every type, the sets on it and the fields inside, plus a list of each field and everywhere it is used. When something is not appearing where you expect, look there first.</p>
                </div>

                <!-- 3. Making a field -->
                <div class="help-section" id="making">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.cf_nav_making')); ?></h3>
                    </div>
                    <p>The quickest route is the top section, <strong>Fields by asset type</strong>: pick a type, click <strong>Add</strong>, and the field is attached to that type straight away. A set is created behind the scenes, so you never have to think about sets at all unless you want to reuse a bundle.</p>
                    <p style="margin-top: 14px;"><strong>Kinds of information</strong></p>
                    <div class="help-list">
                        <div><strong>Text</strong> &mdash; anything. Optionally several lines.</div>
                        <div><strong>Number</strong> &mdash; with an optional unit shown beside the box (<code>&quot;</code>, GB, kg) and a choice of decimal places.</div>
                        <div><strong>Date</strong> &mdash; a date, a time, or both.</div>
                        <div><strong>Yes / no</strong> &mdash; and <em>not filled in</em> stays a third, separate answer.</div>
                        <div><strong>Pick from a list</strong> &mdash; you supply the choices.</div>
                        <div><strong>Web address</strong> and <strong>Email address</strong>.</div>
                        <div><strong>Link to something else</strong> &mdash; a person, another asset, or a configuration item.</div>
                    </div>
                    <p style="margin-top: 14px;"><strong>The switches</strong></p>
                    <div class="help-list">
                        <div><strong>Offer as a column in the asset list</strong> &mdash; makes it available in the Table view&rsquo;s column picker. It is not shown to everybody automatically; each person turns it on for themselves.</div>
                        <div><strong>Find assets by this field</strong> &mdash; searching (including &#8984;K) will match on its values. Best for serial numbers and part numbers, not free-text notes.</div>
                        <div><strong>No two assets may share a value</strong> &mdash; useful for a serial or MAC address. Checked within your own company, so two customers can each hold a PR0001.</div>
                    </div>
                    <p class="help-note">If you type a name that already exists as a built-in field &mdash; Make, Serial number, Cost &mdash; you will be warned. Every asset already has those as proper columns, and having both means the Add dialog asks twice and no report can put the two together. Use the built-in one unless you specifically want a second.</p>
                </div>

                <!-- 4. Sets -->
                <div class="help-section" id="sets">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.cf_nav_sets')); ?></h3>
                    </div>
                    <p>Sets earn their keep when several types want the same handful of fields. Make a set called <em>Peripheral basics</em> holding Serial number and Warranty end, attach it to Headset, Webcam and Keyboard, and all three record both. Add a third field to the set later and all three gain it at once &mdash; rather than you editing three types.</p>
                    <p>Types often want overlapping but not identical fields, and that is what more than one set is for. A worked example:</p>
                    <div class="help-list">
                        <div><strong>Screen</strong> = Screen size + Resolution &rarr; attached to <strong>Television</strong> and <strong>Monitor</strong></div>
                        <div><strong>Image</strong> = Resolution &rarr; attached to <strong>Webcam</strong> and <strong>Projector</strong></div>
                    </div>
                    <p style="margin-top: 14px;">A webcam has a resolution but no screen, so it gets the second set. <strong>Resolution is still one field</strong>, appearing in both sets &mdash; so a search for a resolution finds screens and webcams together.</p>
                    <p>Whether a field is <strong>required</strong> is set on the field&rsquo;s place in a set, not on the field itself, because a serial number may be compulsory for laptops and optional for keyboards.</p>
                </div>

                <!-- 5. One asset only -->
                <div class="help-section" id="one-asset">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.cf_nav_one_asset')); ?></h3>
                    </div>
                    <p>Sometimes only <em>some</em> of a type need extra detail. Ten televisions in meeting rooms, three of which are being trialled as smart TVs and need an IP address, a MAC address and a Netflix switch.</p>
                    <p>Rather than splitting the type in two, make a set for the extra fields and add it to those three assets individually: open the asset, and under <strong>Other details</strong> use <strong>Add a set of fields</strong>. The other seven do not gain empty boxes &mdash; they do not have the fields at all.</p>
                    <p>A set added to one asset shows as a small removable chip on that asset, so it is always obvious why it has fields its type does not.</p>
                    <p class="help-note">Removing the set again hides the fields but <strong>keeps everything recorded in them</strong>. Add it back and the values are all still there, so an accidental click costs nothing.</p>
                </div>

                <!-- 6. Adding assets -->
                <div class="help-section" id="adding">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.cf_nav_adding')); ?></h3>
                    </div>
                    <p>Computers arrive on their own &mdash; the inventory script, Intune and vCenter all report in. Anything that cannot report for itself is added by hand: on the asset list, click <strong>Add</strong> next to Scan and Assign tags.</p>
                    <p>The dialog asks for a name, type, status and location, then the built-in details (manufacturer, model, serial). Pick a type that records custom fields and <strong>those fields appear in the dialog too</strong>, so a television goes in complete in one go.</p>
                    <p>Names must be unique. Adding a second asset with a name you already use is refused, with a message pointing you at the existing one &mdash; a duplicate would split that asset&rsquo;s history in two.</p>
                    <p class="help-note">The hardware details the inventory script collects for itself (CPU, memory, BIOS, operating system) are deliberately not offered here. On anything that does report, typing them in would only be overwritten by the next sync.</p>
                </div>

                <!-- 7. Import -->
                <div class="help-section" id="import">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.cf_nav_import')); ?></h3>
                    </div>
                    <p>Ten items is ten trips through a dialog. <strong>Settings &rarr; Import</strong> takes a CSV instead &mdash; a supplier&rsquo;s stock list, an export from another system, or whatever somebody has been keeping in a spreadsheet. Four steps:</p>
                    <div class="help-list">
                        <div><strong>1. The file</strong> &mdash; a CSV with a heading row. It reports how many rows and columns it found.</div>
                        <div><strong>2. Where each column goes</strong> &mdash; it guesses, including matching a column to one of your own custom fields, and shows the first few values from your file beside each one so a wrong guess is obvious. Columns you do not want are set to <em>Do not import</em>, and it says how many and which are being ignored.</div>
                        <div><strong>3. What identifies a row</strong> &mdash; see the next section. This is the one that matters.</div>
                        <div><strong>4. Check, then import</strong> &mdash; preview first, then go.</div>
                    </div>
                    <p style="margin-top: 14px;"><strong>Preview does every check and writes nothing.</strong> It tells you, row by row, exactly what the real run would do. The <strong>Import</strong> button stays switched off until you have run one, and switches off again if you change the mapping &mdash; because a preview is the only thing between a mis-mapped column and hundreds of wrong records.</p>
                    <p>Type, status, location and supplier are matched <strong>by name</strong>, so a column full of the words Printer and Monitor works as you would expect. A name that does not exist is refused rather than invented &mdash; otherwise the first typo in a spreadsheet quietly creates an asset type called &ldquo;Televsion&rdquo;.</p>
                    <p>Two other choices worth knowing: whether the file&rsquo;s values <strong>replace what is already recorded or only fill in blanks</strong>, and what to do with a value that is not on a list field&rsquo;s choices (set the row aside, or add it to the list).</p>
                    <p class="help-note">5000 rows per file. If your file is longer it says so rather than quietly importing the first 5000.</p>
                </div>

                <!-- 8. Matching -->
                <div class="help-section" id="matching">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.cf_nav_matching')); ?></h3>
                    </div>
                    <p>This is the setting people skip, and the one that decides whether an import is something you can run again next month or a one-off.</p>
                    <p>You tick the columns that <strong>identify</strong> a row &mdash; the ones genuinely unique to one piece of equipment, like a name, an asset tag or a serial number. When the file is imported, each row is looked up on those columns in the order you ticked them:</p>
                    <div class="help-list">
                        <div><strong>Nothing matches</strong> &mdash; a new asset is created.</div>
                        <div><strong>Exactly one matches</strong> &mdash; that asset is updated.</div>
                        <div><strong>More than one matches</strong> &mdash; <strong>nothing is changed</strong> and the row is set aside for you. Picking one at random is how records get quietly overwritten.</div>
                    </div>
                    <p style="margin-top: 14px;">Get this right and importing the same file again updates what is there. Get it wrong &mdash; or leave it on something that is not unique, like a model number &mdash; and you either duplicate the estate or merge things that were never the same item.</p>
                    <p class="help-note">Matching is always within your own company, so two customers can each have a &ldquo;LAPTOP-01&rdquo; without ever being confused for one another.</p>
                </div>

                <!-- 9. Rows needing attention -->
                <div class="help-section" id="attention">
                    <div class="help-section-header">
                        <span class="help-section-num">9</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.cf_nav_attention')); ?></h3>
                    </div>
                    <p>A row that cannot be imported is <strong>kept</strong>, not thrown away and not guessed at. <strong>Rows needing attention</strong> on the Import screen shows each one with:</p>
                    <div class="help-list">
                        <div>which file and which row number it came from</div>
                        <div>exactly what was wrong &mdash; &ldquo;No asset type called &lsquo;Printer&rsquo;&rdquo;, &ldquo;Screen size expects a number&rdquo;</div>
                        <div><strong>what your file actually said</strong>, verbatim</div>
                    </div>
                    <p style="margin-top: 14px;">So you can fix the spreadsheet and import it again, or create whatever was missing and re-run &mdash; rather than working out from scratch what went astray. Mark a row <strong>Done</strong> to take it off the list.</p>
                    <p class="help-note">A row that fails leaves <strong>nothing</strong> behind. If it got as far as creating an asset and then hit a problem, the whole row is undone &mdash; so &ldquo;needs attention&rdquo; never means &ldquo;half of it worked&rdquo;.</p>
                </div>

                <!-- 10. Using the data -->
                <div class="help-section" id="using">
                    <div class="help-section-header">
                        <span class="help-section-num">10</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.cf_nav_using')); ?></h3>
                    </div>
                    <p>Recording things is only half of it. Once a field exists its values turn up in:</p>
                    <div class="help-list">
                        <div><strong>The asset itself</strong> &mdash; under <em>Other details</em>, grouped by set, with a &ldquo;3 of 3 filled in&rdquo; count. Blanks are tucked away until you ask for them.</div>
                        <div><strong>The Table view</strong> &mdash; any field marked <em>Offer as a column</em> is in the column picker, sortable and filterable like any other.</div>
                        <div><strong>CSV and PDF export</strong> &mdash; whatever columns you are looking at, custom ones included.</div>
                        <div><strong>Search and &#8984;K</strong> &mdash; for fields marked <em>Find assets by this field</em>. The result tells you which field matched.</div>
                        <div><strong>Handover documents</strong> &mdash; switch any custom field on as a column in <strong>Settings &rarr; Handover document</strong>, so a monitor is signed for with its size on the paperwork.</div>
                        <div><strong>The REST API</strong> &mdash; every asset carries a <code>fields</code> object keyed by field name.</div>
                        <div><strong>Asset history</strong> &mdash; changes to a custom field are audited exactly like built-in ones.</div>
                    </div>
                </div>

                <!-- 11. Things worth knowing -->
                <div class="help-section" id="gotchas">
                    <div class="help-section-header">
                        <span class="help-section-num">11</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.cf_nav_gotchas')); ?></h3>
                    </div>
                    <div class="help-list">
                        <div><strong>Nothing is ever deleted.</strong> Retiring a field, deleting a set, or removing a set from an asset all keep every value recorded. Put it back and the values return.</div>
                        <div><strong>&ldquo;Not filled in&rdquo; is a real answer.</strong> A yes/no field has three states, and filtering to <em>No</em> will not sweep up every asset that simply has not got the field. This matters more than it sounds.</div>
                        <div><strong>A field&rsquo;s kind cannot change once values exist.</strong> You can rename it freely, and you can always change how it is presented &mdash; a date field can switch between date and date-and-time &mdash; but text cannot become a number once answers have been given. The editor tells you before you try.</div>
                        <div><strong>The reference name is fixed.</strong> Each field has a short reference name used by imports and reports. Renaming the field&rsquo;s label never changes it, so a saved import mapping cannot break because somebody reworded a heading.</div>
                        <div><strong>Companies can have their own fields.</strong> On a multi-company install, a field created while working in a client company belongs to that company; one created in the default company is shared by all of them.</div>
                        <div><strong>Link fields are read-only on the asset for now.</strong> They display, and an import can set them, but choosing a person or another asset by hand needs a picker that has not been built yet.</div>
                        <div><strong>The asset tag cannot be set by an import.</strong> It can be used to identify a row, but not written &mdash; it is unique per company and that check lives with the asset tag itself.</div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
        // Scroll-spy, identical to help.php — see the note at the top of this
        // file about the shared help house style.
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.help-nav-link');
        const sections = [];

        navLinks.forEach(link => {
            const el = document.getElementById(link.dataset.section);
            if (el) sections.push({ id: link.dataset.section, el });
        });

        helpMain.addEventListener('scroll', function () {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0]?.id;
            for (const s of sections) {
                if (s.el.offsetTop - 200 <= scrollTop) current = s.id;
            }
            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
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
    </script>
</body>
</html>
