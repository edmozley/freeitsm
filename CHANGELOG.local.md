# FreeITSM Changelog

This file tracks all fixes, improvements, and features for website updates.
Items are logged here and then published to the updates page at freeitsm.co.uk/updates.html.

Each entry has a unique ID (3-digit, sequential) used as `data-id` on the website update items.
When publishing to the website, move entries from **Unpublished** to the **Published** section.

---

## Unpublished

| ID  | Module            | Type        | Description |
|-----|-------------------|-------------|-------------|
| 101 | Watchtower        | Feature     | Add unified attention dashboard showing actionable items across all modules with color-coded status indicators and auto-refresh |
| 100 | All Modules       | Feature     | Add interactive help pages with scroll-spy sidebar navigation to Tickets, Knowledge, Calendar, Morning Checks, Reporting, Software, Contracts, Forms, and Service Status modules |
| 087 | Calendar          | Fix         | Replace broken SVG chevron icons on month navigation buttons with reliable text-based chevrons |
| 088 | Self-Service      | Feature     | Add self-service portal with user registration, login, and account claiming for passwordless users |
| 089 | Self-Service      | Feature     | Add self-service dashboard showing ticket summary cards, recent tickets, and live system status |
| 090 | Self-Service      | Feature     | Add ticket creation and detail view pages in self-service portal |
| 091 | Self-Service      | Feature     | Add mailbox selection dropdown to self-service new ticket form so replies are sent from the correct mailbox |
| 092 | Self-Service      | Feature     | Add drag-and-drop file attachments to self-service new ticket form |
| 093 | Self-Service      | Feature     | Add initials avatar circle with dropdown menu for account management on all portal pages |
| 094 | Self-Service      | Feature     | Add multi-factor authentication (TOTP) support for self-service users reusing existing MFA libraries |
| 095 | Self-Service      | Feature     | Add preferred name setting so users can choose how they are addressed (e.g. Ed instead of Ed Mozley) |
| 096 | Self-Service      | Feature     | Add change password functionality to self-service user account menu |
| 097 | Self-Service      | Improvement | Deduplicate MFA endpoints by making api/myaccount/ endpoints work for both analysts and self-service users via shared auth context helper |
| 098 | Software          | Feature     | Add customisable dashboard with version distribution, top installed, and publisher widgets with click-to-drill-down and cog editing |
| 099 | Assets            | Feature     | Add device manager enumeration showing device category, name, driver info, and status per asset |

---

## Published

### 22 February 2026

| ID  | Module            | Type        | Description |
|-----|-------------------|-------------|-------------|
| 077 | Tickets           | Feature     | Per-analyst customisable dashboard with widget library, Chart.js charts (bar/pie/doughnut/line), status filtering, and drag-and-drop reordering |
| 078 | Tickets           | Feature     | Full-page widget library management with search, inline editing, duplicate, and delete for ticket dashboard widgets |
| 079 | Tickets           | Feature     | Time-series widgets showing tickets created or closed per day (current month) and per month (last 12 months) with gap-filled labels |
| 080 | Tickets           | Feature     | Multi-series stacked bar and multi-line charts with series breakdown by status or priority, and created-vs-closed comparison charts |
| 081 | System            | Feature     | Dashboard widgets demo data import with 15 pre-built ticket widgets and per-analyst dashboard layouts for 3 analysts |
| 082 | Tickets           | Fix         | Fix tickets by analyst and owner dashboard widgets using wrong column name (a.name instead of a.full_name) |
| 083 | Tickets           | Fix         | Setting ticket owner now also sets assigned_analyst_id so the analyst appears in dashboard widgets |
| 084 | Tickets           | Feature     | Dashboard widgets now support configurable date range, department filter, and day/month/year time grouping |
| 085 | Tickets           | Improvement | Widget editor auto-generates description from selected parameters (e.g. "Tickets by department (last 30 days)") |
| 086 | Tickets           | Feature     | Edit widget properties directly from the dashboard via cog icon modal, with shared form code between library and dashboard |

### 21 February 2026

| ID  | Module            | Type        | Description |
|-----|-------------------|-------------|-------------|
| 064 | Tickets           | Feature     | Automated email templates triggered by ticket events (new ticket from email, ticket assigned, ticket closed) with merge codes for ticket reference, requester, analyst, department, and dates |
| 065 | Tickets           | Feature     | Ask AI button in ticket detail view that opens a slide-in chat panel, auto-sends ticket context to the knowledge base AI, and links referenced articles in new tabs |
| 066 | Knowledge         | Feature     | Article versioning — save as new version button archives previous content, displays version number on article view, and stores version history |
| 067 | System            | Feature     | Shared customAlert component replacing native alert/confirm with styled modals supporting info, warning, danger, and success types |
| 068 | Tickets           | Feature     | Staff rota with weekly grid showing analyst shift patterns, WFH/office location, and on-call status; configurable shifts and include-weekends setting in Ticket Settings |
| 069 | Change Management | Feature     | Risk assessment matrix with 5x5 colour-coded grid, likelihood/impact scoring (1-5), auto-calculated risk score and level displayed on list cards and detail view |
| 070 | Change Management | Feature     | Post-implementation review structured fields (success status, actual start/end, lessons learned, follow-up actions) visible when change status is Completed or Failed |
| 071 | Change Management | Feature     | Activity timeline combining comments and audit trail into a single chronological view on the change detail page, with inline comment posting |
| 072 | Change Management | Feature     | Server-side audit logging that tracks all field changes by comparing old and new values before each update, with automatic audit entries for status changes |
| 073 | Change Management | Feature     | CAB (Change Advisory Board) multi-member approval workflow with required/optional reviewers, Approve/Reject/Abstain voting, configurable threshold (all or majority), and auto-status transitions |
| 074 | Change Management | Feature     | CAB review panel in change detail view showing member cards with colour-coded vote status, progress badge, and inline vote form for pending CAB members |
| 075 | Change Management | Feature     | My CAB Reviews filter on approvals page showing changes where current analyst has a pending CAB vote, with CAB progress badge on approval cards |
| 076 | Change Management | Improvement | Help guide moved from modal to dedicated full page with left-pane section navigation, scroll-spy active section highlighting, and smooth scrolling |
| 055 | Assets            | Feature     | PowerShell inventory agent and system-info ingest API that collects and syncs hardware, disks, network, GPU, TPM, BitLocker, and software data per asset |
| 056 | Assets            | Improvement | Widen hostname and service_tag columns to VARCHAR(50) and add new asset columns for domain, logged-in user, last boot, TPM, BitLocker, and GPU |
| 057 | Software          | Feature     | Distinguish system components from user-visible applications using registry SystemComponent flag, with tabbed views (Applications / Components / All) in both the software module and asset detail |
| 058 | Assets            | Feature     | Per-analyst customisable dashboard with widget library, Chart.js charts (bar/pie/doughnut), status filtering, and drag-and-drop reordering |
| 059 | Assets            | Feature     | Full-page widget library management with search, inline editing, duplicate, and delete for dashboard widgets |
| 060 | Tickets           | Feature     | Per-mailbox email whitelist (domains and addresses) with searchable activity log for imported and rejected emails |
| 061 | Tickets           | Improvement | Configurable email actions for rejected and imported emails (delete, move to deleted, mark as read, move to folder) with modern toggle switch for active state |
| 062 | Tickets           | Fix         | Fix move-to-folder by resolving display names to Graph API folder IDs, add processing log JSON with clickable detail view in activity log, and folder Verify button |
| 063 | Tickets           | Feature     | Full-screen mailbox activity log with left-hand mailbox panel, search, pagination, and processing log detail view |
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
