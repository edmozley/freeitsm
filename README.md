<h1 align="center">Thanks for Visiting 👋</h1>

<p align="center">
If you're downloading this project and find it useful,<br>
⭐ Please consider starring it<br>
🌍 And mentioning <a href="https://freeitsm.co.uk">freeitsm.co.uk</a> on Reddit, Hacker News, Spiceworks, LinkedIn, or anywhere IT pros hang out<br><br>
It genuinely helps and means a lot!
</p>

<p align="center">
<a href="https://github.com/edmozley/freeitsm/stargazers"><img src="https://img.shields.io/github/stars/edmozley/freeitsm?style=social" alt="GitHub stars"></a>
</p>

# FreeITSM - Open Source Service Desk Platform

A comprehensive web-based IT Service Management (ITSM) platform with 11 integrated modules covering tickets, assets, knowledge, change management, calendar, morning checks, reporting, software inventory, dynamic forms, contracts, service status, and system administration. Includes analyst account management with password reset and TOTP multi-factor authentication.

> **⚠️ Note for early adopters:** If you downloaded FreeITSM before 18 February 2026, the project required Microsoft SQL Server Express and ODBC drivers — sorry about that! The original choice of SQL Server made sense at the time (it was the database I was most familiar with), but it created a painful setup experience: downloading SQL Server Express, installing ODBC drivers, enabling Mixed Mode Authentication, and troubleshooting driver compatibility issues. That's a lot of friction for an open-source project that's supposed to be easy to get running.
>
> FreeITSM now runs on **MySQL**, which comes pre-installed with WAMP, XAMPP, and most web hosting stacks. No extra downloads, no driver headaches. If you've already set up with SQL Server, you'll need to migrate your data to MySQL and update your `db_config.php` — but for new installations, it's just clone and go.

## Table of Contents

- [Quick Start](#-quick-start)
  - [Prerequisites](#prerequisites)
  - [Installation](#installation)
  - [Configuration Files](#configuration-files)
- [Technology Stack](#technology-stack)
- [ITSM Modules](#itsm-modules)
- [Directory Structure](#directory-structure)
- [Shared Components](#shared-components)
- [Module Details](#module-details)
  - [Tickets](#tickets-tickets)
  - [Assets](#assets-asset-management)
  - [Knowledge](#knowledge-knowledge)
  - [Change Management](#change-management-change-management)
  - [Calendar](#calendar-calendar)
  - [Morning Checks](#morning-checks-morning-checks)
  - [Reporting](#reporting-reporting)
  - [Software](#software-software)
  - [System](#system-system)
  - [Forms](#forms-forms)
  - [Contracts](#contracts-contracts)
  - [Service Status](#service-status-service-status)
  - [Self-Service Portal](#self-service-portal-self-service)
- [API Reference](#api-reference)
- [Database](#database)
- [Security](#security)
- [Key Workflows](#key-workflows)
- [Development Notes](#development-notes)
- [File Locations Quick Reference](#file-locations-quick-reference)

---

## 🚀 Quick Start

### Prerequisites
- **Web Server**: WAMP, XAMPP, LAMP, or any PHP-capable web server
- **PHP**: 7.4 or higher
- **Database**: MySQL 8.0 or higher (included with WAMP/XAMPP)
- **Extensions**: PHP PDO, pdo_mysql, curl, openssl, mbstring
- **Database credentials file**: A `db_config.php` file stored **outside your web root** (e.g. `C:\wamp64\db_config.php`) — see step 2 below. The path is configured in `config.php`.

### Installation

> **Tip:** After completing these steps, navigate to `/setup/` to verify everything is configured correctly.

1. **Clone the repository**
   ```bash
   git clone https://github.com/edmozley/freeitsm.git
   cd freeitsm
   ```

2. **Configure database credentials**
   - Copy `db_config.sample.php` to a secure location **outside your web root**:
     ```
     C:\wamp64\db_config.php  (recommended)
     ```
   - Edit the copied file with your MySQL credentials:
     ```php
     define('DB_SERVER', 'localhost');
     define('DB_NAME', 'freeitsm');
     define('DB_USERNAME', 'your_username');
     define('DB_PASSWORD', 'your_password');
     ```
   - Update `config.php` line 10 if you chose a different location

3. **Create the database**
   - Create a new database named `freeitsm` in MySQL
   - Run `database/freeitsm.sql` to create tables, or use the Setup page's DB Verify to auto-create them

4. **Set up encryption key** (for sensitive settings)
   ```bash
   mkdir C:\wamp64\encryption_keys
   # Generate a random 256-bit key (64 hex characters)
   php -r "echo bin2hex(random_bytes(32));" > C:\wamp64\encryption_keys\sdtickets.key
   ```

5. **Configure web server**
   - Point your web server to the application root
   - Ensure PHP extensions are enabled: `pdo_mysql`, `curl`, `openssl`, `mbstring`
   - Restart your web server

6. **Verify setup**
   - Navigate to `http://your-server/setup/` to run the setup verification checks
   - Confirms config files, database connection, PHP extensions, and security settings
   - **Delete the `/setup` folder** once your system is in production

7. **First login**
   - Navigate to `http://your-server/login.php`
   - A default admin account is created by the SQL script:
     - **Username:** `admin`
     - **Password:** `freeitsm`
   - **Change this password immediately** after first login via the account menu

8. **Import demo data** (optional)
   - Navigate to **System → Demo Data** to populate modules with realistic sample data
   - Import **Core** first (creates analysts, departments, teams, and end users), then choose which modules to populate
   - Includes tickets, assets, knowledge articles, changes, calendar events, morning checks, contracts, service status, software licences, and forms
   - Demo analysts use password `demo1234`
   - Designed for fresh installations only — each module can be imported once

### Configuration Files

| File | Location | Purpose | Commit to Git? |
|------|----------|---------|----------------|
| `config.php` | Web root | Main config (references external DB config) | ✅ Yes |
| `db_config.php` | **Outside web root** | Database credentials | ❌ **NO** |
| `db_config.sample.php` | Web root | Template for db_config.php | ✅ Yes |

---

## Quick Start for AI Assistants

> **Before making changes**: This README provides essential context about the codebase structure. Read relevant sections before modifying code.

## Technology Stack

| Component | Technology |
|-----------|------------|
| Backend | PHP 7.4+ |
| Database | MySQL 8.0+ (PDO MySQL) |
| Frontend | Vanilla JavaScript, HTML5, CSS3 (no frameworks) |
| Rich Text Editor | TinyMCE 6+ |
| Email Integration | Microsoft Graph API (OAuth 2.0) |
| Encryption | AES-256-GCM (sensitive data at rest) |
| Web Server | Apache (WAMP/XAMPP/LAMP) or any PHP-capable server |

---

## ITSM Modules

The platform is organised into 10 modules, accessible from a landing page (`index.php`) and a shared waffle menu for cross-module navigation.

| Module | Folder | Colour | Description |
|--------|--------|--------|-------------|
| **Tickets** | `tickets/` | Blue `#0078d4` | Outlook-style ticket inbox with email integration, departments, teams, and audit trails |
| **Assets** | `asset-management/` | Green `#107c10` | IT asset tracking, user assignments, and vCenter VM inventory |
| **Knowledge** | `knowledge/` | Purple `#8764b8` | Rich-text knowledge base articles with AI chat and vector search |
| **Changes** | `change-management/` | Teal `#00897b` | ITIL change management with risk matrix, audit trail, comments, and post-implementation review |
| **Calendar** | `calendar/` | Orange `#ef6c00` | Event calendar with categories and scheduling |
| **Checks** | `morning-checks/` | Cyan `#00acc1` | Daily infrastructure health checks (RAG status) with 30-day trend charts |
| **Reporting** | `reporting/` | Brown `#ca5010` | System logs, audit trails, and analytics |
| **Software** | `software/` | Indigo `#5c6bc0` | Software inventory and deployment tracking |
| **Forms** | `forms/` | Teal `#00897b` | Dynamic form builder with sidebar list, tabbed editor (Fields/Preview), filler, and submission reporting |
| **System** | `system/` | Blue-grey `#546e7a` | Encryption key management and module access control |

---

## Directory Structure

```
sdtickets/
├── config.php                        # Database credentials & global settings
├── index.php                         # Landing page (module selection grid)
├── login.php                         # Authentication page
├── logout.php                        # Logout handlers
├── analyst_logout.php
├── admin_settings.php                # Legacy admin panel (tickets settings)
├── check_email.php                   # Scheduled email import (all mailboxes)
├── oauth_callback.php                # Microsoft OAuth 2.0 callback
│
├── includes/                         # Shared PHP components
│   ├── functions.php                 # Database connection helper
│   ├── waffle-menu.php               # Cross-module navigation menu + user account menu
│   ├── encryption.php                # AES-256-GCM encryption/decryption
│   └── totp.php                      # Pure PHP TOTP (RFC 6238) for MFA
│
├── assets/                           # Static assets
│   ├── css/
│   │   ├── inbox.css                 # Core layout & shared styles
│   │   ├── knowledge.css             # Knowledge base styles
│   │   ├── calendar.css              # Calendar widget styles
│   │   ├── rota.css                  # Staff rota styles
│   │   ├── change-management.css     # Change management styles
│   │   └── itsm_calendar.css         # ITSM calendar styles
│   ├── js/
│   │   ├── inbox.js                  # Ticket interface logic
│   │   ├── knowledge.js              # Knowledge base logic
│   │   ├── calendar.js               # Calendar logic
│   │   ├── rota.js                   # Staff rota logic
│   │   ├── change-management.js      # Change management logic
│   │   ├── change-calendar.js        # Change management calendar logic
│   │   ├── itsm_calendar.js          # ITSM calendar logic
│   │   ├── qrcode.min.js             # Client-side QR code generator (for MFA setup)
│   │   └── tinymce/                  # Rich text editor library
│   └── images/
│       └── CompanyLogo.png           # Company logo (replace with your own)
│
├── tickets/                          # Ticket Management Module
│   ├── index.php                     # Three-panel inbox interface
│   ├── users.php                     # User directory & their tickets
│   ├── calendar.php                  # Ticket scheduling calendar
│   ├── rota.php                      # Staff rota weekly grid
│   ├── settings/                     # Departments, types, origins, mailboxes, analysts, teams, rota shifts
│   ├── includes/                     # Module header
│   └── attachments/                  # Email attachment storage
│
├── asset-management/                 # Asset Management Module
│   ├── index.php                     # Asset list & user assignments
│   ├── dashboard/
│   │   └── index.php                 # Per-analyst widget dashboard with Chart.js
│   ├── servers/
│   │   └── index.php                 # vCenter VM inventory with detail modal
│   ├── settings/
│   │   └── index.php                 # vCenter connection settings
│   └── includes/
│
├── knowledge/                        # Knowledge Base Module
│   ├── index.php                     # Article list & editor
│   ├── review.php                    # Article review workflow
│   ├── settings/                     # Email, AI, and embedding settings
│   └── includes/
│
├── change-management/                # Change Management Module
│   ├── index.php                     # Change request list & detail
│   ├── calendar.php                  # Calendar view of scheduled changes
│   ├── approvals.php                 # Pending approvals view
│   ├── settings/                     # Module settings (field visibility)
│   └── includes/
│
├── calendar/                         # Calendar Module
│   ├── index.php                     # Full calendar view with events
│   ├── settings/                     # Event categories
│   └── includes/
│
├── morning-checks/                   # Morning Checks Module
│   ├── index.php                     # Daily check interface with PDF export
│   ├── settings/                     # Settings page (check definitions, drag-and-drop reorder)
│   ├── create_tables.sql             # Database schema
│   └── includes/
│
├── reporting/                        # Reporting Module
│   ├── index.php                     # Reporting landing page (area selection)
│   ├── logs/
│   │   └── index.php                 # System logs (logins, email imports)
│   ├── tickets/
│   │   └── index.php                 # Ticket dashboards (coming soon)
│   └── includes/
│
├── software/                         # Software Module
│   ├── index.php                     # Software inventory dashboard
│   ├── dashboard/
│   │   ├── index.php                 # Software dashboard (Chart.js widgets with drill-down)
│   │   └── library.php               # Widget library management
│   ├── licences/
│   │   └── index.php                 # Licence management (CRUD, search, CSV export)
│   ├── settings/
│   │   └── index.php                 # API key management
│   └── includes/
│
├── system/                           # System Module
│   ├── index.php                     # System landing page (area selection)
│   ├── encryption/
│   │   └── index.php                 # Encryption key management
│   ├── modules/
│   │   └── index.php                 # Analyst module access control
│   └── includes/
│
├── forms/                            # Forms Module
│   ├── index.php                     # Unified form list + builder (sidebar + editor layout)
│   ├── builder.php                   # Legacy form designer (deprecated, use index.php)
│   ├── fill.php                      # Form filler (A4-style with company logo)
│   ├── submissions.php               # Submission table, detail modal, CSV export
│   ├── create_tables.sql             # Database schema
│   └── includes/
│
├── setup/                            # Setup verification (delete after going live)
│   └── index.php                     # Diagnostic checks page
│
├── api/                              # REST API endpoints (~125 total)
│   ├── tickets/                      # ~48 endpoints
│   ├── assets/                       # 8 endpoints (inc. vCenter sync)
│   ├── knowledge/                    # 16 endpoints (inc. AI chat)
│   ├── change-management/            # 15 endpoints
│   ├── calendar/                     # 7 endpoints
│   ├── morning-checks/               # 7 endpoints
│   ├── reporting/                    # 2 endpoints
│   ├── software/                     # 5 endpoints
│   ├── forms/                        # 7 endpoints
│   ├── settings/                     # 2 endpoints
│   ├── system/                       # 4 endpoints (encryption, module access)
│   ├── myaccount/                    # 6 endpoints (password, MFA setup/verify/disable)
│   └── external/                     # External API (software inventory)
│
└── database/                         # SQL schema scripts
    ├── create_teams_tables.sql
    ├── create_users_assets_table.sql
    └── add_knowledge_embeddings.sql
```

---

## Shared Components

### Waffle Menu & User Account Menu (`includes/waffle-menu.php`)
A cross-module navigation component inspired by Microsoft 365's app launcher. Appears in every module's header, allowing quick switching between all modules. Each module is registered here with its name, path, icon, and colour gradient. Respects `$_SESSION['allowed_modules']` to filter visible modules per analyst.

Also contains the **user account menu** — an initials avatar circle in the top-right of every page. Clicking opens a dropdown with:
- **Change Password** — modal to update password (validates current password, minimum 8 characters)
- **Multi-Factor Authentication** — modal to set up or disable TOTP-based MFA (generates QR code for authenticator apps)
- **Logout** — with confirmation prompt

To add a new module, add an entry to the `$modules` array and corresponding CSS classes.

### TOTP Library (`includes/totp.php`)
Pure PHP implementation of RFC 6238 (TOTP) and RFC 4226 (HOTP) for multi-factor authentication. No external dependencies — uses PHP's built-in `hash_hmac()` and `random_bytes()`.

- **Secret generation**: 20 random bytes → Base32 encoded (32-character string)
- **Code generation**: HMAC-SHA1 with 30-second time steps, dynamic truncation → 6-digit code
- **Verification**: Checks ±1 time window (90-second tolerance) using `hash_equals()` for timing-safe comparison
- **URI format**: `otpauth://totp/FreeITSM:{username}?secret={base32}&issuer=FreeITSM`

TOTP secrets are encrypted at rest using AES-256-GCM via `encryptValue()` before being stored in the `analysts.totp_secret` column.

### Encryption (`includes/encryption.php`)
AES-256-GCM authenticated encryption for sensitive database values.

- **Key file**: `C:\wamp64\encryption_keys\sdtickets.key` (outside web root)
- **Format**: Encrypted values stored as `ENC:` + base64(IV + auth tag + ciphertext)
- **Migration**: Values without the `ENC:` prefix pass through unchanged, allowing gradual rollout
- **Encrypted settings**: Defined in `ENCRYPTED_SETTING_KEYS` and `ENCRYPTED_MAILBOX_COLUMNS` constants

```php
// Encrypt before saving to DB
$encrypted = encryptValue($plaintext);

// Decrypt after reading from DB
$plaintext = decryptValue($encrypted);

// Decrypt all sensitive columns in a mailbox row
$mailbox = decryptMailboxRow($mailbox);
```

Currently encrypted in `system_settings`:
- `vcenter_server`, `vcenter_user`, `vcenter_password`
- `knowledge_ai_api_key`, `knowledge_openai_api_key`

Currently encrypted in `target_mailboxes`:
- `azure_tenant_id`, `azure_client_id`, `azure_client_secret`
- `oauth_redirect_uri`, `imap_server`, `target_mailbox`

### Functions (`includes/functions.php`)
Contains `connectToDatabase()` which returns a PDO MySQL connection using the credentials from `db_config.php`. Also contains `getAnalystAllowedModules()` which loads module access permissions for an analyst.

### Module Header Pattern
Each module has its own `includes/header.php` that:
1. Checks session authentication (redirects to login if not logged in)
2. Sets `$current_module` for waffle menu highlighting
3. Renders the header bar with the module's colour gradient
4. Includes the waffle menu button, nav tabs, and user account avatar menu

---

## Module Details

### Tickets (`tickets/`)
The primary module. Three-panel Outlook-style interface.

- **Left panel**: Department/status folders with ticket counts
- **Middle panel**: Ticket list (searchable)
- **Right panel**: Reading pane with full email thread
- **Features**: Create tickets, reply/forward emails, attachments, internal notes, audit trail, team-based filtering, scheduling
- **Settings**: Departments, ticket types, origins, mailboxes (Office 365), email templates, analysts, teams
- **Mailbox whitelist**: Per-mailbox domain and email address whitelisting — non-whitelisted senders are rejected
- **Email actions**: Configurable per-mailbox actions for rejected emails (delete, move to Deleted Items, mark as read) and imported emails (delete, move to folder) with folder verification
- **Email templates**: Automated email responses triggered by ticket events (new ticket from email, ticket assigned, ticket closed) with merge codes for ticket reference, requester name, analyst name, and more
- **Ask AI**: Button in ticket detail view opens a slide-in chat panel that searches the knowledge base for relevant articles using ticket context
- **Staff rota**: Weekly grid showing analyst shift patterns, WFH/office location, and on-call status with per-day entry management and configurable shift definitions
- **Activity log**: Searchable, paginated log of imported and rejected emails per mailbox with clickable processing log details
- **Dashboard** (`dashboard/`): Per-analyst customisable dashboard with Chart.js widgets
  - Widget library with 15 pre-built charts (bar, pie, doughnut, line) covering status, priority, department, type, analyst, origin, first time fix, and time-series
  - Time-series widgets with configurable grouping: day, month, or year
  - Multi-series: stacked bar charts broken down by status or priority, and created-vs-closed comparison line charts
  - Configurable date range filter (last 7/30 days, this month, last 3/6/12 months, this year, all time)
  - Department filter: scope any widget to specific departments
  - Each analyst picks widgets for their own dashboard
  - Status filtering on supported widgets
  - Inline editing: cog icon on each widget opens a modal to edit properties without leaving the dashboard
  - Drag-and-drop reordering
- **Help guide** (`help.php`): Interactive guide covering inbox navigation, ticket lifecycle, comments and attachments, dashboard widgets, calendar, rota, and settings

### Assets (`asset-management/`)
IT asset management with vCenter integration.

- **Assets tab**: Searchable asset list with user assignments (many-to-many)
- **Servers tab** (`servers/`): Virtual machine inventory synced from VMware vCenter REST API
  - Displays VM name, OS, IP, host, cluster, CPU, memory, disk
  - Clickable rows show full detail modal with raw JSON from vCenter
  - Stores all API response data in `raw_data` column
- **Dashboard** (`dashboard/`): Per-analyst customisable dashboard with Chart.js widgets
  - Widget library with 13 pre-built charts (bar, pie, doughnut) covering OS, manufacturer, model, memory, GPU, TPM, BitLocker, etc.
  - Each analyst picks widgets for their own dashboard
  - Status filtering on supported widgets
  - Drag-and-drop reordering
- **Settings**: vCenter server URL, username, and password (encrypted)
- **Device Manager** (`api/external/device-manager/submit/`): Enumerates Windows Device Manager devices (category, name, driver manufacturer, driver version, status) and displays them grouped by class on the asset detail screen
- **PowerShell inventory agent** (`scripts/Invoke-AssetInventory.ps1`): Collects hardware, disks, network, GPU, TPM, BitLocker, device manager, and installed software from Windows machines and posts to the system-info and device-manager APIs
- **System-info API** (`api/external/system-info/submit/`): External endpoint that ingests asset inventory data, syncs disk and network adapter tables, and processes software inventory
- **Help guide** (`help.php`): Interactive guide covering asset overview, detail screen, inventory script deployment, collected data, server management, and dashboard widgets

### Knowledge (`knowledge/`)
Rich-text knowledge base with AI integration.

- TinyMCE editor for article creation
- Tag-based organisation and full-text search
- AI chat powered by Anthropic Claude (searches articles via vector similarity)
- OpenAI embeddings for semantic search
- Email sharing capability
- Article review workflow
- Article versioning — save as new version to archive previous content with full version history
- Article archiving with recycle bin (soft delete, restore, configurable auto-purge)
- **Help guide** (`help.php`): Interactive guide covering article creation, review workflow, Ask AI, search and navigation, sharing and export, with scroll-spy sidebar navigation

### Change Management (`change-management/`)
Change request tracking and approval workflows with ITIL-aligned processes.
- Calendar view with month/week/day views for visualising scheduled changes
- Status-based filtering (Draft, Pending Approval, Approved, In Progress, Completed, Failed, Cancelled)
- Click-through from calendar to change detail view
- Approvals page showing changes pending approval (filter by All, Assigned to me, Requested by me, My CAB reviews)
- CAB (Change Advisory Board) multi-member approval with required/optional reviewers, configurable threshold (all or majority), and auto-status transitions on vote
- CAB review panel in detail view with colour-coded vote cards and inline voting for pending members
- Risk assessment matrix with 5x5 colour-coded grid, auto-calculated risk score and level
- Post-implementation review fields for completed/failed changes
- Activity timeline combining comments and audit trail with inline commenting
- Settings page with configurable form field visibility (show/hide fields per section)
- **Help guide** (`help.php`): Interactive guide covering change types, lifecycle, recording changes, CAB review, risk assessment, and post-implementation review

### Calendar (`calendar/`)
Event calendar with configurable categories.

- Month, week, and day views
- Drag-and-drop to move events between days in month view
- Category colour coding and filtering
- Events visible in adjacent-month cells for context
- **Help guide** (`help.php`): Interactive guide covering calendar views, event creation, categories, settings configuration, and scheduling tips for IT teams

### Morning Checks (`morning-checks/`)
Daily infrastructure health check recording.

- Define checks with Red/Amber/Green (RAG) status options
- Record daily results per check item
- 30-day trend charts — click any bar to jump to that day's checks
- Settings page with tabbed layout, modal popups for add/edit, and drag-and-drop reordering
- PDF export with selectable text, company logo, and coloured status values
- **Help guide** (`help.php`): Interactive guide covering daily checks, status types, trend chart, PDF export, and settings configuration

### Reporting (`reporting/`)
System logs and audit trails.

- Login attempt tracking (success/failure with IP and user agent)
- Email import logs
- System event logs
- Searchable and sortable tables
- **Help guide** (`help.php`): Interactive guide covering ticket reports, system logs, data interpretation, filters, and reporting best practices

### Software (`software/`)
Software inventory tracking across the estate.

- External API endpoint for automated inventory submission
- Per-machine software mapping
- **Dashboard** (`software/dashboard/`): Customisable Chart.js widget dashboard
  - Widget types: version distribution per application, top installed applications, publisher distribution
  - Chart types: bar, pie, doughnut
  - Click any chart segment to drill down and see machines/details in a modal
  - Cog icon for inline widget editing, drag-and-drop reordering
  - Widget library for creating, editing, duplicating, and deleting widgets
- **Licences** (`software/licences/`): Software licence management database
  - Record licences against applications in the software inventory
  - Licence types: Per User, Per Device, Site, Concurrent, Subscription, Other
  - Track renewal dates with colour-coded warnings (overdue/approaching/ok)
  - Store licence keys, costs, portal URLs, vendor contacts, and notes
  - Searchable and sortable table with status badges (Active/Expired/Cancelled)
  - CSV export of all licence data
- **Help guide** (`help.php`): Interactive guide covering software inventory, dashboard widgets, licence management, data collection, and settings

### System (`system/`)
System administration and configuration.

- **Encryption** (`system/encryption/`): Guided interface for managing the AES-256-GCM encryption key
  - Shows key status (configured/missing/invalid) with colour-coded status card
  - One-click key generation — writes directly to `c:\wamp64\encryption_keys\sdtickets.key`
  - Instructions on key placement, backup importance, and what data is encrypted
  - No regenerate button to prevent accidental key destruction
- **Module Access** (`system/modules/`): Control which modules each analyst can see
  - Toggle matrix: analysts as rows, modules as columns
  - "All Access" toggle per analyst (default state — backward compatible)
  - System module cannot be disabled (always accessible)
  - Auto-saves on toggle with debounced API calls and toast notifications
  - Permissions enforced on homepage cards and waffle menu navigation
- **Toast Notifications** (`assets/js/toast.js`): Global notification system used across all modules
  - Four types: success (green), error (red), warning (amber), info (blue) — each with icon and colour bar
  - 9 configurable screen positions via visual grid picker in System Settings → General
  - Position preference saved per-browser in localStorage
  - Slide-in animations, auto-dismiss after 4 seconds, manual close button
- **Demo Data** (`system/demo-data/`): One-click import of realistic sample data across all modules
  - Populates tickets, assets, knowledge articles, changes, calendar events, morning checks, contracts, services, software, forms, analysts, and end users
  - Designed for fresh installations — makes the system feel alive for evaluation and testing

### Forms (`forms/`)
Dynamic form builder and submission system with a unified sidebar + editor layout.

- **Form List & Builder** (`index.php`): Sidebar shows searchable list of all forms with quick actions (Fill In, Submissions, Delete). Main area has full-width title/description inputs with tabbed Fields and Preview panels. Click a form in the sidebar to edit it inline. Unsaved changes indicator with browser leave warning. Toast notifications on save/delete.
- **Filler** (`fill.php`): A4-style form rendering with company logo (alignment configurable). Required field validation.
- **Submissions** (`submissions.php`): Table view of all submissions. Click rows for detail modal. Date range filtering. CSV export with UTF-8 BOM for Excel compatibility.
- **Settings**: Gear icon in sidebar opens settings modal. Configurable logo alignment (left, centre, right) applied to both preview and fill-in views.
- **Field types**: `text`, `textarea`, `checkbox`, `dropdown`
- **Help Guide** (`help.php`): Full-page guide with left-pane navigation, scroll-spy, and 7 sections covering the form builder, field types, filling in forms, submissions, CSV export, settings, and quick tips.

### Contracts (`contracts/`)
Supplier and contract lifecycle management with configurable rich text terms.

- **Dashboard** (`index.php`): Left sidebar with overview stats (contracts, active, expiring, suppliers, contacts), quick links, and universal search across contracts, suppliers, and contacts. Main area shows the full contracts table.
- **Add/Edit** (`edit.php`): Sectioned form with contract details, dates, financial, documents, and terms & data protection fields. Below the main form, a "Contract Terms Detail" section displays configurable TinyMCE rich text tabs for detailed contract terms (e.g. Special terms, KPIs, SLAs).
- **View** (`view.php`): Read-only detail view with all fields organised into sections. Contract terms content displayed in read-only tabs.
- **Suppliers** (`suppliers/`): Supplier register with legal/trading names, registration details, address, type/status, questionnaire tracking, and comments.
- **Contacts** (`contacts/`): Supplier contacts with name, job title, email, direct dial, and switchboard fields.
- **Settings** (`settings/`): Tabbed management of supplier types, supplier statuses, contract statuses, payment schedules, and contract term tabs.
- **Help** (`help.php`): Guided help page with left-pane navigation, scroll-spy, and 7 sections covering contract management, rich text terms, suppliers, contacts, settings configuration, and best practice tips.

### Service Status (`service-status/`)
Service health dashboard with incident-driven status tracking.

- **Dashboard** (`index.php`): Grid view of all active services showing worst current impact from open incidents. Below, a list of active and recently resolved incidents. Create/edit incidents via modal with multi-service impact selector.
- **Settings** (`settings/`): Manage the list of services (name, description, display order, active status).
- **Help** (`help.php`): Guided help page with left-pane navigation, scroll-spy, and 6 sections covering the status dashboard, status levels, managing services and incidents, incident history, settings, and communication tips.

### Self-Service Portal (`self-service/`)
End-user portal allowing ticket requesters to register, log in, and interact with the service desk directly.

- **Registration & Login**: Users register with email, name, and password. If a user already exists in the system (e.g. created via email processing) but has no password, they can claim their account by registering with their email. Separate authentication from the analyst login using `$_SESSION['ss_user_id']`. Supports MFA (TOTP) login challenge.
- **Dashboard** (`index.php`): Personalised overview showing ticket summary cards (Open, In Progress, On Hold, Total), a recent tickets table, and a live system status panel pulled from the Service Status module.
- **New Ticket** (`new-ticket.php`): Submit a new support ticket with mailbox selection, subject, priority, description, and drag-and-drop file attachments.
- **Ticket Detail** (`ticket.php`): View full ticket conversation thread and non-internal notes. Internal analyst notes are hidden from the portal.
- **User Avatar Menu**: Initials circle in the header with dropdown for account management, MFA setup, password change, and logout.
- **My Account**: Users can set a preferred name (e.g. "Ed" instead of "Ed Mozley") and change their password.
- **Multi-Factor Authentication**: TOTP-based MFA using the same core libraries (`includes/totp.php`, `includes/encryption.php`) as the analyst system. Users can enable/disable MFA from their account menu.

---

## API Reference

All endpoints live under `api/` and return JSON. Every endpoint requires an active session (`$_SESSION['analyst_id']`).

### Standard Pattern
```php
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
```

### Tickets (`api/tickets/`) ~54 endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `get_emails.php` | GET | List tickets with latest email (filtered by dept/status) |
| `get_email_detail.php` | GET | Full email content and ticket info |
| `create_ticket.php` | POST | Create manual ticket |
| `delete_ticket.php` | POST | Delete ticket and related records |
| `assign_ticket.php` | POST | Assign ticket to analyst |
| `update_ticket_owner.php` | POST | Set ticket owner |
| `schedule_ticket.php` | POST | Set work_start_datetime |
| `search_tickets.php` | POST | Search by ticket#, email, or subject |
| `send_email.php` | POST | Send email via Microsoft Graph API |
| `get_ticket_attachments.php` | GET | List attachments for a ticket |
| `get_attachment.php` | GET | Download attachment file |
| `check_mailbox_email.php` | POST | Import emails for a mailbox |
| `get_departments.php` | GET | List all departments |
| `get_my_departments.php` | GET | List analyst's team-filtered departments |
| `save_department.php` | POST | Create/update department |
| `get_analysts.php` | GET | List all analysts |
| `save_analyst.php` | POST | Create/update analyst |
| `get_teams.php` | GET | List teams |
| `save_team.php` | POST | Create/update team |
| `get_mailboxes.php` | GET | List mailbox configurations |
| `save_mailbox.php` | POST | Create/update mailbox |
| `get_mailbox_whitelist.php` | GET | Get whitelist entries for a mailbox |
| `save_mailbox_whitelist.php` | POST | Replace whitelist entries for a mailbox |
| `get_mailbox_activity.php` | GET | Paginated activity log for a mailbox |
| `verify_mailbox_folder.php` | POST | Verify a mail folder exists via Graph API |
| `get_email_templates.php` | GET | List email templates |
| `save_email_template.php` | POST | Create/update an email template |
| `delete_email_template.php` | POST | Delete an email template |
| `get_notes.php` | GET | Get notes for a ticket |
| `save_note.php` | POST | Add internal note |
| `get_ticket_audit.php` | GET | Get change history |
| `get_ticket_counts.php` | GET | Counts by department/status |
| `get_rota_shifts.php` | GET | List rota shift definitions |
| `save_rota_shift.php` | POST | Create/update rota shift |
| `delete_rota_shift.php` | POST | Delete rota shift |
| `get_rota.php` | GET | Get rota entries for a week |
| `save_rota_entry.php` | POST | Create/update rota entry |
| `delete_rota_entry.php` | POST | Delete rota entry |
| `get_ticket_dashboard.php` | GET | Get analyst's dashboard widgets |
| `get_ticket_widget_data.php` | GET | Aggregated data for a widget chart |
| `get_ticket_widget_library.php` | GET | List all widget definitions |
| `add_ticket_dashboard_widget.php` | POST | Add widget to dashboard |
| `remove_ticket_dashboard_widget.php` | POST | Remove widget from dashboard |
| `save_ticket_dashboard_widget.php` | POST | Create/update widget definition |
| `reorder_ticket_dashboard_widgets.php` | POST | Reorder dashboard widgets |
| `delete_ticket_dashboard_widget.php` | POST | Soft-delete a widget |
| *...and more* | | |

### Assets (`api/assets/`)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `get_assets.php` | GET | List assets with user counts |
| `get_asset_users.php` | GET | Users assigned to an asset |
| `assign_asset_user.php` | POST | Assign user to asset |
| `unassign_asset_user.php` | POST | Remove user from asset |
| `get_servers.php` | GET | List VMs and ESXi hosts from servers table |
| `get_vcenter.php` | POST | Sync VMs from vCenter REST API |
| `debug_vcenter.php` | GET | Dump raw vCenter API responses |
| `get_software.php` | GET | Software inventory for a server |

### Knowledge (`api/knowledge/`)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `knowledge_articles.php` | GET | List articles (with search) |
| `knowledge_article.php` | GET | Get single article |
| `knowledge_save.php` | POST | Create/update article (auto-generates embedding) |
| `knowledge_delete.php` | POST | Delete article |
| `knowledge_tags.php` | GET | List available tags |
| `ai_chat.php` | POST | AI-powered Q&A over knowledge base |
| `generate_embedding.php` | POST | Generate OpenAI embedding for article |
| `get_email_settings.php` | GET | Get email & AI settings (keys masked) |
| `save_email_settings.php` | POST | Save email & AI settings (keys encrypted) |
| *...and more* | | |

### Forms (`api/forms/`)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `get_forms.php` | GET | List all forms with field/submission counts |
| `get_form.php` | GET | Single form with fields (for builder & filler) |
| `save_form.php` | POST | Create/update form with fields |
| `delete_form.php` | POST | Delete form and all submissions |
| `submit_form.php` | POST | Submit a filled-in form |
| `get_submissions.php` | GET | Submissions for a form (with field data) |
| `delete_submission.php` | POST | Delete a submission |
| `get_settings.php` | GET | Get forms module settings (logo alignment) |
| `save_settings.php` | POST | Save forms module settings |

### Settings (`api/settings/`)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `get_system_settings.php` | GET | Get all settings (auto-decrypts sensitive keys) |
| `save_system_settings.php` | POST | Save settings (auto-encrypts sensitive keys) |

### My Account (`api/myaccount/`)

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `change_password.php` | POST | Validate current password, update to new (min 8 chars) |
| `get_mfa_status.php` | GET | Return `{ mfa_enabled: bool }` for current analyst |
| `setup_mfa.php` | POST | Generate TOTP secret, return secret + otpauth URI for QR |
| `verify_mfa.php` | POST | Verify OTP against pending secret, encrypt and enable MFA |
| `disable_mfa.php` | POST | Verify password and disable MFA for current analyst |
| `verify_login_otp.php` | POST | Verify OTP during login MFA challenge, complete login |

### Other Module APIs

- `api/change-management/` — 15 endpoints for change CRUD, attachments, calendar, approvals, CAB workflow, and settings
- `api/calendar/` — 7 endpoints for events and categories
- `api/morning-checks/` — 8 endpoints for check definitions, results, charts, and reorder
- `api/reporting/` — 2 endpoints for system logs
- `api/software/` — 5 endpoints for software inventory and licence management
- `api/service-status/` — 7 endpoints for services CRUD, incident management, and dashboard aggregation
- `api/system/` — 4 endpoints for encryption status and module access management
- `api/external/software-inventory/submit/` — External API for automated software inventory collection
- `api/external/system-info/submit/` — External API for full asset inventory ingestion (hardware, disks, network, software)

---

## Database

### Connection
PDO MySQL connecting to MySQL 8.0+. Connection handled by `includes/functions.php`:

```php
$conn = connectToDatabase();
$stmt = $conn->prepare("SELECT * FROM table WHERE id = ?");
$stmt->execute([$id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);
```

### Identity Pattern
MySQL `AUTO_INCREMENT` for auto-increment. Use `$conn->lastInsertId()` to retrieve new IDs after INSERT:

```php
$stmt = $conn->prepare("INSERT INTO table (col) VALUES (?)");
$stmt->execute([$value]);
$newId = (int)$conn->lastInsertId();
```

### Core Tables

#### analysts
```sql
id, username, password_hash, full_name, email, is_active, totp_secret, totp_enabled,
created_datetime, last_modified_datetime, last_login_datetime
```
- `totp_secret`: AES-256-GCM encrypted TOTP secret (NULL when MFA not set up)
- `totp_enabled`: Boolean flag indicating whether MFA is active for this analyst

#### tickets
```sql
id, ticket_number (YYYYMMDD-XXXX), subject, status, priority, department_id, ticket_type_id,
ticket_origin_id, assigned_analyst_id, owner_id, requester_name, requester_email,
first_time_fix, it_training_provided, work_start_datetime, created_datetime, updated_datetime
```

#### emails
```sql
id, ticket_id, exchange_message_id (NOT NULL), from_address, from_name, to_recipients (JSON),
cc_recipients (JSON), received_datetime, subject, body_content, body_type, has_attachments,
importance, is_read, is_initial, direction (Incoming/Outgoing/Manual)
```

#### system_settings
```sql
setting_key, setting_value, updated_datetime
```
Key-value store for all configuration. Sensitive values are encrypted with AES-256-GCM.

#### target_mailboxes
```sql
id, name, target_mailbox*, azure_tenant_id*, azure_client_id*, azure_client_secret*,
oauth_redirect_uri*, oauth_scopes, imap_server*, imap_port, imap_encryption,
email_folder, max_emails_per_check, mark_as_read,
token_data (JSON), is_active, created_datetime, last_checked_datetime
```
\* *Encrypted at rest with AES-256-GCM*

#### mailbox_email_whitelist
```sql
id, mailbox_id, entry_type ('domain'|'email'), entry_value, created_datetime
```
Per-mailbox sender whitelist. If no entries exist, all senders are allowed.

#### mailbox_activity_log
```sql
id, mailbox_id, action ('imported'|'rejected'), from_address, from_name,
subject, reason, ticket_id, created_datetime
```
Records every email imported or rejected during mailbox processing.

#### ticket_email_templates
```sql
id, name, event_trigger, subject_template, body_template, is_active, display_order,
created_datetime, updated_datetime
```
Automated email templates triggered by ticket events (`new_ticket_email`, `ticket_assigned`, `ticket_closed`). Subject and body support merge codes like `[ticket_reference]`, `[analyst_name]`, etc.

### Asset Tables

#### servers
```sql
id, vm_name, guest_os, ip_address, host, cluster, cpu_count, memory_mb, disk_gb,
power_state, raw_data (LONGTEXT - full vCenter JSON), source, last_synced
```

### Forms Tables

#### forms
```sql
id, title, description, is_active, created_by, created_date, modified_date
```

#### form_fields
```sql
id, form_id (FK CASCADE), field_type, label, options (JSON), is_required, sort_order
```

#### form_submissions
```sql
id, form_id (FK), submitted_by (FK analysts), submitted_date
```

#### form_submission_data
```sql
id, submission_id (FK CASCADE), field_id (FK), field_value
```

### Morning Checks Tables

#### morningChecks_Checks
```sql
id, check_name, is_active, display_order
```

#### morningChecks_Results
```sql
id, check_id (FK), check_date, status (Green/Amber/Red), notes, analyst_id
```

### Team Tables

#### teams, analyst_teams, department_teams
Many-to-many relationships for team-based access control. Analysts only see tickets in departments linked to their teams. Analysts with no team assignments see everything (admin behaviour).

### Module Access Tables

#### analyst_modules
```sql
id, analyst_id (FK analysts ON DELETE CASCADE), module_key
```
Controls which modules an analyst can access. No rows = full access to all modules (backward compatible). When rows exist, analyst only sees those modules on homepage and in waffle menu. The `system` module is always included and cannot be disabled.

---

## Security

### Implemented
- AES-256-GCM encryption for sensitive settings, mailbox credentials, and TOTP secrets with key stored outside web root
- Bcrypt password hashing (`PASSWORD_DEFAULT`)
- TOTP multi-factor authentication (RFC 6238) — optional per analyst, enforced at login
- Session-based authentication on all pages and API endpoints
- PDO prepared statements throughout (SQL injection prevention)
- Output encoding with `htmlspecialchars()` (XSS prevention)
- Client-side escaping via DOM `textContent` → `innerHTML` pattern
- OAuth 2.0 for Microsoft 365 email integration
- Team-based access control for ticket visibility
- Module-level access control per analyst (configurable via System module)
- Audit logging for all ticket changes
- Login attempt logging with IP and user agent
- Credential masking in UI (`****` + last 4 characters)
- Password required to disable MFA (prevents unauthorized deactivation)
- Trusted device: users can opt in to skip OTP on trusted browsers for a configurable number of days (cookie-based with SHA-256 hashed tokens stored server-side)
- Password expiry policy: configurable maximum password age (30–365 days) with forced password change on next login when expired
- Account lockout: configurable max failed login attempts before temporary account lock with configurable lockout duration

### Password Hashing
- **Algorithm**: Bcrypt via PHP's `password_hash()` with `PASSWORD_DEFAULT`
- **One-way**: Passwords cannot be reversed from the stored hash — authentication uses `password_verify()` to re-hash the input and compare
- **Per-hash salt**: Every call to `password_hash()` generates a unique random salt, so the same password produces a different hash each time
- **Cost factor**: Bcrypt uses configurable work rounds (currently 2^12 = 4,096 iterations) making brute-force attacks extremely slow
- **Default admin account**: The SQL script includes a pre-computed hash for the initial `admin` / `freeitsm` account. Once the password is changed, a new unique hash is generated. The `db_verify.php` endpoint also seeds this account at runtime (with a fresh hash) if no analysts exist

### Encryption Details
- **Algorithm**: AES-256-GCM (authenticated encryption — provides confidentiality + integrity)
- **Key**: 256-bit random key stored at `C:\wamp64\encryption_keys\sdtickets.key`
- **Nonce**: 96-bit random IV per encryption (same value encrypted twice produces different ciphertext)
- **Auth tag**: 128-bit — detects any tampering with encrypted data
- **Prefix**: `ENC:` allows coexistence of encrypted and plaintext values during migration

---

## Key Workflows

### Email Import
1. `check_email.php` runs (scheduled or manual trigger)
2. For each active mailbox: refreshes OAuth token, calls Graph API, imports new emails
3. Creates/matches tickets based on subject/requester
4. Downloads attachments, logs results

### Email Threading & Reply Flow

The ticketing system handles email correspondence as a flat thread — each email in a ticket is stored and displayed as its own standalone entry, newest first, with no nesting or indentation.

#### The Problem with Email Threads
When a user replies to an email, their email client (Gmail, Outlook, etc.) automatically appends the entire previous conversation as a quoted block below their new content. If you simply store the full email body, each reply contains every previous message nested inside it, creating an ever-growing blob of duplicated content. Displaying these naively produces deeply indented, confusing threads with coloured borders and boxes-within-boxes.

#### The Solution: Server-Side Assembly + Clean Storage

The reply flow separates what the **recipient sees** from what gets **saved to the database**:

1. **Reply editor is empty** — when an analyst clicks Reply, TinyMCE opens with a blank editor. No quoted thread, no markers, just a clean box for typing.

2. **Server assembles the full email** — when the analyst clicks Send, only their typed content is sent to the server. The server (`api/tickets/send_email.php`) then:
   - Fetches all previous emails for the ticket from the database
   - Builds a quoted thread (each email as "On [date], [name] wrote:" + blockquote)
   - Inserts a visible reply marker: **— Please reply above this line —**
   - Constructs the full email: analyst's reply + marker + quoted thread
   - Sends this assembled email to the recipient via Microsoft Graph API

3. **Only the analyst's content is saved** — the database stores just what the analyst typed, not the full assembled email. This prevents thread duplication in the DB.

#### Reply Marker
The visible text `— Please reply above this line —` serves as the primary anchor for stripping. It is:
- Plain Unicode text (em dashes + words) that survives every email client's HTML processing
- Wrapped in a `<div>` with `data-reply-marker="true"` as a secondary signal
- Displayed to the recipient as a subtle grey line between the analyst's reply and the quoted thread

#### Inbound Email Stripping
When a user replies back, their email contains the full thread (their reply + our marker + quoted history). The stripping functions extract **only the user's new content** by looking for these anchors in order:

1. **Our visible marker text** — `— Please reply above this line —` (primary, works with all email clients)
2. **Our `data-reply-marker` div** — backup if the HTML attribute survives
3. **Legacy SDREF marker** — `[*** SDREF:XXX-000-00000 REPLY ABOVE THIS LINE ***]` for older emails
4. **Generic blockquote fallback** — takes content before the first `<blockquote>` tag
5. **Attribution line cleanup** — removes trailing "On [date], [name] wrote:" lines that email clients add before quoted blocks

Stripping happens at two points:
- **Import time** (`check_mailbox_email.php` → `stripInboundThread()`) — cleans the body before saving to DB
- **Display time** (`get_ticket_thread.php` → `stripQuotedThread()`) — safety net for legacy emails already in the DB

#### Thread Display
The correspondence thread in the reading pane renders emails as a flat list:
- Newest email at the top, oldest at the bottom
- Each email separated by a thin horizontal line
- Direction badge (Received/Sent) with sender name, email address, and timestamp
- CSS overrides (`!important`) to kill any inline styles, blockquote indentation, or coloured borders from email HTML that leak through stripping

#### Files Involved

| File | Role |
|------|------|
| `assets/js/inbox.js` | Reply/forward modals (empty editor), `sendEmail()` passes `type` param, `loadCorrespondenceThread()` renders flat thread |
| `api/tickets/send_email.php` | `buildFullEmailBody()` assembles full email for recipient, saves only analyst content to DB |
| `api/tickets/get_ticket_thread.php` | `stripQuotedThread()` strips quoted content at display time |
| `api/tickets/check_mailbox_email.php` | `stripInboundThread()` strips quoted content at import time |
| `assets/css/inbox.css` | Flat thread styles, inline HTML overrides |

### MFA Login Flow
MFA is optional and per-analyst. Analysts with MFA disabled log in with just username and password as normal. When an analyst enables MFA, subsequent logins require a second verification step:

1. Analyst enters username and password on `login.php`
2. Password verified → MFA pending state stored in session (`mfa_pending_analyst_id` etc.) — **`analyst_id` is NOT set yet**
3. Login page renders OTP form (shield icon, 6-digit input, auto-submit)
4. Analyst enters code from authenticator app → JS calls `api/myaccount/verify_login_otp.php`
5. Server decrypts stored TOTP secret, verifies code (±1 time window)
6. On success: `$_SESSION['analyst_id']` is set, pending state cleared, redirected to `index.php`

The "Cancel and return to login" link clears the pending state so a different analyst (who may not have MFA) can log in on the same browser.

### Team-Based Filtering
1. Analyst's team memberships stored in session at login
2. API endpoints filter by team-accessible departments
3. No teams assigned = see everything (admin)

### vCenter VM Sync
1. Settings page stores vCenter credentials (encrypted)
2. `api/assets/get_vcenter.php` authenticates with vCenter REST API
3. Fetches hosts, clusters, VMs with guest identity and filesystems
4. Builds host/cluster maps by querying VMs per host/cluster
5. Stores everything including raw JSON in `servers` table

### Form Submission
1. Admin designs form in the unified form builder (`index.php`) — sidebar lists all forms, editor has tabbed Fields/Preview panels
2. Users fill in form at `fill.php?id=X` (A4-style layout with company logo)
3. Submissions viewable in table format with CSV export

---

## Development Notes

### Module Page Pattern
Every module page follows this structure:

```php
<?php
session_start();
require_once '../config.php';
$current_page = 'module_name';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <!-- Module-specific styles in <style> block -->
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <div class="main-container module-container">
        <!-- Module content -->
    </div>
    <script>
        const API_BASE = '../api/module_name/';
        // Module JavaScript
    </script>
</body>
</html>
```

### Adding a New Module
1. Create module folder with `index.php` and `includes/header.php`
2. Create API folder under `api/`
3. Register in `includes/waffle-menu.php` (add to `$modules` array + CSS colours)
4. Add card to `index.php` landing page (icon, colour, link)
5. Create SQL schema file if needed

### Important: exchange_message_id
The `emails.exchange_message_id` column does NOT allow NULL. Manual tickets must use a placeholder: `'manual-' . time() . '-' . uniqid()`.

---

## File Locations Quick Reference

| Need to... | Look in... |
|------------|------------|
| Add a new module | Module folder + `api/` + `includes/waffle-menu.php` + `index.php` |
| Change database connection | `config.php`, `includes/functions.php` |
| Add an encrypted setting | `includes/encryption.php` → `ENCRYPTED_SETTING_KEYS` or `ENCRYPTED_MAILBOX_COLUMNS` |
| Modify cross-module navigation | `includes/waffle-menu.php` |
| Change the landing page | `index.php` |
| Modify ticket inbox | `tickets/index.php`, `assets/js/inbox.js` |
| Configure vCenter | `asset-management/settings/`, `api/assets/get_vcenter.php` |
| Manage knowledge AI | `knowledge/settings/`, `api/knowledge/ai_chat.php` |
| Design forms | `forms/index.php`, `api/forms/save_form.php` |
| View form submissions | `forms/submissions.php`, `api/forms/get_submissions.php` |
| Manage encryption key | `system/encryption/`, `api/system/check_encryption.php` |
| Configure module access | `system/modules/`, `api/system/save_analyst_modules.php` |
| Account menu (avatar/password/MFA) | `includes/waffle-menu.php` → `renderHeaderRight()`, `api/myaccount/` |
| MFA login challenge | `login.php`, `api/myaccount/verify_login_otp.php` |
| TOTP implementation | `includes/totp.php` |

---

*Last updated: February 2026*
