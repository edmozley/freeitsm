<?php
/**
 * CMDB Object Detail Page
 * Shows a single object: name (editable inline), class + parent breadcrumb,
 * dynamic properties form (with type-aware editors), parent/children, and
 * relationships (outgoing + incoming with add/remove).
 *
 * The AI summary header, impact panel, mini-graph and "suggest relationships"
 * features land in the next pass — this is the foundation.
 */
session_start();
require_once '../config.php';

$current_page = 'browse';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM - CMDB Object</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script src="../assets/js/toast.js"></script>
    <style>
        body { overflow: auto; height: auto; background: #f5f5f5; }
        .obj-page { max-width: 1100px; margin: 24px auto; padding: 0 20px; }

        .obj-breadcrumb { font-size: 13px; color: #6b7280; margin-bottom: 8px; }
        .obj-breadcrumb a { color: #be185d; text-decoration: none; }
        .obj-breadcrumb a:hover { text-decoration: underline; }
        .obj-breadcrumb .sep { margin: 0 6px; color: #d1d5db; }

        .obj-header {
            background: white;
            border-radius: 8px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            margin-bottom: 16px;
        }
        .obj-name {
            font-size: 28px;
            font-weight: 600;
            color: #111827;
            border: 1px solid transparent;
            padding: 4px 8px;
            margin: -4px -8px 8px -8px;
            border-radius: 4px;
            cursor: text;
            display: block;
            width: 100%;
            background: transparent;
            font-family: inherit;
        }
        .obj-name:hover { background: #fafafa; }
        .obj-name:focus { background: white; border-color: #be185d; outline: none; }

        .obj-meta { display: flex; gap: 18px; flex-wrap: wrap; color: #6b7280; font-size: 13px; align-items: center; }
        .obj-meta .class-badge {
            background: linear-gradient(135deg, #fce7f3, #fbcfe8);
            color: #be185d;
            padding: 4px 12px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 12px;
        }
        .obj-meta strong { color: #374151; font-weight: 500; }
        .obj-meta a { color: #be185d; text-decoration: none; }
        .obj-meta a:hover { text-decoration: underline; }

        .obj-actions { margin-top: 16px; display: flex; gap: 10px; }

        .obj-section {
            background: white;
            border-radius: 8px;
            padding: 20px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            margin-bottom: 16px;
        }
        .obj-section h3 {
            font-size: 14px;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 14px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Properties table */
        .props-table { width: 100%; border-collapse: collapse; }
        .props-table tr { border-bottom: 1px solid #f3f4f6; }
        .props-table tr:last-child { border-bottom: none; }
        .props-table td {
            padding: 10px 0;
            font-size: 14px;
            vertical-align: top;
        }
        .props-table td.prop-label {
            width: 220px;
            color: #6b7280;
            font-weight: 500;
            padding-right: 16px;
        }
        .props-table td.prop-label .req { color: #be185d; margin-left: 3px; }
        .props-table td.prop-value { color: #1f2937; }
        .props-table .prop-type-tag {
            display: inline-block;
            font-size: 10px;
            text-transform: uppercase;
            background: #f3f4f6;
            color: #9ca3af;
            padding: 1px 6px;
            border-radius: 3px;
            margin-left: 8px;
            font-weight: 500;
        }

        /* Editable cells */
        .prop-display {
            display: block;
            padding: 6px 8px;
            border: 1px solid transparent;
            border-radius: 4px;
            cursor: text;
            min-height: 22px;
        }
        .prop-display:hover { background: #fafafa; border-color: #e5e7eb; }
        .prop-display.empty { color: #d1d5db; font-style: italic; }
        .prop-display.empty:hover::after { content: ' — click to set'; color: #9ca3af; font-style: normal; }
        .prop-edit {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #be185d;
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
            background: white;
            box-shadow: 0 0 0 3px rgba(190, 24, 93, 0.1);
        }
        .prop-edit:focus { outline: none; }

        /* AI summary card — sits at the very top of the detail page */
        .ai-summary-card {
            background: linear-gradient(135deg, #fdf2f8, #fce7f3);
            border: 1px solid #fbcfe8;
            border-radius: 8px;
            padding: 18px 22px;
            margin-bottom: 16px;
        }
        .ai-summary-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .ai-summary-label {
            color: #be185d;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .ai-summary-text {
            color: #1f2937;
            font-size: 15px;
            line-height: 1.55;
        }
        .ai-summary-empty {
            color: #6b7280;
            font-style: italic;
            font-size: 14px;
        }
        .ai-summary-meta {
            color: #9d174d;
            font-size: 12px;
            margin-top: 8px;
            opacity: 0.75;
        }
        .ai-summary-spinner-dot {
            display: inline-block; width: 6px; height: 6px; margin: 0 2px;
            background: #be185d; border-radius: 50%; animation: aiblink2 1.4s infinite both;
        }
        .ai-summary-spinner-dot:nth-child(2) { animation-delay: 0.2s; }
        .ai-summary-spinner-dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes aiblink2 { 0%, 80%, 100% { opacity: 0.3; } 40% { opacity: 1; } }

        /* Impact panel — what would break if this object went offline */
        .impact-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        @media (max-width: 900px) { .impact-grid { grid-template-columns: 1fr; } }
        .impact-bucket {
            background: #fafafa;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px 14px;
        }
        .impact-bucket h4 {
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: 600;
            letter-spacing: 0.4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .impact-bucket .count-badge {
            background: white;
            color: #be185d;
            padding: 1px 8px;
            border-radius: 999px;
            font-size: 11px;
            border: 1px solid #fbcfe8;
        }
        .impact-bucket ul { list-style: none; padding: 0; margin: 0; max-height: 220px; overflow-y: auto; }
        .impact-bucket li {
            padding: 5px 0;
            font-size: 13px;
            border-bottom: 1px solid #f3f4f6;
        }
        .impact-bucket li:last-child { border-bottom: none; }
        .impact-bucket a { color: #be185d; text-decoration: none; font-weight: 500; }
        .impact-bucket a:hover { text-decoration: underline; }
        .impact-bucket .meta { color: #6b7280; font-size: 11px; }
        .impact-bucket .empty { color: #9ca3af; font-style: italic; font-size: 13px; }

        /* Activity panel — tickets that reference this object */
        .activity-bucket-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            text-transform: uppercase;
            color: #6b7280;
            font-weight: 600;
            letter-spacing: 0.4px;
            margin-bottom: 8px;
        }
        .activity-bucket-head .count-badge {
            background: white;
            color: #be185d;
            padding: 1px 8px;
            border-radius: 999px;
            font-size: 11px;
            border: 1px solid #fbcfe8;
        }
        .ticket-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            background: #fafafa;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            margin-bottom: 6px;
            text-decoration: none;
            color: inherit;
            transition: background 0.12s;
        }
        .ticket-card:last-child { margin-bottom: 0; }
        .ticket-card:hover { background: #fce7f3; border-color: #fbcfe8; }
        .ticket-card.closed { opacity: 0.7; }
        .ticket-card-body { flex: 1; min-width: 0; }
        .ticket-card-line1 {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 4px;
        }
        .ticket-card-number {
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 11px;
            color: #9ca3af;
            font-weight: 500;
        }
        .ticket-card-subject {
            color: #111827;
            font-weight: 500;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 1;
            min-width: 0;
        }
        .ticket-card-meta {
            display: flex;
            gap: 10px;
            color: #6b7280;
            font-size: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .ticket-status-pill {
            display: inline-block;
            padding: 1px 8px;
            font-size: 11px;
            border-radius: 999px;
            border: 1px solid;
            font-weight: 500;
        }
        .activity-empty {
            padding: 20px 12px;
            color: #9ca3af;
            font-style: italic;
            font-size: 13px;
            text-align: center;
        }

        /* Inline mini-graph — parent / this / children + related */
        .mini-graph {
            background: white;
            padding: 24px 12px;
            border-radius: 6px;
            border: 1px dashed #e5e7eb;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0;
        }
        .mg-row {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            position: relative;
            max-width: 100%;
        }
        .mg-node {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #fafafa;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            color: #374151;
            text-decoration: none;
            font-size: 13px;
            max-width: 220px;
        }
        .mg-node:hover { background: #fdf2f8; border-color: #fbcfe8; color: #be185d; }
        .mg-node.this {
            background: linear-gradient(135deg, #be185d, #9d174d);
            color: white;
            border-color: #9d174d;
            font-weight: 600;
            cursor: default;
        }
        .mg-node .mg-class { font-size: 11px; opacity: 0.7; }
        .mg-node.this .mg-class { color: rgba(255, 255, 255, 0.85); opacity: 1; }
        .mg-node-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 160px;
        }
        .mg-connector {
            width: 2px;
            height: 16px;
            background: #d1d5db;
            margin: 0 auto;
        }
        .mg-side-rels {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            width: 100%;
            margin-top: 12px;
            gap: 16px;
        }
        .mg-side {
            flex: 1;
            min-width: 0;
        }
        .mg-side-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #9ca3af;
            margin-bottom: 6px;
            font-weight: 600;
            letter-spacing: 0.4px;
        }
        .mg-rel-link {
            display: block;
            font-size: 12px;
            color: #6b7280;
            padding: 3px 0;
            text-decoration: none;
        }
        .mg-rel-link:hover { color: #be185d; }
        .mg-rel-link strong { color: #374151; }
        .mg-rel-link:hover strong { color: #be185d; }
        .mg-rel-verb { color: #9ca3af; font-style: italic; margin: 0 4px; }

        /* Coloured dropdown value pill */
        .prop-display.dropdown-pill {
            display: inline-block;
            padding: 3px 12px;
            border: 1px solid;
            border-radius: 999px;
            font-weight: 500;
            font-size: 13px;
        }
        .prop-display.dropdown-pill:hover { filter: brightness(0.95); }

        /* Object-ref value pill */
        .obj-ref-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: #fdf2f8;
            border: 1px solid #fbcfe8;
            border-radius: 999px;
            font-size: 13px;
            color: #be185d;
            text-decoration: none;
            font-weight: 500;
        }
        .obj-ref-pill:hover { background: #fce7f3; }
        .obj-ref-pill .pill-class { color: #9d174d; opacity: 0.7; font-size: 11px; }

        /* Hierarchy + relationships lists */
        .item-list { list-style: none; padding: 0; margin: 0; }
        .item-list li {
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }
        .item-list li:last-child { border-bottom: none; }
        .item-list a { color: #be185d; text-decoration: none; font-weight: 500; }
        .item-list a:hover { text-decoration: underline; }
        .item-list .meta { color: #6b7280; font-size: 12px; }
        .item-list .verb { color: #4b5563; font-style: italic; padding: 0 8px; }

        .empty-row { color: #9ca3af; font-style: italic; padding: 8px 0; font-size: 14px; }

        /* Relationships split into outgoing + incoming columns */
        .rel-split { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media (max-width: 800px) { .rel-split { grid-template-columns: 1fr; } }
        .rel-col h4 {
            font-size: 12px;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 8px;
            font-weight: 600;
        }

        /* Add buttons */
        .btn-mini {
            background: #fdf2f8;
            color: #be185d;
            border: 1px solid #fbcfe8;
            padding: 5px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
        }
        .btn-mini:hover { background: #fce7f3; }
        .x-btn {
            background: none;
            border: none;
            color: #d1d5db;
            cursor: pointer;
            font-size: 16px;
            padding: 0 4px;
            line-height: 1;
        }
        .x-btn:hover { color: #b91c1c; }

        .btn { padding: 9px 18px; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; border: 1px solid transparent; }
        .btn-primary { background: #be185d; color: white; }
        .btn-primary:hover { background: #9d174d; }
        .btn-secondary { background: white; color: #374151; border-color: #d1d5db; }
        .btn-danger { background: white; color: #b91c1c; border-color: #fecaca; }
        .btn-danger:hover { background: #fef2f2; }

        /* Modal */
        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1000; }
        .modal.active { display: flex; align-items: center; justify-content: center; }
        .modal-content { background: white; border-radius: 8px; width: 520px; max-width: 95vw; }
        .modal-header { padding: 18px 24px; border-bottom: 1px solid #e5e7eb; font-weight: 600; font-size: 16px; }
        .modal-body { padding: 24px; }
        .modal-actions {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px; }
        .form-group select, .form-group input {
            width: 100%; padding: 9px 12px; border: 1px solid #d1d5db;
            border-radius: 4px; font-size: 14px;
        }

        /* Autocomplete */
        .autocomplete-wrap { position: relative; }
        .autocomplete-results {
            position: absolute;
            top: 100%; left: 0; right: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            max-height: 240px;
            overflow-y: auto;
            z-index: 10;
            margin-top: 4px;
            display: none;
        }
        .autocomplete-results.active { display: block; }
        .ac-result {
            padding: 8px 12px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            font-size: 13px;
        }
        .ac-result:hover, .ac-result.highlighted { background: #fdf2f8; color: #be185d; }
        .ac-result .ac-class { color: #9ca3af; font-size: 11px; }
        .ac-empty { padding: 10px; color: #9ca3af; font-size: 13px; text-align: center; }

        /* Inline parent picker */
        .parent-edit-wrap { display: inline-block; min-width: 200px; }

        /* Edit-property-definition cog button next to each property type tag */
        .prop-cog {
            background: none;
            border: none;
            color: #d1d5db;
            cursor: pointer;
            padding: 2px 4px;
            margin-left: 4px;
            border-radius: 3px;
            line-height: 1;
            vertical-align: middle;
        }
        .prop-cog:hover { color: #be185d; background: #fdf2f8; }
        .prop-cog svg { width: 13px; height: 13px; vertical-align: middle; }

        /* Floating draggable property-edit modal — no backdrop so the object stays visible */
        .float-modal {
            position: fixed;
            top: 100px;
            left: 50%;
            transform: translateX(-50%);
            width: 540px;
            max-width: 95vw;
            background: white;
            border-radius: 8px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.25);
            z-index: 3000;
            display: none;
            flex-direction: column;
        }
        .float-modal.active { display: flex; }
        .float-modal-header {
            padding: 12px 16px;
            background: linear-gradient(135deg, #be185d, #9d174d);
            color: white;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: move;
            user-select: none;
        }
        .float-modal-header span { font-weight: 600; font-size: 14px; }
        .float-modal-close {
            background: none; border: none; color: white;
            font-size: 22px; cursor: pointer; line-height: 1;
            padding: 0; opacity: 0.85;
        }
        .float-modal-close:hover { opacity: 1; }
        .float-modal-body { padding: 18px 20px; overflow-y: auto; max-height: calc(100vh - 220px); }
        .float-modal-actions {
            padding: 12px 20px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            background: #fafafa;
            border-radius: 0 0 8px 8px;
        }
        .float-modal .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .float-modal .form-check { display: flex; align-items: center; gap: 8px; font-size: 14px; }
        .float-modal .form-check input { width: auto; }
        .key-hint { font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="obj-page" id="objPage">
        <div style="text-align: center; padding: 60px; color: #9ca3af;">Loading…</div>
    </div>

    <!-- Add Relationship Modal -->
    <div class="modal" id="relModal">
        <div class="modal-content">
            <div class="modal-header">Add relationship</div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="relTypeSelect">Verb *</label>
                    <select id="relTypeSelect"></select>
                    <small id="relInverseHint" style="color: #6b7280; font-size: 12px; display: block; margin-top: 4px;"></small>
                </div>
                <div class="form-group">
                    <label for="relTargetInput">Linked object *</label>
                    <div class="autocomplete-wrap">
                        <input type="text" id="relTargetInput" autocomplete="off" placeholder="Type to search any object…">
                        <input type="hidden" id="relTargetId">
                        <div class="autocomplete-results" id="relTargetResults"></div>
                    </div>
                    <small style="color: #6b7280; font-size: 12px;">Type at least 1 character to search across every class.</small>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeRelModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveRelationship()">Add</button>
            </div>
        </div>
    </div>

    <!-- Parent Picker Modal -->
    <div class="modal" id="parentModal">
        <div class="modal-content">
            <div class="modal-header">Set parent</div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="parentInput">Parent object</label>
                    <div class="autocomplete-wrap">
                        <input type="text" id="parentInput" autocomplete="off" placeholder="Type to search…">
                        <input type="hidden" id="parentId">
                        <div class="autocomplete-results" id="parentResults"></div>
                    </div>
                    <small style="color: #6b7280; font-size: 12px;">The object this one belongs inside (e.g. a Database's parent might be a SQL Instance).</small>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-danger" onclick="clearParent()">Clear parent</button>
                <button type="button" class="btn btn-secondary" onclick="closeParentModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveParent()">Save</button>
            </div>
        </div>
    </div>

    <!-- Floating draggable Edit-Property-Definition modal — drag the pink header to move it -->
    <div class="float-modal" id="propDefModal">
        <div class="float-modal-header" id="propDefModalHeader">
            <span id="propDefModalTitle">Edit property</span>
            <button type="button" class="float-modal-close" onclick="closePropDefModal()">&times;</button>
        </div>
        <div class="float-modal-body">
            <form id="propDefForm" onsubmit="event.preventDefault(); savePropDef();">
                <input type="hidden" id="pdId">
                <div class="form-group">
                    <label for="pdLabel">Label *</label>
                    <input type="text" id="pdLabel" required maxlength="150">
                    <small>What an analyst sees on the object form. Edit freely.</small>
                </div>
                <div class="form-group">
                    <label for="pdKey">Key</label>
                    <input type="text" id="pdKey" maxlength="100">
                    <small class="key-hint">Immutable identifier — change only if you have a strong reason.</small>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="pdType">Type *</label>
                        <select id="pdType" required onchange="onPropDefTypeChange()">
                            <option value="text">Text</option>
                            <option value="number">Number</option>
                            <option value="date">Date</option>
                            <option value="boolean">Yes/No</option>
                            <option value="dropdown">Dropdown</option>
                            <option value="object_ref">Object Reference</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="pdDisplayOrder">Display Order</label>
                        <input type="number" id="pdDisplayOrder" value="0">
                    </div>
                </div>
                <div class="form-group" id="pdTargetClassGroup" style="display: none;">
                    <label for="pdTargetClass">Target Class *</label>
                    <select id="pdTargetClass">
                        <option value="">— Select —</option>
                    </select>
                    <small>The class of objects this property can point at.</small>
                </div>
                <div class="form-group" id="pdOptionsGroup" style="display: none;">
                    <label>Dropdown Options</label>
                    <div id="pdOptionsContainer"></div>
                    <small>One row per allowed value, with an optional colour. The colour drives a coloured pill on the object detail page when set; leave grey for plain text. Existing object values matching the new list are preserved; values no longer in the list stay on existing objects but are no longer offered in the picker until you clear them.</small>
                </div>
                <div class="form-group">
                    <label class="form-check">
                        <input type="checkbox" id="pdIsRequired"> Required
                    </label>
                </div>
            </form>
        </div>
        <div class="float-modal-actions">
            <button type="button" class="btn btn-secondary" onclick="closePropDefModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="savePropDef()">Save</button>
        </div>
    </div>

    <script>
        window.OBJECT_ID = <?php echo isset($_GET['id']) ? (int)$_GET['id'] : 0; ?>;
    </script>
    <script src="options-editor.js?v=1"></script>
    <script src="object.js?v=5"></script>
</body>
</html>
