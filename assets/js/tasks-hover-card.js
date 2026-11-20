/**
 * FreeITSM Tasks — hover card
 *
 * A formatted popover with a task's details, shown when the pointer rests on a
 * task in the Calendar or the Timeline. It replaces the plain title="" tooltip,
 * which showed one line of text and could not be formatted.
 *
 * Usage:
 *   TasksHoverCard.init({ root: element, selector: '.cal-bar', resolve: el => task });
 *   // markup: <div class="cal-bar" data-hc="${TasksHoverCard.ref(task)}">
 *   TasksHoverCard.hide();       // e.g. when a drag starts
 *   TasksHoverCard.suspend(bool) // no card while true (dragging)
 *
 * ref(task) hands back a key for a task object, so the card can show any object
 * (a real task or a projected repeat) without the page keeping its own lookup.
 *
 * Positioning: fixed to the viewport, below the pointer when it fits, otherwise
 * above, otherwise wherever there is more room, with its height capped to that
 * room (it scrolls inside itself). Always kept 8px inside the window edges.
 */
(function () {
    'use strict';

    const MARGIN = 8;      // gap kept from every viewport edge
    const OFFSET = 14;     // gap between the pointer and the card
    const SHOW_DELAY = 250;
    const HIDE_DELAY = 120;

    const registry = new Map();
    let nextKey = 1;
    let card = null;
    let showTimer = null, hideTimer = null;
    let currentEl = null;
    let lastX = 0, lastY = 0;
    let suspended = false;

    /** A translated string; the English fallback covers a page without i18n.js. */
    const T = (key, fb) => {
        const v = (typeof window.t === 'function') ? window.t(key) : null;
        return (v && v !== key) ? v : fb;
    };

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** Only a hex colour may go into a style attribute. */
    function colour(c, fb) {
        return (typeof c === 'string' && /^#[0-9a-f]{3,8}$/i.test(c)) ? c : fb;
    }

    function fmtDate(ds) {
        if (!ds) return '';
        const d = new Date(String(ds).slice(0, 10) + 'T00:00:00');
        if (isNaN(d.getTime())) return String(ds);
        try {
            if (typeof window.fmtNaiveTemplate === 'function') {
                const wd = typeof window.fmtNaiveWeekday === 'function' ? window.fmtNaiveWeekday(d).slice(0, 3) + ' ' : '';
                return wd + window.fmtNaiveTemplate(d, 'D MONTH YYYY');
            }
        } catch (e) { /* fall through */ }
        return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    }

    /**
     * Plain text from the (HTML) description, shortened.
     *
     * TRAP: parse with DOMParser, never innerHTML on an element of this page. An
     * element made by document.createElement belongs to the live document, so
     * even detached it loads <img src> and fires onerror - a description holding
     * one would run script on hover. DOMParser's document is inert: nothing loads
     * or runs. (Descriptions are sanitised on save; this must not depend on it.)
     */
    function excerpt(html, max) {
        if (!html) return '';
        const doc = new DOMParser().parseFromString(String(html).replace(/<(br|\/p|\/div|\/li)[^>]*>/gi, ' '), 'text/html');
        const txt = ((doc.body && doc.body.textContent) || '').replace(/\s+/g, ' ').trim();
        return txt.length > max ? txt.slice(0, max).replace(/\s+\S*$/, '') + '…' : txt;
    }

    function isOverdue(t) {
        if (!t.due_date || Number(t.status_is_closed) === 1) return false;
        const today = new Date();
        const ymd = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        return String(t.due_date).slice(0, 10) < ymd;
    }

    function row(label, valueHtml) {
        return valueHtml ? `<div class="thc-row"><span class="thc-label">${esc(label)}</span><span class="thc-value">${valueHtml}</span></div>` : '';
    }

    function build(t) {
        const sc = colour(t.status_colour, '#6b7280');
        const status = t.status || t.status_name || '';
        const id = t.projected ? t.source_task_id : t.id;
        const done = Number(t.status_is_closed) === 1;

        let head = '<div class="thc-head">';
        if (id) head += `<span class="thc-id">#${esc(id)}</span>`;
        if (status) head += `<span class="thc-status" style="--thc-st:${sc}">${esc(status)}</span>`;
        if (t.projected) head += `<span class="thc-flag">${esc(T('tasks.recur.projected', 'Upcoming repeat'))}</span>`;
        else if (t.recurrence_id) head += `<span class="thc-flag">${esc(T('tasks.hover.repeating', 'Repeats'))}</span>`;
        head += '</div>';

        let html = head + `<div class="thc-title${done ? ' is-done' : ''}">${esc(t.title || '')}</div>`;
        if (t.parent_task_id && t.parent_title) {
            html += `<div class="thc-parent">${esc(T('tasks.hover.subtask_of', 'Subtask of'))} <strong>${esc(t.parent_title)}</strong></div>`;
        }

        let rows = '';
        if (t.priority) {
            const pc = colour(t.priority_colour, '#6b7280');
            rows += row(T('tasks.detail.priority', 'Priority'), `<span class="thc-dot" style="background:${pc}"></span>${esc(t.priority)}`);
        }
        rows += row(T('tasks.detail.assignee', 'Assignee'), t.analyst_name ? esc(t.analyst_name) : `<span class="thc-muted">${esc(T('tasks.detail.unassigned', 'Unassigned'))}</span>`);
        if (t.team_name) rows += row(T('tasks.detail.team', 'Team'), esc(t.team_name));
        if (t.project_name) {
            const pc = colour(t.project_colour, '#e11d48');
            rows += row(T('tasks.detail.project', 'Project'), `<span class="thc-dot" style="background:${pc}"></span>${esc(t.project_name)}${t.project_stage_name ? ' <span class="thc-muted">· ' + esc(t.project_stage_name) + '</span>' : ''}`);
        }
        if (t.supplier_name) rows += row(T('tasks.detail.contractor', 'Contractor'), esc(t.supplier_name) + (t.contact_name ? ' <span class="thc-muted">· ' + esc(t.contact_name) + '</span>' : ''));
        if (t.start_date) rows += row(T('tasks.detail.start_date', 'Start Date'), esc(fmtDate(t.start_date)));
        if (t.due_date) {
            rows += row(T('tasks.detail.due_date', 'Due Date'), esc(fmtDate(t.due_date)) +
                (isOverdue(t) ? ` <span class="thc-overdue">${esc(T('tasks.board.overdue', 'Overdue'))}</span>` : ''));
        }
        if (t.subtasks && t.subtasks.total > 0) {
            const pct = Math.round(t.subtasks.done / t.subtasks.total * 100);
            rows += row(T('tasks.detail.subtasks', 'Subtasks'),
                `<span class="thc-bar"><span style="width:${pct}%"></span></span>${esc(t.subtasks.done)}/${esc(t.subtasks.total)}`);
        }
        if (rows) html += `<div class="thc-rows">${rows}</div>`;

        if (t.tags && t.tags.length) {
            html += '<div class="thc-tags">' + t.tags.map(tg =>
                `<span class="thc-tag" style="--thc-tg:${colour(tg.colour, '#6b7280')}">${esc(tg.name)}</span>`).join('') + '</div>';
        }

        const desc = excerpt(t.description, 220);
        if (desc) html += `<div class="thc-desc">${esc(desc)}</div>`;
        return html;
    }

    function ensureCard() {
        if (card) return card;
        card = document.createElement('div');
        card.className = 'task-hover-card';
        card.setAttribute('role', 'tooltip');
        card.addEventListener('mouseenter', () => clearTimeout(hideTimer));
        card.addEventListener('mouseleave', scheduleHide);
        document.body.appendChild(card);
        return card;
    }

    /** Places the card next to (x, y), keeping it fully inside the viewport. */
    function position(x, y, anchorRect) {
        const vw = document.documentElement.clientWidth;
        const vh = document.documentElement.clientHeight;
        card.style.maxHeight = '';
        card.style.left = '0px';
        card.style.top = '0px';
        const w = card.offsetWidth;
        let h = card.offsetHeight;

        // Vertical: below the anchor (or pointer), else above, else the larger side.
        const below = anchorRect ? anchorRect.bottom + 6 : y + OFFSET;
        const above = anchorRect ? anchorRect.top - 6 : y - OFFSET;
        const roomBelow = vh - MARGIN - below;
        const roomAbove = above - MARGIN;
        let top;
        if (h <= roomBelow) top = below;
        else if (h <= roomAbove) top = above - h;
        else if (roomBelow >= roomAbove) {
            card.style.maxHeight = Math.max(80, roomBelow) + 'px'; h = card.offsetHeight; top = below;
        } else {
            card.style.maxHeight = Math.max(80, roomAbove) + 'px'; h = card.offsetHeight; top = above - h;
        }
        top = Math.max(MARGIN, Math.min(top, vh - MARGIN - h));

        // Horizontal: start at the pointer, slide left to stay inside.
        let left = x - 16;
        left = Math.max(MARGIN, Math.min(left, vw - MARGIN - w));

        card.style.left = Math.round(left) + 'px';
        card.style.top = Math.round(top) + 'px';
    }

    let resolvers = [];

    function resolveFor(el) {
        for (const r of resolvers) {
            if (el.closest(r.selector) === el && (!r.root || r.root.contains(el))) {
                const t = r.resolve(el);
                if (t) return t;
            }
        }
        return null;
    }

    function show(el) {
        const t = resolveFor(el);
        if (!t) return;
        const c = ensureCard();
        c.innerHTML = build(t);
        c.scrollTop = 0;
        c.classList.add('is-visible');
        // Anchor vertically on the element when it is short (a calendar bar),
        // on the pointer when it is tall.
        const r = el.getBoundingClientRect();
        position(lastX, lastY, r.height <= 40 ? r : null);
    }

    function hide() {
        clearTimeout(showTimer);
        clearTimeout(hideTimer);
        currentEl = null;
        if (card) card.classList.remove('is-visible');
    }

    function scheduleHide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(hide, HIDE_DELAY);
    }

    function matchTarget(target) {
        if (!target || !target.closest) return null;
        for (const r of resolvers) {
            const el = target.closest(r.selector);
            if (el && (!r.root || r.root.contains(el))) return el;
        }
        return null;
    }

    function onOver(e) {
        // No card while a button is held: a timeline bar is being dragged.
        if (suspended || e.buttons) return;
        const el = matchTarget(e.target);
        if (!el) return;
        lastX = e.clientX; lastY = e.clientY;
        clearTimeout(hideTimer);
        if (el === currentEl) return;
        currentEl = el;
        clearTimeout(showTimer);
        const visible = card && card.classList.contains('is-visible');
        showTimer = setTimeout(() => { if (currentEl === el && !suspended) show(el); }, visible ? 60 : SHOW_DELAY);
    }

    function onMove(e) { lastX = e.clientX; lastY = e.clientY; }

    function onOut(e) {
        const el = matchTarget(e.target);
        if (!el) return;
        const to = e.relatedTarget;
        if (to && (el.contains(to) || (card && card.contains(to)))) return;
        clearTimeout(showTimer);
        currentEl = null;
        scheduleHide();
    }

    let wired = false;
    function wire() {
        if (wired) return;
        wired = true;
        document.addEventListener('mouseover', onOver);
        document.addEventListener('mousemove', onMove, { passive: true });
        document.addEventListener('mouseout', onOut);
        // Anything that moves the page or starts an action hides the card.
        document.addEventListener('mousedown', e => { if (!card || !card.contains(e.target)) hide(); }, true);
        window.addEventListener('scroll', hide, true);
        window.addEventListener('resize', hide);
        document.addEventListener('keydown', e => { if (e.key === 'Escape') hide(); });
    }

    window.TasksHoverCard = {
        init(opts) {
            resolvers.push({ root: opts.root || null, selector: opts.selector, resolve: opts.resolve || (el => registry.get(el.dataset.hc)) });
            wire();
        },
        /** A key for this task object, for a data-hc attribute. */
        ref(task) {
            if (!task) return '';
            if (!task.__hc) {
                Object.defineProperty(task, '__hc', { value: 'h' + (nextKey++), enumerable: false });
            }
            if (registry.size > 20000) registry.clear();
            registry.set(task.__hc, task);
            return task.__hc;
        },
        hide,
        suspend(on) { suspended = !!on; if (on) hide(); },
    };
})();
