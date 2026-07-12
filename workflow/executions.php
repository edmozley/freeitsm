<?php
/**
 * Workflows — the execution log.
 *
 * Every run of every workflow, filterable and paginated, with a drill-down into
 * the step log and the trigger payload snapshot.
 *
 * Why this page exists: the only view of a run used to be the editor's "Recent
 * runs" panel — the last 20 runs of ONE workflow. That's the wrong shape for the
 * question you actually ask when something goes wrong ("what failed, and why?"),
 * because you don't yet know which workflow to open. This starts from the
 * failure and works back.
 *
 * Deep-linkable: ?status=failed&from=2026-07-12 — which is how the Watchtower
 * card and the "N failures" tallies link in.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('workflow');

$current_page = 'executions';
$path_prefix = '../';
$translationNamespaces = ['common', 'workflow'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('workflow.executions.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=11">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="container">
        <div class="tab-content active">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('workflow.executions.page_title')); ?></h2>
            </div>
            <p style="margin-bottom: 16px; color: var(--text-muted, #666);"><?php echo htmlspecialchars(t('workflow.executions.intro')); ?></p>

            <!-- Status tallies — double as one-click filters -->
            <div class="wfx-tally" id="wfxTally"></div>

            <!-- Filters -->
            <div class="wfx-filters">
                <div>
                    <label for="fWorkflow"><?php echo htmlspecialchars(t('workflow.executions.f_workflow')); ?></label>
                    <select id="fWorkflow" class="form-input"><option value=""><?php echo htmlspecialchars(t('workflow.executions.all')); ?></option></select>
                </div>
                <div>
                    <label for="fStatus"><?php echo htmlspecialchars(t('workflow.executions.f_status')); ?></label>
                    <select id="fStatus" class="form-input">
                        <option value=""><?php echo htmlspecialchars(t('workflow.executions.all')); ?></option>
                        <option value="success"><?php echo htmlspecialchars(t('workflow.status.success')); ?></option>
                        <option value="failed"><?php echo htmlspecialchars(t('workflow.status.failed')); ?></option>
                        <option value="skipped"><?php echo htmlspecialchars(t('workflow.status.skipped')); ?></option>
                        <option value="aborted"><?php echo htmlspecialchars(t('workflow.status.aborted')); ?></option>
                        <option value="running"><?php echo htmlspecialchars(t('workflow.status.running')); ?></option>
                    </select>
                </div>
                <div>
                    <label for="fTrigger"><?php echo htmlspecialchars(t('workflow.executions.f_trigger')); ?></label>
                    <select id="fTrigger" class="form-input"><option value=""><?php echo htmlspecialchars(t('workflow.executions.all')); ?></option></select>
                </div>
                <div>
                    <label for="fDry"><?php echo htmlspecialchars(t('workflow.executions.f_dry')); ?></label>
                    <select id="fDry" class="form-input">
                        <option value=""><?php echo htmlspecialchars(t('workflow.executions.all')); ?></option>
                        <option value="0"><?php echo htmlspecialchars(t('workflow.executions.real_only')); ?></option>
                        <option value="1"><?php echo htmlspecialchars(t('workflow.executions.dry_only')); ?></option>
                    </select>
                </div>
                <div>
                    <label for="fFrom"><?php echo htmlspecialchars(t('workflow.executions.f_from')); ?></label>
                    <input type="date" id="fFrom" class="form-input">
                </div>
                <div>
                    <label for="fTo"><?php echo htmlspecialchars(t('workflow.executions.f_to')); ?></label>
                    <input type="date" id="fTo" class="form-input">
                </div>
                <div style="flex: 1 1 200px;">
                    <label for="fQ"><?php echo htmlspecialchars(t('workflow.executions.f_search')); ?></label>
                    <input type="text" id="fQ" class="form-input" placeholder="<?php echo htmlspecialchars(t('workflow.executions.f_search_ph')); ?>">
                </div>
                <div style="align-self: end;">
                    <button class="btn btn-secondary" onclick="WFX.reset()"><?php echo htmlspecialchars(t('workflow.executions.clear')); ?></button>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('workflow.executions.col_status')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.executions.col_workflow')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.executions.col_trigger')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.executions.col_when')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.executions.col_took')); ?></th>
                        <th><?php echo htmlspecialchars(t('workflow.executions.col_detail')); ?></th>
                    </tr>
                </thead>
                <tbody id="wfxRows">
                    <tr><td colspan="6" style="text-align:center;"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr>
                </tbody>
            </table>

            <div class="wfx-pager" id="wfxPager"></div>
        </div>
    </div>

    <!-- Run detail -->
    <div class="modal" id="wfxModal">
        <div class="modal-content" style="max-width: 860px; width: 94%;">
            <div class="modal-header" id="wfxModalTitle"></div>
            <div class="modal-body" id="wfxModalBody"></div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="WFX.close()"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <script src="../assets/js/workflow-executions.js?v=1"></script>
</body>
</html>
