# FreeITSM Changelog

This file tracks all fixes, improvements, and features for website updates.
Items are logged here and then published to the updates page at freeitsm.co.uk/updates.html.

Each entry has a unique ID (3-digit, sequential) used as `data-id` on the website update items.
When publishing to the website, move entries from **Unpublished** to the **Published** section.

---

## Unpublished

### 16 February 2026

| ID  | Module            | Type        | Description |
|-----|-------------------|-------------|-------------|
| 035 | Contracts         | Feature     | Configurable contract terms tabs with TinyMCE rich text editors for detailed terms like special terms, KPIs, SLAs, and termination conditions |
| 036 | Contracts         | Improvement | Widened contract edit and view pages from 800px/900px to 1120px for better use of screen space |
| 037 | Contracts         | Improvement | Unified contract and terms saving into a single Save button with automatic ID handling for new contracts |
| 038 | System            | Feature     | Global toast notification system with configurable position (9 positions via visual grid picker in System settings), colour-coded types with icons, and slide-in animations |
| 039 | System            | Feature     | Security settings with trusted device (skip OTP for configurable days with per-user toggle), password expiry policy, and account lockout after failed login attempts |
| 040 | Service Status    | Feature     | Service status dashboard with configurable services, incident tracking, multi-service impact levels, and worst-status board view |

---

## Published

### 15 February 2026

| ID  | Module            | Type        | Description |
|-----|-------------------|-------------|-------------|
| 023 | Morning Checks    | Improvement | PDF export now uses jsPDF with selectable text, coloured status values, and no more cropping issues |
| 024 | System            | Feature     | Setup verification page at /setup checks config files, database connection, PHP extensions, and security settings |
| 025 | System            | Improvement | Default admin account (admin/freeitsm) seeded in SQL script and auto-created by db_verify if no analysts exist |
| 026 | System            | Feature     | Setup page offers one-click admin account creation when database connects but has no analyst accounts |
| 027 | Software          | Improvement | Settings page restructured to use standard tabbed layout matching other modules |
| 028 | Contracts         | Feature     | Supplier types and supplier statuses lookup tables with full CRUD in contracts settings |
| 029 | Contracts         | Feature     | Suppliers expanded with reg number, VAT number, type, status, address, questionnaire dates, and comments fields |
| 030 | Contracts         | Feature     | Contacts expanded with job title, direct dial, and switchboard fields |
| 031 | Contracts         | Feature     | Contract statuses and payment schedules lookup tables with CRUD in settings |
| 032 | Contracts         | Feature     | Contracts expanded with status, notice date, value, currency, payment schedule, cost centre, DMS link, description, terms, and data protection fields |
| 033 | Contracts         | Feature     | Dashboard redesigned with left sidebar panel showing stats, quick links, and universal search across contracts, suppliers, and contacts |
| 034 | Contracts         | Improvement | Contract view page updated to display all new fields organised into dates, financial, and terms & data protection sections |
| 019 | Morning Checks    | Feature     | Settings page with tabbed layout, modal popups for add/edit checks, and drag-and-drop reordering with grip handles |
| 020 | Morning Checks    | Improvement | Clicking a bar in the 30-day chart jumps to that day's checks |
| 021 | Morning Checks    | Improvement | Modern toggle switch replacing checkbox for Active/Inactive status in settings |
| 022 | Morning Checks    | Fix         | PDF export no longer chopped by the 30-day chart footer overlapping content |
| 010 | Change Management | Feature     | Calendar View with month/week/day views, status-based filtering, and click-through to change detail |
| 011 | Change Management | Feature     | Settings page with field visibility toggles for each section of the change form |
| 012 | Change Management | Feature     | Approvals page with All/Assigned to me/Requested by me filter tabs |
| 013 | Forms             | Improvement | Form layout constraint — elements snap to 50% or 100% width only (max 2 per row) |
| 014 | Change Management | Improvement | Sticky editor header with Cancel/Save buttons positioned at the top |
| 015 | Change Management | Improvement | Search modal replacing sidebar search box — draggable dialog matching ticket module pattern |
| 016 | Change Management | Improvement | Sticky detail view header keeping buttons and properties visible while scrolling |
| 017 | Change Management | Fix         | ODBC datetime format — append seconds for SQL Server ODBC Driver 17 compatibility |
| 018 | Change Management | Fix         | Approvals count query consolidated to single query with SUM/CASE for ODBC compatibility |
