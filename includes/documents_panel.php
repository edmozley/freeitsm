<?php
/**
 * Render the documents panel on any record.
 *
 * A module adds attachments with one call:
 *
 *     require_once __DIR__ . '/../includes/documents_panel.php';
 *     renderDocumentsPanel('contract', $contractId, '../');
 *
 * That is the whole integration. The module does not learn how documents are
 * stored, served or authorised, and includes/documents.php does not learn
 * anything about the module — the only coupling is the entity type string, which
 * must exist in documentEntityRegistry().
 *
 * ⚠️ The panel loads its own list over the API rather than being handed rows, so
 * the permission check happens server-side on every view. A page that fetched
 * the documents itself and passed them in would be one forgotten filter away
 * from showing somebody a document they cannot open.
 */

require_once __DIR__ . '/documents.php';

/**
 * Load the panel's stylesheet and script once per page.
 *
 * Separate from rendering so a page that mounts the panel itself — one whose
 * record changes in JavaScript, like the asset detail view — can still get the
 * assets without a server-rendered container.
 *
 * @param string $pathPrefix  '' from the web root, '../' from a module folder - put before the asset URLs
 */
function documentsPanelAssets(string $pathPrefix = '../'): void
{
    static $emitted = false;
    if ($emitted) return;
    $emitted = true;
    echo '<link rel="stylesheet" href="' . htmlspecialchars($pathPrefix) . 'assets/css/documents.css?v=6">' . "\n";
    echo '<script src="' . htmlspecialchars($pathPrefix) . 'assets/js/documents.js?v=9"></script>' . "\n";
}

/**
 * Renders the attached-documents panel for one record, loading its CSS and JS once.
 *
 * Renders nothing for an unknown record type, or for an unsaved record (id 0)
 * unless $jsVar is given so the page can point it at a record later.
 *
 * @param string $parentType  a key from documentEntityRegistry()
 * @param int    $parentId    the record's id. 0 mounts an EMPTY panel, for a page
 *                            that will call panel.setParent() once the user picks
 *                            a record — pass a $jsVar to capture the instance.
 * @param string $pathPrefix  '' from the web root, '../' from a module folder
 * @param bool   $canEdit     false renders a read-only list
 * @param string $jsVar       optional global to hold the mounted panel, so a
 *                            JS-driven page can re-point it
 */
function renderDocumentsPanel(string $parentType, int $parentId, string $pathPrefix = '../', bool $canEdit = true, string $jsVar = ''): void
{
    if (!documentEntityDef($parentType)) {
        return;
    }
    // An unsaved record has no id to attach to, and no way to be given one, so
    // render nothing at all — the caller shows the panel after saving.
    if ($parentId <= 0 && $jsVar === '') {
        return;
    }

    documentsPanelAssets($pathPrefix);

    $id = 'fdPanel_' . preg_replace('/[^a-z0-9]/i', '', $parentType) . '_' . ($parentId ?: 'dynamic');
    ?>
    <div id="<?php echo $id; ?>"></div>
    <script>
        (function () {
            var mount = function () {
                var panel = FreeITSMDocuments.mount(document.getElementById('<?php echo $id; ?>'), {
                    parentType: <?php echo json_encode($parentType); ?>,
                    parentId:   <?php echo (int) $parentId; ?>,
                    apiBase:    <?php echo json_encode($pathPrefix . 'api/documents/'); ?>,
                    canEdit:    <?php echo $canEdit ? 'true' : 'false'; ?>
                });
                <?php if ($jsVar !== ''): ?>
                /* Handed to the page so it can follow the record the user is
                   looking at: <?php echo $jsVar; ?>.setParent('<?php echo $parentType; ?>', id) */
                window[<?php echo json_encode($jsVar); ?>] = panel;
                <?php endif; ?>
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', mount);
            } else {
                mount();
            }
        })();
    </script>
    <?php
}
