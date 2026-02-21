# FreeITSM Changelog

This file tracks all fixes, improvements, and features for website updates.
Items are logged here and then published to the updates page at freeitsm.co.uk/updates.html.

Each entry has a unique ID (3-digit, sequential) used as `data-id` on the website update items.
When publishing to the website, move entries from **Unpublished** to the **Published** section.

---

## Unpublished

### 21 February 2026

| ID  | Module            | Type        | Description |
|-----|-------------------|-------------|-------------|
| 055 | Assets            | Feature     | PowerShell inventory agent and system-info ingest API that collects and syncs hardware, disks, network, GPU, TPM, BitLocker, and software data per asset |
| 056 | Assets            | Improvement | Widen hostname and service_tag columns to VARCHAR(50) and add new asset columns for domain, logged-in user, last boot, TPM, BitLocker, and GPU |
| 057 | Software          | Feature     | Distinguish system components from user-visible applications using registry SystemComponent flag, with tabbed views (Applications / Components / All) in both the software module and asset detail |
| 058 | Assets            | Feature     | Per-analyst customisable dashboard with widget library, Chart.js charts (bar/pie/doughnut), status filtering, and drag-and-drop reordering |
| 059 | Assets            | Feature     | Full-page widget library management with search, inline editing, duplicate, and delete for dashboard widgets |
| 060 | Tickets           | Feature     | Per-mailbox email whitelist (domains and addresses) with searchable activity log for imported and rejected emails |

---

## Published

### 21 February 2026

| ID  | Module            | Type        | Description |
|-----|-------------------|-------------|-------------|
| 035 | Contracts         | Feature     | Configurable contract terms tabs with TinyMCE rich text editors for detailed terms like special terms, KPIs, SLAs, and termination conditions |
| 036 | Contracts         | Improvement | Widened contract edit and view pages from 800px/900px to 1120px for better use of screen space |
| 037 | Contracts         | Improvement | Unified contract and terms saving into a single Save button with automatic ID handling for new contracts |
| 038 | System            | Feature     | Global toast notification system with configurable position (9 positions via visual grid picker in System settings), colour-coded types with icons, and slide-in animations |
| 039 | System            | Feature     | Security settings with trusted device (skip OTP for configurable days with per-user toggle), password expiry policy, and account lockout after failed login attempts |
| 040 | Service Status    | Feature     | Service status dashboard with configurable services, incident tracking, multi-service impact levels, and worst-status board view |
| 041 | System            | Feature     | Configurable module colours with per-module primary and secondary colour pickers, live preview, and reset to defaults |
| 042 | System            | Feature     | Migrate entire database layer from SQL Server Express (PDO ODBC) to MySQL 8.0+ (PDO MySQL) across ~55 PHP files and 47 tables |
| 043 | System            | Improvement | Replace all OUTPUT INSERTED.id and SCOPE_IDENTITY() patterns with MySQL lastInsertId() across 18 API files |
| 044 | System            | Improvement | Convert all SQL Server syntax (GETUTCDATE, DATEADD, DATEDIFF, CONVERT, TOP, CAST NVARCHAR, OFFSET/FETCH) to MySQL equivalents |
| 045 | System            | Improvement | Rewrite database schema file (freeitsm.sql) and schema engine (db_verify.php) for MySQL with InnoDB and utf8mb4 |
| 046 | System            | Improvement | Replace OBJECT_ID table existence checks with information_schema queries |
| 047 | System            | Fix         | Parameterize all raw token_data SQL updates and integer interpolation to prevent SQL injection (6 files) |
| 048 | System            | Improvement | Update setup page to check for pdo_mysql extension instead of pdo_odbc |
| 049 | System            | Feature     | Per-module demo data import with 11 JSON datasets covering tickets, assets, knowledge, changes, calendar, morning checks, contracts, services, software, and forms |
| 050 | Knowledge         | Fix         | Replace SQL Server DATALENGTH() with MySQL LENGTH() in AI chat, embedding stats, and article embedding queries |
| 051 | Knowledge         | Improvement | Ask AI button navigates to knowledge page first when clicked from Settings or Review, then slides open the chat panel |
| 052 | Knowledge         | Improvement | Clicking an article link in AI chat loads the article in-page while keeping the chat panel open |
| 053 | Software          | Improvement | Expand software demo data from 5 to 20 applications and 13 licences with varied types (subscription, perpetual, expired, bundled), realistic notes, and vendor details |
| 054 | Software          | Feature     | Cross-module demo data linking software installations to asset computers, with conditional import prompt when both modules are imported |

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
