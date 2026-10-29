<?php
/**
 * Asset Management Help Guide - Full page with left pane navigation
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
    <title><?php echo htmlspecialchars(pageTitle('assets', t('asset-management.nav.help'))); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=79">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/help.css?v=6">
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
        <!-- Left pane navigation -->
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars(t('asset-management.help.guide')); ?></h3>
            <a href="#overview" class="help-nav-link active" data-section="overview">
                <span class="help-nav-num">1</span>
                <?php echo htmlspecialchars(t('asset-management.help.nav_overview')); ?>
            </a>
            <a href="#asset-detail" class="help-nav-link" data-section="asset-detail">
                <span class="help-nav-num">2</span>
                <?php echo htmlspecialchars(t('asset-management.help.nav_asset_detail')); ?>
            </a>
            <a href="#table-view" class="help-nav-link" data-section="table-view">
                <span class="help-nav-num">3</span>
                <?php echo htmlspecialchars(t('asset-management.help.nav_table_view')); ?>
            </a>
            <a href="#inventory-script" class="help-nav-link" data-section="inventory-script">
                <span class="help-nav-num">4</span>
                <?php echo htmlspecialchars(t('asset-management.help.nav_inventory_script')); ?>
            </a>
            <a href="#what-gets-collected" class="help-nav-link" data-section="what-gets-collected">
                <span class="help-nav-num">5</span>
                <?php echo htmlspecialchars(t('asset-management.help.nav_what_collected')); ?>
            </a>
            <a href="#deployment" class="help-nav-link" data-section="deployment">
                <span class="help-nav-num">6</span>
                <?php echo htmlspecialchars(t('asset-management.help.nav_deployment')); ?>
            </a>
            <a href="#reconciliation" class="help-nav-link" data-section="reconciliation">
                <span class="help-nav-num">7</span>
                <?php /* the string already holds &amp; - escaping it again showed "&amp;amp;" */ echo t('asset-management.help.reconciliation.nav'); ?>
            </a>
            <a href="#asset-tags" class="help-nav-link" data-section="asset-tags">
                <span class="help-nav-num">8</span>
                <?php echo htmlspecialchars(t('asset-management.help.asset_tags.nav')); ?>
            </a>
            <a href="#asset-labels" class="help-nav-link" data-section="asset-labels">
                <span class="help-nav-num">9</span>
                <?php echo t('asset-management.help.asset_labels.nav'); ?>
            </a>
            <a href="#servers" class="help-nav-link" data-section="servers">
                <span class="help-nav-num">10</span>
                <?php echo htmlspecialchars(t('asset-management.help.nav_servers')); ?>
            </a>
            <a href="#proxmox" class="help-nav-link" data-section="proxmox">
                <span class="help-nav-num">11</span>
                <?php echo htmlspecialchars(t('asset-management.help.proxmox.nav')); ?>
            </a>
            <a href="#vcloud" class="help-nav-link" data-section="vcloud">
                <span class="help-nav-num">12</span>
                <?php echo htmlspecialchars(t('asset-management.help.vcloud.nav')); ?>
            </a>
            <a href="#dashboard" class="help-nav-link" data-section="dashboard">
                <span class="help-nav-num">13</span>
                <?php echo htmlspecialchars(t('asset-management.help.nav_dashboard')); ?>
            </a>
            <a href="#who-holds-what" class="help-nav-link" data-section="who-holds-what">
                <span class="help-nav-num">14</span>
                <?php echo htmlspecialchars(t('asset-management.help.nav_users')); ?>
            </a>
            <a href="#linked-tickets" class="help-nav-link" data-section="linked-tickets">
                <span class="help-nav-num">15</span>
                Tickets on an asset
            </a>
            <a href="#linked-contracts" class="help-nav-link" data-section="linked-contracts">
                <span class="help-nav-num">16</span> Contracts covering an asset
            </a>
            <a href="#right-click" class="help-nav-link" data-section="right-click">
                <span class="help-nav-num">17</span> Right-click an asset
            </a>
            <a href="#companies" class="help-nav-link" data-section="companies">
                <span class="help-nav-num">18</span>
                <?php echo htmlspecialchars(t('asset-management.help.companies.nav')); ?>
            </a>
            <a href="#leases" class="help-nav-link" data-section="leases">
                <span class="help-nav-num">19</span> Leases
            </a>
            <a href="#cmdb-link" class="help-nav-link" data-section="cmdb-link">
                <span class="help-nav-num">20</span> Configuration item
            </a>
            <a href="#hyperv" class="help-nav-link" data-section="hyperv">
                <span class="help-nav-num">21</span> Hyper-V
            </a>
            <a href="#retired" class="help-nav-link" data-section="retired">
                <span class="help-nav-num">22</span>
                <?php echo htmlspecialchars(t('asset-management.help.retired.nav')); ?>
            </a>
            <a href="#tips" class="help-nav-link" data-section="tips">
                <span class="help-nav-num">23</span>
                <?php echo htmlspecialchars(t('asset-management.help.nav_tips')); ?>
            </a>
        </div>

        <!-- Main content area -->
        <div class="help-main" id="helpMain">
            <!-- Hero banner -->
            <div class="help-hero">
                <h2><?php echo htmlspecialchars(t('asset-management.help.hero_title')); ?></h2>
                <p><?php echo t('asset-management.help.hero_subtitle'); ?></p>
            </div>

            <div class="help-content">

                <!-- Section 1: Overview -->
                <div class="help-section" id="overview">
                    <div class="help-section-header">
                        <span class="help-section-num">1</span>
                        <div>
                            <h3><?php echo htmlspecialchars(t('asset-management.help.nav_overview')); ?></h3>
                            <p><?php echo htmlspecialchars(t('asset-management.help.overview_intro')); ?></p>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('asset-management.help.card_assets_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('asset-management.help.card_assets_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="9" y1="21" x2="9" y2="9"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('asset-management.help.card_dashboard_title')); ?></h4>
                            <p><?php echo t('asset-management.help.card_dashboard_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect><line x1="6" y1="6" x2="6.01" y2="6"></line><line x1="6" y1="18" x2="6.01" y2="18"></line></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('asset-management.help.card_servers_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('asset-management.help.card_servers_desc')); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </div>
                            <h4><?php echo htmlspecialchars(t('asset-management.help.card_assignment_title')); ?></h4>
                            <p><?php echo htmlspecialchars(t('asset-management.help.card_assignment_desc')); ?></p>
                        </div>
                    </div>
                    <?php /* Its own guide rather than a section here: custom
                             fields + import is a large feature, and folded in it
                             would be the longest section on the page and bury
                             the inventory-script material everybody arrives
                             for. Signposted from the top so it is findable. */ ?>
                    <div class="help-note" style="margin-top: 18px;">
                        <strong><?php echo htmlspecialchars(t('asset-management.help.overview_cf_lead')); ?></strong>
                        <?php echo t('asset-management.help.overview_cf_body'); ?>
                        <a href="help-custom-fields.php"><?php echo htmlspecialchars(t('asset-management.help.cf_link')); ?> &rarr;</a>
                    </div>
                </div>

                <!-- Section 2: Asset Detail View -->
                <div class="help-section" id="asset-detail">
                    <div class="help-section-header">
                        <span class="help-section-num">2</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.nav_asset_detail')); ?></h3>
                    </div>
                    <p>Click any asset in the list to open its detail panel. The view is split into sections:</p>
                    <div class="help-list">
                        <div><strong>Header</strong> &mdash; Hostname, service tag, assigned user, and a View History button for the full audit trail</div>
                        <div><strong>Info grid</strong> &mdash; Type, status, manufacturer, model, CPU, memory, operating system, feature release, build number, and BIOS</div>
                        <div><strong>Other details</strong> &mdash; your own <a href="help-custom-fields.php">custom fields</a> for this kind of asset, grouped by set with a &ldquo;3 of 3 filled in&rdquo; count. Only appears when the asset&rsquo;s type records something extra.</div>
                        <div><strong>Financial tab</strong> &mdash; Purchase date and cost, supplier, cost centre, order number, warranty expiry and the lease dates, with the lease the asset is on (see <a href="#leases">Leases</a>)</div>
                        <div><strong>Storage</strong> &mdash; Drive cards showing capacity, usage percentage with colour-coded bars (green &lt; 75%, amber 75&ndash;90%, red &gt; 90%), and file system</div>
                        <div><strong>Devices tab</strong> &mdash; Every device from Windows Device Manager, grouped by category (Display adapters, Network adapters, etc.) with driver info and status badges. Use the search box to filter.</div>
                        <div><strong>Software tab</strong> &mdash; All installed applications and system components with publisher and version. Toggle between Applications, Components, and All.</div>
                    </div>
                    <p class="help-note">Type and Status are editable inline &mdash; click the dropdown in the info grid to change them. Changes are recorded in the history.</p>
                    <?php /* GH #200: per-type layouts. */ ?>
                    <h4 id="asset-layouts"><?php echo htmlspecialchars(t('asset-management.help.layout_heading')); ?></h4>
                    <p><?php echo t('asset-management.help.layout_body'); ?></p>
                    <p class="help-note"><?php echo t('asset-management.help.layout_hidden'); ?></p>
                    <?php /* GH #200 Part B: inventory freshness. */ ?>
                    <h4 id="inventory-freshness"><?php echo htmlspecialchars(t('asset-management.help.freshness_heading')); ?></h4>
                    <p><?php echo t('asset-management.help.freshness_body'); ?></p>
                    <p class="help-note"><?php echo t('asset-management.help.freshness_agent'); ?></p>
                    <?php /* GH #200 part C: connected assets. */ ?>
                    <h4 id="connected-assets"><?php echo htmlspecialchars(t('asset-management.help.connected_heading')); ?></h4>
                    <p><?php echo t('asset-management.help.connected_body'); ?></p>
                    <p class="help-note"><?php echo t('asset-management.help.connected_rules'); ?></p>
                    <?php /* GH #200 part D: review, merge, protected values, names. */ ?>
                    <h4 id="asset-review"><?php echo htmlspecialchars(t('asset-management.help.review_heading')); ?></h4>
                    <p><?php echo t('asset-management.help.review_body'); ?></p>
                    <p class="help-note"><?php echo t('asset-management.help.merge_body'); ?></p>
                    <h4 id="protected-values"><?php echo htmlspecialchars(t('asset-management.help.protect_heading')); ?></h4>
                    <p><?php echo t('asset-management.help.protect_body'); ?></p>
                    <h4 id="name-mappings"><?php echo htmlspecialchars(t('asset-management.help.names_heading')); ?></h4>
                    <p><?php echo t('asset-management.help.names_body'); ?></p>
                </div>

                <!-- Section 3: Table View -->
                <div class="help-section" id="table-view">
                    <div class="help-section-header">
                        <span class="help-section-num">3</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.nav_table_view')); ?></h3>
                    </div>
                    <p>The <strong>Table</strong> tab in the module nav gives you a full-screen spreadsheet-style alternative to the split-pane Assets tab &mdash; built for power users who want to slice, sort and export the estate rather than drill into one asset at a time. Click any row to jump back into the split-pane detail view for that asset.</p>

                    <p style="margin-top: 14px;"><strong>Sort, search and filter</strong></p>
                    <div class="help-list">
                        <div><strong>Click a column header</strong> &mdash; cycles the sort: ascending &rarr; descending &rarr; ascending. The arrow on the active sort column highlights in blue.</div>
                        <div><strong>Funnel icon on each header</strong> &mdash; opens an Excel-style dropdown listing the distinct values in that column with row counts and an inline search box. Untick a value to hide rows that have it; tick again to show. <em>Select all</em> / <em>Clear</em> shortcuts at the top.</div>
                        <div><strong>Cascading filters</strong> &mdash; the distinct-values list for a column is narrowed by whatever other column filters are active, so the dropdown only shows values that actually appear in the rows still visible (matching Excel's behaviour).</div>
                        <div><strong>Global search box</strong> &mdash; matches the typed term as a substring across every <em>visible</em> column. Hide a column with the Columns drawer to take it out of the search scope.</div>
                        <div><strong>Reset</strong> &mdash; clears every filter, the search box and the sort in one click.</div>
                    </div>

                    <p style="margin-top: 14px;"><strong>Saved views</strong></p>
                    <p>Once the table looks how you want it &mdash; the right columns in the right order, sorted and filtered &mdash; <strong>Save view</strong> in the toolbar keeps that arrangement under a name. <strong>Views</strong> beside it opens the library of everything you can reach.</p>
                    <div class="help-list">
                        <div><strong>Who can see it</strong> &mdash; only you, one of your teams, or everyone. Sharing asks <em>which</em> team, because most people are in more than one, and a view shared with a team is readable by them and editable only by whoever wrote it.</div>
                        <div><strong>A description</strong> &mdash; worth writing on anything you share, since it is what tells somebody else whether the view is the one they want.</div>
                        <div><strong>Your default</strong> &mdash; the star opens the table with that view every time. It is your default, not everybody's: two people can have different defaults pointing at the same shared view.</div>
                        <div><strong>Searching the library</strong> &mdash; by name, by description, or by who made it, which is how people look for a view they did not write. List or cards, whichever you prefer; the choice is remembered.</div>
                    </div>
                    <p class="help-note">The <strong>search box is not saved</strong> with a view, on purpose. A filter is how you like to look at things; a search is a question you asked once, and reopening &ldquo;Servers&rdquo; tomorrow to find three rows because of what you typed last week would be baffling. Filters, columns and sort are all saved.</p>
                    <p class="help-note">Editing a view changes its name, description and who can see it &mdash; not what it shows. Renaming something should not silently change what it does. To capture the table as it looks now, use <strong>Save view</strong> again.</p>
                    <p class="help-note">The same views exist on the Tasks, Calendar and Change Management tables, because all four share one table engine. Each table has its own views, so an asset view never turns up on the tasks table.</p>
                    <p style="margin-top: 14px;"><strong>Customise the columns</strong></p>
                    <p>The default visible set is Hostname, Type, Status, Manufacturer, Model, OS and Assigned users. Click the <strong>Columns</strong> button on the toolbar to open a drawer where you can tick to show / hide and drag the ⋮⋮ handles to reorder. You can also drag the table headers themselves to reorder columns directly. The available hidden-by-default columns include Feature release, Build, Serial number, CPU, CPU speed, Memory and BIOS.</p>
                    <p><strong>Your own fields appear here too.</strong> Any <a href="help-custom-fields.php">custom field</a> marked <em>Offer as a column</em> joins the same drawer, and sorts, filters and exports exactly like a built-in one. Filtering a yes/no field keeps <em>Yes</em>, <em>No</em> and <em>not filled in</em> as three separate choices, so filtering to <em>No</em> never sweeps up assets that simply have not got the field.</p>
                    <p>Visible columns, column order and sort direction <strong>persist per analyst</strong> &mdash; saved against your account via <code>user_preferences</code> so you keep the same layout when you sign in on another machine. Search and active filters are deliberately transient session state.</p>

                    <p style="margin-top: 14px;"><strong>Export</strong></p>
                    <div class="help-list">
                        <div><strong>CSV</strong> &mdash; UTF-8 with a byte-order mark so Excel opens it cleanly; embedded commas, quotes and newlines are properly escaped. Exports the <em>current</em> view (whatever columns are visible, after filters / search / sort).</div>
                        <div><strong>PDF</strong> &mdash; landscape A4, blue header band, your company logo on the top left, and a "{visible} of {total} &mdash; {timestamp}" subhead. Text is selectable (not a screenshot) because it's generated with jsPDF + autotable, the same library the morning-checks module uses.</div>
                    </div>

                    <p class="help-note">The whole feature is column-agnostic &mdash; if a new asset field is added later, it'll automatically pick up sorting, filtering, search and export with no extra code.</p>
                </div>

                <!-- Section 4: Inventory Script (highlighted) -->
                <div class="help-section" id="inventory-script">
                    <div class="help-section-header">
                        <span class="help-section-num">4</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.inventory_script_heading')); ?></h3>
                    </div>
                    <p>Assets are discovered automatically using a PowerShell script that runs on each Windows machine. It collects hardware, software, and device information, then posts it to your FreeITSM instance via the API.</p>

                    <p>The script is located at <strong>scripts/Invoke-AssetInventory.ps1</strong> in your FreeITSM installation. It takes two parameters:</p>

                    <div class="help-code">
                        <span class="comment"># Basic usage &mdash; post inventory to FreeITSM</span><br>
                        .\Invoke-AssetInventory.ps1 <span class="flag">-ApiUrl</span> <span class="string">"https://itsm.yourcompany.com"</span> <span class="flag">-ApiKey</span> <span class="string">"your-api-key"</span><br><br>
                        <span class="comment"># Save to a file (useful for testing)</span><br>
                        .\Invoke-AssetInventory.ps1 <span class="flag">-OutputFile</span> <span class="string">"C:\Temp\asset.json"</span><br><br>
                        <span class="comment"># Both &mdash; post to API and save a local copy</span><br>
                        .\Invoke-AssetInventory.ps1 <span class="flag">-ApiUrl</span> <span class="string">"https://itsm.yourcompany.com"</span> <span class="flag">-ApiKey</span> <span class="string">"your-api-key"</span> <span class="flag">-OutputFile</span> <span class="string">"C:\Temp\asset.json"</span>
                    </div>

                    <h4>If FreeITSM uses a self-signed certificate</h4>

                    <p>On an internal address such as <strong>https://freeitsm.internal</strong>, the certificate is often self-signed or issued by a private CA. Machines that do not trust that certificate cannot post their inventory, and the script stops with <em>&ldquo;Could not establish trust relationship for the SSL/TLS secure channel&rdquo;</em>.</p>

                    <p>There are three ways through, best first:</p>

                    <ol>
                        <li><strong>Install the issuing CA certificate</strong> into the Trusted Root store on the machines running the script &mdash; by Group Policy if you have a domain. Nothing about the script changes, and every other tool on those machines benefits too. In a domain with an internal PKI this is usually already the case.</li>
                        <li><strong>Pin the certificate</strong> with <strong>-CertificateThumbprint</strong>, if there is no internal CA to install. The inventory is sent only if the server presents exactly that certificate, so an impostor server is still refused. This is safe to use in production.</li>
                        <li><strong>Skip the check</strong> with <strong>-SkipCertificateCheck</strong>. This accepts <em>any</em> certificate, so anyone able to intercept the connection can read the API key and the inventory. Use it to get going in a lab, not as a permanent setting.</li>
                    </ol>

                    <div class="help-code">
                        <span class="comment"># Pin the server's certificate &mdash; recommended for internal addresses</span><br>
                        .\Invoke-AssetInventory.ps1 <span class="flag">-ApiUrl</span> <span class="string">"https://freeitsm.internal"</span> <span class="flag">-ApiKey</span> <span class="string">"your-api-key"</span> <span class="flag">-CertificateThumbprint</span> <span class="string">"A1B2C3D4E5F60718293A4B5C6D7E8F9012345678"</span><br><br>
                        <span class="comment"># Accept any certificate &mdash; lab use only</span><br>
                        .\Invoke-AssetInventory.ps1 <span class="flag">-ApiUrl</span> <span class="string">"https://freeitsm.internal"</span> <span class="flag">-ApiKey</span> <span class="string">"your-api-key"</span> <span class="flag">-SkipCertificateCheck</span>
                    </div>

                    <p>Where you read the thumbprint depends on what serves FreeITSM. Spaces, colons and lower case are all accepted, so paste it however you find it.</p>

                    <p><strong>Apache, XAMPP, WAMP or nginx</strong> &mdash; the certificate is a file, not an entry in the Windows certificate store. Run this on the FreeITSM server, adjusting the path to your certificate:</p>

                    <div class="help-code">
                        (New-Object System.Security.Cryptography.X509Certificates.X509Certificate2(<span class="string">"C:\xampp\apache\conf\ssl.crt\server.crt"</span>)).Thumbprint
                    </div>

                    <p><strong>IIS</strong> &mdash; the certificate is in the store, so on the FreeITSM server:</p>

                    <div class="help-code">
                        Get-ChildItem Cert:\LocalMachine\My | Format-List Subject, Thumbprint
                    </div>

                    <p><strong>Any server, from a browser</strong> &mdash; visit your FreeITSM address, click through the certificate warning, open the certificate details and copy <strong>Thumbprint</strong> or <strong>SHA-1 fingerprint</strong>.</p>

                    <p class="help-note">XAMPP's built-in certificate is issued to <strong>localhost</strong>, so installing it as a trusted root still won't work &mdash; it will be rejected on the name instead. Pinning the thumbprint sidesteps that, because you have named the exact certificate you mean.</p>

                    <p class="help-note">A thumbprint is not a secret &mdash; it is a fingerprint of the certificate the server already shows to everyone who connects. The API key is the secret, which is exactly why it should not travel over a connection nobody is checking.</p>

                    <div class="help-flow">
                        <div class="help-flow-step">PowerShell script</div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step">system-info API</div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step">device-manager API</div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step">Database</div>
                        <div class="help-flow-arrow">&rarr;</div>
                        <div class="help-flow-step">Asset detail</div>
                    </div>

                    <p class="help-note">The script makes two API calls: one to <strong>/api/external/system-info/submit/</strong> (hardware, disks, network, software) and one to <strong>/api/external/device-manager/submit/</strong> (Device Manager data). Both are authenticated using the same API key.</p>
                </div>

                <!-- Section 4: What Gets Collected -->
                <div class="help-section" id="what-gets-collected">
                    <div class="help-section-header">
                        <span class="help-section-num">5</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.nav_what_collected')); ?></h3>
                    </div>
                    <p>The PowerShell script gathers everything you'd want to know about a Windows machine in a single run:</p>
                    <div class="help-cards cols-3">
                        <div class="help-card">
                            <strong>System</strong>
                            <span>Hostname, manufacturer, model, service tag, domain, logged-in user</span>
                        </div>
                        <div class="help-card">
                            <strong>CPU &amp; Memory</strong>
                            <span>Processor name, clock speed, total physical memory</span>
                        </div>
                        <div class="help-card">
                            <strong>Operating System</strong>
                            <span>OS name, feature release (e.g. 24H2), build number</span>
                        </div>
                        <div class="help-card">
                            <strong>Storage</strong>
                            <span>Logical drives with capacity, free space, file system, and percentage used</span>
                        </div>
                        <div class="help-card">
                            <strong>Network</strong>
                            <span>Adapter name, MAC address, IP, subnet, gateway, DHCP status</span>
                        </div>
                        <div class="help-card">
                            <strong>GPU</strong>
                            <span>Graphics card name, driver version, VRAM, resolution</span>
                        </div>
                        <div class="help-card">
                            <strong>Device Manager</strong>
                            <span>All present devices grouped by category, with driver manufacturer, version, and date</span>
                        </div>
                        <div class="help-card">
                            <strong>Installed Software</strong>
                            <span>Every application and component from Add/Remove Programs, with version and publisher</span>
                        </div>
                        <div class="help-card optional">
                            <strong>BIOS &amp; Boot</strong>
                            <span>BIOS version, last boot time, uptime</span>
                        </div>
                        <div class="help-card optional">
                            <strong>TPM</strong>
                            <span>Version, manufacturer, enabled/activated status (requires admin)</span>
                        </div>
                        <div class="help-card optional">
                            <strong>BitLocker</strong>
                            <span>Protection status, encryption method per volume (requires admin)</span>
                        </div>
                        <div class="help-card optional">
                            <strong>Uptime</strong>
                            <span>Last boot time (UTC) and uptime in days</span>
                        </div>
                    </div>
                    <p class="help-note">Items with an amber border require running the script as Administrator. Everything else works under a standard user account.</p>
                </div>

                <!-- Section 5: Deploying at Scale (highlighted) -->
                <div class="help-section" id="deployment">
                    <div class="help-section-header">
                        <span class="help-section-num">6</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.nav_deployment')); ?></h3>
                    </div>
                    <p>Running the script manually on one machine is fine for testing. In production, you'll want it running automatically across your entire estate.</p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong>Get your API key</strong> &mdash; go to <strong>Software &rarr; Settings &rarr; API Keys</strong> and generate a key. This authenticates the script against FreeITSM.<br>
                                <span class="help-note" style="display:block;margin-top:6px;">Yes, <strong>Software</strong>, not Assets. And <strong>not</strong> the keys under <strong>System &rarr; API</strong>, which are a different system entirely: those are for the REST API and begin <code>fitsm_</code>. The inventory script needs the Software one, which is 40 characters with no prefix. A key from the wrong page fails with <em>Invalid authorization key</em>.</span>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong>Copy the script</strong> &mdash; place <strong>Invoke-AssetInventory.ps1</strong> on a network share (e.g. <code>\\server\scripts$\</code>) so all machines can reach it.
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong>Create a scheduled task</strong> &mdash; use Group Policy Preferences or your endpoint management tool to schedule the script. A daily or weekly run keeps things fresh.
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <strong>Set execution policy</strong> &mdash; the scheduled task command should use:<br>
                                <code>powershell.exe -ExecutionPolicy Bypass -File "\\server\scripts$\Invoke-AssetInventory.ps1" -ApiUrl "https://itsm.yourcompany.com" -ApiKey "your-key"</code>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <strong>Run as SYSTEM</strong> &mdash; for full data (TPM, BitLocker), run the scheduled task as <strong>NT AUTHORITY\SYSTEM</strong> with highest privileges. Otherwise, standard user works for the core inventory.
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">6</div>
                            <div>
                                <strong>Check the certificate is trusted</strong> &mdash; if FreeITSM is on an internal address with a self-signed certificate, add <strong>-CertificateThumbprint</strong> to the arguments, or install the issuing CA by Group Policy. Without one of the two, every machine will fail at the same point and nothing will appear in the asset list. Prove it on one machine before rolling the task out.
                            </div>
                        </div>
                    </div>

                    <div class="help-code">
                        <span class="comment"># Example: Group Policy scheduled task action</span><br>
                        <span class="param">Program:</span> <span class="string">powershell.exe</span><br>
                        <span class="param">Arguments:</span> <span class="string">-ExecutionPolicy Bypass -File "\\fileserver\scripts$\Invoke-AssetInventory.ps1" -ApiUrl "https://itsm.yourcompany.com" -ApiKey "abc123"</span><br>
                        <span class="param">Run as:</span> <span class="string">NT AUTHORITY\SYSTEM</span><br>
                        <span class="param">Schedule:</span> <span class="string">Daily at 12:00</span>
                    </div>

                    <p class="help-note">Each run is idempotent &mdash; the API creates the asset on first contact and updates it on every subsequent run. Renamed computers have their names updated in place using hardware serial numbers, without losing history or creating duplicates. Software that's been uninstalled is automatically removed.</p>
                </div>

                <!-- Section 7: Discovery & Reconciliation -->
                <div class="help-section" id="reconciliation">
                    <div class="help-section-header">
                        <span class="help-section-num">7</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.reconciliation.heading')); ?></h3>
                    </div>
                    <p><?php echo t('asset-management.help.reconciliation.intro'); ?></p>

                    <div class="help-list">
                        <div><strong><?php echo htmlspecialchars(t('asset-management.help.reconciliation.renames_strong')); ?></strong> &mdash; <?php echo t('asset-management.help.reconciliation.renames_text'); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('asset-management.help.reconciliation.ignored_strong')); ?></strong> &mdash; <?php echo t('asset-management.help.reconciliation.ignored_text'); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('asset-management.help.reconciliation.refresh_strong')); ?></strong> &mdash; <?php echo t('asset-management.help.reconciliation.refresh_text'); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('asset-management.help.reconciliation.intune_strong')); ?></strong> &mdash; <?php echo t('asset-management.help.reconciliation.intune_text'); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('asset-management.help.reconciliation.companies_strong')); ?></strong> &mdash; <?php echo t('asset-management.help.reconciliation.companies_text'); ?></div>
                    </div>
                </div>

                <!-- Section 8: Asset tag auto-generation -->
                <div class="help-section" id="asset-tags">
                    <div class="help-section-header">
                        <span class="help-section-num">8</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.asset_tags.heading')); ?></h3>
                    </div>
                    <p><?php echo t('asset-management.help.asset_tags.intro'); ?></p>

                    <div class="help-list">
                        <div><strong><?php echo htmlspecialchars(t('asset-management.help.asset_tags.monotonic_strong')); ?></strong> &mdash; <?php echo t('asset-management.help.asset_tags.monotonic_text'); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('asset-management.help.asset_tags.lifecycle_strong')); ?></strong> &mdash; <?php echo t('asset-management.help.asset_tags.lifecycle_text'); ?></div>
                    </div>
                </div>

                <!-- Section 9: Physical asset labels & QR customisation -->
                <div class="help-section" id="asset-labels">
                    <div class="help-section-header">
                        <span class="help-section-num">9</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.asset_labels.heading')); ?></h3>
                    </div>
                    <p><?php echo t('asset-management.help.asset_labels.intro'); ?></p>

                    <div class="help-list">
                        <div><strong><?php echo htmlspecialchars(t('asset-management.help.asset_labels.fields_strong')); ?></strong> &mdash; <?php echo t('asset-management.help.asset_labels.fields_text'); ?></div>
                        <div><strong><?php echo htmlspecialchars(t('asset-management.help.asset_labels.qr_strong')); ?></strong> &mdash; <?php echo t('asset-management.help.asset_labels.qr_text'); ?></div>
                    </div>
                </div>

                <!-- Section 10: Servers & vCenter -->
                <div class="help-section" id="servers">
                    <div class="help-section-header">
                        <span class="help-section-num">10</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.nav_servers')); ?></h3>
                    </div>
                    <p>If you run VMware vCenter, FreeITSM can sync your entire virtual machine estate with a single click.</p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong>Configure credentials</strong> &mdash; go to Settings &gt; vCenter tab and enter the server hostname, username, and password.
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong>Click Sync hypervisors</strong> &mdash; on the Servers tab, click the sync button. FreeITSM syncs vCenter, then every active Proxmox VE and VMware Cloud Director server (sections 11 and 12), and shows one result line for each.
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong>Browse the results</strong> &mdash; the Servers tab shows summary cards (total VMs, vCPU, memory, storage, hosts, clusters) and a searchable, sortable table of every VM. Click any row to see the full detail &mdash; disks, NICs, guest OS, VMware Tools, and the raw JSON from vCenter.
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <strong>Hosts and clusters</strong> &mdash; click the <em>Hosts</em> or <em>Clusters</em> card to see every source's clusters and the hosts in them, with the VMs on each. Click a host to list only its VMs (the <em>Host:</em> chip by the filters clears it). Hosts come from Hyper-V, Proxmox nodes and the ESXi hosts named on vCenter VMs. Cloud Director never shows its hosts to a tenant, so only its virtual data centres are listed, and they are not counted.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 11: proxmox (PR #167) -->
                <div class="help-section" id="proxmox">
                    <div class="help-section-header">
                        <span class="help-section-num">11</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.proxmox.heading')); ?></h3>
                    </div>
                    <p><?php echo t('asset-management.help.proxmox.intro'); ?></p>
                    <h4><?php echo htmlspecialchars(t('asset-management.help.proxmox.user_heading')); ?></h4>
                    <ol>
                        <li><?php echo t('asset-management.help.proxmox.user_1'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.user_2'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.user_3'); ?></li>
                    </ol>
                    <h4><?php echo htmlspecialchars(t('asset-management.help.proxmox.token_heading')); ?></h4>
                    <ol>
                        <li><?php echo t('asset-management.help.proxmox.token_1'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.token_2'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.token_3'); ?></li>
                    </ol>
                    <h4><?php echo htmlspecialchars(t('asset-management.help.proxmox.fill_heading')); ?></h4>
                    <ul>
                        <li><?php echo t('asset-management.help.proxmox.fill_host'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.fill_user'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.fill_secret'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.fill_verify'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.fill_interval'); ?></li>
                    </ul>
                    <p><?php echo t('asset-management.help.proxmox.test'); ?></p>
                    <h4><?php echo htmlspecialchars(t('asset-management.help.proxmox.ips_heading')); ?></h4>
                    <p><?php echo t('asset-management.help.proxmox.ips'); ?></p>
                    <h4><?php echo htmlspecialchars(t('asset-management.help.proxmox.removal_heading')); ?></h4>
                    <p><?php echo t('asset-management.help.proxmox.removal'); ?></p>
                    <h4><?php echo htmlspecialchars(t('asset-management.help.proxmox.trouble_heading')); ?></h4>
                    <ul>
                        <li><?php echo t('asset-management.help.proxmox.trouble_401'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.trouble_reach'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.trouble_cert'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.trouble_node'); ?></li>
                        <li><?php echo t('asset-management.help.proxmox.trouble_guard'); ?></li>
                    </ul>
                    <p class="help-note"><?php echo t('asset-management.help.proxmox.more'); ?></p>
                </div>

                <!-- Section 12: vcloud (PR #167) -->
                <div class="help-section" id="vcloud">
                    <div class="help-section-header">
                        <span class="help-section-num">12</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.vcloud.heading')); ?></h3>
                    </div>
                    <p><?php echo t('asset-management.help.vcloud.intro'); ?></p>
                    <h4><?php echo htmlspecialchars(t('asset-management.help.vcloud.user_heading')); ?></h4>
                    <ul>
                        <li><?php echo t('asset-management.help.vcloud.user_1'); ?></li>
                        <li><?php echo t('asset-management.help.vcloud.user_2'); ?></li>
                    </ul>
                    <h4><?php echo htmlspecialchars(t('asset-management.help.vcloud.fill_heading')); ?></h4>
                    <ul>
                        <li><?php echo t('asset-management.help.vcloud.fill_host'); ?></li>
                        <li><?php echo t('asset-management.help.vcloud.fill_org'); ?></li>
                        <li><?php echo t('asset-management.help.vcloud.fill_user'); ?></li>
                        <li><?php echo t('asset-management.help.vcloud.fill_version'); ?></li>
                        <li><?php echo t('asset-management.help.vcloud.fill_verify'); ?></li>
                    </ul>
                    <p><?php echo t('asset-management.help.vcloud.test'); ?></p>
                    <h4><?php echo htmlspecialchars(t('asset-management.help.vcloud.removal_heading')); ?></h4>
                    <p><?php echo t('asset-management.help.vcloud.removal'); ?></p>
                    <h4><?php echo htmlspecialchars(t('asset-management.help.vcloud.trouble_heading')); ?></h4>
                    <ul>
                        <li><?php echo t('asset-management.help.vcloud.trouble_401'); ?></li>
                        <li><?php echo t('asset-management.help.vcloud.trouble_reach'); ?></li>
                        <li><?php echo t('asset-management.help.vcloud.trouble_partial'); ?></li>
                        <li><?php echo t('asset-management.help.vcloud.trouble_guard'); ?></li>
                    </ul>
                    <p class="help-note"><?php echo t('asset-management.help.vcloud.more'); ?></p>
                </div>

                <!-- Section 7: Dashboard -->
                <div class="help-section" id="dashboard">
                    <div class="help-section-header">
                        <span class="help-section-num">13</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.nav_dashboard')); ?></h3>
                    </div>
                    <p>The dashboard lets you visualise your asset estate with customisable Chart.js widgets. Each analyst has their own dashboard &mdash; choose the charts that matter to you.</p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong>Open the Library</strong> &mdash; click Edit Dashboard, then browse or create widgets. Each widget has a chart type (bar, pie, doughnut) and an aggregate property.
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong>Add to your dashboard</strong> &mdash; click the + button to add any widget. It appears on your dashboard immediately.
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong>Customise</strong> &mdash; drag widgets to reorder. Click the cog icon to change the title, chart type, or apply date range and department filters.
                            </div>
                        </div>
                    </div>
                    <p>Available chart properties include: <strong>Operating System</strong>, <strong>Manufacturer</strong>, <strong>Model</strong>, <strong>Asset Type</strong>, <strong>Asset Status</strong>, <strong>Feature Release</strong>, <strong>Domain</strong>, <strong>CPU</strong>, <strong>Memory</strong>, <strong>GPU</strong>, <strong>TPM Version</strong>, <strong>BitLocker Status</strong>, and <strong>BIOS Version</strong>.</p>
                </div>

                <!-- Section 9: Quick Tips -->
                <!-- Section 9: Who holds what + the handover document (discussion #56) -->
                <div class="help-section" id="who-holds-what">
                    <div class="help-section-header">
                        <span class="help-section-num">14</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.users_heading')); ?></h3>
                    </div>
                    <p><?php echo htmlspecialchars(t('asset-management.help.users_intro')); ?></p>

                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('asset-management.help.users_step1_strong')); ?></strong> <?php echo t('asset-management.help.users_step1_list'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('asset-management.help.users_step2_strong')); ?></strong> <?php echo t('asset-management.help.users_step2_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('asset-management.help.users_step3_strong')); ?></strong> <?php echo t('asset-management.help.users_step3_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">4</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('asset-management.help.users_step4_strong')); ?></strong> <?php echo t('asset-management.help.users_step4_text'); ?>
                            </div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">5</div>
                            <div>
                                <strong><?php echo htmlspecialchars(t('asset-management.help.users_step5_strong')); ?></strong> <?php echo t('asset-management.help.users_step5_text'); ?>
                            </div>
                        </div>
                    </div>

                    <p><?php echo t('asset-management.help.users_managers'); ?></p>
                    <p class="help-note"><?php echo htmlspecialchars(t('asset-management.help.users_tip')); ?></p>
                </div>

                <div class="help-section" id="linked-tickets">
                    <div class="help-section-header">
                        <span class="help-section-num">15</span>
                        <h3>Tickets raised against an asset</h3>
                    </div>
                    <p>Every asset has a <strong>Tickets</strong> tab listing what has been reported against it &mdash; open tickets first, then everything that came before. Click any row to open the ticket.</p>

                    <p>The value is in the history rather than the open list. A monitor that has been reported three times in a year is a monitor to replace, not repair, and that pattern is invisible if each report is only ever read on its own. It is also the quickest answer to &ldquo;has this happened before?&rdquo; when a user says the problem is back.</p>

                    <p>A long history is capped at the twenty most recent closed tickets, with the true total shown alongside, so an asset that has been in service for years still opens quickly.</p>

                    <p>Each row carries a small <strong>&#9432;</strong>. Click it for a card showing the ticket&rsquo;s status, priority, who it is with and who raised it &mdash; enough to tell whether it is the report you were thinking of, without leaving the asset. It is read-only; <strong>Open</strong> takes you to the ticket itself.</p>

                    <p class="help-note">Tickets appear here once somebody links the asset to them &mdash; from the <strong>Links</strong> bar on the ticket (<strong>Link to&hellip; &rarr; Equipment</strong>), or by the requester choosing their device when raising it in the self-service portal. Nothing is linked automatically, so this tab stays empty on assets nobody has attached to a ticket.</p>
                </div>

                <div class="help-section" id="linked-contracts">
                    <div class="help-section-header">
                        <span class="help-section-num">16</span>
                        <h3>Contracts covering an asset</h3>
                    </div>
                    <p>Every asset has a <strong>Contracts</strong> tab listing the agreements that cover it &mdash; the mobile service agreement behind a handset, the internet agreement behind a router, the maintenance contract behind a server. Each row shows the supplier, when the contract ends, and when notice has to be given. Click one to open the contract.</p>

                    <p>The <strong>notice date</strong> is picked out in the warning colour, and that is the point of the tab. An end date is easy to plan around; a notice period is what quietly turns a decision you meant to make into a renewal you did not. Having it on the equipment means the question can be asked at the moment somebody is holding the thing, rather than only when the contract happens to be open in front of them.</p>

                    <p>A link can carry a short <strong>reference</strong> &mdash; a phone number, a line ID, a seat number &mdash; which is shown beside the contract title. It describes the equipment's place on that particular agreement, so a handset moved to a different contract keeps the handset and loses the line.</p>

                    <p>Each row carries a small <strong>&#9432;</strong> as well. Click it for a card giving the contract&rsquo;s supplier, status and renewal date on the spot &mdash; which is usually all you need to answer &ldquo;is this the agreement I was thinking of?&rdquo; without opening it. The card is read-only, and <strong>Open</strong> takes you to the contract itself.</p>

                    <p>Links can be made <strong>from here</strong> as well as from the contract. <strong>Add to a contract</strong> searches by contract number, title or supplier name &mdash; &ldquo;the Vodafone one&rdquo; is how most people refer to a contract they have not opened in a year &mdash; and offers the contracts closest to ending first, since a contract you are about to lose is the one you are most likely to be attaching equipment to. Anything this asset is already on is left out.</p>

                    <p class="help-note">Nothing is linked automatically, so this tab stays empty on equipment nobody has put on a contract. Removing a link, from either end, takes the equipment off that contract and nothing else: the contract is not cancelled and the asset is not deleted.</p>

                    <p class="help-note">If you cannot see the Contracts module, this tab says so rather than showing an empty list &mdash; &ldquo;no contracts&rdquo; and &ldquo;you may not see contracts&rdquo; are different answers, and reading one as the other is how somebody concludes a contract was never set up.</p>
                </div>

<div class="help-section" id="right-click">
                    <div class="help-section-header">
                        <span class="help-section-num">17</span>
                        <h3>Right-click an asset</h3>
                    </div>
                    <p>Right-clicking any asset in the list opens a menu of the things you most often want to do to one piece of equipment, without opening it and without hunting through the detail panel.</p>

                    <div class="help-list">
                        <div><strong>Status, Type and Location</strong> &mdash; each opens a submenu of everything configured, with a tick against the current value, so you can see what it is as well as change it.</div>
                        <div><strong>Add to a contract</strong> &mdash; the same picker as the Contracts tab.</div>
                        <div><strong>Assign to somebody</strong> &mdash; opens the assignment window.</div>
                        <div><strong>Print label</strong> &mdash; the printable label sheet for this one asset.</div>
                        <div><strong>Copy asset tag</strong> and <strong>Copy serial number</strong> &mdash; straight to the clipboard, for pasting into a supplier's warranty checker or a purchase order. These only appear when the asset actually has one.</div>
                    </div>

                    <p>Right-clicking <strong>selects</strong> the asset first, so the detail panel on the right is always showing the same equipment the menu is about to change. Changes made from the menu are recorded in the asset's history exactly as they would be if you had edited the field by hand.</p>

                    <p class="help-note">A submenu that says <strong>None configured</strong> means that lookup is empty &mdash; add statuses, types or locations under <strong>Settings</strong> and they appear here straight away.</p>
                </div>

<?php /* 2.6.0. Wired to t() from the start, unlike sections 10-12 above:
         a section written straight into the page stays English in every
         language while the translation score still reads 100%. */ ?>
                <div class="help-section" id="companies">
                    <div class="help-section-header">
                        <span class="help-section-num">18</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.companies.heading')); ?></h3>
                    </div>
                    <p><?php echo t('asset-management.help.companies.intro'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('asset-management.help.companies.move'); ?></div>
                        <div><?php echo t('asset-management.help.companies.cleared'); ?></div>
                        <div><?php echo t('asset-management.help.companies.refused'); ?></div>
                        <div><?php echo t('asset-management.help.companies.add'); ?></div>
                        <div><?php echo t('asset-management.help.companies.shared'); ?></div>
                        <div><?php echo t('asset-management.help.companies.recon'); ?></div>
                    </div>
                    <p class="help-note"><?php echo t('asset-management.help.companies.note'); ?></p>
                </div>

                <!-- Section 19: Leases (3.5.0) -->
                <div class="help-section" id="leases">
                    <div class="help-section-header">
                        <span class="help-section-num">19</span>
                        <h3>Leases</h3>
                    </div>
                    <p><strong>Assets &rarr; Leases</strong> holds your lease agreements and shows what has to go back to the leasing company, and when.</p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div><strong>Add the lease</strong> &mdash; its name, the agreement number, the leasing company (from your suppliers), the cost centre it is charged to, the notice period the leasing company needs, and optionally a contract in Contracts that it sits under. Attach the signed agreement in Documents.</div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div><strong>Give it a schedule</strong> &mdash; the start and end dates and the cost. One agreement can cover kit leased at different times (ten laptops from January, ten monitors from March), so add a schedule for each group. A simple lease needs only the one it starts with.</div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div><strong>Put the assets on it</strong> &mdash; <strong>Add</strong> on a schedule finds assets by hostname, tag or model. Their <strong>Lease starts</strong> and <strong>Lease ends</strong> dates fill in from the schedule and follow it if the schedule's dates change.</div>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row"><div><strong>Keeping one asset longer</strong><p>Change the lease dates on that asset (on the asset itself, or with the pencil on the lease). They become its own dates and are marked <em>Own</em>; the rest of the schedule is untouched. Clear them to go back to the schedule's.</p></div></div>
                        <div class="help-card row"><div><strong>Outcomes</strong><p>Each asset on a lease has an outcome: <em>On lease</em> and <em>Extended</em> mean it is still out; <em>Returned</em> and <em>Bought out</em> mean it is finished with, and the day is recorded. The list is yours to change in <strong>Settings &rarr; Leasing</strong>.</p></div></div>
                        <div class="help-card row"><div><strong>Due back</strong><p>Every asset still out whose lease ends in the next 30 days to a year, with who holds it and where it is, soonest first; overdue ones are always shown. <strong>Export</strong> downloads it as a spreadsheet for the collection.</p></div></div>
                        <div class="help-card row"><div><strong>Notice reminders</strong><p>When lease alerts go to the Calendar (<strong>Settings &rarr; Expiry alerts</strong>), each schedule also gets a <em>Lease notice</em> entry on the last day to give notice - the end date minus the notice period.</p></div></div>
                    </div>
                    <p class="help-note">On a multi-company install a lease belongs to one company, and only that company's assets, contracts and cost centres can go on it. An asset can be out on one lease at a time, and one that is out on a lease cannot move to another company until it is returned.</p>
                </div>

                <!-- Section 20: the configuration item this asset is (GH #201) -->
                <div class="help-section" id="cmdb-link">
                    <div class="help-section-header">
                        <span class="help-section-num">20</span>
                        <h3>Configuration item</h3>
                    </div>
                    <p>A server is often two records: the <strong>asset</strong> says what the box is, and a <strong>configuration item</strong> in the CMDB says what it does - what runs on it and what breaks if it fails. The <strong>Configuration item</strong> card on Key info links the two: <em>these are the same piece of equipment</em>.</p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div><strong>Link</strong> searches the CMDB for configuration items in the asset's company. Pick one and the card shows its name and class, with a link into the CMDB. The configuration item shows this asset the same way, with its details read live from here.</div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div><strong>If this failed</strong> - the card repeats what the configuration item's own impact analysis says: how many things would be affected, with <strong>Show</strong> to see them in the CMDB and <strong>Open as diagram</strong> for Network Mapper users.</div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>One asset has one configuration item, and one configuration item has one asset. <strong>Replace</strong> swaps it - if someone changed the link while you were looking, you are asked to look again. <strong>Unlink</strong> ends it; neither record is deleted. <strong>Link history</strong> keeps every link, with who and when.</div>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row"><div><strong>Merging two assets</strong><p>The link moves to the asset you keep. If both were linked, to different configuration items, the merge window shows both and asks which link to keep; <strong>Merge</strong> stays greyed out until you tick that you understand the other one ends. Neither configuration item is changed.</p></div></div>
                        <div class="help-card row"><div><strong>Create linked CI</strong><p>Map an asset type to a CMDB class in <strong>Settings &rarr; Asset types</strong> (<em>CMDB class</em>) and its unlinked assets offer <strong>Create linked CI</strong>: it asks for a name and anything the class requires, and makes the configuration item and the link together. Items of that class that already have the asset's name are shown first, with <em>Link this one instead</em> - a name is never treated as proof.</p></div></div>
                        <div class="help-card row"><div><strong>Not on every type</strong><p>A monitor or a mouse will never be a configuration item. Untick <strong>Configuration item</strong> in the type's layout (<strong>Settings &rarr; Asset types &rarr; Layout</strong>) to hide the card on that type.</p></div></div>
                        <div class="help-card row"><div><strong>Who can link</strong><p>You need to be able to change both Assets and the CMDB. Someone without the CMDB sees only that the asset is linked to a <em>restricted</em> configuration item - no name. Existing <em>Link to something else</em> custom fields that point at configuration items are separate and stay as they are.</p></div></div>
                    </div>
                </div>

                <!-- Section 21: Hyper-V and Failover Clusters (GH #199) -->
                <div class="help-section" id="hyperv">
                    <div class="help-section-header">
                        <span class="help-section-num">21</span>
                        <h3>Hyper-V</h3>
                    </div>
                    <p>Your Hyper-V hosts, Failover Clusters and virtual machines can appear in <strong>Servers</strong>. A read-only PowerShell collector, <code>scripts/Invoke-HyperVInventory.ps1</code>, runs as a scheduled task on one of your Windows servers and <em>sends</em> what it reads - FreeITSM never connects to your hosts.</p>
                    <div class="help-steps">
                        <div class="help-step">
                            <div class="help-step-num">1</div>
                            <div><strong>Settings &rarr; Hyper-V &rarr; Add</strong> a connection: its name, its company, and how often the collector will report. Saving shows the collector's <strong>key once</strong> - copy it - and the command to schedule.</div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">2</div>
                            <div>On the Windows server, run the command with <code>-TestAccess</code> first: it shows which reads the account is allowed and sends nothing. <code>-OutputFile</code> writes a report without sending it.</div>
                        </div>
                        <div class="help-step">
                            <div class="help-step-num">3</div>
                            <div>Schedule it. <strong>Servers</strong> then shows each connection as <em>On time</em>, <em>Late</em> or <em>Overdue</em>, the VMs with their host and cluster, and - under <strong>Hosts and clusters</strong> (the <em>Hosts</em> card) - every node, its state and how it is identified.</div>
                        </div>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row"><div><strong>Nothing disappears on a bad read</strong><p>A host the collector could not read keeps its VMs, marked <em>Not confirmed since</em>. A VM missing from a complete report is <em>Not seen since</em>, never deleted - unless you set <em>Remove VMs not seen for</em> on the connection.</p></div></div>
                        <div class="help-card row"><div><strong>Identity changes</strong><p>A renamed, migrated or failed-over VM stays the same record. One imported as a copy comes back with a new identity: FreeITSM asks you on the connection whether it is the <em>same machine</em> - it never decides by name.</p></div></div>
                        <div class="help-card row"><div><strong>Background jobs</strong><p>Each connection appears on System &rarr; Background jobs as <em>Hyper-V reports</em>, overdue when the collector stops.</p></div></div>
                        <div class="help-card row"><div><strong>Into the CMDB</strong><p><strong>CMDB</strong> on a connection maps the estate onto your own CMDB classes and relationship types, shows what it would do, and - once switched on - keeps CIs and the relationships it made in step after every report. A same-name CI is never bound without you; owners, properties and relationships people drew are never touched.</p></div></div>
                    </div>
                </div>

<div class="help-section" id="retired">
                    <div class="help-section-header">
                        <span class="help-section-num">22</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.retired.heading')); ?></h3>
                    </div>
                    <p><?php echo t('asset-management.help.retired.intro'); ?></p>
                    <div class="help-list">
                        <div><?php echo t('asset-management.help.retired.p1'); ?></div>
                        <div><?php echo t('asset-management.help.retired.p2'); ?></div>
                        <div><?php echo t('asset-management.help.retired.p3'); ?></div>
                        <div><?php echo t('asset-management.help.retired.p4'); ?></div>
                    </div>
                </div>

<div class="help-section" id="tips">
                    <div class="help-section-header">
                        <span class="help-section-num">23</span>
                        <h3><?php echo htmlspecialchars(t('asset-management.help.nav_tips')); ?></h3>
                    </div>
                    <div class="help-cards">
                        <div class="help-card row">
                            <div class="help-card-icon">&#128269;</div>
                            <div><strong>Search</strong><br>The asset list search checks hostnames. On the Servers tab, it also searches IP, host, cluster, and guest OS.</div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128203;</div>
                            <div><strong>History</strong><br>Click "View History" on any asset to see every change &mdash; who updated a field, what the old and new values were, and when.</div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128274;</div>
                            <div><strong>API keys</strong><br>API keys authenticate the PowerShell script. Generate them in <strong>Software &rarr; Settings &rarr; API Keys</strong>, not System &rarr; API. You can deactivate a key at any time without deleting it.</div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128187;</div>
                            <div><strong>Device Manager</strong><br>The Devices tab shows exactly what Windows Device Manager shows. Use the filter box to quickly find a specific driver or device class.</div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#128230;</div>
                            <div><strong>Software tabs</strong><br>Applications are what users see in Add/Remove Programs. Components are hidden system entries. Toggle between them to reduce noise.</div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#9889;</div>
                            <div><strong>Idempotent syncs</strong><br>Run the script as many times as you like. It creates assets on first contact and updates them on every subsequent run. Removed software is automatically cleaned up.</div>
                        </div>
                        <div class="help-card row">
                            <div class="help-card-icon">&#9881;</div>
                            <div><strong>Reconciliation</strong><br>When a computer is renamed, its hardware serial number matches the existing asset. FreeITSM updates the hostname in place, keeping all history, tickets, and user assignments intact.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight active section in sidebar as user scrolls
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.help-nav-link');
        const sections = [];

        navLinks.forEach(link => {
            const id = link.dataset.section;
            const el = document.getElementById(id);
            if (el) sections.push({ id, el });
        });

        helpMain.addEventListener('scroll', function() {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0]?.id;

            for (const s of sections) {
                if (s.el.offsetTop - 200 <= scrollTop) {
                    current = s.id;
                }
            }

            navLinks.forEach(link => {
                link.classList.toggle('active', link.dataset.section === current);
            });
        });

        // Scroll within the help container, not the page
        navLinks.forEach(link => {
            link.addEventListener('click', function(e) {
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
