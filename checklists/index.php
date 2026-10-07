<?php
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';

I18n::initFromSession();
Tz::init();
requireModuleAccess('checklists');
$current_page = 'templates';
$path_prefix = '../';

$conn = connectToDatabase();

// Schema lives in database/freeitsm.sql + includes/db_verify_schema.php, like
// every other module's. It used to be created from here as well - see the wiki
// page Checklists-Module-House-Style, "the same table, created five ways".

// Fetch templates and items
$templates = $conn->query("SELECT * FROM checklist_templates ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$allItems = $conn->query("SELECT * FROM checklist_template_items ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

$itemsByTemplate = [];
foreach ($allItems as $it) {
    $itemsByTemplate[$it['template_id']][] = $it;
}

// Fetch categories
$categories = [];
try {
    $categories = $conn->query("SELECT * FROM checklist_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$catCounts = [];
foreach ($templates as $t) {
    $c = $t['category'] ?: 'General';
    $catCounts[$c] = ($catCounts[$c] ?? 0) + 1;
}

// Defined roles. The default eight are seeded by Database Verification on an
// empty table, the same way ticket resolution codes are - not created and
// inserted from inside a page render.
$definedRoles = $conn->query("SELECT id, name FROM checklist_roles ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Compute template counts per role
$roleCounts = [];
foreach ($templates as $t) {
    $tItems = $itemsByTemplate[$t['id']] ?? [];
    $rolesInThisTpl = [];
    foreach ($tItems as $it) {
        if (!empty($it['suggested_role'])) {
            $rolesInThisTpl[trim($it['suggested_role'])] = true;
        }
    }
    foreach (array_keys($rolesInThisTpl) as $rn) {
        $roleCounts[$rn] = ($roleCounts[$rn] ?? 0) + 1;
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('checklists.list.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=76">
    <style>
        body { margin: 0; padding: 0; background: var(--app-bg, #f8fafc); }
        .chk-layout { display: flex; height: calc(100vh - 48px); width: 100%; overflow: hidden; }
        .chk-sidebar { width: 260px; background: var(--surface, #fff); border-right: 1px solid var(--border-soft, #e2e8f0); padding: 20px; overflow-y: auto; flex-shrink: 0; display: flex; flex-direction: column; gap: 20px; }
        .chk-main { flex: 1; overflow-y: auto; padding: 28px 36px; }
        .chk-btn-primary { background: #0d9488; color: #fff; border: none; border-radius: 6px; padding: 10px 16px; font-weight: 600; font-size: 14px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; }
        .chk-btn-primary:hover { background: #0f766e; }
        .chk-search { width: 100%; box-sizing: border-box; padding: 9px 12px; border: 1px solid var(--border-soft, #cbd5e1); border-radius: 6px; font-size: 13px; background: var(--app-bg, #f8fafc); color: var(--text, #1e293b); }
        .chk-filter-link { display: flex; justify-content: space-between; align-items: center; padding: 7px 10px; border-radius: 6px; color: var(--text, #334155); text-decoration: none; font-size: 13px; font-weight: 500; cursor: pointer; }
        .chk-filter-link:hover, .chk-filter-link.active { background: rgba(13, 148, 136, 0.1); color: #0d9488; font-weight: 600; }
        .chk-filter-count { font-size: 11px; background: var(--border-soft, #e2e8f0); color: var(--text-muted, #64748b); padding: 1px 6px; border-radius: 10px; }
        .chk-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; }
        .chk-card { background: var(--surface, #fff); border: 1px solid var(--border-soft, #e2e8f0); border-radius: 8px; padding: 20px; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 4px rgba(0,0,0,0.04); transition: transform 0.15s, box-shadow 0.15s; }
        .chk-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .chk-pill { font-size: 11px; padding: 2px 8px; border-radius: 12px; font-weight: 600; text-transform: uppercase; white-space: nowrap; }
        .chk-pill-ticket { background: #e0f2fe; color: #0284c7; }
        .chk-pill-task { background: #dcfce7; color: #16a34a; }
        .chk-pill-both { background: #f3e8ff; color: #9333ea; }
    </style>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=184">
</head>
<body data-mobile-page="checklists" data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>">
    <?php include 'includes/header.php'; ?>

    <div class="chk-layout">
        <!-- Sidebar -->
        <aside class="chk-sidebar">
            <button class="chk-btn-primary" onclick="location.href='edit/'">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <?php echo htmlspecialchars(t('checklists.list.new_template')); ?>
            </button>

            <div>
                <input type="text" class="chk-search" id="chkSearchInput" placeholder="<?php echo htmlspecialchars(t('checklists.list.search_ph')); ?>" oninput="filterTemplates()">
            </div>

            <!-- Scope Filter -->
            <div style="display: flex; flex-direction: column; gap: 4px;">
                <div style="font-size: 11px; font-weight: 700; color: var(--text-muted, #94a3b8); text-transform: uppercase; margin-bottom: 4px;"><?php echo htmlspecialchars(t('checklists.list.scope')); ?></div>
                <a class="chk-filter-link active scope-link" onclick="setScopeFilter('all', this)"><?php echo htmlspecialchars(t('checklists.list.scope_all')); ?> <span class="chk-filter-count"><?php echo count($templates); ?></span></a>
                <a class="chk-filter-link scope-link" onclick="setScopeFilter('ticket', this)"><?php echo htmlspecialchars(t('checklists.list.scope_ticket')); ?></a>
                <a class="chk-filter-link scope-link" onclick="setScopeFilter('task', this)"><?php echo htmlspecialchars(t('checklists.list.scope_task')); ?></a>
            </div>

            <!-- Category Quick-Filter -->
            <div style="display: flex; flex-direction: column; gap: 4px; border-top: 1px solid var(--border-soft, #e2e8f0); padding-top: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                    <span style="font-size: 11px; font-weight: 700; color: var(--text-muted, #94a3b8); text-transform: uppercase;"><?php echo htmlspecialchars(t('checklists.list.categories')); ?></span>
                    <a href="settings/?tab=categories" style="font-size: 11px; color: #0d9488; text-decoration: none; font-weight: 600;"><?php echo htmlspecialchars(t('checklists.list.manage')); ?></a>
                </div>
                <a class="chk-filter-link active cat-link" onclick="setCategoryFilter('all', this)"><?php echo htmlspecialchars(t('checklists.list.categories_all')); ?></a>
                <?php foreach ($categories as $cat): ?>
                    <?php $cName = $cat['name']; $cCount = $catCounts[$cName] ?? 0; ?>
                    <a class="chk-filter-link cat-link" onclick="setCategoryFilter('<?php echo htmlspecialchars(strtolower($cName)); ?>', this)">
                        <span><?php echo htmlspecialchars($cName); ?></span>
                        <span class="chk-filter-count"><?php echo $cCount; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Suggested Roles Quick-Filter -->
            <div style="display: flex; flex-direction: column; gap: 4px; border-top: 1px solid var(--border-soft, #e2e8f0); padding-top: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                    <span style="font-size: 11px; font-weight: 700; color: var(--text-muted, #94a3b8); text-transform: uppercase;"><?php echo htmlspecialchars(t('checklists.list.roles')); ?></span>
                    <a href="settings/?tab=roles" style="font-size: 11px; color: #0d9488; text-decoration: none; font-weight: 600;"><?php echo htmlspecialchars(t('checklists.list.manage')); ?></a>
                </div>
                <a class="chk-filter-link active role-link" onclick="setRoleFilter('all', this)"><?php echo htmlspecialchars(t('checklists.list.roles_all')); ?></a>
                <?php foreach ($definedRoles as $r): ?>
                    <?php $rName = $r['name']; $rCount = $roleCounts[$rName] ?? 0; ?>
                    <a class="chk-filter-link role-link" onclick="setRoleFilter('<?php echo htmlspecialchars(strtolower($rName)); ?>', this)">
                        <span><?php echo htmlspecialchars($rName); ?></span>
                        <span class="chk-filter-count"><?php echo $rCount; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="chk-main">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <div>
                    <h1 style="font-size: 22px; font-weight: 700; margin: 0 0 4px 0; color: var(--text, #0f172a);"><?php echo htmlspecialchars(t('checklists.list.heading')); ?></h1>
                    <p style="font-size: 13px; color: var(--text-muted, #64748b); margin: 0;"><?php echo htmlspecialchars(t('checklists.list.heading_sub')); ?></p>
                </div>
            </div>

            <div class="chk-grid" id="chkCardsGrid">
                <?php foreach ($templates as $t): ?>
                    <?php
                        $items = $itemsByTemplate[$t['id']] ?? [];
                        $badgeClass = 'chk-pill-' . htmlspecialchars($t['scope']);
                        $badgeLabel = $t['scope'] === 'ticket' ? t('checklists.list.badge_ticket')
                                    : ($t['scope'] === 'task' ? t('checklists.list.badge_task')
                                                              : t('checklists.list.badge_both'));

                        $tplRoles = [];
                        foreach ($items as $it) {
                            if (!empty($it['suggested_role'])) {
                                $tplRoles[] = strtolower(trim($it['suggested_role']));
                            }
                        }
                        $tplRolesStr = implode('|', array_unique($tplRoles));
                    ?>
                                        <div class="chk-card"
                         data-scope="<?php echo htmlspecialchars($t['scope']); ?>"
                         data-category="<?php echo htmlspecialchars(strtolower($t['category'] ?: 'general')); ?>"
                         data-roles="<?php echo htmlspecialchars($tplRolesStr); ?>"
                         data-title="<?php echo htmlspecialchars(strtolower($t['title'])); ?>"
                         data-keywords="<?php echo htmlspecialchars(strtolower($t['keywords'] ?? '')); ?>"
                         data-desc="<?php echo htmlspecialchars(strtolower($t['description'] ?? '')); ?>">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                                <h3 style="font-size: 15px; font-weight: 600; margin: 0; color: var(--text, #0f172a);"><?php echo htmlspecialchars($t['title']); ?></h3>
                                <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
                                    <?php if (($t['closure_mode'] ?? 'warn') === 'block'): ?>
                                        <span title="<?php echo htmlspecialchars(t('checklists.editor.closure_block') ?: 'Block closure until complete'); ?>" style="display: inline-flex; color: #dc2626;" aria-label="Block closure until complete">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        </span>
                                    <?php endif; ?>
                                    <span class="chk-pill <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span>
                                </div>
                            </div>

                            <div style="margin-bottom: 10px;">
                                <span style="font-size: 12px; color: #0d9488; font-weight: 600; background: rgba(13,148,136,0.1); padding: 2px 6px; border-radius: 4px;">
                                    <?php echo htmlspecialchars($t['category'] ?: 'General'); ?>
                                </span>
                            </div>

                            <p style="font-size: 12px; color: var(--text-muted, #64748b); margin: 0 0 14px 0; line-height: 1.5;"><?php echo htmlspecialchars($t['description'] ?: 'No description provided.'); ?></p>

                            <!-- Task-Level Steps with Assigned Role / Owner -->
                            <div style="border-top: 1px solid var(--border-soft, #e2e8f0); padding-top: 10px; font-size: 12px; display: flex; flex-direction: column; gap: 6px;">
                                <?php if (empty($items)): ?>
                                    <span style="color: var(--text-muted, #94a3b8); font-style: italic;"><?php echo htmlspecialchars(t('checklists.list.no_steps')); ?></span>
                                <?php else: ?>
                                    <?php foreach (array_slice($items, 0, 4) as $it): ?>
                                        <div class="chk-step-row" style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                                            <div class="chk-step-main" style="display: flex; align-items: center; gap: 6px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <span style="color: <?php echo $it['is_mandatory'] ? '#0d9488' : '#94a3b8'; ?>;">✓</span>
                                                <span style="color: var(--text, #334155); overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($it['title']); ?></span>
                                                <?php if ($it['is_mandatory']): ?>
                                                    <span style="font-size: 10px; color: #ef4444; font-weight: bold;">*</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($it['suggested_role'])): ?>
                                                <span class="chk-step-role" style="font-size: 10px; background: rgba(13,148,136,0.1); color: #0d9488; font-weight: 600; padding: 1px 6px; border-radius: 4px; white-space: nowrap;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 3px; display: inline-block; vertical-align: -1px;"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg><?php echo htmlspecialchars($it['suggested_role']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($items) > 4): ?>
                                        <div style="font-size: 11px; color: var(--text-muted, #94a3b8); margin-top: 2px;"><?php echo htmlspecialchars(t('checklists.list.more_steps', ['count' => count($items) - 4])); ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Action Buttons -->
                        <div style="border-top: 1px solid var(--border-soft, #e2e8f0); margin-top: 16px; padding-top: 12px; display: flex; justify-content: flex-end; gap: 8px;">
                            <button onclick="location.href='edit/?id=<?php echo (int)$t['id']; ?>'" style="padding: 5px 12px; font-size: 12px; border: 1px solid var(--border-soft, #cbd5e1); border-radius: 4px; background: var(--surface, #fff); color: var(--text, #334155); cursor: pointer; font-weight: 500;">
                                <?php echo htmlspecialchars(t('checklists.list.edit')); ?>
                            </button>
                            <button onclick="deleteTemplate(<?php echo (int)$t['id']; ?>, <?php echo htmlspecialchars(json_encode($t['title']), ENT_QUOTES); ?>)" style="padding: 5px 12px; font-size: 12px; border: 1px solid #fecaca; border-radius: 4px; background: #fff; color: #ef4444; cursor: pointer; font-weight: 500;">
                                <?php echo htmlspecialchars(t('checklists.list.delete')); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </main>
    </div>


    <script src="search_scoring.js?v=1"></script>
<script>
        const DEFINED_ROLES = <?php echo json_encode(array_column($definedRoles, 'name')); ?>;
        let currentScopeFilter = 'all';
        let currentCatFilter = 'all';
        let currentRoleFilter = 'all';

        function setScopeFilter(scope, el) {
            currentScopeFilter = scope;
            document.querySelectorAll('.scope-link').forEach(a => a.classList.remove('active'));
            el.classList.add('active');
            filterTemplates();
        }

        function setCategoryFilter(cat, el) {
            currentCatFilter = cat;
            document.querySelectorAll('.cat-link').forEach(a => a.classList.remove('active'));
            el.classList.add('active');
            filterTemplates();
        }

        function setRoleFilter(role, el) {
            currentRoleFilter = role;
            document.querySelectorAll('.role-link').forEach(a => a.classList.remove('active'));
            el.classList.add('active');
            filterTemplates();
        }


        function filterTemplates() {
            const query = (document.getElementById('chkSearchInput').value || '').trim();
            const grid = document.getElementById('chkCardsGrid');
            const cards = Array.from(document.querySelectorAll('.chk-card'));

            const cardScores = [];
            cards.forEach(card => {
                const cardScope = card.getAttribute('data-scope');
                const cardCat = card.getAttribute('data-category') || '';
                const cardRoles = (card.getAttribute('data-roles') || '').split('|').filter(Boolean);

                const item = {
                    title: card.getAttribute('data-title') || '',
                    category: cardCat,
                    keywords: card.getAttribute('data-keywords') || '',
                    description: card.getAttribute('data-desc') || ''
                };

                const matchScope = (currentScopeFilter === 'all') || (cardScope === currentScopeFilter) || (cardScope === 'both');
                const matchCat = (currentCatFilter === 'all') || (cardCat === currentCatFilter);
                const matchRole = (currentRoleFilter === 'all') || cardRoles.includes(currentRoleFilter);

                const res = scoreChecklistTemplate(item, query);
                const isVisible = (matchScope && matchCat && matchRole && res.matched);
                card.style.display = isVisible ? 'flex' : 'none';
                if (isVisible) {
                    cardScores.push({ card, score: res.score, title: item.title });
                }
            });

            // Re-order visible cards in DOM according to relevance score descending
            if (query && grid) {
                cardScores.sort((a, b) => b.score - a.score || a.title.localeCompare(b.title));
                cardScores.forEach(cs => grid.appendChild(cs.card));
            }
        }


        async function deleteTemplate(id, name) {
            const ok = await showConfirm({
                title: t('checklists.list.delete_title'),
                message: t('checklists.list.delete_confirm', { name: name }),
                okLabel: 'Delete', okClass: 'danger'
            });
            if (!ok) return;
            try {
                const res = await fetch('api.php?action=delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `id=${id}`
                });
                const data = await res.json();
                if (!data.success) { showToast(data.error || 'Could not delete', 'error'); return; }
                showToast('Template deleted', 'success');
                window.location.reload();
            } catch (e) {
                showToast('Could not delete', 'error');
            }
        }

    </script>
    <script src="../assets/js/mobile.js?v=78"></script>
</body>
</html>
