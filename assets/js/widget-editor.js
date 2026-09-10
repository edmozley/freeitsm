/**
 * WidgetEditor — shared form logic for creating/editing dashboard widgets.
 * Used by both the library page (inline panel) and the dashboard page (modal).
 */
(function() {
    'use strict';

    const PROPERTY_LABELS = {
        status: 'Status', priority: 'Priority', department: 'Department',
        ticket_type: 'Ticket type', analyst: 'Assigned analyst', owner: 'Owner',
        origin: 'Origin', first_time_fix: 'First time fix', training_provided: 'Training provided',
        // Classification (#1540). The two category dimensions roll up to the TOP
        // level - a leaf-level chart of a three-deep tree is slices nobody can read.
        category: 'Category', closure_category: 'Category at close', resolution_code: 'Resolution code',
        created: 'Created', closed: 'Closed', created_vs_closed: 'Created vs closed'
    };

    const SERIES_LABELS = { status: 'Status', priority: 'Priority' };

    const TIME_GROUPING_LABELS = { day: 'Daily', month: 'Monthly', year: 'Yearly' };
    const DATE_RANGE_LABELS = {
        '': 'All time', '7d': 'Last 7 days', '30d': 'Last 30 days',
        'this_month': 'This month', '3m': 'Last 3 months',
        '6m': 'Last 6 months', '12m': 'Last 12 months', 'this_year': 'This year'
    };

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

    function getValidChartTypes(aggProp, seriesProp) {
        const isTime = TIME_AGGREGATES.includes(aggProp);
        if (seriesProp) return ['bar', 'line'];
        if (isTime) return ['bar', 'line'];
        return ['bar', 'doughnut', 'pie'];
    }

    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function generateDescription() {
        const prop = document.getElementById('editProperty').value;
        const series = document.getElementById('editSeries').value;
        const dateRange = document.getElementById('editDateRange').value;
        const timeGrouping = document.getElementById('editTimeGrouping').value;
        const isTime = TIME_AGGREGATES.includes(prop);
        const checkedDepts = [...document.querySelectorAll('.dept-checkbox:checked')];

        let desc = '';

        if (prop === 'created_vs_closed') {
            desc = 'Created vs closed';
            if (isTime) desc += ' per ' + (TIME_GROUPING_LABELS[timeGrouping] || timeGrouping).toLowerCase().replace(/ly$/, '');
        } else if (isTime) {
            const verb = prop === 'created' ? 'created' : 'closed';
            const groupLabel = (TIME_GROUPING_LABELS[timeGrouping] || timeGrouping).toLowerCase().replace(/ly$/, '');
            desc = 'Tickets ' + verb + ' per ' + groupLabel;
            if (series) desc += ' by ' + (SERIES_LABELS[series] || series).toLowerCase();
        } else {
            desc = 'Tickets by ' + (PROPERTY_LABELS[prop] || prop).toLowerCase();
            if (series) desc += ' and ' + (SERIES_LABELS[series] || series).toLowerCase();
        }

        if (dateRange) {
            desc += ' (' + (DATE_RANGE_LABELS[dateRange] || dateRange).toLowerCase() + ')';
        }

        if (checkedDepts.length > 0 && checkedDepts.length < allDepartments.length) {
            const names = checkedDepts.map(cb => {
                const label = cb.closest('label');
                return label ? label.textContent.trim() : '';
            }).filter(Boolean);
            if (names.length <= 2) {
                desc += ' \u2014 ' + names.join(', ');
            } else {
                desc += ' \u2014 ' + names.length + ' departments';
            }
        }

        return desc;
    }

    function autoFillDescription() {
        if (descriptionManuallyEdited) return;
        document.getElementById('editDescription').value = generateDescription();
    }

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

    function onPropertyChange() {
        const prop = document.getElementById('editProperty').value;
        const seriesSelect = document.getElementById('editSeries');
        const seriesGroup = document.getElementById('seriesGroup');
        const timeGroupingGroup = document.getElementById('timeGroupingGroup');
        const isTime = TIME_AGGREGATES.includes(prop);

        const allowedSeries = SERIES_RULES[prop] || [];
        seriesSelect.innerHTML = '<option value="">None (single series)</option>';
        allowedSeries.forEach(function(s) {
            seriesSelect.innerHTML += '<option value="' + s + '">By ' + (SERIES_LABELS[s] || s) + '</option>';
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

    function validateForm() {
        var data = collectFormData();
        if (!data.title) {
            showToast('Title is required', 'error');
            return false;
        }
        if (TIME_AGGREGATES.includes(data.aggregate_property) && !data.time_grouping) {
            showToast('Time grouping is required for time-based aggregates', 'error');
            return false;
        }
        return true;
    }

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
            return { success: false, error: 'Network error' };
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
        PROPERTY_LABELS: PROPERTY_LABELS,
        SERIES_LABELS: SERIES_LABELS,
        TIME_GROUPING_LABELS: TIME_GROUPING_LABELS,
        DATE_RANGE_LABELS: DATE_RANGE_LABELS,
        TIME_AGGREGATES: TIME_AGGREGATES,
        SERIES_RULES: SERIES_RULES,
        getValidChartTypes: getValidChartTypes
    };
})();
