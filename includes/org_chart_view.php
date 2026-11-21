<?php
/**
 * The organisation chart's markup - the sidebar, the tree and chart, their tools and the
 * Mouse and keyboard dialog - for both pages that show it (GH #414):
 *   people/org.php              analysts, with leavers and gaps
 *   self-service/org-chart.php  the portal, without either
 * assets/js/people-org.js drives it; each page hands that its endpoints as window.PEOPLE_ORG.
 * Needs includes/people.php (the level constants) and the 'people' translations.
 *
 * @wiki Organisation-Chart-Developer-Guide
 */

if (!function_exists('orgE')) {
    /** Escaped for HTML, quotes included. */
    function orgE($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Draws the chart.
 *
 * @param array $opt  leavers (bool) - the Show leavers box; gaps (bool) - the Gaps lists;
 *                    intro (string) - the line under the sidebar; search_ph (string) - the search box's hint
 */
function orgChartViewRender(array $opt): void
{
    $opt += ['leavers' => true, 'gaps' => true, 'intro' => t('people.org.intro'), 'search_ph' => t('people.org.search_ph')];
    /** Refresh, in both views' tools: an icon with its word beside it. */
    $refresh = '<button type="button" class="ppl-btn ppl-org-refresh" data-refresh="1" title="' . orgE(t('people.org.refresh_title')) . '">'
        . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-2.64-6.36"></path><path d="M21 3v6h-6"></path></svg>'
        . orgE(t('people.org.refresh')) . '</button>';
    /** A two- or three-button switch in the card header. */
    $seg = function (string $label, string $attr, array $options) {
        echo '<div class="ppl-org-seg" role="group" aria-label="' . orgE($label) . '">';
        foreach ($options as $value => $text) {
            echo '<button type="button" ' . $attr . '="' . orgE($value) . '" aria-pressed="false">' . orgE($text) . '</button>';
        }
        echo '</div>';
    };
    ?>
    <div class="ppl-layout">
        <aside class="ppl-sidebar ppl-org-side">
            <h3><?php echo orgE(t('people.org.search')); ?></h3>
            <input type="search" id="orgSearch" placeholder="<?php echo orgE($opt['search_ph']); ?>" autocomplete="off" aria-label="<?php echo orgE(t('people.org.search')); ?>" aria-controls="orgResults">
            <div class="ppl-org-results" id="orgResults" role="listbox" hidden></div>
            <div class="ppl-org-focus" id="orgFocus" hidden></div>

            <h3><?php echo orgE(t('people.org.view')); ?></h3>
            <div class="ppl-org-views" role="group" aria-label="<?php echo orgE(t('people.org.view')); ?>">
                <button type="button" class="ppl-org-view" data-view="tree" aria-pressed="true"><?php echo orgE(t('people.org.view_tree')); ?></button>
                <button type="button" class="ppl-org-view" data-view="chart" aria-pressed="false"><?php echo orgE(t('people.org.view_chart')); ?></button>
            </div>
            <?php if ($opt['leavers']): ?>
            <label class="ppl-org-check"><input type="checkbox" id="orgLeavers"> <?php echo orgE(t('people.org.leavers')); ?></label>
            <?php endif; ?>

            <?php if ($opt['gaps']): ?>
            <h3><?php echo orgE(t('people.org.gaps')); ?></h3>
            <div id="orgGaps"><p class="ppl-dim ppl-org-small"><?php echo orgE(t('people.org.loading')); ?></p></div>
            <?php endif; ?>

            <p class="ppl-dim ppl-intro"><?php echo orgE($opt['intro']); ?></p>

            <?php /* How to move around - Shift and the wheel for sideways is not
                     something anybody guesses. In a dialog rather than on screen,
                     because it is read once. */ ?>
            <div class="ppl-org-side-foot" id="orgKeysFoot" hidden>
            <button type="button" class="ppl-org-keys-btn" id="orgKeysBtn" aria-haspopup="dialog" aria-controls="orgKeys">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="6" width="20" height="12" rx="2"></rect><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"></path></svg>
                <?php echo orgE(t('people.org.keys')); ?>
            </button>
            </div>
        </aside>
        <main class="ppl-main-list">
            <section class="ppl-card ppl-org-card">
                <div class="ppl-card-h" id="orgHead">
                    <h3 id="orgTitle"><?php echo orgE(t('people.org.view_tree')); ?></h3>
                    <div class="ppl-org-tools" id="orgTreeTools">
                        <?php echo $refresh; ?>
                        <button type="button" class="ppl-btn" id="orgExpandAll"><?php echo orgE(t('people.org.expand_all')); ?></button>
                        <button type="button" class="ppl-btn" id="orgCollapseAll"><?php echo orgE(t('people.org.collapse_all')); ?></button>
                        <?php $seg(t('people.org.layout'), 'data-treemode', ['cards' => t('people.org.mode_cards'), 'compact' => t('people.org.mode_compact'), 'columns' => t('people.org.mode_columns')]); ?>
                    </div>
                    <div class="ppl-org-tools" id="orgChartTools" hidden>
                        <?php echo $refresh; ?>
                        <?php $seg(t('people.org.layout'), 'data-chartmode', ['cards' => t('people.org.mode_cards'), 'compact' => t('people.org.mode_compact')]); ?>
                        <?php $seg(t('people.org.direction'), 'data-dir', ['v' => t('people.org.dir_v'), 'h' => t('people.org.dir_h')]); ?>
                        <div class="ppl-org-dd ppl-org-levels">
                            <button type="button" class="ppl-btn" id="orgLevels" aria-haspopup="menu" aria-expanded="false" aria-controls="orgLevelsMenu" title="<?php echo orgE(t('people.org.levels_title')); ?>"><span id="orgLevelsText"><?php echo orgE(t('people.org.levels')); ?></span> &#9662;</button>
                            <div class="ppl-org-menu" id="orgLevelsMenu" role="menu" aria-label="<?php echo orgE(t('people.org.levels')); ?>" hidden>
                                <?php foreach (PEOPLE_ORG_CHART_LEVELS_PRESETS as $n): ?>
                                <button type="button" role="menuitemradio" aria-checked="false" data-levels="<?php echo (int)$n; ?>"><?php echo orgE((int)$n === 1 ? t('people.org.levels_1') : t('people.org.levels_n', ['n' => (int)$n])); ?></button>
                                <?php endforeach; ?>
                                <button type="button" role="menuitemradio" aria-checked="false" data-levels="all"><?php echo orgE(t('people.org.levels_all')); ?></button>
                                <div class="ppl-org-menu-sep" role="separator"></div>
                                <form class="ppl-org-menu-form" id="orgLevelsForm" novalidate>
                                    <label for="orgLevelsInput"><?php echo orgE(t('people.org.levels_custom')); ?></label>
                                    <div class="ppl-org-menu-row">
                                        <input type="number" id="orgLevelsInput" min="0" max="<?php echo PEOPLE_ORG_CHART_LEVELS_MAX; ?>" step="1" inputmode="numeric" aria-describedby="orgLevelsHint">
                                        <button type="submit" class="ppl-btn"><?php echo orgE(t('people.org.levels_apply')); ?></button>
                                    </div>
                                    <p class="ppl-org-menu-hint" id="orgLevelsHint"><?php echo orgE(t('people.org.levels_range', ['max' => PEOPLE_ORG_CHART_LEVELS_MAX])); ?></p>
                                </form>
                            </div>
                        </div>
                        <label class="ppl-org-check ppl-org-dim" title="<?php echo orgE(t('people.org.dim_title')); ?>"><input type="checkbox" id="orgDim"> <?php echo orgE(t('people.org.dim')); ?></label>
                        <button type="button" class="ppl-btn" data-zoom="-1" title="<?php echo orgE(t('people.org.zoom_out')); ?>" aria-label="<?php echo orgE(t('people.org.zoom_out')); ?>">&minus;</button>
                        <button type="button" class="ppl-btn ppl-org-zoom" data-zoom="0" id="orgZoomLevel" title="<?php echo orgE(t('people.org.zoom_reset')); ?>">100%</button>
                        <button type="button" class="ppl-btn" data-zoom="1" title="<?php echo orgE(t('people.org.zoom_in')); ?>" aria-label="<?php echo orgE(t('people.org.zoom_in')); ?>">+</button>
                        <div class="ppl-org-dd ppl-org-export">
                            <button type="button" class="ppl-btn" id="orgExport" aria-haspopup="menu" aria-expanded="false" aria-controls="orgExportMenu"><?php echo orgE(t('people.org.export')); ?> &#9662;</button>
                            <div class="ppl-org-menu" id="orgExportMenu" role="menu" hidden>
                                <?php foreach (['png', 'svg', 'pdf', '-', 'csv', 'json', 'xml'] as $fmt): ?>
                                <?php if ($fmt === '-'): ?><div class="ppl-org-menu-sep" role="separator"></div>
                                <?php else: ?><button type="button" role="menuitem" data-export="<?php echo $fmt; ?>"><?php echo orgE(t('people.org.export_' . $fmt)); ?></button><?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ppl-org-status" id="orgStatus" role="status" aria-live="polite"></div>
                <div class="ppl-org-tree" id="orgTree"></div>
                <div class="ppl-org-chart" id="orgChart" hidden></div>
            </section>
        </main>
    </div>

    <?php
    // One row of the dialog: the keys (each a <kbd>; MOD is Ctrl, and Cmd for a
    // Mac - both, so it reads right whichever keyboard is in front of you), and
    // what they do.
    $keyRow = function (array $keys, string $what): string {
        $k = implode(' + ', array_map(fn($key) => $key === 'MOD'
            ? '<kbd>Ctrl</kbd> / <kbd>&#8984; Cmd</kbd>'
            : '<kbd>' . orgE(t('people.org.kbd_' . $key)) . '</kbd>', $keys));
        return '<tr><td class="ppl-org-keys-k">' . $k . '</td><td>' . orgE(t('people.org.' . $what)) . '</td></tr>';
    };
    ?>
    <dialog class="ppl-org-keys" id="orgKeys" aria-labelledby="orgKeysTitle">
        <div class="ppl-org-keys-head">
            <h3 id="orgKeysTitle"><?php echo orgE(t('people.org.keys_title')); ?></h3>
            <button type="button" class="ppl-org-keys-close" data-close aria-label="<?php echo orgE(t('people.org.keys_close')); ?>">&times;</button>
        </div>
        <table>
            <?php echo $keyRow(['wheel'], 'key_scroll'); ?>
            <?php echo $keyRow(['shift', 'wheel'], 'key_scroll_side'); ?>
            <?php echo $keyRow(['MOD', 'wheel'], 'key_zoom'); ?>
            <?php echo $keyRow(['pinch'], 'key_pinch'); ?>
            <?php echo $keyRow(['drag'], 'key_drag'); ?>
            <?php echo $keyRow(['click'], 'key_click'); ?>
            <?php echo $keyRow(['dblclick'], 'key_dblclick'); ?>
            <?php echo $keyRow(['tab'], 'key_tab'); ?>
            <?php echo $keyRow(['enter'], 'key_enter'); ?>
            <?php echo $keyRow(['esc'], 'key_esc'); ?>
            <?php echo $keyRow(['f5'], 'key_f5'); ?>
        </table>
    </dialog>
    <?php
}
