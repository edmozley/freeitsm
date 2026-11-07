/**
 * WidgetEditor — shared form logic for creating/editing dashboard widgets.
 * Used by both the library page (inline panel) and the dashboard page (modal).
 */
(function() {
    'use strict';

    /** Translate, or the English written at the call site (i18n.js). */
    function we(key, english, params) {
        return window.tf ? window.tf('tickets.dashboard.' + key, english, params) : english;
    }

    // Read through functions, not constants: this file is evaluated at load
    // time, and a constant would freeze whatever window.translations held then.
    function propertyLabel(p) {
        return {
            status:            we('form.prop_status', 'Status'),
            priority:          we('form.prop_priority', 'Priority'),
            department:        we('form.prop_department', 'Department'),
            ticket_type:       we('form.prop_ticket_type', 'Ticket type'),
            analyst:           we('form.prop_analyst', 'Assigned analyst'),
            owner:             we('form.prop_owner', 'Owner'),
            origin:            we('form.prop_origin', 'Origin'),
            first_time_fix:    we('form.prop_ftf', 'First time fix'),
            training_provided: we('form.prop_training', 'Training provided'),
            // Classification (#1540). The two category dimensions roll up to the TOP
            // level - a leaf-level chart of a three-deep tree is slices nobody can read.
            category:          we('auto_desc.prop_category', 'Category'),
            closure_category:  we('auto_desc.prop_closure_category', 'Category at close'),
            resolution_code:   we('auto_desc.prop_resolution_code', 'Resolution code'),
            created:           we('form.prop_created', 'Created'),
            closed:            we('form.prop_closed', 'Closed'),
            created_vs_closed: we('form.prop_created_vs_closed', 'Created vs closed')
        }[p] || p;
    }

    /** The same property as it reads INSIDE a sentence - not a lower-cased title. */
    function propertyInSentence(p) {
        return {
            status:            we('auto_desc.of_status', 'status'),
            priority:          we('auto_desc.of_priority', 'priority'),
            department:        we('auto_desc.of_department', 'department'),
            ticket_type:       we('auto_desc.of_ticket_type', 'ticket type'),
            analyst:           we('auto_desc.of_analyst', 'assigned analyst'),
            owner:             we('auto_desc.of_owner', 'owner'),
            origin:            we('auto_desc.of_origin', 'origin'),
            first_time_fix:    we('auto_desc.of_first_time_fix', 'first time fix'),
            training_provided: we('auto_desc.of_training_provided', 'training provided'),
            category:          we('auto_desc.of_category', 'category'),
            closure_category:  we('auto_desc.of_closure_category', 'category at close'),
            resolution_code:   we('auto_desc.of_resolution_code', 'resolution code')
        }[p] || propertyLabel(p);
    }

    /** Returns the translated title for a series property (Status or Priority). */
    function seriesLabel(s) {
        return { status: we('form.prop_status', 'Status'), priority: we('form.prop_priority', 'Priority') }[s] || s;
    }
    /** Returns the series property as it reads inside a sentence ("status", "priority"). */
    function seriesInSentence(s) {
        return { status: we('auto_desc.of_status', 'status'), priority: we('auto_desc.of_priority', 'priority') }[s] || s;
    }

    /** Returns the translated label for a time grouping (Daily, Monthly, Yearly). */
    function timeGroupingLabel(g) {
        return {
            day:   we('auto_desc.grouping_daily', 'Daily'),
            month: we('auto_desc.grouping_monthly', 'Monthly'),
            year:  we('auto_desc.grouping_yearly', 'Yearly')
        }[g] || g;
    }
    /** The unit as a noun: "per day". Was derived by stripping "ly" off
     *  "Daily", which gave "dai". */
    function timeUnit(g) {
        return {
            day:   we('auto_desc.unit_day', 'day'),
            month: we('auto_desc.unit_month', 'month'),
            year:  we('auto_desc.unit_year', 'year')
        }[g] || g;
    }

    /** Returns the translated label for a date-range code ('' is All time, '7d' is Last 7 days, and so on). */
    function dateRangeLabel(r) {
        return {
            '':           we('form.range_all', 'All time'),
            '7d':         we('form.range_7d', 'Last 7 days'),
            '30d':        we('form.range_30d', 'Last 30 days'),
            'this_month': we('form.range_this_month', 'This month'),
            '3m':         we('form.range_3m', 'Last 3 months'),
            '6m':         we('form.range_6m', 'Last 6 months'),
            '12m':        we('form.range_12m', 'Last 12 months'),
            'this_year':  we('form.range_this_year', 'This year')
        }[r] || r;
    }
    /** Returns a date-range code as it reads inside a sentence ("last 7 days"), falling back to the label. */
    function dateRangeInSentence(r) {
        return {
            '7d':         we('auto_desc.in_7d', 'last 7 days'),
            '30d':        we('auto_desc.in_30d', 'last 30 days'),
            'this_month': we('auto_desc.in_this_month', 'this month'),
            '3m':         we('auto_desc.in_3m', 'last 3 months'),
            '6m':         we('auto_desc.in_6m', 'last 6 months'),
            '12m':        we('auto_desc.in_12m', 'last 12 months'),
            'this_year':  we('auto_desc.in_this_year', 'this year')
        }[r] || dateRangeLabel(r);
    }

    const TIME_AGGREGATES = ['created', 'closed', 'created_vs_closed'];

    const SERIES_RULES = {
        status: [],
        priority: ['status'],
        department: ['status', 'priority'],
        ticket_type: ['status', 'priority'],
        analyst: ['status', 'priority'],
        owner: ['status', 'priority'],
        origin: ['status', 'priority'],
        category: ['status', 'priority'],
        closure_category: ['status', 'priority'],
        resolution_code: ['status', 'priority'],
        first_time_fix: [],
        training_provided: [],
        created: ['status', 'priority'],
        closed: ['status', 'priority'],
        created_vs_closed: []
    };

    let allDepartments = [];
    let descriptionManuallyEdited = false;
    let apiBase = '';

    /**
     * Returns the chart types that fit the choice - bar or line for a series or a time aggregate, otherwise bar, doughnut or pie.
     */
    function getValidChartTypes(aggProp, seriesProp) {
        const isTime = TIME_AGGREGATES.includes(aggProp);
        if (seriesProp) return ['bar', 'line'];
        if (isTime) return ['bar', 'line'];
        return ['bar', 'doughnut', 'pie'];
    }

    /** Escapes text for safe use in HTML. */
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /**
     * Builds the automatic widget description from the form - property, series, time unit, date range and any department subset - as one translated sentence.
     */
    function generateDescription() {
        const prop = document.getElementById('editProperty').value;
        const series = document.getElementById('editSeries').value;
        const dateRange = document.getElementById('editDateRange').value;
        const timeGrouping = document.getElementById('editTimeGrouping').value;
        const isTime = TIME_AGGREGATES.includes(prop);
        const checkedDepts = [...document.querySelectorAll('.dept-checkbox:checked')];

        let desc = '';
        const unit = timeUnit(timeGrouping);

        if (prop === 'created_vs_closed') {
            desc = isTime
                ? we('auto_desc.created_vs_closed_per', 'Created vs closed per {unit}', { unit: unit })
                : we('auto_desc.created_vs_closed', 'Created vs closed');
        } else if (isTime) {
            const verb = prop === 'created'
                ? we('auto_desc.verb_created', 'created')
                : we('auto_desc.verb_closed', 'closed');
            desc = series
                ? we('auto_desc.per_by', 'Tickets {verb} per {unit} by {series}',
                     { verb: verb, unit: unit, series: seriesInSentence(series) })
                : we('auto_desc.per', 'Tickets {verb} per {unit}', { verb: verb, unit: unit });
        } else {
            desc = series
                ? we('auto_desc.by_and', 'Tickets by {property} and {series}',
                     { property: propertyInSentence(prop), series: seriesInSentence(series) })
                : we('auto_desc.by', 'Tickets by {property}', { property: propertyInSentence(prop) });
        }

        if (dateRange) {
            desc = we('auto_desc.with_range', '{desc} ({range})',
                      { desc: desc, range: dateRangeInSentence(dateRange) });
        }

        if (checkedDepts.length > 0 && checkedDepts.length < allDepartments.length) {
            const names = checkedDepts.map(cb => {
                const label = cb.closest('label');
                return label ? label.textContent.trim() : '';
            }).filter(Boolean);
            desc = names.length <= 2
                ? we('auto_desc.with_depts', '{desc} \u2014 {names}', { desc: desc, names: names.join(', ') })
                : we('auto_desc.with_dept_count', '{desc} \u2014 {n} departments', { desc: desc, n: names.length });
        }

        return desc;
    }

    /** Rewrites the description field with the generated sentence, unless the analyst has typed their own. */
    function autoFillDescription() {
        if (descriptionManuallyEdited) return;
        document.getElementById('editDescription').value = generateDescription();
    }

    /** Draws one checkbox per active department for the department filter. */
    function buildDeptCheckboxes() {
        const container = document.getElementById('deptCheckboxes');
        if (!container) return;
        container.innerHTML = allDepartments.map(d =>
            '<label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:normal;cursor:pointer;">' +
                '<input type="checkbox" value="' + d.id + '" class="dept-checkbox" style="width:14px;height:14px;">' +
                escapeHtml(d.name) +
            '</label>'
        ).join('');
    }

    /**
     * Reacts to a new aggregate property - rebuilds the allowed series options, shows or hides the series and time-grouping fields, then refreshes chart types, the filterable tick and the description.
     */
    function onPropertyChange() {
        const prop = document.getElementById('editProperty').value;
        const seriesSelect = document.getElementById('editSeries');
        const seriesGroup = document.getElementById('seriesGroup');
        const timeGroupingGroup = document.getElementById('timeGroupingGroup');
        const isTime = TIME_AGGREGATES.includes(prop);

        const allowedSeries = SERIES_RULES[prop] || [];
        seriesSelect.innerHTML = '<option value="">' +
            escapeHtml(we('form.series_none', 'None (single series)')) + '</option>';
        allowedSeries.forEach(function(s) {
            // "By status" is one string, not "By " + a word: the preposition
            // does not sit in front of the noun in every language.
            var label = s === 'priority'
                ? we('form.series_priority', 'By priority')
                : we('form.series_status', 'By status');
            seriesSelect.innerHTML += '<option value="' + s + '">' + escapeHtml(label) + '</option>';
        });

        if (allowedSeries.length === 0) {
            seriesGroup.style.display = 'none';
            seriesSelect.value = '';
        } else {
            seriesGroup.style.display = '';
        }

        if (isTime) {
            timeGroupingGroup.style.display = '';
            if (!document.getElementById('editTimeGrouping').value) {
                document.getElementById('editTimeGrouping').value = 'month';
            }
        } else {
            timeGroupingGroup.style.display = 'none';
        }

        updateChartTypeOptions();
        updateFilterableVisibility();
        autoFillDescription();
    }

    /**
     * Limits the chart-type list to the types valid for the property and series, keeping the current choice when it is still allowed.
     */
    function updateChartTypeOptions() {
        const prop = document.getElementById('editProperty').value;
        const series = document.getElementById('editSeries').value;
        const chartSelect = document.getElementById('editChartType');
        const current = chartSelect.value;

        const valid = getValidChartTypes(prop, series);
        var allTypes = [
            { value: 'bar', label: 'Bar' },
            { value: 'doughnut', label: 'Doughnut' },
            { value: 'pie', label: 'Pie' },
            { value: 'line', label: 'Line' }
        ];

        chartSelect.innerHTML = allTypes
            .filter(function(t) { return valid.includes(t.value); })
            .map(function(t) { return '<option value="' + t.value + '">' + t.label + '</option>'; })
            .join('');

        if (valid.includes(current)) {
            chartSelect.value = current;
        }
    }

    /** Hides and unticks "status filterable" when the series is status; shows it otherwise. */
    function updateFilterableVisibility() {
        var series = document.getElementById('editSeries').value;
        var filterableGroup = document.getElementById('filterableGroup');
        if (series === 'status') {
            filterableGroup.style.display = 'none';
            document.getElementById('editFilterable').checked = false;
        } else {
            filterableGroup.style.display = '';
        }
    }

    /** Wires the form fields so every change refreshes the dependent options and the automatic description. */
    function bindEventListeners() {
        document.getElementById('editProperty').addEventListener('change', onPropertyChange);

        document.getElementById('editSeries').addEventListener('change', function() {
            updateChartTypeOptions();
            updateFilterableVisibility();
            autoFillDescription();
        });

        document.getElementById('editTimeGrouping').addEventListener('change', autoFillDescription);
        document.getElementById('editDateRange').addEventListener('change', autoFillDescription);
        document.getElementById('deptCheckboxes').addEventListener('change', autoFillDescription);

        document.getElementById('editDescription').addEventListener('input', function() {
            descriptionManuallyEdited = this.value.trim().length > 0;
            if (!descriptionManuallyEdited) autoFillDescription();
        });
    }

    /** Sets the API base, loads the active departments for the filter checkboxes, and wires the form events. */
    async function init(base) {
        apiBase = base;
        var deptRes = await fetch(apiBase + 'get_departments.php')
            .then(function(r) { return r.json(); })
            .catch(function() { return { success: false }; });
        if (deptRes.success) {
            allDepartments = (deptRes.departments || []).filter(function(d) { return d.is_active; });
            buildDeptCheckboxes();
        }
        bindEventListeners();
    }

    /** Fills the form from an existing widget row, including its department filter (stored as JSON). */
    function populateForm(w) {
        document.getElementById('editId').value = w.id || '';
        document.getElementById('editTitle').value = w.title || '';
        document.getElementById('editDescription').value = w.description || '';
        document.getElementById('editProperty').value = w.aggregate_property || 'status';

        onPropertyChange();

        document.getElementById('editSeries').value = w.series_property || '';
        updateChartTypeOptions();
        document.getElementById('editChartType').value = w.chart_type || 'bar';
        document.getElementById('editFilterable').checked = parseInt(w.is_status_filterable) === 1;
        updateFilterableVisibility();

        document.getElementById('editDateRange').value = w.date_range || '';
        document.getElementById('editTimeGrouping').value = w.time_grouping || 'month';

        var deptIds = w.department_filter
            ? (typeof w.department_filter === 'string' ? JSON.parse(w.department_filter) : w.department_filter)
            : [];
        document.querySelectorAll('.dept-checkbox').forEach(function(cb) {
            cb.checked = deptIds.includes(parseInt(cb.value));
        });

        descriptionManuallyEdited = !!(w.description || '').trim();
    }

    /** Clears the form to the defaults for a new widget. */
    function resetForm() {
        document.getElementById('editId').value = '';
        document.getElementById('editTitle').value = '';
        document.getElementById('editDescription').value = '';
        document.getElementById('editChartType').value = 'bar';
        document.getElementById('editProperty').value = 'status';
        document.getElementById('editSeries').value = '';
        document.getElementById('editFilterable').checked = true;
        document.getElementById('editDateRange').value = '';
        document.getElementById('editTimeGrouping').value = 'month';
        document.querySelectorAll('.dept-checkbox').forEach(function(cb) { cb.checked = false; });
        document.getElementById('seriesGroup').style.display = 'none';
        document.getElementById('filterableGroup').style.display = '';
        descriptionManuallyEdited = false;
        onPropertyChange();
    }

    /**
     * Reads the form into the payload save_ticket_dashboard_widget.php expects; time_grouping is sent only for time aggregates.
     */
    function collectFormData() {
        var aggregate_property = document.getElementById('editProperty').value;
        var department_filter = [...document.querySelectorAll('.dept-checkbox:checked')]
            .map(function(cb) { return parseInt(cb.value); });
        return {
            id: document.getElementById('editId').value || null,
            title: document.getElementById('editTitle').value.trim(),
            description: document.getElementById('editDescription').value.trim(),
            chart_type: document.getElementById('editChartType').value,
            aggregate_property: aggregate_property,
            series_property: document.getElementById('editSeries').value || null,
            is_status_filterable: document.getElementById('editFilterable').checked ? 1 : 0,
            date_range: document.getElementById('editDateRange').value || null,
            time_grouping: TIME_AGGREGATES.includes(aggregate_property)
                ? document.getElementById('editTimeGrouping').value || null
                : null,
            department_filter: department_filter.length > 0 ? department_filter : null
        };
    }

    /** Checks the title is filled and a time aggregate has a grouping, toasting the first problem. */
    function validateForm() {
        var data = collectFormData();
        if (!data.title) {
            showToast(we('auto_desc.err_title', 'Title is required'), 'error');
            return false;
        }
        if (TIME_AGGREGATES.includes(data.aggregate_property) && !data.time_grouping) {
            showToast(we('auto_desc.err_time_grouping', 'Time grouping is required for time-based aggregates'), 'error');
            return false;
        }
        return true;
    }

    /** Validates, then posts the widget to save_ticket_dashboard_widget.php and returns the JSON reply. */
    async function saveWidget() {
        if (!validateForm()) return { success: false };
        var data = collectFormData();
        try {
            var res = await fetch(apiBase + 'save_ticket_dashboard_widget.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            return await res.json();
        } catch (err) {
            return { success: false, error: window.tf ? window.tf('common.error_network', 'Network error') : 'Network error' };
        }
    }

    window.WidgetEditor = {
        init: init,
        populateForm: populateForm,
        resetForm: resetForm,
        collectFormData: collectFormData,
        validateForm: validateForm,
        saveWidget: saveWidget,
        onPropertyChange: onPropertyChange,
        // Exported so the auto-description can be exercised directly. It is the
        // one function here that writes a whole SENTENCE, which is where an
        // i18n mistake hides best.
        generateDescription: generateDescription,
        propertyLabel: propertyLabel,
        seriesLabel: seriesLabel,
        timeGroupingLabel: timeGroupingLabel,
        dateRangeLabel: dateRangeLabel,
        TIME_AGGREGATES: TIME_AGGREGATES,
        SERIES_RULES: SERIES_RULES,
        getValidChartTypes: getValidChartTypes
    };
})();
