<?php
/** Native task detail shell shared by the module and its ticket embedding. */
function renderTaskDetailShell(): void
{
?>
    <!-- Detail panel overlay -->
    <div class="detail-overlay" id="detailOverlay" onclick="closeDetailPanel()"></div>

    <!-- Detail panel -->
    <div class="detail-panel" id="detailPanel">
        <div class="detail-panel-header">
            <h3><?php echo htmlspecialchars(t('tasks.detail.heading')); ?></h3>
            <div class="detail-panel-actions">
                <!-- Switches between the side panel and the large window, and
                     remembers the choice. The setting also lives in System →
                     Preferences, but nobody finds a setting they do not know
                     exists — this is the same choice, offered at the moment you
                     are wishing for it. Icon and tooltip are set per state by
                     paintViewToggle(). -->
                <button class="btn-icon" id="detailViewToggle" onclick="toggleTaskView()" title="">
                    <svg id="detailViewToggleIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"></svg>
                </button>
                <!-- Columns or tabs, WITHIN the large window. Hidden entirely in
                     the side panel, because there is nothing there it could
                     apply to — a control that is present but does nothing is
                     worse than one that is absent. Same reasoning as the button
                     above: the choice is offered where you are wishing for it. -->
                <button class="btn-icon" id="detailLayoutToggle" onclick="toggleModalLayout()" title="" style="display:none;">
                    <svg id="detailLayoutToggleIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"></svg>
                </button>
                <!-- GH #204: this task and its subtasks as a printable case report. -->
                <button class="btn-icon" onclick="openTaskCaseReport()" title="<?php echo htmlspecialchars(t('tasks.detail.export_tip')); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="8" y1="13" x2="16" y2="13"></line><line x1="8" y1="17" x2="16" y2="17"></line></svg>
                </button>
                <button class="btn-icon" onclick="deleteCurrentTask()" title="<?php echo htmlspecialchars(t('tasks.detail.delete')); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                </button>
                <button class="btn-icon" onclick="closeDetailPanel()" title="<?php echo htmlspecialchars(t('tasks.detail.close')); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>
        </div>
        <div class="detail-panel-body" id="detailPanelBody">
            <!-- Populated by JS -->
        </div>
    </div>

<?php
}
