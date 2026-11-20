<!-- Widget Edit Form (shared partial) — used by library.php and index.php -->
<input type="hidden" id="editId">
<div class="edit-form">
    <div class="form-group">
        <label><?php echo htmlspecialchars(t('tickets.dashboard.form.title')); ?></label>
        <input type="text" id="editTitle" maxlength="100" placeholder="<?php echo htmlspecialchars(t('tickets.dashboard.form.title_ph')); ?>">
    </div>
    <div class="form-group">
        <label><?php echo htmlspecialchars(t('tickets.dashboard.form.chart_type')); ?></label>
        <select id="editChartType">
            <option value="bar"><?php echo htmlspecialchars(t('tickets.dashboard.form.chart_bar')); ?></option>
            <option value="doughnut"><?php echo htmlspecialchars(t('tickets.dashboard.form.chart_doughnut')); ?></option>
            <option value="pie"><?php echo htmlspecialchars(t('tickets.dashboard.form.chart_pie')); ?></option>
            <option value="line"><?php echo htmlspecialchars(t('tickets.dashboard.form.chart_line')); ?></option>
        </select>
    </div>
    <div class="form-group full-width">
        <label><?php echo htmlspecialchars(t('tickets.dashboard.form.description')); ?></label>
        <textarea id="editDescription" maxlength="255" placeholder="<?php echo htmlspecialchars(t('tickets.dashboard.form.description_ph')); ?>"></textarea>
    </div>
    <div class="form-group">
        <label><?php echo htmlspecialchars(t('tickets.dashboard.form.property')); ?></label>
        <select id="editProperty">
            <optgroup label="<?php echo htmlspecialchars(t('tickets.dashboard.form.group_categorical')); ?>">
                <option value="status"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_status')); ?></option>
                <option value="priority"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_priority')); ?></option>
                <option value="department"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_department')); ?></option>
                <option value="ticket_type"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_ticket_type')); ?></option>
                <option value="analyst"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_analyst')); ?></option>
                <option value="owner"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_owner')); ?></option>
                <option value="origin"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_origin')); ?></option>
                <option value="first_time_fix"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_ftf')); ?></option>
                <option value="training_provided"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_training')); ?></option>
            </optgroup>
            <optgroup label="<?php echo htmlspecialchars(t('tickets.dashboard.form.group_timeseries')); ?>">
                <option value="created"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_created')); ?></option>
                <option value="closed"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_closed')); ?></option>
            </optgroup>
            <optgroup label="<?php echo htmlspecialchars(t('tickets.dashboard.form.group_comparison')); ?>">
                <option value="created_vs_closed"><?php echo htmlspecialchars(t('tickets.dashboard.form.prop_created_vs_closed')); ?></option>
            </optgroup>
        </select>
    </div>
    <div class="form-group" id="timeGroupingGroup" style="display:none;">
        <label><?php echo htmlspecialchars(t('tickets.dashboard.form.time_grouping')); ?></label>
        <select id="editTimeGrouping">
            <option value="day"><?php echo htmlspecialchars(t('tickets.dashboard.form.time_day')); ?></option>
            <option value="month"><?php echo htmlspecialchars(t('tickets.dashboard.form.time_month')); ?></option>
            <option value="year"><?php echo htmlspecialchars(t('tickets.dashboard.form.time_year')); ?></option>
        </select>
    </div>
    <div class="form-group" id="seriesGroup">
        <label><?php echo htmlspecialchars(t('tickets.dashboard.form.series')); ?></label>
        <select id="editSeries">
            <option value=""><?php echo htmlspecialchars(t('tickets.dashboard.form.series_none')); ?></option>
            <option value="status"><?php echo htmlspecialchars(t('tickets.dashboard.form.series_status')); ?></option>
            <option value="priority"><?php echo htmlspecialchars(t('tickets.dashboard.form.series_priority')); ?></option>
        </select>
    </div>
    <div class="form-group">
        <label><?php echo htmlspecialchars(t('tickets.dashboard.form.date_range')); ?></label>
        <select id="editDateRange">
            <option value=""><?php echo htmlspecialchars(t('tickets.dashboard.form.range_all')); ?></option>
            <option value="7d"><?php echo htmlspecialchars(t('tickets.dashboard.form.range_7d')); ?></option>
            <option value="30d"><?php echo htmlspecialchars(t('tickets.dashboard.form.range_30d')); ?></option>
            <option value="this_month"><?php echo htmlspecialchars(t('tickets.dashboard.form.range_this_month')); ?></option>
            <option value="3m"><?php echo htmlspecialchars(t('tickets.dashboard.form.range_3m')); ?></option>
            <option value="6m"><?php echo htmlspecialchars(t('tickets.dashboard.form.range_6m')); ?></option>
            <option value="12m"><?php echo htmlspecialchars(t('tickets.dashboard.form.range_12m')); ?></option>
            <option value="this_year"><?php echo htmlspecialchars(t('tickets.dashboard.form.range_this_year')); ?></option>
        </select>
    </div>
    <div class="form-group full-width" id="deptFilterGroup">
        <label><?php echo htmlspecialchars(t('tickets.dashboard.form.dept_filter')); ?></label>
        <div id="deptCheckboxes" style="display:flex;flex-wrap:wrap;gap:8px;padding:4px 0;"></div>
        <div style="font-size:var(--font-size-xs, 11px);color:#888;margin-top:4px;"><?php echo htmlspecialchars(t('tickets.dashboard.form.dept_hint')); ?></div>
    </div>
    <div class="form-group checkbox-group" id="filterableGroup">
        <input type="checkbox" id="editFilterable" checked>
        <label for="editFilterable"><?php echo htmlspecialchars(t('tickets.dashboard.form.filterable')); ?></label>
    </div>
</div>
