<?php
/**
 * Calendar Settings - Manage event categories
 */
session_start();
require_once '../../config.php';

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Calendar Settings</title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        .settings-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: 900px;
            margin: 0 auto;
            padding: 30px;
        }

        .settings-section {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
            margin-bottom: 30px;
        }

        .settings-section h2 {
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 10px;
        }

        .settings-section p {
            color: #666;
            margin-bottom: 20px;
        }

        .category-list {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            overflow: hidden;
        }

        .category-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #e0e0e0;
            gap: 15px;
        }

        .category-item:last-child {
            border-bottom: none;
        }

        .category-item:hover {
            background: #f9f9f9;
        }

        .category-color {
            width: 24px;
            height: 24px;
            border-radius: 4px;
            flex-shrink: 0;
        }

        .category-info {
            flex-grow: 1;
        }

        .category-name {
            font-weight: 500;
            color: #333;
        }

        .category-description {
            font-size: 13px;
            color: #666;
            margin-top: 2px;
        }

        .category-status {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            background: #e8f5e9;
            color: #2e7d32;
        }

        .category-status.inactive {
            background: #fafafa;
            color: #999;
        }

        .category-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #ef6c00;
            color: white;
        }

        .btn-primary:hover {
            background: #e65100;
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
            border: 1px solid #ddd;
        }

        .btn-secondary:hover {
            background: #e0e0e0;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-sm {
            padding: 6px 10px;
            font-size: 12px;
        }

        .add-category-btn {
            margin-bottom: 20px;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        /* Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: #fff;
            border-radius: 8px;
            width: 450px;
            max-width: 90vw;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: #333;
            font-size: 13px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #ef6c00;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-row {
            display: flex;
            gap: 16px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .form-group input[type="color"] {
            width: 60px;
            height: 40px;
            padding: 2px;
            cursor: pointer;
        }

        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #333;
            cursor: pointer;
        }

        .form-checkbox input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px;
        }

        .spinner {
            width: 32px;
            height: 32px;
            border: 3px solid #e0e0e0;
            border-top-color: #ef6c00;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="settings-container">
        <div class="settings-section">
            <h2>Event Categories</h2>
            <p>Manage categories used to organize calendar events. Each category can have a custom color for easy identification.</p>

            <button class="btn btn-primary add-category-btn" onclick="openCategoryModal()">+ Add Category</button>

            <div class="category-list" id="categoryList">
                <div class="loading"><div class="spinner"></div></div>
            </div>
        </div>
    </div>

    <!-- Category Modal -->
    <div class="modal" id="categoryModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="categoryModalTitle">Add Category</h3>
            </div>
            <div class="modal-body">
                <input type="hidden" id="categoryId" value="">
                <div class="form-group">
                    <label for="categoryName">Name *</label>
                    <input type="text" id="categoryName" placeholder="e.g., Certificate Expiry">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="categoryColor">Color</label>
                        <input type="color" id="categoryColor" value="#ef6c00">
                    </div>
                    <div class="form-group" style="display: flex; align-items: flex-end; padding-bottom: 10px;">
                        <label class="form-checkbox">
                            <input type="checkbox" id="categoryActive" checked>
                            Active
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label for="categoryDescription">Description</label>
                    <textarea id="categoryDescription" placeholder="Optional description..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeCategoryModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveCategory()">Save</button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/calendar/';
        let categories = [];

        document.addEventListener('DOMContentLoaded', function() {
            loadCategories();
        });

        async function loadCategories() {
            try {
                const response = await fetch(API_BASE + 'get_categories.php');
                const data = await response.json();

                if (data.success) {
                    categories = data.categories;
                    renderCategories();
                } else {
                    document.getElementById('categoryList').innerHTML =
                        '<div class="empty-state">Error loading categories</div>';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('categoryList').innerHTML =
                    '<div class="empty-state">Error loading categories</div>';
            }
        }

        function renderCategories() {
            const container = document.getElementById('categoryList');

            if (categories.length === 0) {
                container.innerHTML = '<div class="empty-state">No categories found. Add one to get started.</div>';
                return;
            }

            container.innerHTML = categories.map(cat => `
                <div class="category-item">
                    <div class="category-color" style="background-color: ${cat.color}"></div>
                    <div class="category-info">
                        <div class="category-name">${escapeHtml(cat.name)}</div>
                        ${cat.description ? `<div class="category-description">${escapeHtml(cat.description)}</div>` : ''}
                    </div>
                    <span class="category-status ${cat.is_active ? '' : 'inactive'}">
                        ${cat.is_active ? 'Active' : 'Inactive'}
                    </span>
                    <div class="category-actions">
                        <button class="btn btn-secondary btn-sm" onclick="editCategory(${cat.id})">Edit</button>
                        <button class="btn btn-danger btn-sm" onclick="deleteCategory(${cat.id})">Delete</button>
                    </div>
                </div>
            `).join('');
        }

        function openCategoryModal(categoryId = null) {
            const modal = document.getElementById('categoryModal');
            const title = document.getElementById('categoryModalTitle');

            // Reset form
            document.getElementById('categoryId').value = '';
            document.getElementById('categoryName').value = '';
            document.getElementById('categoryColor').value = '#ef6c00';
            document.getElementById('categoryDescription').value = '';
            document.getElementById('categoryActive').checked = true;

            if (categoryId) {
                const cat = categories.find(c => c.id == categoryId);
                if (cat) {
                    title.textContent = 'Edit Category';
                    document.getElementById('categoryId').value = cat.id;
                    document.getElementById('categoryName').value = cat.name;
                    document.getElementById('categoryColor').value = cat.color;
                    document.getElementById('categoryDescription').value = cat.description || '';
                    document.getElementById('categoryActive').checked = cat.is_active;
                }
            } else {
                title.textContent = 'Add Category';
            }

            modal.classList.add('active');
        }

        function editCategory(id) {
            openCategoryModal(id);
        }

        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.remove('active');
        }

        async function saveCategory() {
            const id = document.getElementById('categoryId').value;
            const name = document.getElementById('categoryName').value.trim();
            const color = document.getElementById('categoryColor').value;
            const description = document.getElementById('categoryDescription').value.trim();
            const isActive = document.getElementById('categoryActive').checked;

            if (!name) {
                alert('Please enter a category name');
                return;
            }

            const payload = {
                id: id || null,
                name,
                color,
                description,
                is_active: isActive
            };

            try {
                const response = await fetch(API_BASE + 'save_category.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();

                if (data.success) {
                    closeCategoryModal();
                    loadCategories();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error saving category');
            }
        }

        async function deleteCategory(id) {
            if (!confirm('Are you sure you want to delete this category?')) return;

            try {
                const response = await fetch(API_BASE + 'delete_category.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await response.json();

                if (data.success) {
                    loadCategories();
                } else {
                    alert('Error: ' + data.error);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Error deleting category');
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
