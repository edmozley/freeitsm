<?php
/**
 * The create / edit project dialog, shared by the portfolio and the project
 * page. Filled and saved by Prj.openProjectForm() in assets/js/projects.js.
 * The method cards, swatches and icons are drawn from api/projects/lookups.php,
 * so the palette and icon set have one source (includes/projects/methodologies.php).
 */
?>
<div class="modal" id="prjFormModal" aria-hidden="true">
    <div class="modal-content prj-form-modal">
        <div class="prj-form-banner" id="pfBanner">
            <span class="prj-form-banner-icon" id="pfBannerIcon"></span>
            <div>
                <div class="prj-form-banner-title" id="pfTitle"><?php echo htmlspecialchars(t('projects.form.new_title')); ?></div>
                <div class="prj-form-banner-name" id="pfBannerName"></div>
            </div>
        </div>
        <div class="modal-body prj-form-body">
            <input type="hidden" id="pfId">
            <div class="form-group">
                <label for="pfName"><?php echo htmlspecialchars(t('projects.form.name')); ?></label>
                <input type="text" id="pfName" maxlength="200" placeholder="<?php echo htmlspecialchars(t('projects.form.name_ph')); ?>" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="pfGoal"><?php echo htmlspecialchars(t('projects.form.goal')); ?></label>
                <input type="text" id="pfGoal" maxlength="500" placeholder="<?php echo htmlspecialchars(t('projects.form.goal_ph')); ?>" autocomplete="off">
            </div>

            <div class="form-group">
                <label><?php echo htmlspecialchars(t('projects.method.label')); ?></label>
                <div class="prj-method-cards" id="pfMethods" role="radiogroup"></div>
                <p class="prj-hint" id="pfMethodNote" hidden><?php echo htmlspecialchars(t('projects.method.switch_note')); ?></p>
            </div>

            <div class="prj-form-grid">
                <div class="form-group">
                    <label for="pfOwner"><?php echo htmlspecialchars(t('projects.form.owner')); ?></label>
                    <select id="pfOwner"></select>
                </div>
                <div class="form-group" id="pfCompanyWrap" hidden>
                    <label for="pfCompany"><?php echo htmlspecialchars(t('projects.form.company')); ?></label>
                    <select id="pfCompany"></select>
                </div>
                <div class="form-group">
                    <label for="pfStart"><?php echo htmlspecialchars(t('projects.form.start')); ?></label>
                    <input type="date" id="pfStart">
                </div>
                <div class="form-group">
                    <label for="pfTarget"><?php echo htmlspecialchars(t('projects.form.target')); ?></label>
                    <input type="date" id="pfTarget">
                </div>
                <div class="form-group prj-edit-only">
                    <label for="pfStatus"><?php echo htmlspecialchars(t('projects.form.status')); ?></label>
                    <select id="pfStatus"></select>
                </div>
                <div class="form-group prj-edit-only">
                    <label for="pfActual"><?php echo htmlspecialchars(t('projects.form.actual')); ?></label>
                    <input type="date" id="pfActual">
                </div>
                <div class="form-group prj-edit-only">
                    <label for="pfHealth"><?php echo htmlspecialchars(t('projects.form.health')); ?></label>
                    <select id="pfHealth"></select>
                </div>
                <div class="form-group prj-edit-only" id="pfHealthNoteWrap">
                    <label for="pfHealthNote"><?php echo htmlspecialchars(t('projects.form.health_note')); ?></label>
                    <input type="text" id="pfHealthNote" maxlength="500" placeholder="<?php echo htmlspecialchars(t('projects.form.health_note_ph')); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="pfSummary"><?php echo htmlspecialchars(t('projects.form.summary')); ?></label>
                <textarea id="pfSummary" rows="3" placeholder="<?php echo htmlspecialchars(t('projects.form.summary_ph')); ?>"></textarea>
            </div>

            <div class="prj-look">
                <div class="prj-look-title"><?php echo htmlspecialchars(t('projects.form.look')); ?></div>
                <div class="prj-look-row">
                    <span class="prj-look-label"><?php echo htmlspecialchars(t('projects.form.colour')); ?></span>
                    <div class="prj-swatches" id="pfColours" role="radiogroup"></div>
                </div>
                <div class="prj-look-row">
                    <span class="prj-look-label"><?php echo htmlspecialchars(t('projects.form.icon')); ?></span>
                    <div class="prj-icons" id="pfIcons" role="radiogroup"></div>
                </div>
            </div>
            <div class="prj-form-error" id="pfError" hidden></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-prj-close="prjFormModal"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
            <button type="button" class="btn btn-primary prj-btn" id="pfSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
        </div>
    </div>
</div>
