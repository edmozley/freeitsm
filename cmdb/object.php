<?php
/**
 * CMDB Object Detail Page — PROTOTYPE (v2 layout)
 *
 * A side-by-side alternative to object.php, built to test whether the CMDB
 * can lead with answers rather than fields. Same object, same endpoints, same
 * data — different information architecture:
 *
 *   - a hero band with the class icon (cmdb_icons has always had one per
 *     class; object.php never rendered it) and the object's own signal colour
 *   - a strip of headline numbers, so "what does this thing matter to?" is
 *     answered before any scrolling
 *   - the blast radius drawn as a left-to-right chain of hops instead of a
 *     grouped list
 *   - Impact / Map / Hierarchy / Relationships merged into ONE connections
 *     panel, because they are four framings of a single question and on a
 *     sparse object they produced four separate empty states
 *   - properties with a value shown first; blank ones collapsed behind a
 *     single line rather than each occupying a row saying "(not set)"
 *   - Delete demoted out of the header into a danger zone at the foot
 *
 * ⚠️ Merging those four panels for READING broke the mapping to WRITING: the
 * four kinds are created four different ways, so the connection tally counts
 * by KIND (descendants / relationships / property links / parent) rather than
 * by direction, and each kind carries its own control. A single
 * "+ Add relationship" button over the merged panel implied it could create
 * all four when it can only ever create one.
 *
 * Editing is at full parity with object.php, through the same endpoints:
 * name, parent (set/change/detach), planned toggle, relationship add/remove,
 * every property type including object_ref, and the property-DEFINITION modal
 * (reusing the shared options-editor.js). The add-relationship modal goes one
 * further with a direction switch — object.php can only create outgoing
 * relationships, so "SolarWinds monitors this" cannot be recorded from the
 * server's own page there.
 *
 * Strings are English-only on purpose — this is a prototype for a layout
 * decision, and translating ~70 keys into two locales before that decision is
 * made would be waste. If this layout wins, i18n comes with the merge.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('cmdb');

// Same rule as object.php: the diagram hand-off is a Network Mapper right, so
// the button is decided server-side and never rendered for someone the
// endpoint would refuse.
$canMakeDiagram = analystCanAccessModule(connectToDatabase(), (int) $_SESSION['analyst_id'], 'network-mapper');

// The recent trail (#124). Server-side here, rather than the JS ping the other
// modules use, because a CI IS its own page — opening one is a real navigation
// with a real URL, so the moment it is recorded is this one.
require_once '../includes/recent_trail.php';
entityVisit('cmdb_object', (int) ($_GET['id'] ?? 0));

$current_page = 'browse';
$translationNamespaces = ['common', 'cmdb'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM - <?php echo htmlspecialchars(t('cmdb.title')); ?> (v2)</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=62">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        /* ------------------------------------------------------------------
           Motion tokens. The built-in CSS easings are too soft to read as
           intentional; these are the stronger variants. Everything animated
           below is transform/opacity only so it stays off the layout path.
           ------------------------------------------------------------------ */
        :root {
            --o2-ease-out: cubic-bezier(0.23, 1, 0.32, 1);
            --o2-ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);
        }

        body { --accent: var(--cmdb-accent); background: var(--app-bg, #f5f5f5); }

        .o2-page {
            height: calc(100vh - 48px);
            overflow-y: auto;
            padding: 0 24px 60px;
        }
        /* Full-bleed: no centring column. Only the AI prose keeps a measure,
           because a 2000px-wide line of text is unreadable however wide the
           monitor is. */
        .o2-wrap { max-width: none; width: 100%; margin: 0; }

        /* Cards fade up in sequence. Stagger is short (45ms) — long enough to
           read as a cascade, short enough that the page is never waiting.

           ⚠️ The resting state is VISIBLE and the hidden state lives in the
           keyframe's `from`, with fill-mode `backwards`. The obvious way round
           — opacity:0 in the rule, `forwards` to hold the end state — makes
           every section depend on its animation actually completing, and a
           page that renders blank when animation does not run is a bad trade
           for a fade. */
        .o2-secs > .o2-sec {
            animation: o2In 320ms var(--o2-ease-out) backwards;
        }
        @keyframes o2In { from { opacity: 0; transform: translateY(10px); } }
        /* The stagger stops climbing after the fifth section. Left to run over
           all eight it would be ~640ms before the page settled, and a cascade
           that outlasts the reader's attention stops reading as polish. */
        .o2-secs > .o2-sec:nth-child(1) { animation-delay: 0ms; }
        .o2-secs > .o2-sec:nth-child(2) { animation-delay: 35ms; }
        .o2-secs > .o2-sec:nth-child(3) { animation-delay: 70ms; }
        .o2-secs > .o2-sec:nth-child(4) { animation-delay: 105ms; }
        .o2-secs > .o2-sec:nth-child(n+5) { animation-delay: 140ms; }

        .o2-breadcrumb { font-size: 13px; color: var(--text-muted, #6b7280); padding: 14px 2px 10px; }
        .o2-breadcrumb a { color: var(--cmdb-accent, #be185d); text-decoration: none; }
        .o2-breadcrumb a:hover { text-decoration: underline; }
        .o2-breadcrumb .sep { margin: 0 7px; color: var(--text-faint, #d1d5db); }
        .o2-breadcrumb .here { color: var(--text, #111827); font-weight: 600; }

        /* ---------------- Hero ---------------- */
        .o2-hero {
            position: relative;
            background: var(--surface, #ffffff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 14px;
            padding: 22px 24px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 20px;
            overflow: hidden;
        }
        /* The signal rail. Colour comes from the object's own data (a coloured
           dropdown value, or a severity word), so a Critical CI does not look
           identical to a decommissioned wall switch. */
        .o2-hero::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 5px;
            background: var(--o2-signal, var(--cmdb-accent, #be185d));
        }
        .o2-hero.is-planned {
            border-style: dashed;
            border-color: var(--warning-border, #fcd34d);
        }

        .o2-hero-icon {
            flex: 0 0 auto;
            width: 76px; height: 76px;
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            color: var(--o2-signal, var(--cmdb-accent, #be185d));
            background: var(--cmdb-accent-soft, #fdf2f8);
            border: 1px solid var(--border-soft, #f1f2f4);
        }
        .o2-hero-main { flex: 1 1 auto; min-width: 0; }

        .o2-name {
            font-size: 30px;
            font-weight: 650;
            letter-spacing: -0.02em;
            color: var(--text, #111827);
            border: 1px solid transparent;
            border-radius: 6px;
            padding: 2px 7px;
            margin: 0 0 7px -7px;
            width: 100%;
            background: transparent;
            font-family: inherit;
            display: block;
            transition: background-color 140ms ease, border-color 140ms ease;
        }
        .o2-name:hover { background: var(--surface-2, #fafafa); }
        .o2-name:focus { background: var(--surface, #fff); border-color: var(--cmdb-accent, #be185d); outline: none; }

        .o2-chips { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .o2-chip {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 600;
            padding: 4px 11px;
            border-radius: 999px;
            border: 1px solid var(--border, #e5e7eb);
            color: var(--text-muted, #6b7280);
            background: var(--surface-2, #fafafa);
            white-space: nowrap;
        }
        .o2-chip.class {
            color: var(--cmdb-accent, #be185d);
            background: var(--cmdb-accent-soft, #fdf2f8);
            border-color: transparent;
        }
        .o2-chip.signal { color: #fff; border-color: transparent; background: var(--o2-signal, var(--cmdb-accent, #be185d)); }
        .o2-chip.planned { color: var(--warning-text, #92400e); background: var(--warning-bg, #fffbeb); border-color: var(--warning-border, #fcd34d); }
        .o2-chip.stale { color: var(--warning-text, #92400e); background: var(--warning-bg, #fffbeb); border-color: var(--warning-border, #fcd34d); }
        .o2-chip a { color: inherit; text-decoration: none; }
        .o2-chip a:hover { text-decoration: underline; }

        .o2-hero-actions { flex: 0 0 auto; display: flex; flex-direction: column; gap: 8px; }

        /* ---------------- Buttons ---------------- */
        .o2-btn {
            display: inline-flex; align-items: center; gap: 7px;
            font: inherit; font-size: 13px; font-weight: 600;
            padding: 9px 15px;
            border-radius: 8px;
            border: 1px solid var(--border, #e5e7eb);
            background: var(--surface, #fff);
            color: var(--text, #111827);
            cursor: pointer;
            white-space: nowrap;
            transition: transform 160ms var(--o2-ease-out), background-color 140ms ease, border-color 140ms ease;
        }
        .o2-btn:active { transform: scale(0.97); }
        .o2-btn.primary {
            background: var(--cmdb-accent, #be185d);
            border-color: transparent;
            color: var(--cmdb-on-accent, #fff);
        }
        .o2-btn.danger { color: var(--danger-text, #b91c1c); border-color: var(--danger-border, #fecaca); }
        .o2-btn[disabled] { opacity: 0.55; cursor: default; }
        .o2-btn[disabled]:active { transform: none; }
        @media (hover: hover) and (pointer: fine) {
            .o2-btn:hover { background: var(--surface-hover, #f3f4f6); }
            .o2-btn.primary:hover { background: var(--cmdb-accent-hover, #9d174d); }
            .o2-btn.danger:hover { background: var(--danger-bg, #fef2f2); }
        }

        /* ---------------- Stat strip ---------------- */
        .o2-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 14px;
        }
        .o2-stat {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 12px;
            padding: 16px 18px;
            text-align: left;
            font: inherit;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: transform 180ms var(--o2-ease-out), border-color 140ms ease;
        }
        .o2-stat:active { transform: scale(0.985); }
        @media (hover: hover) and (pointer: fine) {
            .o2-stat:hover { transform: translateY(-2px); border-color: var(--cmdb-accent, #be185d); }
        }
        .o2-stat.is-quiet { cursor: default; }
        .o2-stat.is-quiet:active { transform: none; }
        @media (hover: hover) and (pointer: fine) {
            .o2-stat.is-quiet:hover { transform: none; border-color: var(--border, #e5e7eb); }
        }
        .o2-stat-val {
            font-size: 34px;
            font-weight: 680;
            line-height: 1.05;
            letter-spacing: -0.03em;
            color: var(--text, #111827);
            font-variant-numeric: tabular-nums;
        }
        .o2-stat-val .unit { font-size: 16px; font-weight: 600; color: var(--text-muted, #6b7280); margin-left: 2px; }
        .o2-stat-lbl {
            font-size: 12px;
            color: var(--text-muted, #6b7280);
            margin-top: 5px;
            line-height: 1.35;
        }
        .o2-stat.hot .o2-stat-val { color: var(--danger-text, #b91c1c); }
        .o2-stat.warm .o2-stat-val { color: var(--warning-text, #92400e); }
        .o2-stat.zero .o2-stat-val { color: var(--text-faint, #d1d5db); }

        /* ---------------- Section card ---------------- */
        .o2-card {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 14px;
            padding: 18px 20px 20px;
            margin-bottom: 14px;
        }
        .o2-card-head {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 14px;
        }
        .o2-card-title {
            font-size: 12px; font-weight: 700;
            letter-spacing: 0.07em; text-transform: uppercase;
            color: var(--text-muted, #6b7280);
        }
        .o2-card-sub { font-size: 13px; color: var(--text-dim, #9ca3af); margin-left: auto; }
        .o2-lede {
            font-size: 17px; font-weight: 600;
            color: var(--text, #111827);
            margin-bottom: 14px;
            letter-spacing: -0.01em;
        }
        .o2-empty {
            font-size: 13px;
            color: var(--text-dim, #9ca3af);
            padding: 4px 0;
        }

        /* ---------------- Blast chain ---------------- */
        .o2-chain { display: flex; align-items: stretch; gap: 0; overflow-x: auto; padding: 4px 0 8px; }
        .o2-hop { flex: 0 0 auto; min-width: 168px; }
        .o2-hop-lbl {
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
            color: var(--text-dim, #9ca3af);
            margin-bottom: 8px; padding-left: 2px;
        }
        .o2-hop-items { display: flex; flex-direction: column; gap: 8px; }
        .o2-arrow {
            flex: 0 0 auto;
            width: 34px;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-faint, #d1d5db);
            padding-top: 22px;
        }
        .o2-node {
            display: flex; align-items: center; gap: 9px;
            padding: 9px 11px;
            border-radius: 10px;
            border: 1px solid var(--border, #e5e7eb);
            background: var(--surface-2, #fafafa);
            text-decoration: none;
            color: inherit;
            transition: transform 180ms var(--o2-ease-out), border-color 140ms ease;
        }
        .o2-node:active { transform: scale(0.98); }
        @media (hover: hover) and (pointer: fine) {
            .o2-node:hover { transform: translateY(-1px); border-color: var(--cmdb-accent, #be185d); }
        }
        .o2-node.root {
            background: var(--cmdb-accent, #be185d);
            border-color: transparent;
            color: var(--cmdb-on-accent, #fff);
        }
        .o2-node-ico { flex: 0 0 auto; display: flex; color: var(--cmdb-accent, #be185d); }
        .o2-node.root .o2-node-ico { color: var(--cmdb-on-accent, #fff); }
        /* These are spans (they sit inside an <a>), so they need to be told to
           stack — otherwise the name and the class render as one word. */
        .o2-node-txt { min-width: 0; display: flex; flex-direction: column; }
        .o2-node-name {
            display: block;
            font-size: 13px; font-weight: 600;
            color: var(--text, #111827);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .o2-node.root .o2-node-name { color: var(--cmdb-on-accent, #fff); }
        .o2-node-sub { display: block; font-size: 11px; color: var(--text-dim, #9ca3af); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .o2-node.root .o2-node-sub { color: var(--cmdb-on-accent, #fff); opacity: 0.8; }
        .o2-via { font-size: 11px; color: var(--text-dim, #9ca3af); font-style: italic; margin-top: 3px; padding-left: 2px; }

        /* ---------------- Connections ---------------- */
        /* The always-on count row. Every figure renders, zeroes included — a
           count of nothing is a fact, and the reader cannot tell it apart from
           a panel that failed to load if it is simply absent. */
        .o2-tally {
            display: flex; flex-wrap: wrap; gap: 6px 22px;
            padding: 0 0 16px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--border-soft, #f1f2f4);
        }
        .o2-tally-item {
            font-size: 12px;
            color: var(--text-muted, #6b7280);
            letter-spacing: 0.02em;
        }
        .o2-tally-item b {
            font-size: 15px;
            font-weight: 680;
            color: var(--text, #111827);
            font-variant-numeric: tabular-nums;
            margin-right: 2px;
        }
        .o2-tally-item.zero b { color: var(--text-faint, #d1d5db); }
        /* Each kind carries its own way in, because each is created a different
           way. One button above the row implied it covered all four. */
        .o2-tally-act {
            font: inherit; font-size: 11px; font-weight: 600;
            background: none; cursor: pointer;
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 999px;
            padding: 2px 9px;
            margin-left: 8px;
            color: var(--cmdb-accent, #be185d);
            transition: transform 160ms var(--o2-ease-out), background-color 140ms ease, border-color 140ms ease;
        }
        .o2-tally-act:active { transform: scale(0.96); }
        @media (hover: hover) and (pointer: fine) {
            .o2-tally-act:hover { background: var(--cmdb-accent-soft, #fdf2f8); border-color: var(--cmdb-accent, #be185d); }
        }

        /* Segmented direction switch in the add-relationship modal. */
        .o2-seg { display: flex; gap: 0; }
        .o2-seg button {
            flex: 1 1 0;
            font: inherit; font-size: 13px; font-weight: 600;
            padding: 9px 10px;
            border: 1px solid var(--border, #e5e7eb);
            background: var(--surface, #fff);
            color: var(--text-muted, #6b7280);
            cursor: pointer;
            transition: background-color 140ms ease, color 140ms ease, border-color 140ms ease;
        }
        .o2-seg button:first-child { border-radius: 8px 0 0 8px; }
        .o2-seg button:last-child { border-radius: 0 8px 8px 0; border-left-width: 0; }
        .o2-seg button.on {
            background: var(--cmdb-accent, #be185d);
            border-color: var(--cmdb-accent, #be185d);
            color: var(--cmdb-on-accent, #fff);
        }
        .o2-seg button.on + button { border-left-width: 1px; }

        /* The sentence that will actually be stored, spelled out both ways. */
        .o2-preview {
            background: var(--surface-2, #fafafa);
            border: 1px solid var(--border-soft, #f1f2f4);
            border-radius: 8px;
            padding: 11px 13px;
            font-size: 13.5px;
            line-height: 1.7;
            color: var(--text-muted, #6b7280);
        }
        .o2-preview b { color: var(--text, #111827); font-weight: 650; }

        .o2-conn { display: grid; grid-template-columns: 1fr auto 1fr; gap: 18px; align-items: start; }
        .o2-conn-col { min-width: 0; }
        .o2-conn-col.mid { display: flex; flex-direction: column; align-items: center; gap: 10px; padding-top: 22px; }
        .o2-conn-lbl {
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
            color: var(--text-dim, #9ca3af);
            margin-bottom: 9px;
        }
        .o2-conn-col.right .o2-conn-lbl { text-align: right; }
        .o2-conn-list { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
        .o2-conn-col.right .o2-conn-list { align-items: flex-end; }
        /* Full-bleed means these columns can get very wide; a card stretched to
           1000px for one word reads as broken rather than spacious. */
        .o2-conn-list > div { width: 100%; max-width: 380px; }
        .o2-conn-col.right .o2-conn-list > div { display: flex; flex-direction: column; align-items: stretch; }
        .o2-centre {
            display: flex; flex-direction: column; align-items: center; gap: 7px;
            padding: 14px 20px;
            border-radius: 14px;
            background: var(--cmdb-accent, #be185d);
            color: var(--cmdb-on-accent, #fff);
            min-width: 150px;
        }
        .o2-centre-name { font-size: 14px; font-weight: 650; text-align: center; }
        .o2-centre-cls { font-size: 11px; opacity: 0.8; }
        .o2-rel-verb { font-size: 11px; color: var(--text-dim, #9ca3af); font-style: italic; margin-bottom: 2px; }
        .o2-conn-col.right .o2-rel-verb { text-align: right; }
        .o2-updown { display: flex; flex-direction: column; align-items: center; gap: 6px; }

        /* ---------------- Properties ---------------- */
        .o2-props { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 10px; }
        .o2-prop {
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 10px;
            padding: 11px 13px;
            background: var(--surface-2, #fafafa);
            transition: border-color 140ms ease;
        }
        @media (hover: hover) and (pointer: fine) {
            .o2-prop:hover { border-color: var(--cmdb-accent, #be185d); }
        }
        .o2-prop-lbl {
            font-size: 11px; font-weight: 600; letter-spacing: 0.03em;
            color: var(--text-muted, #6b7280);
            text-transform: uppercase;
            margin-bottom: 5px;
            display: flex; align-items: center; gap: 5px;
        }
        .o2-prop-lbl .req { color: var(--danger-text, #b91c1c); }
        .o2-prop-val {
            font-size: 15px; font-weight: 600;
            color: var(--text, #111827);
            word-break: break-word;
            cursor: text;
            border-radius: 4px;
            min-height: 22px;
        }
        .o2-prop-val input, .o2-prop-val select {
            width: 100%; font: inherit; font-size: 15px;
            padding: 1px 4px; margin: -1px -4px;
            border: 1px solid var(--cmdb-accent, #be185d);
            border-radius: 4px;
            background: var(--surface, #fff);
            color: var(--text, #111827);
        }
        .o2-pill {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 13px; font-weight: 600;
            background: var(--surface-3, #f3f4f6);
            color: var(--text, #111827);
        }
        .o2-ref {
            display: inline-flex; align-items: center; gap: 6px;
            color: var(--cmdb-accent, #be185d);
            text-decoration: none;
            font-size: 14px; font-weight: 600;
        }
        .o2-ref:hover { text-decoration: underline; }

        .o2-blanks {
            margin-top: 12px;
            font-size: 13px;
            color: var(--text-dim, #9ca3af);
        }
        .o2-blanks button {
            font: inherit; font-size: 13px;
            background: none; border: none; padding: 0;
            color: var(--cmdb-accent, #be185d);
            cursor: pointer;
            text-decoration: underline;
        }
        .o2-meter {
            height: 5px; border-radius: 3px;
            background: var(--surface-3, #f3f4f6);
            overflow: hidden;
            width: 120px;
            margin-left: auto;
        }
        .o2-meter i {
            display: block; height: 100%;
            background: var(--cmdb-accent, #be185d);
            border-radius: 3px;
            transform-origin: left center;
            transition: transform 420ms var(--o2-ease-out);
        }

        /* ---------------- AI summary ---------------- */
        .o2-ai {
            background: var(--cmdb-accent-soft, #fdf2f8);
            border: 1px solid var(--border-soft, #f1f2f4);
            border-radius: 14px;
            padding: 16px 20px;
            margin-bottom: 14px;
            display: flex; gap: 14px; align-items: flex-start;
        }
        .o2-ai-ico { color: var(--cmdb-accent, #be185d); flex: 0 0 auto; margin-top: 1px; }
        .o2-ai-txt { flex: 1 1 auto; font-size: 14.5px; line-height: 1.6; color: var(--text, #111827); max-width: 100ch; }
        .o2-ai-txt.muted { color: var(--text-muted, #6b7280); font-style: italic; }
        .o2-ai-when { font-size: 11px; color: var(--text-dim, #9ca3af); margin-top: 6px; }

        /* ---------------- Tickets ---------------- */
        .o2-tix { display: flex; flex-direction: column; gap: 8px; }
        .o2-tik {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 13px;
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 10px;
            text-decoration: none; color: inherit;
            background: var(--surface-2, #fafafa);
            transition: transform 180ms var(--o2-ease-out), border-color 140ms ease;
        }
        .o2-tik:active { transform: scale(0.99); }
        @media (hover: hover) and (pointer: fine) {
            .o2-tik:hover { transform: translateY(-1px); border-color: var(--cmdb-accent, #be185d); }
        }
        .o2-tik-ref { font-size: 12px; font-weight: 700; color: var(--cmdb-accent, #be185d); font-variant-numeric: tabular-nums; }
        .o2-tik-sub { font-size: 14px; font-weight: 600; color: var(--text, #111827); flex: 1 1 auto; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .o2-tik-meta { font-size: 11px; color: var(--text-dim, #9ca3af); white-space: nowrap; }

        /* ---------------- Danger zone ---------------- */
        .o2-danger {
            display: flex; align-items: center; gap: 14px;
            border: 1px dashed var(--danger-border, #fecaca);
            border-radius: 12px;
            padding: 14px 18px;
            margin-top: 22px;
            background: transparent;
        }
        .o2-danger-txt { font-size: 13px; color: var(--text-muted, #6b7280); flex: 1 1 auto; }

        /* Toasts and confirms are the SHARED components (assets/js/toast.js and
           confirm.js), pulled in by includes/header.php via the waffle menu.
           Nothing bespoke here on purpose. */

        /* ---------------- Editing affordances ---------------- */
        .o2-chip.act {
            font: inherit; font-size: 12px; font-weight: 600;
            cursor: pointer;
            transition: transform 160ms var(--o2-ease-out), background-color 140ms ease, border-color 140ms ease;
        }
        .o2-chip.act:active { transform: scale(0.97); }
        @media (hover: hover) and (pointer: fine) {
            .o2-chip.act:hover { border-color: var(--cmdb-accent, #be185d); color: var(--cmdb-accent, #be185d); }
            .o2-chip.act.planned:hover { color: var(--warning-text, #92400e); }
        }
        .o2-chip-x {
            font: inherit; font-size: 11px; font-weight: 600;
            background: none; border: none; cursor: pointer;
            color: var(--text-dim, #9ca3af);
            margin-left: 7px; padding: 0;
            text-decoration: underline;
        }
        .o2-chip-x:hover { color: var(--cmdb-accent, #be185d); }

        .o2-btn.small { padding: 6px 11px; font-size: 12px; margin-left: auto; }
        .o2-card-head .o2-card-sub + .o2-btn.small { margin-left: 12px; }

        /* The unlink × sits outside the anchor — nesting a button inside a link
           makes the whole row ambiguous to click and invalid markup besides. */
        .o2-node-wrap { display: flex; align-items: stretch; gap: 4px; }
        .o2-node-wrap .o2-node { flex: 1 1 auto; min-width: 0; }
        .o2-unlink {
            flex: 0 0 auto;
            width: 26px;
            font: inherit; font-size: 16px; line-height: 1;
            background: none; cursor: pointer;
            border: 1px solid transparent;
            border-radius: 8px;
            color: var(--text-faint, #d1d5db);
            opacity: 0;
            transition: opacity 140ms ease, color 140ms ease, background-color 140ms ease;
        }
        .o2-noderow:hover .o2-unlink, .o2-unlink:focus { opacity: 1; }
        .o2-unlink:hover { color: var(--danger-text, #b91c1c); background: var(--danger-bg, #fef2f2); }
        /* Touch has no hover, so the control must never be hover-gated there. */
        @media (hover: none) { .o2-unlink { opacity: 1; } }

        .o2-prop-lbl { justify-content: space-between; }
        .o2-cog {
            background: none; border: none; padding: 2px;
            cursor: pointer; line-height: 1;
            color: var(--text-faint, #d1d5db);
            border-radius: 4px;
            opacity: 0;
            transition: opacity 140ms ease, color 140ms ease;
        }
        .o2-prop:hover .o2-cog, .o2-cog:focus { opacity: 1; }
        .o2-cog:hover { color: var(--cmdb-accent, #be185d); }
        @media (hover: none) { .o2-cog { opacity: 1; } }

        /* ---------------- Autocomplete ---------------- */
        .autocomplete-wrap { position: relative; }
        .autocomplete-wrap input { width: 100%; }
        .autocomplete-results {
            position: absolute; top: 100%; left: 0; right: 0;
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            box-shadow: 0 8px 26px var(--shadow, rgba(0,0,0,0.12));
            max-height: 240px; overflow-y: auto;
            z-index: 3500; margin-top: 4px;
            display: none;
        }
        .autocomplete-results.active { display: block; }
        .ac-result {
            padding: 8px 12px; cursor: pointer;
            display: flex; justify-content: space-between; gap: 10px;
            font-size: 13px;
        }
        .ac-result:hover, .ac-result.highlighted {
            background: var(--cmdb-accent-soft, #fdf2f8);
            color: var(--cmdb-accent, #be185d);
        }
        .ac-result .ac-class { color: var(--text-dim, #9ca3af); font-size: 11px; }
        .ac-empty { padding: 10px; color: var(--text-dim, #9ca3af); font-size: 13px; text-align: center; }

        /* ---------------- Modals ---------------- */
        .o2-modal {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 3000;
            display: none;
            align-items: flex-start; justify-content: center;
            padding-top: 12vh;
        }
        .o2-modal.active { display: flex; }
        .o2-modal-box {
            background: var(--surface, #fff);
            border-radius: 14px;
            width: 520px; max-width: 94vw;
            box-shadow: 0 18px 50px rgba(0,0,0,0.3);
            /* Modals are not anchored to a trigger, so they scale from centre. */
            animation: o2Pop 180ms var(--o2-ease-out) backwards;
        }
        @keyframes o2Pop { from { opacity: 0; transform: scale(0.96); } }
        .o2-modal-head {
            padding: 16px 20px 0;
            font-size: 16px; font-weight: 650;
            color: var(--text, #111827);
        }
        .o2-modal-body { padding: 14px 20px 4px; }
        .o2-modal-actions {
            padding: 16px 20px;
            display: flex; gap: 9px; justify-content: flex-end;
        }
        .o2-modal-actions .spacer { margin-right: auto; }
        .o2-field { margin-bottom: 14px; }
        .o2-field label {
            display: block;
            font-size: 12px; font-weight: 600;
            color: var(--text-muted, #6b7280);
            margin-bottom: 5px;
        }
        .o2-field input[type=text], .o2-field input[type=number], .o2-field select {
            width: 100%; font: inherit; font-size: 14px;
            padding: 8px 10px;
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            background: var(--surface, #fff);
            color: var(--text, #111827);
        }
        .o2-field input:focus, .o2-field select:focus {
            outline: none; border-color: var(--cmdb-accent, #be185d);
        }
        .o2-field small { display: block; font-size: 11.5px; color: var(--text-dim, #9ca3af); margin-top: 5px; }
        .o2-check { display: flex; align-items: center; gap: 8px; font-size: 14px; color: var(--text, #111827); }
        .o2-check input { width: auto; }
        .o2-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        /* The property-definition modal has no backdrop and is draggable, so the
           object stays readable while its schema is edited. */
        .o2-float {
            position: fixed; top: 90px; left: 50%;
            transform: translateX(-50%);
            width: 560px; max-width: 95vw;
            background: var(--surface, #fff);
            border-radius: 14px;
            box-shadow: 0 18px 50px rgba(0,0,0,0.3);
            z-index: 3200;
            display: none; flex-direction: column;
        }
        .o2-float.active { display: flex; }
        .o2-float-head {
            padding: 13px 18px;
            background: var(--cmdb-accent, #be185d);
            color: var(--cmdb-on-accent, #fff);
            border-radius: 14px 14px 0 0;
            font-size: 14px; font-weight: 650;
            cursor: move; user-select: none;
            display: flex; justify-content: space-between; align-items: center;
        }
        .o2-float-close {
            background: none; border: none; cursor: pointer;
            color: var(--cmdb-on-accent, #fff);
            font-size: 20px; line-height: 1; padding: 0; opacity: 0.85;
        }
        .o2-float-close:hover { opacity: 1; }
        .o2-float-body { padding: 16px 20px 4px; overflow-y: auto; max-height: calc(100vh - 240px); }
        .o2-key { font-family: Consolas, Monaco, monospace; }

        @media (max-width: 900px) {
            .o2-stats { grid-template-columns: repeat(2, 1fr); }
            .o2-conn { grid-template-columns: 1fr; }
            .o2-conn-col.mid { padding-top: 0; }
            .o2-conn-col.right .o2-conn-lbl, .o2-conn-col.right .o2-rel-verb { text-align: left; }
            .o2-hero { flex-wrap: wrap; }
            .o2-hero-actions { flex-direction: row; width: 100%; }
        }

        /* Reduced motion keeps the fades (they aid comprehension) and drops
           every positional transform. */
        @media (prefers-reduced-motion: reduce) {
            /* No entry animation at all — the sections are simply there. */
            .o2-secs > .o2-sec { animation: none; }
            .o2-btn, .o2-stat, .o2-node, .o2-tik { transition: background-color 140ms ease, border-color 140ms ease; }
            .o2-btn:active, .o2-stat:active, .o2-node:active, .o2-tik:active { transform: none; }
            .o2-stat:hover, .o2-node:hover, .o2-tik:hover { transform: none; }
            .o2-meter i { transition: none; }
        }
    </style>
    <!-- Mobile layer: after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=128">
</head>
<body data-mobile-module="cmdb" data-mobile-page="cmdb-object">
    <?php include 'includes/header.php'; ?>

    <div class="o2-page" id="o2Page">
        <div class="o2-wrap">
            <div style="text-align:center;padding:70px;color:var(--text-dim,#9ca3af);"><?php echo htmlspecialchars(t('cmdb.object.loading')); ?></div>
        </div>
    </div>

    <!-- Parent picker -->
    <div class="o2-modal" id="o2ParentModal">
        <div class="o2-modal-box">
            <div class="o2-modal-head"><?php echo htmlspecialchars(t('cmdb.parent_modal.heading')); ?></div>
            <div class="o2-modal-body">
                <div class="o2-field">
                    <label for="o2ParentInput"><?php echo htmlspecialchars(t('cmdb.parent_modal.label')); ?></label>
                    <div class="autocomplete-wrap">
                        <input type="text" id="o2ParentInput" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('cmdb.parent_modal.placeholder')); ?>">
                        <input type="hidden" id="o2ParentId">
                        <div class="autocomplete-results" id="o2ParentResults"></div>
                    </div>
                    <small><?php echo htmlspecialchars(t('cmdb.parent_modal.help')); ?></small>
                </div>
            </div>
            <div class="o2-modal-actions">
                <button type="button" class="o2-btn danger spacer" onclick="clearParent()"><?php echo htmlspecialchars(t('cmdb.parent_modal.clear')); ?></button>
                <button type="button" class="o2-btn" onclick="closeParentModal()"><?php echo htmlspecialchars(t('cmdb.parent_modal.cancel')); ?></button>
                <button type="button" class="o2-btn primary" onclick="saveParent()"><?php echo htmlspecialchars(t('cmdb.parent_modal.save')); ?></button>
            </div>
        </div>
    </div>

    <!-- Add relationship -->
    <div class="o2-modal" id="o2RelModal">
        <div class="o2-modal-box">
            <div class="o2-modal-head"><?php echo htmlspecialchars(t('cmdb.rel_modal.add_heading')); ?></div>
            <div class="o2-modal-body">
                <div class="o2-field">
                    <label><?php echo htmlspecialchars(t('cmdb.rel_modal.direction_label')); ?></label>
                    <div class="o2-seg">
                        <button type="button" id="o2RelDirOut" class="on" onclick="setRelDirection('out')"><?php echo htmlspecialchars(t('cmdb.rel_modal.dir_out')); ?></button>
                        <button type="button" id="o2RelDirIn" onclick="setRelDirection('in')"><?php echo htmlspecialchars(t('cmdb.rel_modal.dir_in')); ?></button>
                    </div>
                    <small><?php echo htmlspecialchars(t('cmdb.rel_modal.direction_help')); ?></small>
                </div>
                <div class="o2-field">
                    <label for="o2RelType"><?php echo htmlspecialchars(t('cmdb.rel_modal.verb_label')); ?></label>
                    <select id="o2RelType"></select>
                </div>
                <div class="o2-field">
                    <label for="o2RelTarget"><?php echo htmlspecialchars(t('cmdb.rel_modal.target_label')); ?></label>
                    <div class="autocomplete-wrap">
                        <input type="text" id="o2RelTarget" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('cmdb.rel_modal.target_placeholder')); ?>">
                        <input type="hidden" id="o2RelTargetId">
                        <div class="autocomplete-results" id="o2RelResults"></div>
                    </div>
                </div>
                <div class="o2-preview" id="o2RelHint"></div>
                <small style="display:block;font-size:11.5px;color:var(--text-dim,#9ca3af);margin:8px 0 4px;">
                    <?php echo htmlspecialchars(t('cmdb.rel_modal.ownership_note')); ?>
                </small>
            </div>
            <div class="o2-modal-actions">
                <button type="button" class="o2-btn" onclick="closeRelModal()"><?php echo htmlspecialchars(t('cmdb.rel_modal.cancel')); ?></button>
                <button type="button" class="o2-btn primary" onclick="saveRelationship()"><?php echo htmlspecialchars(t('cmdb.rel_modal.add')); ?></button>
            </div>
        </div>
    </div>

    <!-- Property definition — draggable, no backdrop, so the object stays visible -->
    <div class="o2-float" id="o2PdModal">
        <div class="o2-float-head" id="o2PdHeader">
            <span id="o2PdTitle"><?php echo htmlspecialchars(t('cmdb.prop_def.title')); ?></span>
            <button type="button" class="o2-float-close" onclick="closePropDefModal()">&times;</button>
        </div>
        <div class="o2-float-body">
            <form id="o2PdForm" onsubmit="event.preventDefault(); savePropDef();">
                <input type="hidden" id="o2PdId">
                <div class="o2-field">
                    <label for="o2PdLabel"><?php echo htmlspecialchars(t('cmdb.prop_def.label_label')); ?></label>
                    <input type="text" id="o2PdLabel" required maxlength="150">
                    <small><?php echo htmlspecialchars(t('cmdb.prop_def.label_help')); ?></small>
                </div>
                <div class="o2-field">
                    <label for="o2PdKey"><?php echo htmlspecialchars(t('cmdb.prop_def.key_label')); ?></label>
                    <input type="text" id="o2PdKey" maxlength="100" class="o2-key">
                    <small><?php echo htmlspecialchars(t('cmdb.prop_def.key_help')); ?></small>
                </div>
                <div class="o2-row">
                    <div class="o2-field">
                        <label for="o2PdType"><?php echo htmlspecialchars(t('cmdb.prop_def.type_label')); ?></label>
                        <select id="o2PdType" onchange="onPropDefTypeChange()">
                            <option value="text"><?php echo htmlspecialchars(t('cmdb.prop_def.type_text')); ?></option>
                            <option value="number"><?php echo htmlspecialchars(t('cmdb.prop_def.type_number')); ?></option>
                            <option value="date"><?php echo htmlspecialchars(t('cmdb.prop_def.type_date')); ?></option>
                            <option value="boolean"><?php echo htmlspecialchars(t('cmdb.prop_def.type_boolean')); ?></option>
                            <option value="dropdown"><?php echo htmlspecialchars(t('cmdb.prop_def.type_dropdown')); ?></option>
                            <option value="object_ref"><?php echo htmlspecialchars(t('cmdb.prop_def.type_object_ref')); ?></option>
                        </select>
                    </div>
                    <div class="o2-field">
                        <label for="o2PdOrder"><?php echo htmlspecialchars(t('cmdb.prop_def.display_order')); ?></label>
                        <input type="number" id="o2PdOrder" value="0">
                    </div>
                </div>
                <div class="o2-field" id="o2PdTargetGroup" style="display:none;">
                    <label for="o2PdTargetClass"><?php echo htmlspecialchars(t('cmdb.prop_def.target_class')); ?></label>
                    <select id="o2PdTargetClass"></select>
                </div>
                <div class="o2-field" id="o2PdOptionsGroup" style="display:none;">
                    <label><?php echo htmlspecialchars(t('cmdb.prop_def.options')); ?></label>
                    <div id="o2PdOptions"></div>
                    <small><?php echo htmlspecialchars(t('cmdb.prop_def.options_help')); ?></small>
                </div>
                <div class="o2-field">
                    <label class="o2-check"><input type="checkbox" id="o2PdRequired"> <?php echo htmlspecialchars(t('cmdb.prop_def.required')); ?></label>
                </div>
                <div class="o2-field" id="o2PdSpreadsGroup" style="display:none;">
                    <label class="o2-check"><input type="checkbox" id="o2PdSpreads"> <?php echo htmlspecialchars(t('cmdb.prop_def.spreads_impact')); ?></label>
                    <small><?php echo htmlspecialchars(t('cmdb.prop_def.spreads_impact_help')); ?></small>
                </div>
            </form>
        </div>
        <div class="o2-modal-actions">
            <button type="button" class="o2-btn" onclick="closePropDefModal()"><?php echo htmlspecialchars(t('cmdb.prop_def.cancel')); ?></button>
            <button type="button" class="o2-btn primary" onclick="savePropDef()"><?php echo htmlspecialchars(t('cmdb.prop_def.save')); ?></button>
        </div>
    </div>

    <script>
        window.OBJECT_ID = <?php echo isset($_GET['id']) ? (int)$_GET['id'] : 0; ?>;
        window.CAN_MAKE_DIAGRAM = <?php echo $canMakeDiagram ? 'true' : 'false'; ?>;
    </script>
    <!-- The class-icon library. Its own docblock names CMDB as consumer #1;
         object.php simply never loaded it. -->
    <script src="../assets/js/network-mapper-icons.js?v=1"></script>
    <!-- The shared dropdown-options editor, same one the settings page uses. -->
    <script src="options-editor.js?v=3"></script>
    <script src="object.js?v=9"></script>
    <script src="../assets/js/mobile.js?v=51"></script>
</body>
</html>
