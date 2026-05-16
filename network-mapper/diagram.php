<?php
/**
 * Network Mapper — Diagram editor (chunk B).
 *
 * Renders the editor shell and hands off to assets/js/network-mapper.js for
 * data loading, autosave, save-as-new-version, and (in later chunks) the
 * drag/bind/connector logic. The PHP side here is intentionally thin — most
 * of the live behaviour lives client-side.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

$diagramId = (int)($_GET['id'] ?? 0);
if ($diagramId <= 0) {
    header('Location: index.php');
    exit;
}

$current_page = 'diagrams';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM &mdash; Network Diagram</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script src="../assets/js/toast.js"></script>
    <style>
        body { background: #f5f5f5; height: 100vh; overflow: hidden; }

        .nm-editor {
            height: calc(100vh - 60px);
            display: flex;
            flex-direction: column;
            background: #f5f5f5;
        }

        /* ---- Top bar ---- */
        .nm-editor-bar {
            padding: 12px 20px;
            background: white;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            gap: 16px;
        }
        .nm-editor-title-area {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1;
        }
        .nm-back-btn {
            background: transparent;
            border: 1px solid #e5e7eb;
            color: #6b7280;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            flex-shrink: 0;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }
        .nm-back-btn:hover { background: #f9fafb; color: #111827; }
        .nm-editor-title {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .nm-version-pill {
            display: inline-block;
            background: #ecfeff;
            color: #0e7490;
            border: 1px solid #a5f3fc;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .nm-version-pill.readonly { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }

        .nm-editor-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-shrink: 0;
        }

        /* ---- Autosave toggle ---- */
        .nm-autosave-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 8px;
            border-right: 1px solid #e5e7eb;
            border-left: 1px solid #e5e7eb;
            height: 32px;
        }
        .nm-autosave-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 12px;
            color: #4b5563;
            user-select: none;
        }
        .nm-autosave-toggle input { display: none; }
        .nm-autosave-switch {
            position: relative;
            display: inline-block;
            width: 26px;
            height: 14px;
            background: #d1d5db;
            border-radius: 999px;
            transition: background 0.15s;
        }
        .nm-autosave-switch::after {
            content: '';
            position: absolute;
            top: 1px;
            left: 1px;
            width: 12px;
            height: 12px;
            background: white;
            border-radius: 50%;
            transition: left 0.15s;
        }
        .nm-autosave-toggle input:checked + .nm-autosave-switch { background: #06b6d4; }
        .nm-autosave-toggle input:checked + .nm-autosave-switch::after { left: 13px; }

        /* ---- Status indicator ---- */
        .nm-status {
            font-size: 12px;
            color: #6b7280;
            min-width: 120px;
            text-align: right;
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
        }
        .nm-status-unsaved { color: #b45309; }
        .nm-status-saving  { color: #0e7490; }
        .nm-status-saved   { color: #166534; }
        .nm-status-failed  { color: #b91c1c; }
        .nm-status-off     { color: #9ca3af; font-style: italic; }
        .nm-status-tick    { color: #16a34a; font-weight: 600; }
        .nm-status-warn    { color: #dc2626; font-weight: 600; }
        .nm-status-failed a { color: #b91c1c; text-decoration: underline; cursor: pointer; }
        .nm-status-spinner {
            width: 10px;
            height: 10px;
            border: 2px solid #a5f3fc;
            border-top-color: #06b6d4;
            border-radius: 50%;
            display: inline-block;
            animation: nm-spin 0.7s linear infinite;
        }
        @keyframes nm-spin { to { transform: rotate(360deg); } }

        /* ---- Buttons ---- */
        .nm-btn {
            padding: 7px 14px;
            background: #06b6d4;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
        }
        .nm-btn:hover { background: #0891b2; }
        .nm-btn.secondary { background: white; color: #374151; border: 1px solid #d1d5db; }
        .nm-btn.secondary:hover { background: #f9fafb; }
        .nm-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        /* ---- Zoom controls: 4 narrow buttons grouped as a single segmented unit ---- */
        .nm-zoom-group {
            display: inline-flex;
            align-items: stretch;
            gap: 0;
            border-radius: 4px;
            overflow: hidden;
        }
        .nm-zoom-group .nm-btn {
            border-radius: 0;
            border-right-width: 0;
            padding: 7px 10px;
            font-size: 13px;
        }
        .nm-zoom-group .nm-btn:first-child { border-radius: 4px 0 0 4px; }
        .nm-zoom-group .nm-btn:last-child  { border-radius: 0 4px 4px 0; border-right-width: 1px; }
        .nm-zoom-btn  { min-width: 30px; font-weight: 600; }
        .nm-zoom-label {
            min-width: 56px;
            font-variant-numeric: tabular-nums;
            color: #374151;
            font-weight: 500;
        }
        .nm-zoom-fit { font-size: 12px; }

        /* Export buttons — same segmented look as zoom, two buttons (PNG/PDF) */
        .nm-export-group {
            display: inline-flex;
            align-items: stretch;
            gap: 0;
            border-radius: 4px;
            overflow: hidden;
        }
        .nm-export-group .nm-btn {
            border-radius: 0;
            border-right-width: 0;
            padding: 7px 10px;
            font-size: 12px;
        }
        .nm-export-group .nm-btn:first-child { border-radius: 4px 0 0 4px; }
        .nm-export-group .nm-btn:last-child  { border-radius: 0 4px 4px 0; border-right-width: 1px; }
        .nm-export-btn { min-width: 40px; font-weight: 600; }

        /* ---- Meta row ---- */
        .nm-meta-row {
            font-size: 12px;
            color: #6b7280;
            padding: 8px 20px;
            background: #fafbfc;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            gap: 18px;
        }
        .nm-meta-row strong { color: #374151; font-weight: 500; }

        /* ---- Read-only banner ---- */
        .nm-readonly-banner {
            padding: 10px 20px;
            background: #fff7ed;
            border-bottom: 1px solid #fed7aa;
            color: #9a3412;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .nm-readonly-banner strong { color: #7c2d12; }
        .nm-readonly-banner a { color: #c2410c; text-decoration: underline; }

        /* ---- Main canvas area ---- */
        .nm-canvas-wrap {
            flex: 1;
            display: flex;
            overflow: hidden;
        }

        /* ---- Palette ---- */
        .nm-palette {
            width: 240px;
            background: white;
            border-right: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .nm-palette-header {
            padding: 11px 14px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .nm-palette-hint {
            font-size: 11px;
            color: #9ca3af;
            font-weight: 400;
        }
        .nm-palette-body {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            align-content: start;
        }
        .nm-palette-empty {
            grid-column: 1 / -1;
            color: #9ca3af;
            font-size: 13px;
            line-height: 1.5;
            padding: 14px 8px;
        }
        .nm-palette-empty a { color: #06b6d4; }
        .nm-palette-tile {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 10px 6px 8px 6px;
            cursor: grab;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            transition: border-color 0.12s, box-shadow 0.12s, background 0.12s;
            user-select: none;
            background: white;
        }
        .nm-palette-tile:hover {
            border-color: #06b6d4;
            background: #ecfeff;
            box-shadow: 0 2px 6px rgba(6,182,212,0.10);
        }
        .nm-palette-tile:active { cursor: grabbing; }
        .nm-palette-tile-icon {
            color: #0e7490;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 32px;
        }
        .nm-palette-tile-name {
            font-size: 11px;
            font-weight: 600;
            color: #111827;
            text-align: center;
            line-height: 1.2;
            word-break: break-word;
        }
        .nm-palette-tile-count {
            font-size: 10px;
            color: #9ca3af;
        }

        /* ---- Canvas ---- */
        .nm-canvas {
            flex: 1;
            position: relative;
            background:
                radial-gradient(circle, #d1d5db 1px, transparent 1px) 0 0 / 20px 20px,
                #fafbfc;
            overflow: auto;
        }
        /* Holds all the zoomable content. Transform applied here (not on
           .nm-canvas) so the dot-grid background stays at 1× and scrolling
           still works against .nm-canvas's overflow:auto. transform-origin
           top-left so node coordinates (which are always stored in 1×
           pixels) map cleanly into the scaled visual at the same x/y. */
        .nm-canvas-inner {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            transform-origin: 0 0;
            transform: scale(1);
        }
        /* Hidden footprint that grows with zoom — see comment on the spacer
           element. width/height managed by JS in applyZoom(). */
        .nm-canvas-spacer {
            position: absolute;
            top: 0;
            left: 0;
            width: 3000px;
            height: 3000px;
            pointer-events: none;
        }
        .nm-canvas-empty {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #9ca3af;
            font-size: 14px;
            max-width: 380px;
            line-height: 1.5;
        }
        .nm-canvas-empty h3 { color: #6b7280; font-weight: 600; margin: 0 0 6px 0; }

        /* ---- Placed nodes ---- */
        .nm-node {
            position: absolute;
            display: flex;
            flex-direction: column;
            align-items: center;
            cursor: grab;
            user-select: none;
            padding: 6px;
            border-radius: 8px;
            border: 1.5px solid transparent;
            background: rgba(255, 255, 255, 0.85);
            transition: border-color 0.12s, box-shadow 0.12s, background 0.12s;
            z-index: 2;
        }
        .nm-node:hover {
            background: white;
            border-color: #a5f3fc;
        }
        .nm-node:active { cursor: grabbing; }
        .nm-node.selected {
            border-color: #06b6d4;
            background: white;
            box-shadow: 0 0 0 3px rgba(6,182,212,0.18), 0 4px 12px rgba(6,182,212,0.18);
            z-index: 3;
        }
        .nm-node-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            color: #0e7490;
        }
        .nm-node-label {
            margin-top: 4px;
            font-size: 12px;
            font-weight: 500;
            color: #1f2937;
            text-align: center;
            line-height: 1.2;
            /* Wider than the icon so multi-word names like "Production Database"
               sit on one line. overflow-wrap (not word-break: break-word) means
               whole words stay intact — "FREEITSM" stays whole rather than
               splitting mid-character to "FREEIT / SM". Single tokens that
               genuinely don't fit fall back to breaking; the 2-line clamp +
               ellipsis catches anything still too long. Hover tooltip shows
               the full name regardless. */
            max-width: 140px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            line-clamp: 2;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow-wrap: break-word;
            hyphens: auto;
        }
        /* Planned-node styling — dashed border + amber tint, matching the CMDB browse/detail treatment */
        .nm-node.is-planned {
            background: #fffbeb;
            border-style: dashed;
            border-color: #fcd34d;
        }
        .nm-node.is-planned .nm-node-icon { color: #92400e; }
        .nm-node.is-planned .nm-node-label { font-style: italic; color: #78350f; }
        .nm-node.is-planned.selected {
            border-style: solid;
            border-color: #06b6d4;
        }
        .nm-node-planned-pill {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
            padding: 1px 6px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }

        /* ---- Connector layer ---- */
        .nm-svg-layer {
            position: absolute;
            top: 0; left: 0;
            pointer-events: none;       /* paths re-enable on themselves */
            z-index: 1;                 /* under nodes (z:2) and selected nodes (z:3) */
            overflow: visible;
        }
        .nm-connector-line {
            fill: none;
            stroke: #64748b;
            stroke-width: 2;
            pointer-events: none;       /* the .nm-connector-hit underneath catches clicks */
        }
        .nm-connector-line.selected {
            stroke: #06b6d4;
            stroke-width: 2.5;
        }
        .nm-connector-line.dashed {
            stroke-dasharray: 6 4;
        }
        .nm-connector-hit {
            fill: none;
            stroke: transparent;
            stroke-width: 14;
            pointer-events: stroke;
            cursor: pointer;
        }
        .nm-temp-connector {
            fill: none;
            stroke: #06b6d4;
            stroke-width: 2;
            stroke-dasharray: 6 4;
            pointer-events: none;
            opacity: 0.8;
        }
        /* Page-size guide (anchored at canvas origin, scaled to paper size) */
        .nm-page-outline-fill {
            fill: white;
            opacity: 0.55;          /* lets the dot-grid bleed through, distinguishes page from off-page */
            pointer-events: none;
        }
        .nm-page-outline-border {
            fill: none;
            stroke: #06b6d4;
            stroke-width: 1.5;
            stroke-dasharray: 8 5;
            opacity: 0.75;
            pointer-events: none;
        }
        .nm-page-outline-label {
            fill: #0e7490;
            font-size: 11px;
            font-family: inherit;
            font-weight: 600;
            text-transform: capitalize;
            opacity: 0.85;
            pointer-events: none;
            user-select: none;
        }
        /* Header/footer branding overlay — absolute-positioned inside .nm-canvas
           so it aligns with the page outline at SVG coords 0..dims.w. HTML
           (not SVG) so the {{logo}} token can render as an <img>. */
        .nm-brand-header, .nm-brand-footer {
            position: absolute;
            left: 0;
            height: 32px;
            display: flex;
            align-items: center;
            padding: 0 12px;
            pointer-events: none;
            z-index: 1;
            color: #334155;
            font-size: 12px;
            box-sizing: border-box;
            gap: 12px;
        }
        .nm-brand-slot {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }
        .nm-brand-slot.left   { text-align: left; }
        .nm-brand-slot.center { text-align: center; }
        .nm-brand-slot.right  { text-align: right; }
        .nm-brand-logo {
            max-height: 28px;
            max-width: 140px;
            vertical-align: middle;
        }
        /* Branding settings modal — 6 slot inputs in a 3-column header/footer grid */
        .nm-brand-grid {
            display: grid;
            grid-template-columns: 70px 1fr 1fr 1fr;
            gap: 8px 10px;
            align-items: center;
            margin-top: 8px;
        }
        .nm-brand-grid .row-label {
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            text-align: right;
            padding-right: 4px;
        }
        .nm-brand-grid .col-head {
            font-size: 10px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            text-align: center;
        }
        .nm-brand-grid input {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 12px;
            font-family: inherit;
            box-sizing: border-box;
        }
        .nm-brand-grid input:focus {
            outline: none;
            border-color: #06b6d4;
            box-shadow: 0 0 0 3px rgba(6,182,212,0.12);
        }
        .nm-brand-tokens {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 6px;
            padding: 10px 12px;
            margin-top: 14px;
            font-size: 11px;
            color: #075985;
            line-height: 1.7;
        }
        .nm-brand-tokens code {
            background: #ffffff;
            border: 1px solid #bae6fd;
            border-radius: 3px;
            padding: 1px 5px;
            font-size: 10px;
            color: #0891b2;
            font-family: 'Consolas', 'Monaco', monospace;
        }
        .nm-connector-label-bg {
            fill: white;
            stroke: #e5e7eb;
            stroke-width: 1;
            cursor: pointer;
        }
        .nm-connector-label {
            fill: #1f2937;
            font-size: 11px;
            font-family: inherit;
            text-anchor: middle;
            user-select: none;
            cursor: pointer;
        }
        .nm-connector-label-input {
            position: absolute;
            z-index: 10;
            width: 160px;
            height: 28px;
            padding: 4px 8px;
            font-size: 12px;
            font-family: inherit;
            color: #1f2937;
            background: white;
            border: 1.5px solid #06b6d4;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(6,182,212,0.25);
            outline: none;
            box-sizing: border-box;
        }
        .nm-connector-label-input::placeholder { color: #9ca3af; font-style: italic; font-size: 11px; }

        /* ---- Edge handles (start a connector drag) ---- */
        .nm-edge-handle {
            position: absolute;
            width: 10px;
            height: 10px;
            background: #06b6d4;
            border: 2px solid white;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            cursor: crosshair;
            opacity: 0;
            transform: translate(-50%, -50%);
            transition: opacity 0.12s, transform 0.12s;
            z-index: 5;
            pointer-events: auto;
        }
        .nm-node:hover .nm-edge-handle,
        .nm-node.selected .nm-edge-handle { opacity: 1; }
        .nm-edge-handle:hover { transform: translate(-50%, -50%) scale(1.3); background: #0891b2; }

        /* ---- Versions dropdown (anchored to the Versions button in the toolbar) ---- */
        .nm-versions-wrap { position: relative; }
        .nm-versions-dropdown {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 320px;
            max-width: 400px;
            max-height: 380px;
            overflow-y: auto;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            z-index: 100;
        }
        .nm-vd-loading,
        .nm-vd-empty {
            padding: 20px 16px;
            text-align: center;
            color: #9ca3af;
            font-size: 13px;
        }
        .nm-vd-row {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 10px 14px;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            transition: background 0.12s;
        }
        .nm-vd-row:last-child { border-bottom: 0; }
        .nm-vd-row:hover { background: #ecfeff; }
        .nm-vd-row.active { background: #ecfeff; }
        .nm-vd-row-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
        }
        .nm-vd-label {
            font-size: 13px;
            font-weight: 600;
            color: #111827;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
            min-width: 0;
        }
        .nm-vd-row-meta {
            font-size: 11px;
            color: #6b7280;
        }
        .nm-vd-pill {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            flex-shrink: 0;
        }
        .nm-vd-pill.current  { background: #ecfeff; color: #0e7490; border: 1px solid #a5f3fc; }
        .nm-vd-pill.readonly { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
        .nm-vd-pill.viewing  { background: #06b6d4; color: white; }

        /* ---- Node detail panel (slides in beside canvas when a node is selected) ---- */
        .nm-detail-panel {
            width: 0;
            background: white;
            border-left: 1px solid #e5e7eb;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            overflow: hidden;
            transition: width 0.18s ease;
        }
        .nm-detail-panel.open { width: 320px; }
        .nm-detail-header {
            padding: 14px 16px;
            border-bottom: 1px solid #e5e7eb;
            background: #fafbfc;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            flex-shrink: 0;
        }
        .nm-detail-title-area {
            display: flex;
            gap: 10px;
            min-width: 0;
            flex: 1;
            align-items: center;
        }
        .nm-detail-icon {
            color: #0e7490;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .nm-detail-title-text { min-width: 0; }
        .nm-detail-title-text h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #111827;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .nm-detail-subtitle {
            font-size: 11px;
            color: #6b7280;
            margin-top: 2px;
        }
        .nm-detail-close {
            background: transparent;
            border: 0;
            color: #9ca3af;
            font-size: 22px;
            line-height: 1;
            cursor: pointer;
            padding: 0 4px;
        }
        .nm-detail-close:hover { color: #111827; }
        .nm-detail-body {
            flex: 1;
            overflow-y: auto;
            padding: 14px 16px;
        }
        .nm-detail-section { margin-bottom: 14px; }
        .nm-detail-field {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 6px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 13px;
        }
        .nm-detail-field:last-child { border-bottom: 0; }
        .nm-detail-label { color: #6b7280; flex-shrink: 0; }
        .nm-detail-value { color: #111827; text-align: right; word-break: break-word; }
        .nm-detail-value a { color: #0e7490; text-decoration: none; }
        .nm-detail-value a:hover { text-decoration: underline; }
        .nm-detail-planned-pill {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
            padding: 1px 6px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-right: 4px;
            vertical-align: middle;
        }
        .nm-detail-actions {
            margin: 14px 0 10px 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .nm-detail-actions .nm-btn { width: 100%; padding: 9px 14px; }
        .nm-detail-hint {
            font-size: 12px;
            color: #6b7280;
            line-height: 1.5;
            margin: 8px 0 0 0;
        }

        /* ---- Properties sub-section (CMDB property values for the bound object) ---- */
        .nm-detail-section-header {
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 0 0 8px 0;
            border-bottom: 1px solid #f3f4f6;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
        .nm-detail-section-sub {
            font-size: 10px;
            color: #9ca3af;
            font-weight: 500;
            text-transform: none;
            letter-spacing: 0;
        }
        .nm-prop-loading,
        .nm-prop-empty {
            padding: 8px 0;
            color: #9ca3af;
            font-size: 12px;
            font-style: italic;
        }
        .nm-prop-row {
            padding: 7px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .nm-prop-row:last-child { border-bottom: 0; }
        .nm-prop-label {
            display: block;
            font-size: 11px;
            color: #6b7280;
            margin-bottom: 3px;
        }
        .nm-prop-value {
            display: block;
            font-size: 13px;
            color: #111827;
            line-height: 1.45;
            word-break: break-word;
        }
        .nm-prop-value.bool-yes { color: #166534; font-weight: 500; }
        .nm-prop-value.bool-no  { color: #6b7280; }
        .nm-prop-pill {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 500;
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #e5e7eb;
            max-width: 100%;
            word-break: break-word;
        }
        .nm-prop-ref {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
            background: #fce7f3;
            color: #9d174d;
            border: 1px solid #fbcfe8;
            text-decoration: none;
            max-width: 100%;
        }
        .nm-prop-ref:hover { background: #fbcfe8; }
        .nm-prop-ref-class {
            font-size: 10px;
            color: #be185d;
            opacity: 0.8;
        }

        /* ---- Detail panel: icon row + icon picker triggers ---- */
        .nm-detail-icon-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .nm-detail-icon-preview {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px; height: 28px;
            color: #0e7490;
            background: #ecfeff;
            border-radius: 6px;
            border: 1px solid #a5f3fc;
            flex-shrink: 0;
        }
        .nm-detail-icon-btn {
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 500;
            color: #0e7490;
            background: white;
            border: 1px solid #a5f3fc;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.12s;
        }
        .nm-detail-icon-btn:hover { background: #ecfeff; }
        .nm-detail-icon-reset {
            color: #6b7280;
            border-color: #e5e7eb;
        }
        .nm-detail-icon-reset:hover { background: #f9fafb; color: #111827; }

        /* ---- Icon picker modal ---- */
        .nm-ip-search-wrap { margin-bottom: 12px; }
        .nm-ip-search {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .nm-ip-search:focus {
            outline: none;
            border-color: #06b6d4;
            box-shadow: 0 0 0 3px rgba(6,182,212,0.12);
        }
        .nm-ip-grid {
            max-height: 440px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            background: #fafbfc;
            padding: 4px;
        }
        .nm-ip-category {
            padding: 8px 8px 4px 8px;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .nm-ip-category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(78px, 1fr));
            gap: 6px;
            padding: 0 4px 12px 4px;
        }
        .nm-ip-tile {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            padding: 8px 4px 6px 4px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            cursor: pointer;
            transition: border-color 0.12s, background 0.12s, box-shadow 0.12s;
        }
        .nm-ip-tile:hover {
            border-color: #06b6d4;
            background: #ecfeff;
            box-shadow: 0 2px 6px rgba(6,182,212,0.12);
        }
        .nm-ip-tile.selected {
            border-color: #06b6d4;
            background: #ecfeff;
            box-shadow: 0 0 0 2px rgba(6,182,212,0.25);
        }
        .nm-ip-tile-icon {
            color: #0e7490;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 28px;
        }
        .nm-ip-tile-name {
            font-size: 10.5px;
            font-weight: 500;
            color: #1f2937;
            text-align: center;
            line-height: 1.2;
            word-break: break-word;
        }
        .nm-ip-empty {
            padding: 28px 16px;
            text-align: center;
            color: #9ca3af;
            font-size: 13px;
        }

        /* ---- Related-objects modal ---- */
        .nm-modal.nm-modal-wide { width: 560px; }
        .nm-rm-intro {
            font-size: 13px;
            color: #6b7280;
            margin: 0 0 14px 0;
            line-height: 1.5;
        }
        .nm-rm-results {
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            background: #fafbfc;
            max-height: 420px;
            overflow-y: auto;
        }
        .nm-rm-loading,
        .nm-rm-empty {
            padding: 28px 16px;
            text-align: center;
            color: #9ca3af;
            font-size: 13px;
        }
        .nm-rm-group {
            background: white;
        }
        .nm-rm-group + .nm-rm-group { border-top: 1px solid #e5e7eb; }
        .nm-rm-group-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 14px;
            background: #f9fafb;
            font-size: 11px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border-bottom: 1px solid #f3f4f6;
        }
        .nm-rm-group-count { color: #9ca3af; font-weight: 500; }
        .nm-rm-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 14px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 13px;
            cursor: pointer;
        }
        .nm-rm-row:last-child { border-bottom: 0; }
        .nm-rm-row:hover { background: #ecfeff; }
        .nm-rm-row.disabled { opacity: 0.55; cursor: not-allowed; background: #fafbfc; }
        .nm-rm-row.disabled:hover { background: #fafbfc; }
        .nm-rm-checkbox {
            margin: 0;
            width: 16px;
            height: 16px;
            accent-color: #06b6d4;
            cursor: pointer;
            flex-shrink: 0;
        }
        .nm-rm-icon {
            color: #0e7490;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 22px;
        }
        .nm-rm-main {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 1px;
        }
        .nm-rm-name {
            font-weight: 500;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
        }
        .nm-rm-name-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .nm-rm-class {
            font-size: 11px;
            color: #6b7280;
        }
        .nm-rm-link-text {
            font-size: 11px;
            color: #0e7490;
            font-style: italic;
        }
        .nm-rm-onboard {
            font-size: 10px;
            color: #6b7280;
            background: #e5e7eb;
            padding: 1px 6px;
            border-radius: 999px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .nm-rm-planned-pill {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
            padding: 1px 6px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        /* ---- Object picker modal ---- */
        .nm-picker-search-wrap { margin-bottom: 12px; }
        .nm-picker-search {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .nm-picker-search:focus {
            outline: none;
            border-color: #06b6d4;
            box-shadow: 0 0 0 3px rgba(6,182,212,0.12);
        }
        .nm-picker-results {
            max-height: 320px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            background: #fafbfc;
        }
        .nm-picker-row {
            padding: 9px 12px;
            border-bottom: 1px solid #f3f4f6;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-size: 13px;
            color: #1f2937;
            background: white;
        }
        .nm-picker-row:last-child { border-bottom: 0; }
        .nm-picker-row:hover,
        .nm-picker-row.highlighted {
            background: #ecfeff;
            color: #0e7490;
        }
        .nm-picker-name {
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .nm-picker-parent {
            font-size: 11px;
            color: #9ca3af;
        }
        .nm-picker-planned {
            display: inline-block;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
            padding: 1px 6px;
            border-radius: 999px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .nm-picker-empty {
            padding: 28px 16px;
            text-align: center;
            color: #9ca3af;
            font-size: 13px;
            background: white;
        }
        .nm-picker-empty a { color: #06b6d4; }

        /* ---- Modal (Save as new version) ---- */
        .nm-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .nm-modal-overlay.active { display: flex; }
        .nm-modal {
            background: white;
            border-radius: 8px;
            width: 480px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        .nm-modal-header {
            padding: 16px 22px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            font-size: 16px;
            color: #111827;
        }
        .nm-modal-body { padding: 22px; flex: 1; overflow-y: auto; }
        .nm-modal-actions {
            padding: 14px 22px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .nm-form-group { margin-bottom: 14px; }
        .nm-form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 5px;
        }
        .nm-form-group input, .nm-form-group textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
            box-sizing: border-box;
        }
        .nm-form-group textarea { resize: vertical; min-height: 70px; }
        .nm-form-group input:focus, .nm-form-group textarea:focus {
            outline: none;
            border-color: #06b6d4;
            box-shadow: 0 0 0 3px rgba(6,182,212,0.12);
        }
        .nm-form-group small { color: #6b7280; font-size: 12px; display: block; margin-top: 4px; }

        /* ---- Present mode: hide all chrome and show only the diagram.
           Toggled by adding .is-presenting to .nm-editor. The canvas itself
           stays visible and takes the full editor area. F11 is left to the
           browser/user for a true fullscreen escalation. ---- */
        .nm-present-exit {
            position: fixed;
            top: 14px;
            right: 14px;
            z-index: 2000;
            display: none;             /* shown only in present mode */
            padding: 8px 14px;
            background: rgba(15, 23, 42, 0.85);
            color: white;
            border: none;
            border-radius: 999px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.3px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
        }
        .nm-present-exit:hover { background: rgba(15, 23, 42, 1); }
        .nm-editor.is-presenting .nm-editor-bar,
        .nm-editor.is-presenting .nm-meta-row,
        .nm-editor.is-presenting .nm-readonly-banner,
        .nm-editor.is-presenting .nm-palette,
        .nm-editor.is-presenting .nm-detail-panel { display: none !important; }
        .nm-editor.is-presenting .nm-present-exit { display: block; }
        /* In present mode the canvas-wrap fills the editor and the canvas
           keeps its dot-grid + scroll behaviour — we're only hiding chrome
           around it, not changing the canvas itself. */
        .nm-editor.is-presenting .nm-canvas-wrap { padding: 0; }

        /* ---- Export capture mode: applied to .nm-canvas-inner during a
           PNG/PDF snapshot so the rasterised image doesn't pick up
           edit-time chrome (selection rings, edge handles, the empty-state
           placeholder). ---- */
        .nm-canvas-inner.is-exporting .nm-node-edge-handle,
        .nm-canvas-inner.is-exporting .nm-canvas-empty { display: none !important; }
        .nm-canvas-inner.is-exporting .nm-node.selected {
            border-color: transparent !important;
            box-shadow: none !important;
        }
        .nm-canvas-inner.is-exporting .nm-connector-line.selected {
            stroke: #64748b !important;
            stroke-width: 2 !important;
        }
        /* Use the cyan default arrowhead in non-selected form too */
        .nm-canvas-inner.is-exporting .nm-connector-line { marker-end: url(#nm-arrow) !important; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="nm-editor">
        <div class="nm-editor-bar">
            <div class="nm-editor-title-area">
                <a class="nm-back-btn" href="index.php">&larr; All diagrams</a>
                <h1 class="nm-editor-title" id="diagramTitle">Loading&hellip;</h1>
                <span class="nm-version-pill" id="versionPill" style="display:none;"></span>
            </div>
            <div class="nm-editor-actions">
                <div class="nm-autosave-wrap" id="autosaveWrap">
                    <label class="nm-autosave-toggle" title="Auto-save changes ~2s after the last edit">
                        <input type="checkbox" id="nmAutosaveToggle" onchange="NM.toggleAutosave(this.checked)">
                        <span class="nm-autosave-switch"></span>
                        <span>Autosave</span>
                    </label>
                </div>
                <span class="nm-status" id="saveStatus"></span>
                <div class="nm-versions-wrap">
                    <button class="nm-btn secondary" id="pageBtn" onclick="NM.togglePageDropdown(event)" title="Show a paper-size outline on the canvas — useful before exporting to PNG/PDF">
                        <span id="pageBtnLabel">Page: Off</span>
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-left: 4px; vertical-align: -1px;"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="nm-versions-dropdown" id="pageDropdown" style="display:none;"></div>
                </div>
                <div class="nm-zoom-group" role="group" aria-label="Zoom">
                    <button class="nm-btn secondary nm-zoom-btn" id="zoomOutBtn" onclick="NM.zoomOut()" title="Zoom out">&minus;</button>
                    <button class="nm-btn secondary nm-zoom-label" id="zoomLabel" onclick="NM.zoomReset()" title="Click to reset to 100%">100%</button>
                    <button class="nm-btn secondary nm-zoom-btn" id="zoomInBtn" onclick="NM.zoomIn()" title="Zoom in">+</button>
                    <button class="nm-btn secondary nm-zoom-fit" id="zoomFitBtn" onclick="NM.zoomFit()" title="Fit page (or all nodes) to the visible canvas">Fit</button>
                </div>
                <button class="nm-btn secondary" id="brandingBtn" onclick="NM.openBrandingModal()" title="Override the org-wide header/footer for this diagram (set a page size first)">Branding</button>
                <button class="nm-btn secondary" id="centreBtn" onclick="NM.centre()" title="Move all nodes so the diagram is centred on the selected paper size (requires a page size to be set)">Centre</button>
                <div class="nm-export-group" role="group" aria-label="Export">
                    <button class="nm-btn secondary nm-export-btn" id="exportPngBtn" onclick="NM.exportPng()" title="Export the diagram as a PNG image (clipped to the page outline if set)">PNG</button>
                    <button class="nm-btn secondary nm-export-btn" id="exportPdfBtn" onclick="NM.exportPdf()" title="Export the diagram as a PDF (uses the chosen paper size + orientation)">PDF</button>
                </div>
                <button class="nm-btn secondary" id="presentBtn" onclick="NM.enterPresent()" title="Hide the toolbar and panels to show just the diagram (Esc to exit, then F11 for full-screen)">Present</button>
                <div class="nm-versions-wrap">
                    <button class="nm-btn secondary" id="versionsBtn" onclick="NM.toggleVersionsDropdown(event)" title="Browse the version history of this diagram">
                        Versions
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-left: 4px; vertical-align: -1px;"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                    <div class="nm-versions-dropdown" id="versionsDropdown" style="display:none;"></div>
                </div>
                <button class="nm-btn secondary" id="saveVersionBtn" onclick="NM.openNewVersionModal()" title="Clone the current version forward into a new editable version">Save as new version</button>
                <button class="nm-btn" id="saveBtn" onclick="NM.save()" title="Save (Ctrl+S)">Save</button>
            </div>
        </div>

        <div class="nm-meta-row" id="metaRow" style="display:none;">
            <span><strong>Author:</strong> <span id="metaAuthor">&mdash;</span></span>
            <span><strong>Created:</strong> <span id="metaCreated">&mdash;</span></span>
            <span><strong>Updated:</strong> <span id="metaUpdated">&mdash;</span></span>
        </div>

        <div class="nm-readonly-banner" id="readonlyBanner" style="display:none;">
            <span><strong>Read-only version.</strong> This is a historical version of the diagram. To make changes, fork it into a new version from the current (leaf) version.</span>
            <a href="index.php">&larr; Back to diagrams</a>
        </div>

        <div class="nm-canvas-wrap">
            <aside class="nm-palette">
                <div class="nm-palette-header">
                    <span>CMDB classes</span>
                    <span class="nm-palette-hint">drag to canvas</span>
                </div>
                <div class="nm-palette-body" id="paletteBody">
                    <div class="nm-palette-empty">Loading classes&hellip;</div>
                </div>
            </aside>
            <div class="nm-canvas" id="canvas">
                <!-- Invisible spacer that drives the scrollable area when zoomed:
                     CSS `transform` doesn't affect layout, so without this the
                     canvas would only scroll over the unscaled content extent and
                     clip whatever zoom-in pushed off-screen. JS sets its size to
                     (BASE * zoom) on every zoom change. -->
                <div class="nm-canvas-spacer" id="canvasSpacer"></div>
                <!-- All zoomable content (nodes, SVG layer, brand strips, inline
                     label editor) is appended to .nm-canvas-inner so the
                     transform: scale() on it doesn't also scale the dot-grid
                     background painted by .nm-canvas itself. -->
                <div class="nm-canvas-inner" id="canvasInner">
                    <div class="nm-canvas-empty" id="canvasEmpty">
                        <h3>Empty diagram</h3>
                        <p>Drag a class from the palette onto the canvas to start placing nodes. You'll be asked which CMDB object to bind it to.</p>
                    </div>
                </div>
            </div>
            <!-- Floating Exit pill in Present mode (hidden until .nm-editor.is-presenting) -->
            <button class="nm-present-exit" id="presentExitBtn" onclick="NM.exitPresent()" title="Exit Present mode (Esc)">Exit&nbsp;Present</button>
            <!-- Detail panel (slides in when a node is selected). Sits beside
                 the canvas inside the same wrap so it shrinks the canvas
                 rather than overlaying it — a chunk-D-only addition. -->
            <aside class="nm-detail-panel" id="nodeDetailPanel" aria-hidden="true">
                <div class="nm-detail-header">
                    <div class="nm-detail-title-area">
                        <div class="nm-detail-icon" id="ndIcon"></div>
                        <div class="nm-detail-title-text">
                            <h3 id="ndName">Node</h3>
                            <div class="nm-detail-subtitle" id="ndClass">&mdash;</div>
                        </div>
                    </div>
                    <button class="nm-detail-close" onclick="NM.closeDetail()" title="Close (Esc)">&times;</button>
                </div>
                <div class="nm-detail-body">
                    <div class="nm-detail-section">
                        <div class="nm-detail-field"><span class="nm-detail-label">Class</span><span class="nm-detail-value" id="ndClassValue">&mdash;</span></div>
                        <div class="nm-detail-field" id="ndPlannedRow" style="display:none;"><span class="nm-detail-label">Status</span><span class="nm-detail-value"><span class="nm-detail-planned-pill">PLANNED</span> Future state</span></div>
                        <div class="nm-detail-field"><span class="nm-detail-label">CMDB</span><span class="nm-detail-value"><a id="ndCmdbLink" href="#" target="_blank">Open in CMDB &rarr;</a></span></div>
                        <div class="nm-detail-field">
                            <span class="nm-detail-label">Icon</span>
                            <span class="nm-detail-value nm-detail-icon-row">
                                <span class="nm-detail-icon-preview" id="ndIconPreview"></span>
                                <button class="nm-detail-icon-btn" id="ndIconChangeBtn" onclick="NM.openIconPicker()" title="Pick a different icon for this node">Change</button>
                                <button class="nm-detail-icon-btn nm-detail-icon-reset" id="ndIconResetBtn" onclick="NM.resetIconOverride()" title="Use the class default icon" style="display:none;">Reset</button>
                            </span>
                        </div>
                    </div>
                    <div class="nm-detail-section" id="ndPropertiesSection" style="display:none;">
                        <div class="nm-detail-section-header">Properties <span class="nm-detail-section-sub">from CMDB</span></div>
                        <div id="ndProperties"></div>
                    </div>
                    <div class="nm-detail-actions">
                        <button class="nm-btn" id="ndAddRelatedBtn" onclick="NM.openRelatedModal()">Add related objects</button>
                    </div>
                    <p class="nm-detail-hint">
                        Pulls in CMDB neighbours of this object &mdash; what it depends on, what depends on it, and any objects that reference it via a property. Tick which to add; selected objects get placed in a ring around this node and a connector is drawn for each so the line traces back to a real relationship.
                    </p>
                </div>
            </aside>
        </div>
    </div>

    <!-- CMDB object picker (opened on drop) -->
    <div class="nm-modal-overlay" id="objectPickerModal">
        <div class="nm-modal">
            <div class="nm-modal-header">
                Pick a <span id="pickerClassLabel">CMDB object</span> to place
            </div>
            <div class="nm-modal-body">
                <div class="nm-picker-search-wrap">
                    <input type="text" class="nm-picker-search" id="pickerSearch" placeholder="Type to filter&hellip;" oninput="NM.onPickerSearchInput(this.value)" onkeydown="NM.onPickerKeyDown(event)">
                </div>
                <div class="nm-picker-results" id="pickerResults"></div>
            </div>
            <div class="nm-modal-actions">
                <button class="nm-btn secondary" onclick="NM.closeObjectPicker()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Per-node icon picker modal (opened from the detail panel) -->
    <div class="nm-modal-overlay" id="iconPickerModal">
        <div class="nm-modal nm-modal-wide">
            <div class="nm-modal-header">
                Pick an icon for <span id="ipNodeName">&hellip;</span>
            </div>
            <div class="nm-modal-body">
                <div class="nm-ip-search-wrap">
                    <input type="text" class="nm-ip-search" id="ipSearch" placeholder="Filter by name (e.g. &lsquo;database&rsquo;, &lsquo;firewall&rsquo;)&hellip;" oninput="NM.onIconSearchInput(this.value)">
                </div>
                <div class="nm-ip-grid" id="ipGrid"></div>
            </div>
            <div class="nm-modal-actions">
                <button class="nm-btn secondary" onclick="NM.closeIconPicker()">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Add-related-objects modal (opened from the node detail panel) -->
    <div class="nm-modal-overlay" id="relatedObjectsModal">
        <div class="nm-modal nm-modal-wide">
            <div class="nm-modal-header">
                Add objects related to <span id="rmSourceName">&hellip;</span>
            </div>
            <div class="nm-modal-body">
                <p class="nm-rm-intro">
                    Tick any to add them to the diagram. Each tick places the object as a new node (auto-laid-out around the source) and draws a connector that mirrors the relationship.
                </p>
                <div class="nm-rm-results" id="rmResults">
                    <div class="nm-rm-loading">Loading related objects&hellip;</div>
                </div>
            </div>
            <div class="nm-modal-actions">
                <button class="nm-btn secondary" onclick="NM.closeRelatedModal()">Cancel</button>
                <button class="nm-btn" id="rmAddBtn" onclick="NM.commitRelatedSelections()" disabled>Add</button>
            </div>
        </div>
    </div>

    <!-- Save as new version modal -->
    <div class="nm-modal-overlay" id="brandingModal">
        <div class="nm-modal nm-modal-wide">
            <div class="nm-modal-header">Diagram branding &mdash; header &amp; footer</div>
            <div class="nm-modal-body">
                <p style="font-size:13px;color:#6b7280;margin:0 0 12px 0;line-height:1.5;">
                    Override the organisation-wide header/footer for this diagram only. Placeholders show the default values that would be inherited &mdash; clear a slot and Save to <em>explicitly</em> blank it, or click <strong>Reset</strong> to clear all overrides and inherit the org-wide defaults configured in <a href="../system/branding/" target="_blank">System &rsaquo; Branding</a>.
                </p>
                <div class="nm-brand-grid">
                    <div></div>
                    <div class="col-head">Left</div>
                    <div class="col-head">Centre</div>
                    <div class="col-head">Right</div>

                    <div class="row-label">Header</div>
                    <input type="text" id="bmHeaderLeft" maxlength="200">
                    <input type="text" id="bmHeaderCenter" maxlength="200">
                    <input type="text" id="bmHeaderRight" maxlength="200">

                    <div class="row-label">Footer</div>
                    <input type="text" id="bmFooterLeft" maxlength="200">
                    <input type="text" id="bmFooterCenter" maxlength="200">
                    <input type="text" id="bmFooterRight" maxlength="200">
                </div>
                <div class="nm-brand-tokens">
                    <strong>Tokens</strong> resolved at render time:
                    <code>{{logo}}</code> &middot; <code>{{title}}</code> &middot; <code>{{author}}</code> &middot; <code>{{version}}</code> &middot; <code>{{modified}}</code>.
                    Header/footer only renders when a page outline is set &mdash; use the <strong>Page</strong> dropdown to pick one.
                </div>
            </div>
            <div class="nm-modal-actions">
                <button class="nm-btn secondary" onclick="NM.resetBrandingOverrides()" title="Clear all overrides — slots will inherit the org-wide defaults">Reset</button>
                <button class="nm-btn secondary" onclick="NM.closeBrandingModal()">Cancel</button>
                <button class="nm-btn" onclick="NM.commitBrandingOverrides()">Save</button>
            </div>
        </div>
    </div>

    <div class="nm-modal-overlay" id="newVersionModal">
        <div class="nm-modal">
            <div class="nm-modal-header">Save as new version</div>
            <div class="nm-modal-body">
                <p style="font-size:13px;color:#6b7280;margin:0 0 16px 0;line-height:1.5;">
                    Clones the current diagram (nodes, connectors, metadata) forward into a new editable version. The current version becomes a read-only historical record.
                </p>
                <div class="nm-form-group">
                    <label for="nvTitle">Title *</label>
                    <input type="text" id="nvTitle" maxlength="255">
                </div>
                <div class="nm-form-group">
                    <label for="nvDescription">Description</label>
                    <textarea id="nvDescription" maxlength="2000"></textarea>
                </div>
                <div class="nm-form-group">
                    <label for="nvVersionLabel">Version label</label>
                    <input type="text" id="nvVersionLabel" maxlength="50" placeholder="v2">
                    <small>Free text &mdash; e.g. &ldquo;v2&rdquo;, &ldquo;Q2 baseline&rdquo;, &ldquo;Post-migration&rdquo;.</small>
                </div>
            </div>
            <div class="nm-modal-actions">
                <button class="nm-btn secondary" onclick="NM.closeNewVersionModal()">Cancel</button>
                <button class="nm-btn" id="nvCreateBtn" onclick="NM.createNewVersion()">Create version</button>
            </div>
        </div>
    </div>

    <!-- Vendor: PNG/PDF export. html2canvas rasterises the diagram canvas;
         jsPDF wraps the rasterised image into a paper-sized PDF document.
         Loaded eagerly because the editor is a heavy page already and lazy
         loading adds complexity for marginal gain. -->
    <script src="../assets/js/vendor/html2canvas.min.js"></script>
    <script src="../assets/js/vendor/jspdf.umd.min.js"></script>
    <script src="../assets/js/network-mapper-icons.js"></script>
    <script src="../assets/js/network-mapper.js"></script>
    <script>
        NM.init(<?php echo $diagramId; ?>);

        // Keyboard shortcuts
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                NM.save();
            }
            if (e.key === 'Escape') {
                NM.closeNewVersionModal();
                NM.closeObjectPicker();
                NM.closeRelatedModal();
                NM.closeVersionsDropdown();
                NM.closePageDropdown();
                NM.closeIconPicker();
                NM.closeBrandingModal();
            }
        });

        // Click-out to close modals
        document.getElementById('newVersionModal').addEventListener('click', function (e) {
            if (e.target === e.currentTarget) NM.closeNewVersionModal();
        });
        document.getElementById('objectPickerModal').addEventListener('click', function (e) {
            if (e.target === e.currentTarget) NM.closeObjectPicker();
        });
        document.getElementById('relatedObjectsModal').addEventListener('click', function (e) {
            if (e.target === e.currentTarget) NM.closeRelatedModal();
        });
        document.getElementById('iconPickerModal').addEventListener('click', function (e) {
            if (e.target === e.currentTarget) NM.closeIconPicker();
        });
        document.getElementById('brandingModal').addEventListener('click', function (e) {
            if (e.target === e.currentTarget) NM.closeBrandingModal();
        });

        // Warn on unload if there are unsaved changes — guard against the user
        // hitting back/refresh after editing without saving
        window.addEventListener('beforeunload', function (e) {
            // The flag lives in the JS module; we ask it via a quick lookup.
            // No public getter — we rely on the autosave status DOM as a proxy.
            const status = document.getElementById('saveStatus');
            if (status && status.classList.contains('nm-status-unsaved')) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>
