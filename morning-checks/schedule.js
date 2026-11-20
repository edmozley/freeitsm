/**
 * Morning check schedules (GH #383) - shared by the round (morning-checks/index.php) and
 * Settings, so the two never describe a schedule differently.
 *
 * A schedule is { type: daily|weekdays|weekly|monthly, days: [1-7] (1 = Monday),
 * month_when: day|first_weekday|last_weekday|last_day, month_day: 1-31 }, exactly as
 * includes/morning_check_schedule.php stores and returns it. Whether a check is due is
 * decided on the server only; this file only words it and drives the form.
 *
 * Day names and dates come from tz.js (fmtNaiveWeekday / fmtNaiveDayMonth), so they follow
 * each person's language and date format.
 *
 * @wiki Morning-Check-Schedules-Developer-Guide
 */
(function () {
    'use strict';
    var T = function (k, p) { return window.t('morning-checks.schedule.' + k, p); };

    /** Y-m-d of ISO weekday n (1 = Monday) in a fixed week - for its name only. */
    function isoDay(n) { return '2026-11-' + String(15 + n).padStart(2, '0'); }   // 16 Nov 2026 is a Monday
    function dayName(n, short) { return window.fmtNaiveWeekday ? window.fmtNaiveWeekday(isoDay(n), short) : String(n); }

    /** "Mon 23 Nov". */
    function dateText(ymd) {
        if (!ymd) return '';
        return (window.fmtNaiveWeekday ? window.fmtNaiveWeekday(ymd, true) + ' ' : '') + (window.fmtNaiveDayMonth ? window.fmtNaiveDayMonth(ymd) : ymd);
    }

    /** One line for a schedule; '' for every day (nothing worth saying). */
    function summary(s) {
        if (!s || !s.type || s.type === 'daily') return '';
        if (s.type === 'weekdays') return T('sum_weekdays');
        if (s.type === 'weekly') return T('sum_weekly', { days: (s.days || []).map(function (d) { return dayName(d, true); }).join(', ') });
        if (s.type === 'monthly') {
            if (s.month_when === 'first_weekday') return T('sum_first_weekday');
            if (s.month_when === 'last_weekday') return T('sum_last_weekday');
            if (s.month_when === 'last_day') return T('sum_last_day');
            return T('sum_monthly_day', { n: s.month_day });
        }
        return '';
    }

    // ── The form (Settings' add and edit windows, prefix 'add' / 'edit') ────────
    function $(id) { return document.getElementById(id); }

    /** Builds the day ticks once, named in the person's language. */
    function init(p) {
        var box = $(p + 'SchedDays');
        if (!box || box.dataset.built) return;
        box.dataset.built = '1';
        var html = '';
        for (var n = 1; n <= 7; n++) {
            html += '<label class="mc-sched-day"><input type="checkbox" value="' + n + '"> ' + dayName(n, true) + '</label>';
        }
        box.innerHTML = html;
    }

    /** Shows the part of the form the chosen repeat needs. */
    function sync(p) {
        var type = $(p + 'SchedType').value;
        $(p + 'SchedDays').hidden = type !== 'weekly';
        $(p + 'SchedMonth').hidden = type !== 'monthly';
        $(p + 'SchedDayWrap').hidden = !(type === 'monthly' && $(p + 'SchedWhen').value === 'day');
    }

    /** Fills the form from a schedule (null = every day). */
    function fill(p, s) {
        init(p);
        s = s || { type: 'daily' };
        $(p + 'SchedType').value = s.type || 'daily';
        Array.prototype.forEach.call($(p + 'SchedDays').querySelectorAll('input'), function (cb) {
            cb.checked = (s.days || []).indexOf(Number(cb.value)) !== -1;
        });
        $(p + 'SchedWhen').value = s.month_when || 'day';
        $(p + 'SchedDay').value = String(s.month_day || 1);
        sync(p);
    }

    /** The schedule the form says, or null after saying what is missing. */
    function read(p) {
        var type = $(p + 'SchedType').value;
        if (type === 'weekly') {
            var days = Array.prototype.filter.call($(p + 'SchedDays').querySelectorAll('input'), function (cb) { return cb.checked; })
                .map(function (cb) { return Number(cb.value); });
            if (!days.length) { if (window.showToast) window.showToast(T('need_day'), 'error'); return null; }
            return { type: 'weekly', days: days };
        }
        if (type === 'monthly') {
            var when = $(p + 'SchedWhen').value;
            return when === 'day' ? { type: 'monthly', month_when: 'day', month_day: Number($(p + 'SchedDay').value) }
                                  : { type: 'monthly', month_when: when };
        }
        return { type: type };   // daily | weekdays
    }

    window.MCSchedule = { summary: summary, dateText: dateText, init: init, sync: sync, fill: fill, read: read };
})();
