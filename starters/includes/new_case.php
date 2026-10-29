<?php
/**
 * The New case dialog - markup only. Behaviour: JmlNew in assets/js/starters.js.
 * The shared .modal from inbox.css; the fields fill from api/starters/lookups.php.
 */
$nt = fn(string $k) => htmlspecialchars(t('starters.' . $k));
?>
<div class="modal jml-modal" id="jmlNewModal" role="dialog" aria-modal="true" aria-labelledby="jmlNewTitle">
    <div class="modal-content">
        <div class="modal-header" id="jmlNewTitle"><?php echo $nt('new.title'); ?></div>
        <div class="modal-body">
            <div class="jml-field">
                <div class="lbl"><?php echo $nt('new.kind'); ?></div>
                <div class="jml-kinds" id="jmlNewKinds">
                    <?php foreach (['starter', 'mover', 'leaver'] as $k): ?>
                    <button type="button" class="jml-kind-card" data-kind="<?php echo $k; ?>"><b><?php echo $nt('kind.' . $k); ?></b><span><?php echo $nt('kind_desc.' . $k); ?></span></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="jml-field">
                <div class="lbl"><?php echo $nt('new.person'); ?></div>
                <div class="jml-seg" id="jmlNewWho">
                    <button type="button" data-who="new" class="active"><?php echo $nt('new.person_new'); ?></button>
                    <button type="button" data-who="existing"><?php echo $nt('new.person_existing'); ?></button>
                </div>
                <div id="jmlNewPersonNew">
                    <div class="jml-row">
                        <div class="jml-field"><label for="jmlNewName"><?php echo $nt('new.name'); ?></label><input type="text" id="jmlNewName" maxlength="255" autocomplete="off"></div>
                        <div class="jml-field"><label for="jmlNewEmail"><?php echo $nt('new.email'); ?></label><input type="email" id="jmlNewEmail" maxlength="255" autocomplete="off"></div>
                    </div>
                    <div class="jml-field"><label for="jmlNewJob"><?php echo $nt('new.job_title'); ?></label><input type="text" id="jmlNewJob" maxlength="150" autocomplete="off"></div>
                </div>
                <div id="jmlNewPersonExisting" hidden>
                    <div class="jml-search" id="jmlNewPersonSearch"></div>
                </div>
            </div>

            <div class="jml-row">
                <div class="jml-field"><label for="jmlNewDate" id="jmlNewDateLabel"><?php echo $nt('date_label.starter'); ?></label><input type="date" id="jmlNewDate"></div>
                <div class="jml-field"><label for="jmlNewOwner"><?php echo $nt('new.owner'); ?></label><select id="jmlNewOwner"></select></div>
            </div>

            <div class="jml-field">
                <label for="jmlNewTemplate"><?php echo $nt('new.template'); ?></label>
                <select id="jmlNewTemplate"></select>
                <div class="jml-hint" id="jmlNewTemplateHint"><?php echo $nt('new.template_hint'); ?></div>
            </div>

            <div class="jml-field">
                <label for="jmlNewNotes"><?php echo $nt('new.notes'); ?></label>
                <textarea id="jmlNewNotes" maxlength="5000"></textarea>
            </div>
            <div class="jml-error" id="jmlNewError" hidden></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-close><?php echo $nt('new.cancel'); ?></button>
            <button type="button" class="btn btn-primary jml-btn primary" id="jmlNewCreate"><?php echo $nt('new.create'); ?></button>
        </div>
    </div>
</div>
