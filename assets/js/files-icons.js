/**
 * Files — the icon set. Our own drawings, not Microsoft's: a manila folder, a
 * page with a coloured band saying what kind of file it is, and the desktop
 * app icons. Everything is inline SVG so it is crisp at any size and needs no
 * image requests.
 *
 *   FilesIcons.folder(px)            a folder
 *   FilesIcons.file(name, px)        a page for that file name's type
 *   FilesIcons.app(key, px)          desktop / start menu icons
 *   FilesIcons.type(name)            {key, label, colour} for a file name
 */
(function () {
    'use strict';

    // Extension -> kind. The kind decides colour, band label and the Type column.
    var KINDS = {
        pdf:     { colour: '#d93025', label: 'PDF',  ext: ['pdf'] },
        word:    { colour: '#2b579a', label: 'W',    ext: ['doc', 'docx', 'odt', 'rtf', 'dot', 'dotx'] },
        excel:   { colour: '#217346', label: 'X',    ext: ['xls', 'xlsx', 'xlsm', 'ods', 'csv', 'xlt', 'xltx'] },
        ppt:     { colour: '#c43e1c', label: 'P',    ext: ['ppt', 'pptx', 'odp', 'pps', 'ppsx'] },
        image:   { colour: '#8e44ad', label: 'IMG',  ext: ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'tif', 'tiff', 'heic', 'ico'] },
        video:   { colour: '#e67e22', label: 'VID',  ext: ['mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'm4v'] },
        audio:   { colour: '#16a085', label: 'AUD',  ext: ['mp3', 'wav', 'flac', 'ogg', 'm4a', 'aac', 'wma'] },
        archive: { colour: '#a67c00', label: 'ZIP',  ext: ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz', 'cab'] },
        app:     { colour: '#4f5bd5', label: 'EXE',  ext: ['exe', 'msi', 'msix', 'appx', 'bat', 'cmd', 'ps1', 'sh', 'dmg', 'pkg', 'deb', 'rpm', 'apk', 'jar'] },
        disk:    { colour: '#37474f', label: 'ISO',  ext: ['iso', 'img', 'vhd', 'vhdx', 'vmdk', 'ova', 'ovf', 'qcow2'] },
        text:    { colour: '#607d8b', label: 'TXT',  ext: ['txt', 'log', 'md', 'ini', 'cfg', 'conf'] },
        code:    { colour: '#455a64', label: '</>',  ext: ['html', 'htm', 'css', 'js', 'json', 'xml', 'php', 'py', 'sql', 'yml', 'yaml', 'ts', 'cs', 'java', 'c', 'cpp', 'h', 'go', 'rb'] },
        email:   { colour: '#0072c6', label: '@',    ext: ['eml', 'msg'] },
        cert:    { colour: '#b45309', label: 'KEY',  ext: ['pem', 'crt', 'cer', 'pfx', 'p12', 'key', 'der', 'csr'] }
    };
    var BY_EXT = {};
    Object.keys(KINDS).forEach(function (k) { KINDS[k].ext.forEach(function (e) { BY_EXT[e] = k; }); });

    function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    function type(name) {
        var m = /\.([A-Za-z0-9]{1,8})$/.exec(name || '');
        var ext = m ? m[1].toLowerCase() : '';
        var key = BY_EXT[ext] || 'other';
        var k = KINDS[key];
        return {
            key: key,
            ext: ext,
            label: k ? k.label : (ext ? ext.toUpperCase().slice(0, 4) : ''),
            colour: k ? k.colour : '#78909c'
        };
    }

    function folder(px) {
        px = px || 48;
        return '<svg class="fi fi-folder" width="' + px + '" height="' + px + '" viewBox="0 0 48 48" aria-hidden="true">' +
            '<path d="M4 10a3 3 0 0 1 3-3h11l4 4h19a3 3 0 0 1 3 3v3H4z" fill="#d9a21b"/>' +
            '<path d="M4 15a3 3 0 0 1 3-3h34a3 3 0 0 1 3 3v22a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3z" fill="#f5c342"/>' +
            '<path d="M4 15a3 3 0 0 1 3-3h34a3 3 0 0 1 3 3v2H4z" fill="#fbd96b"/>' +
            '</svg>';
    }

    function file(name, px) {
        px = px || 48;
        var t = type(name);
        if (px <= 24) {
            return '<svg class="fi fi-file" width="' + px + '" height="' + px + '" viewBox="0 0 24 24" aria-hidden="true">' +
                '<path d="M5 2h10l5 5v15H5z" fill="#fff" stroke="#9aa5b1"/>' +
                '<path d="M15 2v5h5" fill="#e5e9ee" stroke="#9aa5b1"/>' +
                '<rect x="7" y="12" width="11" height="7" rx="1" fill="' + t.colour + '"/>' +
                '</svg>';
        }
        var label = esc(t.label);
        var fs = label.length > 3 ? 7.5 : (label.length > 1 ? 9 : 12);
        return '<svg class="fi fi-file" width="' + px + '" height="' + px + '" viewBox="0 0 48 48" aria-hidden="true">' +
            '<path d="M10 3h20l10 10v32H10z" fill="#ffffff" stroke="#a3adb8" stroke-width="1.2"/>' +
            '<path d="M30 3v10h10" fill="#e8ecf0" stroke="#a3adb8" stroke-width="1.2" stroke-linejoin="round"/>' +
            '<line x1="15" y1="18" x2="35" y2="18" stroke="#d5dbe1" stroke-width="1.5"/>' +
            '<line x1="15" y1="22" x2="31" y2="22" stroke="#d5dbe1" stroke-width="1.5"/>' +
            '<rect x="6" y="27" width="30" height="14" rx="2" fill="' + t.colour + '"/>' +
            (label ? '<text x="21" y="37.5" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-weight="700" font-size="' + fs + '" fill="#fff">' + label + '</text>' : '') +
            '</svg>';
    }

    // Desktop / start menu icons: a rounded tile with a white glyph.
    var APPS = {
        documents:   ['#d9a21b', '<path d="M6 9a2 2 0 0 1 2-2h5l2 2h9a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2z" fill="#fff"/>'],
        recent:      ['#2563eb', '<circle cx="16" cy="16" r="9" fill="none" stroke="#fff" stroke-width="2.5"/><path d="M16 11v5.5l3.5 2" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/>'],
        search:      ['#0f766e', '<circle cx="14" cy="14" r="6.5" fill="none" stroke="#fff" stroke-width="2.6"/><path d="M19 19l6 6" stroke="#fff" stroke-width="2.8" stroke-linecap="round"/>'],
        transfers:   ['#7c3aed', '<path d="M11 24V9m0 0l-4 4m4-4l4 4M21 8v15m0 0l-4-4m4 4l4-4" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'],
        personalise: ['#db2777', '<circle cx="16" cy="16" r="9" fill="#fff"/><circle cx="12.5" cy="13" r="1.8" fill="#db2777"/><circle cx="17" cy="11" r="1.8" fill="#2563eb"/><circle cx="20.5" cy="15" r="1.8" fill="#16a34a"/><circle cx="14" cy="19.5" r="2.4" fill="#f59e0b"/>'],
        settings:    ['#475569', '<circle cx="16" cy="16" r="3.5" fill="none" stroke="#fff" stroke-width="2.4"/><path d="M16 6v3M16 23v3M6 16h3M23 16h3M8.9 8.9l2.1 2.1M21 21l2.1 2.1M8.9 23.1l2.1-2.1M21 11l2.1-2.1" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>'],
        help:        ['#0369a1', '<circle cx="16" cy="16" r="9.5" fill="none" stroke="#fff" stroke-width="2.4"/><path d="M13.2 13.2a3 3 0 1 1 4.3 2.7c-.9.4-1.5 1.1-1.5 2.1v.5" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><circle cx="16" cy="22" r="1.4" fill="#fff"/>'],
        properties:  ['#64748b', '<rect x="8" y="7" width="16" height="18" rx="2" fill="#fff"/><path d="M11.5 12h9M11.5 16h9M11.5 20h6" stroke="#64748b" stroke-width="1.8" stroke-linecap="round"/>'],
        permissions: ['#b45309', '<rect x="9" y="14" width="14" height="11" rx="2" fill="#fff"/><path d="M12 14v-3a4 4 0 0 1 8 0v3" fill="none" stroke="#fff" stroke-width="2.4"/>'],
        home:        ['#334155', '<path d="M7 15l9-8 9 8M10 13v11h12V13" fill="none" stroke="#fff" stroke-width="2.4" stroke-linejoin="round" stroke-linecap="round"/>'],
        signout:     ['#b91c1c', '<path d="M14 8H9a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h5M18 11l5 5-5 5M23 16H13" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>']
    };

    function app(key, px) {
        px = px || 44;
        var a = APPS[key] || APPS.documents;
        return '<svg class="fi fi-app" width="' + px + '" height="' + px + '" viewBox="0 0 32 32" aria-hidden="true">' +
            '<rect x="1" y="1" width="30" height="30" rx="7" fill="' + a[0] + '"/>' +
            '<rect x="1" y="1" width="30" height="15" rx="7" fill="#fff" opacity=".12"/>' + a[1] + '</svg>';
    }

    window.FilesIcons = { folder: folder, file: file, app: app, type: type };
})();
