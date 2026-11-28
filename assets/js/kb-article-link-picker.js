/**
 * The Knowledge article browser behind the folder icon beside URL in TinyMCE's
 * Link dialog. Used by the Knowledge editor and by a task's description.
 *
 * It searches api/knowledge/knowledge_articles.php, the same permission-filtered
 * list the Knowledge browser shows, so only articles the analyst may read appear.
 * Needs window.t with knowledge.editor.link_picker_* and common.cancel.
 */
let kbArticleLinkPicker = null;

/**
 * Escapes text for HTML, quotes included. Its own copy because tasks.js has no
 * escapeHtml; never the textContent -> innerHTML trick, which leaves quotes
 * alone (the 3.14.0 escape-helper fix).
 *
 * @param {*} s  the text to show
 */
const kbLinkPickerEsc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

/** Keep the picker inside TinyMCE so its Link dialog does not trap keyboard focus. */
function kbArticleLinkDialogSpec(picker, items, status) {
    return {
        title: window.t('knowledge.editor.link_picker_title'),
        size: 'large',
        body: { type: 'panel', items: [
            { type: 'input', name: 'search', label: window.t('knowledge.editor.link_picker_search') },
            { type: 'htmlpanel', html: '<p role="status">' + kbLinkPickerEsc(status) + '</p>' },
            { type: 'tree', items: items, onLeafAction: id => kbChooseArticleLink(picker, id) }
        ] },
        initialData: { search: picker.query },
        buttons: [{ type: 'cancel', text: window.t('common.cancel') }],
        onChange: function(dialog, details) {
            if (details.name === 'search') kbScheduleArticleLinkSearch(picker, dialog.getData().search);
        },
        onClose: function() {
            clearTimeout(picker.timer);
            picker.request++;
            if (kbArticleLinkPicker === picker) kbArticleLinkPicker = null;
        }
    };
}

/**
 * Opens the browser from TinyMCE's file_picker_callback.
 *
 * @param {object}   editor    the TinyMCE editor whose Link dialog asked
 * @param {Function} callback  TinyMCE's picker callback, given the chosen link
 * @param {object}   meta      TinyMCE's picker meta; meta.text is display text already typed
 * @param {object}   opts      api: URL of api/knowledge/; href(article): the link to insert;
 *                             place(article), optional: the folder shown after the title
 */
function kbOpenArticleLinkPicker(editor, callback, meta, opts) {
    const picker = { callback: callback, meta: meta, opts: opts, query: '', articles: [], request: 0, timer: null, dialog: null };
    kbArticleLinkPicker = picker;
    picker.dialog = editor.windowManager.open(
        kbArticleLinkDialogSpec(picker, [], window.t('knowledge.editor.link_picker_loading')));
    picker.dialog.focus('search');
    kbSearchArticleLinks(picker);
}

function kbScheduleArticleLinkSearch(picker, query) {
    clearTimeout(picker.timer);
    picker.request++;
    picker.query = query.trim();
    picker.timer = setTimeout(() => kbSearchArticleLinks(picker), 250);
}

async function kbSearchArticleLinks(picker) {
    const request = ++picker.request;
    try {
        const response = await fetch(picker.opts.api + 'knowledge_articles.php?limit=40&search=' + encodeURIComponent(picker.query));
        if (!response.ok) throw new Error('Article search failed');
        const data = await response.json();
        if (!data.success || !Array.isArray(data.articles)) throw new Error('Article search failed');
        if (request !== picker.request || kbArticleLinkPicker !== picker) return;
        picker.articles = data.articles.filter(article => Number.isSafeInteger(Number(article.id)) && Number(article.id) > 0);
        const items = picker.articles.map(article => {
            const place = picker.opts.place ? picker.opts.place(article) : '';
            return { type: 'leaf', id: String(article.id), title: place ? article.title + ' — ' + place : article.title };
        });
        picker.dialog.redial(kbArticleLinkDialogSpec(picker, items, window.t(items.length
            ? 'knowledge.editor.link_picker_count' : 'knowledge.editor.link_picker_empty', { count: items.length })));
        picker.dialog.focus('search');
    } catch (error) {
        if (request !== picker.request || kbArticleLinkPicker !== picker) return;
        picker.articles = [];
        picker.dialog.redial(kbArticleLinkDialogSpec(picker, [], window.t('knowledge.editor.link_picker_error')));
        picker.dialog.focus('search');
    }
}

/** Hand TinyMCE the link and preserve display text already entered there. */
function kbChooseArticleLink(picker, id) {
    const article = picker.articles.find(item => String(item.id) === String(id));
    if (!article || kbArticleLinkPicker !== picker) return;
    const text = typeof picker.meta?.text === 'string' && picker.meta.text.trim()
        ? picker.meta.text : article.title;
    picker.dialog.close();
    picker.callback(picker.opts.href(article), { text: text });
}
