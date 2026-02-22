<?php
/**
 * Calendar Module - Event tracking for certificates, contracts, maintenance, etc.
 */
session_start();
require_once '../config.php';

$current_page = 'calendar';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Calendar</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/itsm_calendar.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="calendar-container">
        <!-- Sidebar with category filters -->
        <div class="calendar-sidebar">
            <div class="sidebar-section">
                <button class="btn btn-primary btn-full" onclick="openEventModal()">+ New Event</button>
            </div>
            <div class="sidebar-section">
                <h3>Categories</h3>
                <div class="category-filter-list" id="categoryFilterList">
                    <div class="loading"><div class="spinner"></div></div>
                </div>
            </div>
        </div>

        <!-- Main calendar area -->
        <div class="calendar-main">
            <!-- Calendar header with navigation -->
            <div class="calendar-header">
                <div class="calendar-nav">
                    <button class="btn btn-secondary" onclick="goToToday()">Today</button>
                    <button class="btn btn-icon" onclick="navigatePrev()" title="Previous">&lsaquo;</button>
                    <button class="btn btn-icon" onclick="navigateNext()" title="Next">&rsaquo;</button>
                    <h2 class="calendar-title" id="calendarTitle">February 2026</h2>
                </div>
                <div class="view-toggle">
                    <button class="view-btn active" data-view="month" onclick="setView('month')">Month</button>
                    <button class="view-btn" data-view="week" onclick="setView('week')">Week</button>
                    <button class="view-btn" data-view="day" onclick="setView('day')">Day</button>
                </div>
            </div>

            <!-- Calendar grid -->
            <div class="calendar-grid" id="calendarGrid">
                <div class="loading"><div class="spinner"></div></div>
            </div>
        </div>
    </div>

    <!-- Event Modal -->
    <div class="modal" id="eventModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="eventModalTitle">New Event</h3>
            </div>
            <div class="modal-body">
                <input type="hidden" id="eventId" value="">
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" class="form-input" id="eventTitle" placeholder="Event title...">
                </div>
                <div class="form-group">
                    <label class="form-label">Category</label>
                    <select class="form-input" id="eventCategory">
                        <option value="">-- Select category --</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Date *</label>
                        <input type="date" class="form-input" id="eventStartDate">
                    </div>
                    <div class="form-group" id="startTimeGroup">
                        <label class="form-label">Start Time</label>
                        <input type="time" class="form-input" id="eventStartTime">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-input" id="eventEndDate">
                    </div>
                    <div class="form-group" id="endTimeGroup">
                        <label class="form-label">End Time</label>
                        <input type="time" class="form-input" id="eventEndTime">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-checkbox">
                        <input type="checkbox" id="eventAllDay" onchange="toggleAllDay()">
                        All day event
                    </label>
                </div>
                <div class="form-group">
                    <label class="form-label">Location</label>
                    <input type="text" class="form-input" id="eventLocation" placeholder="Location (optional)">
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-textarea" id="eventDescription" rows="3" placeholder="Description (optional)"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger" id="deleteEventBtn" onclick="deleteEvent()" style="display: none;">Delete</button>
                <div class="modal-footer-right">
                    <button class="btn btn-secondary" onclick="closeEventModal()">Cancel</button>
                    <button class="btn btn-primary" onclick="saveEvent()">Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Event Detail Popup (for quick view) -->
    <div class="event-popup" id="eventPopup">
        <div class="event-popup-header">
            <span class="event-popup-category" id="popupCategory"></span>
            <button class="event-popup-close" onclick="closeEventPopup()">&times;</button>
        </div>
        <h4 class="event-popup-title" id="popupTitle"></h4>
        <div class="event-popup-time" id="popupTime"></div>
        <div class="event-popup-location" id="popupLocation"></div>
        <div class="event-popup-description" id="popupDescription"></div>
        <div class="event-popup-actions">
            <button class="btn btn-secondary btn-sm" onclick="editEventFromPopup()">Edit</button>
        </div>
    </div>

    <script>window.API_BASE = '../api/calendar/';</script>
    <script src="../assets/js/itsm_calendar.js"></script>
</body>
</html>
