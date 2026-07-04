<h1 align="center">Thanks for Visiting 👋</h1>

<p align="center"><strong>FreeITSM is a one-developer project — your engagement is what keeps it moving.</strong></p>

<p align="center">
⭐ <strong>If you're regularly cloning or using FreeITSM, please <a href="https://github.com/edmozley/freeitsm/stargazers">give the repo a star</a></strong> — it's the single biggest signal that the work is landing<br><br>
📬 <strong>Got feedback, an idea, or a bug?</strong> Email me directly at <a href="mailto:ed@freeitsm.co.uk">ed@freeitsm.co.uk</a> — I read every message<br><br>
💬 <strong>Or jump into the community</strong> — <a href="https://github.com/edmozley/freeitsm/discussions">Discussions</a> for questions, ideas and show-and-tell · <a href="https://github.com/edmozley/freeitsm/issues">Issues</a> for bugs and feature requests<br><br>
🌍 And if you mention <a href="https://freeitsm.co.uk">freeitsm.co.uk</a> on Reddit, Hacker News, Spiceworks, LinkedIn, or anywhere IT pros hang out — it genuinely helps and means a lot
</p>

<p align="center">
<a href="https://github.com/edmozley/freeitsm/blob/main/LICENSE"><img src="https://img.shields.io/github/license/edmozley/freeitsm?style=flat-square&color=blue" alt="MIT License"></a>
<img src="https://img.shields.io/badge/PHP-7.4--8.4-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 7.4–8.4">
<img src="https://img.shields.io/badge/MySQL-8.0%2B-4479A1?style=flat-square&logo=mysql&logoColor=white" alt="MySQL 8.0+">
<img src="https://img.shields.io/badge/Docker-Ready-2496ED?style=flat-square&logo=docker&logoColor=white" alt="Docker Ready">
<a href="https://github.com/edmozley/freeitsm/stargazers"><img src="https://img.shields.io/github/stars/edmozley/freeitsm?style=flat-square&color=gold" alt="GitHub stars"></a>
</p>

# FreeITSM - Open Source Service Desk Platform

A comprehensive web-based IT Service Management (ITSM) platform with 17 integrated modules covering a unified attention dashboard, tickets, tasks with Kanban board, assets, knowledge, change management, calendar, morning checks, reporting, software inventory, dynamic forms, contracts, service status, process mapping, LMS with SCORM course player, and system administration. Includes a Chrome/Edge browser extension for Watchtower dashboard monitoring, analyst account management with password reset, forgot password via email, TOTP multi-factor authentication, and IP-based brute force protection.

## Screenshots

<table>
<tr>
<td align="center"><strong>Watchtower</strong><br><img src="https://freeitsm.co.uk/images/screenshots/watchtower_1.png" width="350" alt="Watchtower"></td>
<td align="center"><strong>Tickets</strong><br><img src="https://freeitsm.co.uk/images/screenshots/tickets_1.png" width="350" alt="Tickets"></td>
<td align="center"><strong>Assets</strong><br><img src="https://freeitsm.co.uk/images/screenshots/assets_1.png" width="350" alt="Assets"></td>
</tr>
<tr>
<td align="center"><strong>Knowledge</strong><br><img src="https://freeitsm.co.uk/images/screenshots/knowledge_1.png" width="350" alt="Knowledge"></td>
<td align="center"><strong>Changes</strong><br><img src="https://freeitsm.co.uk/images/screenshots/changes_1.png" width="350" alt="Changes"></td>
<td align="center"><strong>Calendar</strong><br><img src="https://freeitsm.co.uk/images/screenshots/calendar_1.png" width="350" alt="Calendar"></td>
</tr>
<tr>
<td align="center"><strong>Morning Checks</strong><br><img src="https://freeitsm.co.uk/images/screenshots/checks_1.png" width="350" alt="Morning Checks"></td>
<td align="center"><strong>Software</strong><br><img src="https://freeitsm.co.uk/images/screenshots/software_1.png" width="350" alt="Software"></td>
<td align="center"><strong>Forms</strong><br><img src="https://freeitsm.co.uk/images/screenshots/forms_1.png" width="350" alt="Forms"></td>
</tr>
<tr>
<td align="center"><strong>Contracts</strong><br><img src="https://freeitsm.co.uk/images/screenshots/contracts_1.png" width="350" alt="Contracts"></td>
<td align="center"><strong>System Wiki</strong><br><img src="https://freeitsm.co.uk/images/screenshots/wiki_1.png" width="350" alt="System Wiki"></td>
<td align="center"><strong>Process Mapper</strong><br>Visual flowchart builder with drag-and-drop steps, connector lines, and snap-to-grid canvas</td>
</tr>
<tr>
<td align="center"><strong>LMS</strong><br>Learning Management System with SCORM 1.1/1.2/2004 course player, learning groups, assignments, deadlines, and progress tracking</td>
<td align="center"></td>
<td align="center"></td>
</tr>
</table>

<p align="center"><a href="https://freeitsm.co.uk/screenshots.html"><strong>View all 57 screenshots →</strong></a></p>

## Table of Contents

- [Screenshots](#screenshots)
- [Quick Start](#-quick-start)
  - [Docker (Recommended)](#docker-recommended)
  - [Manual Installation](#manual-installation)
  - [Configuration Files](#configuration-files)
- [Technology Stack](#technology-stack)
- [ITSM Modules](#itsm-modules)
- [Directory Structure](#directory-structure)
- [Shared Components](#shared-components)
- [Module Details](#module-details)
  - [Watchtower](#watchtower-watchtower)
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
  - [LMS](#lms-lms)
  - [Process Mapper](#process-mapper-process-mapper)
  - [Workflows](#workflows-workflow)
  - [Network Mapper](#network-mapper-network-mapper)
  - [Tasks](#tasks-tasks)
- [Browser Extension](#browser-extension)
- [API Reference](#api-reference)
- [Database](#database)
- [Security](#security)
- [Key Workflows](#key-workflows)
- [Development Notes](#development-notes)
- [File Locations Quick Reference](#file-locations-quick-reference)

---

## 🚀 Quick Start

### Docker (Recommended)

The fastest way to get FreeITSM running — no PHP, MySQL, or web server setup required.

```bash
git clone https://github.com/edmozley/freeitsm.git
cd freeitsm
docker compose up -d
```

Then open [http://localhost:8080/setup/](http://localhost:8080/setup/) to verify the installation and create your admin account.

> Requires [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows/Mac) or Docker Engine (Linux).

---

### Manual Installation

#### Prerequisites
- **Web Server**: WAMP, XAMPP, LAMP, or any PHP-capable web server
- **PHP**: 7.4 or higher (tested up to 8.4)
- **Database**: MySQL 8.0 or higher (included with WAMP/XAMPP)
- **Extensions**: PHP PDO, pdo_mysql, curl, openssl, mbstring
- **Database credentials file**: A `db_config.php` file stored **outside your web root** (e.g. `C:\wamp64\db_config.php`) — see step 2 below. The path is configured in `config.php`.

> **⚠️ Note for early adopters:** If you downloaded FreeITSM before 18 February 2026, the project required Microsoft SQL Server Express and ODBC drivers — sorry about that! The original choice of SQL Server made sense at the time (it was the database I was most familiar with), but it created a painful setup experience: downloading SQL Server Express, installing ODBC drivers, enabling Mixed Mode Authentication, and troubleshooting driver compatibility issues. That's a lot of friction for an open-source project that's supposed to be easy to get running.
>
> FreeITSM now runs on **MySQL**, which comes pre-installed with WAMP, XAMPP, and most web hosting stacks. No extra downloads, no driver headaches. If you've already set up with SQL Server, you'll need to migrate your data to MySQL and update your `db_config.php` — but for new installations, it's just clone and go.

#### Installation

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
   - Includes tickets, assets, knowledge articles, changes, calendar events, morning checks, contracts, service status, software licences, forms, tasks, and process-mapper flowcharts
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
| Backend | PHP 7.4–8.4 |
| Database | MySQL 8.0+ (PDO MySQL) |
| Frontend | Vanilla JavaScript, HTML5, CSS3 (no frameworks) |
| Rich Text Editor | TinyMCE 6+ |
| Email Integration | Microsoft Graph API + Gmail API (OAuth 2.0) |
| Encryption | AES-256-GCM (sensitive data at rest) |
| Web Server | Apache (WAMP/XAMPP/LAMP) or any PHP-capable server |

---

## ITSM Modules

The platform is organised into 16 modules, accessible from a landing page (`index.php`) and a shared waffle menu for cross-module navigation.

| Module | Folder | Colour | Description |
|--------|--------|--------|-------------|
| **Watchtower** | `watchtower/` | Slate `#1e293b` | Unified attention dashboard showing actionable items across all modules |
| **Tickets** | `tickets/` | Blue `#0078d4` | Outlook-style ticket inbox with email integration, departments, teams, and audit trails |
| **Assets** | `asset-management/` | Green `#107c10` | IT asset tracking, user assignments, and vCenter VM inventory |
| **Knowledge** | `knowledge/` | Purple `#8764b8` | Rich-text knowledge base articles with AI chat and vector search |
| **Changes** | `change-management/` | Teal `#00897b` | ITIL change management with risk matrix, audit trail, comments, and post-implementation review |
| **Calendar** | `calendar/` | Orange `#ef6c00` | Event calendar with categories and scheduling |
| **Checks** | `morning-checks/` | Cyan `#00acc1` | Daily infrastructure health checks with configurable status options (label + colour + requires-notes), 30-day trend charts and PDF export |
| **Reporting** | `reporting/` | Brown `#ca5010` | System logs, audit trails, and analytics |
| **Software** | `software/` | Indigo `#5c6bc0` | Software inventory and deployment tracking |
| **Forms** | `forms/` | Teal `#00897b` | Dynamic form builder with sidebar list, tabbed editor (Fields/Preview), filler, and submission reporting |
| **Contracts** | `contracts/` | Amber `#f59e0b` | Supplier and contract lifecycle management with terms, financials, and notice tracking |
| **Service Status** | `service-status/` | Emerald `#10b981` | Real-time service health dashboard with incident tracking |
| **Wiki** | `system-wiki/` | Red `#c62828` | Auto-generated codebase documentation browser |
| **LMS** | `lms/` | Blue `#2563eb` | Learning Management System with SCORM 1.1/1.2/2004 course player, learning groups, assignments, deadlines, and progress tracking |
| **Processes** | `process-mapper/` | Indigo `#6366f1` | Visual flowchart builder with dot-grid canvas, snap-to-grid, connectors, and slide-in detail panel |
| **Workflows** | `workflow/` | Amber `#f59e0b` | Cross-module automation engine — triggers, conditions, actions. Stage 1: engine foundation + form-based editor |
| **System** | `system/` | Blue-grey `#546e7a` | Encryption key management, security settings, and module access control |

---

## Multi-Tenancy (in development)

FreeITSM can host **multiple client companies in one install** — aimed at MSPs (managed service providers) supporting many clients from a single deployment, with each client's tickets walled off from the others.

**Opt-in and invisible at N=1.** Every install starts with a single silent **Default** company that owns everything. While there is only one company, *nothing changes* — no company switcher, no domain mapping, no triage queue, no settings split; the app behaves exactly as a single-company helpdesk. The multi-tenant machinery only wakes up when you create a **second** company (System → Companies).

**What's built today** (the feature is **dormant until you add a second company**, so single-company installs are unaffected):

- **Company model & switcher** — a per-analyst company context in the header; cross-company access is permission-gated.
- **Tickets scoped by company** — the ticket list and folder counts show only the active company's tickets.
- **Email routing** — each mailbox is either *pinned* to a company (its mail always goes there) or a *shared intake* that routes by the sender's domain; an exact sender address can be mapped too (for personal/webmail clients). Anything unmatched lands safely in a **triage queue** — nothing is ever lost. A read-only **routing test** and per-company "how email reaches this company" summary help you configure it.
- **Per-company settings** — settings are *global defaults a company can tailor* (add its own / hide an inherited one). Built for **ticket types** and **ticket origins**; statuses, priorities, teams and mailboxes stay global by design.
- **Move ticket to another company** — a misrouted ticket isn't stuck: a **Company** picker in the reading pane re-homes it (access-gated both ways, audit-logged), and a **soft wrong-company warning** flags when the requester's domain points at a different company, with a one-click move.

**Status:** active development. Deeper cross-company isolation on the remaining ticket endpoints (search, ticket detail, dashboards) and per-company **departments / SLAs / self-service** are still being rolled out, so a real multi-company go-live isn't recommended yet. Single-company use is unaffected and production-safe.

> ⚠️ **After updating, run Database Verify** (System → Database Verify) before creating tickets. This release adds a `tenant_id` column used by ticket creation; Database Verify creates it (idempotently). This is the usual post-update step, but its scope now includes core ticket creation.

See the [Multi-Tenancy wiki](https://github.com/edmozley/freeitsm/wiki/Multi-Tenancy) for the full design, the email-routing model, and the rationale for which settings are/aren't per-company.

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
├── google_oauth_callback.php         # Google OAuth 2.0 callback
├── forgot-password.php               # Password reset request page
├── reset-password.php                # Password reset (with token from email)
│
├── includes/                         # Shared PHP components
│   ├── functions.php                 # Database connection helper
│   ├── waffle-menu.php               # Cross-module navigation menu + user account menu
│   ├── encryption.php                # AES-256-GCM encryption/decryption
│   ├── totp.php                      # Pure PHP TOTP (RFC 6238) for MFA
│   ├── gmail.php                     # Gmail API helper (send, read, refresh tokens)
│   ├── template_email.php            # Automated email templates for ticket events
│   └── module-colors.php             # Module colour definitions
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
│   ├── review/                       # Article review workflow
│   │   └── index.php
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
│   ├── index.php                     # Forms dashboard (full-width sortable table)
│   ├── edit/                         # Form editor — title, fields, AI Assist, versioning
│   ├── settings/                     # Layout + AI provider/model/key settings
│   ├── fill.php                      # Form filler (A4-style with company logo)
│   ├── submissions.php               # Submission table, detail modal, CSV export
│   ├── create_tables.sql             # Database schema
│   └── includes/
│
├── lms/                              # LMS Module
│   ├── index.php                     # Dashboard with courses, groups, assignments, progress
│   ├── player.php                    # SCORM player with iframe + API bridge
│   ├── content/                      # Uploaded SCORM packages (one folder per course)
│   └── includes/
│
├── process-mapper/                   # Process Mapper Module
│   ├── index.php                     # Visual flowchart editor
│   └── includes/
│
├── network-mapper/                   # Network Mapper Module
│   ├── index.php                     # Diagrams landing page
│   ├── diagram.php                   # Per-diagram editor shell
│   └── includes/
│
├── setup/                            # Setup verification (delete after going live)
│   └── index.php                     # Diagnostic checks page
│
├── api/                              # REST API endpoints (~140 total)
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
│   ├── auth/                         # 2 endpoints (password reset request/confirm)
│   ├── lms/                          # 9 endpoints (courses, groups, assignments, progress, SCORM data)
│   ├── process-mapper/               # 4 endpoints (list, get, save, delete)
│   ├── network-mapper/               # 7 endpoints (list/get/create/save/delete diagrams + versions)
│   ├── external/                     # External API (software inventory)
│   └── watchtower/                   # Watchtower API (session + extension endpoints)
│
├── browser-extension/                # Chrome/Edge Watchtower extension (Manifest V3)
│   ├── manifest.json
│   ├── background.js                 # Service worker (polling + badge)
│   ├── popup.html/js/css             # Extension popup UI
│   ├── options.html/js               # Settings page
│   └── icons/                        # Extension icons
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
- `intune_tenant_id`, `intune_client_id`, `intune_client_secret`

Of those, the true secrets (`vcenter_password`, `knowledge_ai_api_key`, `knowledge_openai_api_key`, `intune_client_secret`) are also listed in `MASKED_SETTING_KEYS` — `api/settings/get_system_settings.php` returns them as `****<last4>` rather than plaintext, and `api/settings/save_system_settings.php` treats blank or asterisk-prefixed submissions as "leave unchanged" so the encrypted value is preserved when the user saves the form without re-typing the secret.

Currently encrypted in `target_mailboxes`:
- `azure_tenant_id`, `azure_client_id`, `azure_client_secret`
- `oauth_redirect_uri`, `imap_server`, `target_mailbox`

### Functions (`includes/functions.php`)
Contains `connectToDatabase()` which returns a PDO MySQL connection using the credentials from `db_config.php`. Also contains `getAnalystAllowedModules()` which loads module access permissions for an analyst.

### i18n (`includes/i18n.php`, `assets/js/i18n.js`, `lang/`)
Native multi-language support with a `t('namespace.path.to.key')` call pattern in both PHP and JavaScript. The infrastructure ships with a growing set of fully-converted modules: **Tickets, Tasks, Process Mapper, Workflows, Knowledge, Change Management, Asset Management, Calendar, Service Status and CMDB** are each translated across all 21 supported locales (every user-facing string in their PHP pages and JavaScript). Remaining modules render in English until converted; conversion is mechanical (extract strings to `lang/en/<module>.php`, wire the page, then add the per-locale files).

**Folder structure**: `lang/<locale>/<namespace>.php` returns a nested PHP array of translations. The first dot-separated segment of a `t()` key maps to the filename; everything after walks the nested array.

```
lang/
  en/common.php             → t('common.save')           → "Save"
  en/process-mapper.php     → t('process-mapper.toolbar.process') → "Process"
  fr/common.php             → t('common.save')           → "Enregistrer"
  fr/process-mapper.php     → t('process-mapper.toolbar.process') → "Étape"
```

**Supported locales** (21): `en`, `af`, `fr`, `de`, `es`, `pt-BR`, `nl`, `it`, `pl`, `ru`, `uk`, `id`, `hi`, `bn`, `ta`, `te`, `mr`, `kn`, `ml`, `gu`, `pa`. Adding a language is a code change &mdash; add to `I18n::SUPPORTED_LOCALES` and create the `lang/<code>/` folder. Locale codes follow BCP 47 (matches HTML `lang` attribute), with the hyphen form used for region-subtagged locales (`pt-BR`).

**Fallback is per key, not per file**. If `lang/de/tickets.php` has 80% of keys translated, you get those 80% in German and the missing 20% in English. Files that simply don't exist for a locale are treated as empty. Last-resort behaviour: return the key itself so unfilled strings are visible during development.

**Interpolation**: `t('common.welcome', ['name' => 'Ed'])` substitutes `{name}` in the translation. Unknown placeholders are left as-is.

**JS bridge**: pages declare which namespaces they need (`$translationNamespaces = ['common', 'process-mapper']`), and the PHP page emits `window.translations = {...}` with English fallback already merged in per key. JS calls `t('common.save')` using the same key form as PHP.

**Locale resolution** (in priority order): logged-in analyst's `interface_language` user preference → browser `Accept-Language` header (best supported match, primary subtag fallback) → `'en'`. Selectable in `System &rarr; Preferences` &mdash; on change, persists to `user_preferences` and reloads the page so PHP re-renders.

**Security**: namespace identifiers are regex-validated to prevent path traversal via the `t()` argument; locale codes are validated against the supported list before being used as a directory name; JSON encoding of the JS bridge uses `JSON_HEX_*` flags to prevent script-tag injection from translation values.

**Adding translations to a module**: include `includes/i18n.php`, call `I18n::initFromSession()`, set `$translationNamespaces` for the JS bridge, set `<html lang>` to `I18n::getLocale()`, and replace literal strings in PHP with `<?php echo htmlspecialchars(t('namespace.key')); ?>` and in JS with `t('namespace.key')`.

### Theming / palettes (`includes/theme.php`, `assets/css/theme.css`)
Colour theming is built on a **palette token layer**, so "light", "dark" and any future named palette (e.g. an "English Summer") are all just sets of CSS custom properties &mdash; there is no special-cased dark mode. A palette is applied via `<html data-theme="<id>">`, rendered server-side before CSS loads (no flash). Palettes are registered in `Theme::THEMES` (id + label) with a matching `[data-theme="<id>"]` block in `assets/css/theme.css`; the `:root` / `[data-theme="default"]` block is the conventional FreeITSM look.

**Per-module palettes.** A page declares `$theme_module = '<module>'` and resolves its palette via `Theme::active($theme_module)`. Resolution order is **per-module preference (`theme_<module>`) → global preference (`theme`) → built-in default**, so a user can run one palette everywhere or pick a different one per module. The palette picker lives in the account menu (top-right) and saves to `user_preferences` via the generic `api/system/set_user_preference.php` endpoint, then reloads.

**Module opt-in / rollout is one module at a time.** A module supports theming once its CSS consumes the tokens (`var(--text, #333)`, `var(--surface, #fff)`, …) instead of hardcoded colours. Because the default token values equal the current colours and every `var()` carries a fallback, converting a module leaves light mode pixel-identical and is safe even on pages/stylesheets that don't load `theme.css`. Core tokens: `--app-bg`, `--surface`/`--surface-2`/`--surface-3`/`--surface-hover`, `--text`/`--text-muted`/`--text-dim`/`--text-faint`, `--border`/`--border-soft`, `--accent`/`--accent-hover`/`--accent-soft`/`--on-accent`, `--shadow`. **Tickets** is the first themed module.

### Module Header Pattern
Each module has its own `includes/header.php` that:
1. Checks session authentication (redirects to login if not logged in)
2. Sets `$current_module` for waffle menu highlighting
3. Renders the header bar with the module's colour gradient
4. Includes the waffle menu button, nav tabs, and user account avatar menu

---

## Module Details

### Watchtower (`watchtower/`)
Unified attention dashboard — a single pane of glass showing actionable items across all modules.

- **Attention cards**: Eight module-themed cards showing only items that need attention
- **Morning Checks**: Completion status, failed/warning checks
- **Tickets**: Open counts, urgent/high priority, unassigned tickets
- **Changes**: Upcoming changes, pending approvals, in-progress changes
- **Calendar**: Today's events, weekly event count
- **Service Status**: Active incidents, degraded services
- **Contracts**: Expiring contracts (30/90 day), upcoming notice periods
- **Knowledge**: Recently published articles, overdue reviews
- **Assets**: Total count, assets not seen in 7+ days, and (when enabled in Asset Settings → Warranty alerts) a red card for assets whose warranty has expired or is expiring soon
- **Features**: Color-coded status dots (green/amber/red), auto-refresh every 5 minutes, click-through to each module

### Tickets (`tickets/`)
The primary module. Three-panel Outlook-style interface.

- **Left panel**: Folder tree with a **By Department / By Analyst** toggle at the top — switch between grouping tickets by department + status (default) or by assigned analyst + status. The choice is persisted per-analyst via `user_preferences` so each analyst keeps their own preferred view across sessions. The Unassigned folder is contextual: in Department view it lists tickets with no department; in Analyst view it lists tickets with no assignee — making it easy to see what needs picking up
- **Middle panel**: Ticket list (searchable)
- **Right panel**: Reading pane with full email thread
- **Features**: Create tickets, reply/forward emails, attachments, internal notes, audit trail, team-based filtering, scheduling
- **Time tracking**: Log time spent on a ticket directly in the reading pane. Inline form (minutes + optional description) appends entries to a list showing who logged what and when, with a running total at the top. Each entry is soft-deletable (only by the analyst who logged it) so deleted rows stay in the audit history. Backed by a dedicated `ticket_time_entries` table (analyst + ticket + minutes + entry datetime + notes + `is_active` for soft delete)
- **SLAs**: business-hours-aware Service Level Agreements with per-priority response and resolution targets, configurable business calendars (timezone + weekly hours + holidays), pause statuses, mid-ticket-priority-change behaviour, and admin-controlled enforcement cutoff date (so existing tickets can be grandfathered in on first activation). Compute-on-read engine reads the ticket audit log + the per-priority calendar &mdash; no stored counters, no drift. **Breach notifications** are fully configurable per department: define a default rule plus per-dept overrides, choose any combination of recipients (assignee / department team members / specific analyst / custom email addresses), and pick separate triggers for warning (approaching breach) and breach (actual). A cron worker (`cron/sla_breach_check.php`, run every 5 minutes via Windows Task Scheduler or Linux cron &mdash; see [`docs/sla-cron-setup.md`](docs/sla-cron-setup.md)) walks open SLA tickets, fires emails through the ticket's originating mailbox, and de-dupes so each (ticket, target, trigger) fires at most once. See [`docs/sla.md`](docs/sla.md) for the full design
- **WhatsApp channel**: Customers can chat with an analyst over WhatsApp — each inbound message becomes (or threads into an open) ticket tagged with a **WhatsApp** origin, and analysts reply from an inline composer in the reading pane. Provider-agnostic (`includes/messaging/`): works with **Twilio** (recommended first build — has a sandbox you can test end-to-end with no Meta verification) or the **Meta WhatsApp Cloud API**. Inbound arrives at a public webhook (`api/messaging/webhook.php`), authenticated by the provider signature (**direct** mode) or a shared secret (**relay** mode). Reuses the email pipeline end to end (same thread, same multi-tenancy routing/isolation — a channel is *pinned* to a company or a *shared intake* routed by sender number, else triage). The provider **24-hour service window** is enforced in the composer, with **pre-approved templates** to re-open a conversation after it closes (defined under Settings → Messaging; sent via a picker in the reading pane). Two AI helpers: **summarise** the conversation into a note and **suggest** a reply. Inbound **images and files** are downloaded and attached to the ticket, and an open WhatsApp ticket **auto-refreshes every 15 seconds** so new messages appear without reloading. Managed under **Tickets → Settings → Messaging**. Self-hosted/local installs receive inbound via a tunnel (e.g. `ngrok http 80`), since providers can only reach a public HTTPS URL. See [`WhatsApp` on the wiki](https://github.com/edmozley/freeitsm/wiki/WhatsApp)
- **AI Reply Cleanup**: When typing a reply, click the **✨ Cleanup** button to have Claude rewrite the rough draft as a properly formatted email — adds a "Dear [Name]," greeting from the requester, fixes grammar, applies the configured tone, and signs off "Kind regards,". The prompt is locked down so it won't invent technical details, fabricate apologies, or pad the content beyond what was written. Streams live into the editor; an Undo link appears for 30 seconds in case Claude butchers the rewrite. Configured under **Tickets → Settings → Reply Cleanup** with its own Anthropic API key (separate from RFP AI / Knowledge AI) so usage shows up as its own line on the Anthropic billing dashboard. Choose the model (Haiku 4.5 default, Sonnet 4.6, Opus 4.7) and tone (Friendly / Formal / Brief).
- **Drag-and-drop triage**: Drag any ticket from the list onto a folder to update it. In **Department view**: drop on a department folder to reassign, drop on a department + status subfolder to update both, drop on Unassigned to clear the department. In **Analyst view**: drop on an analyst folder to assign that analyst as the owner (sets both `assigned_analyst_id` and `owner_id` so the right-pane Owner field stays in sync), drop on an analyst + status subfolder to update both, drop on Unassigned to clear the analyst. Hovering a collapsed folder during a drag auto-expands it (Outlook-style spring-loaded folders) so any nested status is reachable
- **User management**: The Users page (`tickets/users.php`) lists every end user with their ticket history. Analysts can **Add** a new user (email required, display name / preferred name optional, password optional &mdash; leaving it blank creates a passwordless account the user can later claim via the self-service portal's register flow), **Edit** an existing user, or **Delete** one. Delete is FK-safe: refused with a clear message if the user is the requester on any tickets or has any asset assignments, so audit history can never silently break
- **CSAT surveys**: 1&ndash;5 customer satisfaction survey emailed on ticket closure (auto mode) or on-demand via a *Request feedback* button (manual mode). User clicks the tokenised one-shot URL, lands on a no-login page with a star or emoji picker plus optional comment, response is recorded against the closing analyst. Dedicated CSAT analytics page (`tickets/csat/`) with KPI tiles (avg rating, response count, response rate), score distribution, per-analyst leaderboard, and recent responses with comments. Configurable under **Tickets &rarr; Settings &rarr; CSAT** (off / auto / manual, scale = stars or emojis, one-per-ticket toggle). Survey email content comes from **Tickets &rarr; Settings &rarr; Email templates** with a new `csat_request` event trigger and `[csat_link]` merge code
- **Settings**: Departments, ticket types, origins, mailboxes (Microsoft 365 + Google Workspace), messaging channels (WhatsApp), email templates, analysts, teams
- **Mailbox authentication (Microsoft 365)**: Two modes per mailbox. **Delegated** (interactive sign-in) — you sign in once *as the mailbox account*; the app reads `/me`. **App-only** (client credentials) — no sign-in; the app reads the target mailbox directly via Graph `/users/{address}` using its own client ID + secret (requires Azure **Application** permissions Mail.ReadWrite + Mail.Send with admin consent). App-only is ideal when the target mailbox isn't an analyst's own account. **"Reading from the right inbox" safeguards** apply to delegated mode: on sign-in the app records the **full set of addresses the mailbox owns** (primary SMTP, UPN and all aliases, via Graph `proxyAddresses`) and checks the configured target against that set on every read/send — so an **alias** is accepted while a genuinely different mailbox is blocked with a clear message. Changing a mailbox's target address invalidates the stored sign-in so a stale token can't keep reading the old inbox. The settings list shows a plain-language status per mailbox (*Reading from X ✓* / *Wrong account ⚠* / *Unverified* / *App-only*)
- **Mailbox whitelist**: Per-mailbox domain and email address whitelisting — non-whitelisted senders are rejected
- **Email actions**: Configurable per-mailbox actions for rejected emails (delete, move to Deleted Items, mark as read) and imported emails (delete, move to folder) with folder verification
- **Email templates**: Automated email responses triggered by ticket events (new ticket from email, ticket assigned, ticket closed) with merge codes for ticket reference, requester name/first name, analyst name, and more. The editor has an **Edit / Preview** tab so you can see the rendered email (with sample merge values) — HTML bodies are supported, so you can include styled buttons
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
- **Check-in / check-out**: assigning a user records a check-out (with an optional expected return date shown as "Due back"); removing them records a check-in. A **Custody** button on the asset opens the full check-out/check-in trail (who held it, when, due-back date, which analyst actioned it), stored in `asset_checkout_log`
- **Location, procurement & warranty**: each asset can be placed in the location hierarchy and carry purchase date, cost, supplier, order number and warranty expiry — all editable inline on the detail panel and shown in the table; every change is logged to asset history. **Supplier is normalised** — it's a dropdown of suppliers from the shared registry, chosen in Asset Settings → Suppliers (see below)
- **Table view** (`table.php`): Full-screen spreadsheet-style alternative to the split-pane Assets tab. Excel-style per-column tickbox filters (drop down from any header, shows the distinct values in the current view and counts), click-to-sort on every column, global search across visible columns, and a Columns drawer to show/hide and drag-reorder. Layout (visible columns, column order, sort) persists per analyst via `user_preferences`. Exports the current view to CSV and to landscape A4 PDF (selectable text, via jsPDF + autotable — same approach as the morning-checks export). Click any row to jump to that asset's detail in the split-pane view
- **Servers tab** (`servers/`): Virtual machine inventory synced from VMware vCenter REST API
  - Displays VM name, OS, IP, host, cluster, CPU, memory, disk
  - Clickable rows show full detail modal with raw JSON from vCenter
  - Stores all API response data in `raw_data` column
- **Dashboard** (`dashboard/`): Per-analyst customisable dashboard with Chart.js widgets
  - Widget library with 13 pre-built charts (bar, pie, doughnut) covering OS, manufacturer, model, memory, GPU, TPM, BitLocker, etc.
  - Each analyst picks widgets for their own dashboard
  - Status filtering on supported widgets
  - Drag-and-drop reordering
- **Settings**: vCenter server URL, username, and password (encrypted); Microsoft InTune tenant ID, client ID, client secret, and verify-SSL toggle (sensitive values encrypted); Sync button kicks off a Microsoft Graph-based InTune device import as a background worker, showing a progress bar
- **Warranty alerts** (Settings → Warranty alerts): choose where to surface assets whose warranty has expired or is expiring within a configurable number of days — a red card on the Watchtower dashboard, events on the Calendar (auto-synced into a "Warranty" category), both, or off. Calendar events are regenerated whenever a warranty date changes or the setting is saved, so they stay in step without a cron job
- **Suppliers** (Settings → Suppliers): the asset supplier is normalised against the shared `suppliers` registry (the same one the Contracts module uses) via a `supplies_assets` flag. The tab searches the registry and toggles which suppliers are *available for assets*, and can quick-add a new supplier by name (creating a minimal registry row you can flesh out later in Contracts). Only flagged, active suppliers appear in the asset supplier dropdown
- **Locations** (Settings → Locations): build an arbitrary-depth physical location hierarchy (adjacency-list tree in `asset_locations`) — nest as deep or shallow as you like and each branch is independent (e.g. `UK › London › Office 1` alongside a flat `Datacentre`). Add a sub-location from any node, rename, re-parent (with cycle protection), collapse/expand branches, and delete (blocked while a location still has children)
- **InTune integration**: Pulls all managed devices from Microsoft InTune (via Microsoft Graph `/deviceManagement/managedDevices`) into `intune_devices` and links them to `assets` by hostname (auto-creates stub assets for unknown hostnames). Asset detail panel shows an extra **InTune** tab when a device matches
- **Device Manager** (`api/external/device-manager/submit/`): Enumerates Windows Device Manager devices (category, name, driver manufacturer, driver version, status) and displays them grouped by class on the asset detail screen
- **PowerShell inventory agent** (`scripts/Invoke-AssetInventory.ps1`): Collects hardware, disks, network, GPU, TPM, BitLocker, device manager, and installed software from Windows machines and posts to the system-info and device-manager APIs
- **System-info API** (`api/external/system-info/submit/`): External endpoint that ingests asset inventory data, syncs disk and network adapter tables, and processes software inventory
- **Help guide** (`help.php`): Interactive guide covering asset overview, detail screen, inventory script deployment, collected data, server management, and dashboard widgets

### Knowledge (`knowledge/`)
Rich-text knowledge base with AI integration.

- TinyMCE editor for article creation
- Tag-based organisation and full-text search
- AI chat (Ask AI) — the assistant provider/model is configurable via the shared AI panel (**Anthropic, OpenAI, or OpenRouter**); searches articles via vector similarity
- OpenAI embeddings for semantic search
- Email sharing capability
- Article review workflow
- Article versioning — save as new version to archive previous content with full version history
- Article archiving with recycle bin (soft delete, restore, configurable auto-purge)
- **Help guide** (`help.php`): Interactive guide covering article creation, review workflow, Ask AI, search and navigation, sharing and export, with scroll-spy sidebar navigation

### Change Management (`change-management/`)
Change request tracking and approval workflows with ITIL-aligned processes.
- Calendar view with month/week/day views for visualising scheduled changes
- **Table view** (`table.php`): full-screen Excel-style grid of all changes (ref, title, type, status, priority, risk, assignee, work dates and more) with column show/hide + drag-reorder (persisted per analyst), click-to-sort, search, per-column tickbox filters, and CSV export. Inline-editable for the low-risk list fields — type, priority, impact and assignee — each saved field-at-a-time via `update_field.php` (one column, one audit-trail entry); status and the detailed fields (description, plans, risk, CAB) stay in the full change form, which you reach by clicking a row
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

### Problem Management (`problem-management/`)
ITIL Problem Management — track the root cause behind one or more incidents.
- A **Problem** carries a root cause, a workaround and a **known-error** flag, with a settings-managed lifecycle (New → Investigating → Root Cause Identified → Known Error → Resolved → Closed) and priorities
- **Incident linking** from either side: a **Problem** strip in the ticket reading pane (link an existing problem, or raise a new one from the incident) and a *Link incident* action on the problem; the linked incidents are listed on the problem. Same-company enforced on multi-company installs
- **Change linking**: connect the Change that permanently fixes the problem, so the whole chain — incidents → problem → change — is visible (uses the shared `change_relations` table)
- **Notes**: a free-text journal on each problem recording who wrote each note and when, alongside the structured change History
- **AI** (bring-your-own `problem_ai` key): *Draft root cause* proposes a root cause + workaround from the linked incidents; *Detect problems* scans recent open incidents for recurring patterns and proposes problems to create
- **Company-scoped** like tickets (`problems.tenant_id`, access-gated); entirely invisible on a single-company install
- List / detail / editor SPA, settings (statuses, priorities, Problem AI) and a help guide

### Calendar (`calendar/`)
Event calendar with configurable categories.

- Month, week, and day views
- Drag-and-drop to move events between days in month view
- Category colour coding and filtering
- Events visible in adjacent-month cells for context
- **Subscribe on your phone** (`api/calendar/feed.php`): the sidebar offers a read-only iCalendar (`.ics`) subscription feed — scan the QR code or copy the link to add the team calendar to Apple Calendar, Google Calendar or Outlook, where it stays up to date automatically. Authenticated by a per-analyst capability token (revocable via "Reset link"); the device must be able to reach the server URL
- **Table view** (`calendar/table/`): full-screen Excel-style grid of every event with inline cell editing (title, category, start/end, all-day, location), column show/hide + drag-reorder (persisted per analyst), click-to-sort, search, per-column tickbox filters, and CSV export — the same table experience as the Asset Management and Tasks modules
- **Help guide** (`help.php`): Interactive guide covering calendar views, event creation, categories, settings configuration, and scheduling tips for IT teams

### Morning Checks (`morning-checks/`)
Daily infrastructure health check recording.

- Define checks with Red/Amber/Green (RAG) status options
- Record daily results per check item
- 30-day trend charts — click any bar to jump to that day's checks
- Settings page with tabbed layout, modal popups for add/edit, and drag-and-drop reordering
- PDF export with selectable text, company logo, and coloured status values
- **Raise ticket from a check**: amber and red rows show a "+ Raise ticket" button that opens a modal pre-filled with the check name, status, date and notes. The analyst picks priority, assignee, department and type, and a ticket is created with the current analyst as the requester
- **Help guide** (`help.php`): Interactive guide covering daily checks, status types, trend chart, PDF export, and settings configuration

### Reporting (`reporting/`)
System logs and audit trails.

- Login attempt tracking (success/failure with IP and user agent)
- Email import logs
- System event logs
- Searchable and sortable tables
- **Intune Dashboard** (`reporting/intune/`): Curated dashboard of Microsoft Intune device aggregations — KPI strip (total devices, compliant %, encrypted %, stale 30+ days, recently enrolled) plus eight Chart.js widgets covering compliance breakdown, OS distribution, owner type (corporate vs personal), top manufacturers, top OS versions, last-sync window distribution, 90-day enrolment trend, and encryption-by-OS stacked bar. Single API call returns all aggregates; threshold-based KPI tone (green/red) flags compliance and encryption issues at a glance. Shows the timestamp of the most recent Intune sync job in the toolbar. **Drill-down**: click any chart slice/bar/point or any KPI card to open a modal listing the matching devices with name, user, OS, compliance pill, encryption, and last sync. Paginated (25 rows per page) with Prev/Next; **Export CSV** button on the modal footer downloads every matching row (UTF-8 BOM for Excel).
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

- **Help** (`system/help/`): In-app, step-by-step guides for every System area — a landing grid (driven by the same `system/includes/areas.php` registry as the System landing) plus a dedicated page per area, including a flagship **Single Sign-On** guide that walks through both single-company and multi-company (MSP) setup. The Companies page links to the SSO guide via a banner. Pages share one layout (`_init`/`_top`/`_bottom`) with left-nav + scroll-spy.
- **Orphaned tickets** (`system/orphaned-tickets/`): Finds tickets whose `department_id` points at a department that no longer exists — these are hidden from every team-filtered queue (neither "no department" nor in anyone's departments), so they vanish from the inbox. Lists them and lets an admin reassign them to a real department (or "No department"), individually or in bulk. Endpoints: `api/system/get_orphaned_tickets.php`, `api/system/assign_ticket_department.php` (writes a `ticket_audit` entry per change).
- **Topology** (`system/topology/`): A read-only, collapsible company-rooted tree of the whole configuration — each company with its mailboxes (pinned, with signed-in/active badges), domains & senders, sign-in providers, analysts with access, requester count and ticket totals, plus a "Global / shared" node (shared mailboxes, global SSO providers, all-access analysts). Health hints flag a company with no sendable mailbox and mailboxes that aren't signed in; deep-links out to the relevant editors. Aggregation endpoint: `api/system/get_topology.php`.
- **Landing page** (`system/index.php`): A searchable grid of cards, one per system area — the single way in to everything below. Unlike other modules, System has too many areas to fit the header navbar, so navigation lives entirely in these cards; the header "System" title links back here from any sub-page. A search box filters the cards live by title, description, and hidden keyword synonyms (e.g. typing *oidc*, *saml* or *idp* surfaces the Single Sign-On card). The areas come from one registry (`system/includes/areas.php`) that drives both the cards and the search, and stays fully i18n (titles/descriptions/keywords are translation keys).
- **API** (`system/api/`): Manage keys for the public REST API (`api/v1/`) and explore it interactively
  - Key management: create/edit/disable/delete keys; the full key (`fitsm_…`) is shown once at creation and stored only as a SHA-256 hash (`api_keys` table — separate from the legacy plaintext `apikeys` used by `api/external/`)
  - **Granular permissions** per key: a resource × action matrix (tickets read/create/update/delete/restore, notes, conversation, audit, SLA, time entries; assets read/create/update, assignments, history, inventory; problems read/create/update/delete, journal, audit, incident/change links; changes read/create/update/delete, comments, audit, CAB read/manage/vote; knowledge read/create/update/delete/restore/purge, versions; tasks read/create/update/delete, comments; cmdb classes, objects read/create/update/delete, relationships, ticket links; contracts read/create/update/delete, terms, suppliers, supplier contacts; calendar events read/create/update/delete; software inventory read + licences read/create/update/delete; status services + incidents read/create/update/delete; morning checks read/create/update/delete + results read/record; forms read/create/update/delete + submissions read/create/delete; requesters, analysts, companies, reference data) — keys start from zero
  - Each key **acts as an analyst** (audit rows/notes/time attributed properly), can be scoped to specific **companies** on multi-tenant installs, and carries an optional expiry date and per-minute rate-limit override; last-used time + IP shown in the list
  - **Interactive documentation** (`system/api/docs.php`): every endpoint documented with parameters + required permission, plus a "Try it" panel that fires real requests from the browser and shows status, rate-limit headers and the JSON response, with a copyable cURL equivalent
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
- **Branding** (`system/branding/`): Organisation-wide logo and default header/footer text used by Network Mapper diagrams (and future PDF/PNG exporters)
  - Logo upload supports PNG, JPG, or SVG (2 MB cap, extension + mime whitelist); SVG recommended for crisp print/export; old logos are torn down before the new file is saved
  - Six header/footer slots (header left/centre/right, footer left/centre/right) accept free text mixed with template tokens — `{{logo}}`, `{{title}}`, `{{author}}`, `{{version}}`, `{{modified}}` — resolved client-side at render time
  - Sensible defaults preloaded on a fresh install (logo top-left, title top-centre, author + version + modified along the bottom); per-page "Reset to defaults" button
  - Stored as seven `system_settings` key/value rows (`branding_logo_path`, `branding_header_left`, …, `branding_footer_right`) so no new table is needed; uploads land in `system/uploads/branding/` (gitignored)
  - Logo path is sanity-checked against disk on every read — a stale DB row pointing at a deleted file surfaces as "no logo" rather than 404ing every diagram open
- **Toast Notifications** (`assets/js/toast.js`): Global notification system used across all modules
  - Four types: success (green), error (red), warning (amber), info (blue) — each with icon and colour bar
  - 9 configurable screen positions via visual grid picker in System Settings → General
  - Position preference saved per-browser in localStorage
  - Slide-in animations, auto-dismiss after 4 seconds, manual close button
- **Preferences** (`system/preferences/`): Per-analyst settings that follow the analyst across browsers (persisted in `user_preferences`)
  - Interface language, notification (toast) position + animation, and the Morning Checks trend-chart fill style
  - **Left panels**: per-module control of whether a module's left panel stays pinned open (*Always visible*) or collapses to a thin 16px strip that expands on hover (*Show on hover*, freeing space for the main content). Covers every module that has a left panel — Knowledge, Process Mapper, Contracts, Calendar, Tasks, CMDB, Change Management, Asset Management and System Wiki — each stored as `<module>_sidebar_mode`. Modules with their own settings page also expose the same toggle on a **Left panel** tab there; the module header applies the choice on every page
- **Database Verify** (`system/db-verify/`): checks every table/column exists and auto-creates anything missing, then adds known foreign keys. Strictly **non-destructive** — it never deletes data. When a foreign key can't be added because orphaned child rows exist (e.g. attachments left behind when their email was deleted), the result row explains it in plain English and offers a one-click **Fix** button that — after a confirm — deletes only the provably-orphaned rows (parent gone) via a whitelisted endpoint (`api/system/fix_orphans.php`, which also removes the orphaned attachment files), then re-runs verification so the FK adds cleanly
- **Demo Data** (`system/demo-data/`): One-click import of realistic sample data across all modules
  - Populates tickets, assets, knowledge articles, changes, calendar events, morning checks, contracts, services, software, forms, tasks, process-mapper flowcharts, analysts, and end users
  - Process Mapper demo data is **auto-laid-out**: the JSON omits step coordinates and a server-side layered-DAG layout pass assigns x/y on import
  - Designed for fresh installations — makes the system feel alive for evaluation and testing
- **Debug Tools** (`system/debug-tools/`): Library of self-contained diagnostics for troubleshooting failed flows
  - **Searchable landing** (same live-filter card pattern as the System landing): a registry (`system/debug-tools/includes/tools.php`) drives a grid of cards — each with an ID badge (D001, D002…), icon, category tag and short description — that filters on every keypress. Each card opens that tool's **own page** at `system/debug-tools/<slug>/index.php` (e.g. `d001/`), where the "when to run" description, full checks list, runtime, side-effects and the Run/Copy UI live. A per-tool page is two lines (set the slug, include the shared `includes/tool-page.php` renderer); adding a tool is a registry entry + that two-line page + the API script.
  - Each diagnostic is a single PHP file under `api/system/debug-tools/` that emits a plain-text section-delimited report — designed so the user can click **Run**, click **Copy**, and paste the entire report back to support without back-and-forth
  - Defensive by design: every section is wrapped so one failure doesn't kill the rest, and each diagnostic carries its own expected-state data so it works even when `db_verify.php`, `config.php`, the import script, or the JSON files are missing or broken
  - **D001 — Demo Core Data Import**: 9-section report covering environment (PHP, OS, extensions, limits), config files (`config.php`, `db_config.php`, DB constants), required files, `core.json` parse + record counts, DB connection (server version, charset), per-table schema sanity (expected vs actual columns, row counts, redacted sample row), transactional write probe (sentinel insert per table inside a rolled-back transaction), and a live import attempt that captures the real response + any PHP warnings + post-import row counts. Adding more diagnostics is just `Dnnn_short_name.php` + a registry entry in `includes/tools.php` + a two-line `<slug>/index.php`.
  - **D002 — Delete Ticket (with full SQL trace)**: for when a ticket won't delete because of a foreign-key error (e.g. `1451 Cannot delete or update a parent row` on `email_attachments`). The card carries a **text input** (a diagnostic can now declare an `input` and the value is passed through to the report) — enter the ticket reference and it resolves the ticket, audits every table the delete touches (existence, key columns, each foreign key and its `ON DELETE` rule), counts the child rows that will go, then performs the delete inside a transaction echoing **every** `DELETE` statement, its parameters and rows affected, COMMITs, verifies nothing is left and removes the orphaned attachment files. **Destructive** — on success the ticket and all its data are permanently deleted; on any error the transaction rolls back.
  - **D003 — Self-Service SSO check (by email)**: enter a requester's email and it traces the whole self-service single-sign-on path end to end and prints a plain-English verdict with any blockers. Read-only and **secret-free** — client secrets, TOTP secrets and password hashes are reported only as present/absent, the IdP `subject` is masked. Sections: schema readiness (self-service login + SSO tables/columns + multi-tenant routing tables + key constraints), global SSO config (`sso_enabled` / `local_login_enabled`, global vs company-owned provider counts), tenancy mode (single vs multi-company), email→company routing (exact sender override, domain mapping, freemail detection), the user account (exists / passwordless / TOTP / provider pin / linked identities), the **predicted login outcome** (local / sso / choose — mirrors `resolve_login.php`), provider health with a **live OIDC discovery test** (issuer match + authorization/token/jwks/end-session endpoints reachable, without using the secret), and the exact redirect URI to register in the IdP.
  - **D004 — Local login check (password / hash)**: diagnoses why a username/email + password sign-in fails, built for the "I bulk-imported accounts with password hashes but they can't log in" case. A **multi-field POST** tool (the debug framework now supports text/password/select inputs submitted via POST so the password never lands in a URL/log): pick the account type (analyst / self-service user), enter the username/email, and *optionally* the password. It runs **hash forensics** — detects bcrypt/argon vs an imported MD5 / SHA-1 / SHA-256 / phpass / Django / LDAP hash that `password_verify()` can never read (so login always fails until re-hashed) — checks account state (inactive / locked / expired / SSO-pinned / TOTP / active IP bans), checks (for MFA users) whether the **encryption key** can decrypt that account's TOTP secret (a wrong/missing key fails login at the 6-digit step *after* a correct password — password hashes aren't encrypted, so the key never affects password-only login), and if you supply the password it runs `password_verify()` and, on failure against a raw digest, **identifies the exact wrong-hash-type import** (e.g. "the stored hash equals MD5(the supplied password)") with the fix. Safe to share: the password is never echoed and the stored hash is never printed (only its format / cost / length).

- **Shared AI provider config** (`includes/ai_settings_panel.php` + `includes/ai_provider.php` + `includes/ai_settings.php` + `api/system/ai/`): one reusable building block for per-module AI configuration. Renders a provider/model/key panel (**Anthropic, OpenAI, or OpenRouter** — one OpenRouter key reaches hundreds of models, shown in a live searchable list with per-1M-token pricing), persists per-namespace settings in `system_settings` (`<ns>_provider/_model/_api_key/_verify_ssl`, key encrypted at rest), and routes chat through one provider-agnostic client (`aiProviderChat()`). A namespace allowlist registry is the security boundary for the shared endpoints. Modules opt in by registering a namespace and dropping `renderAiSettingsPanel('<ns>')` on their settings page — currently live on **Knowledge, CMDB, Workflow, Forms and Tickets reply-cleanup**. For the streaming modules (Forms, Tickets), Anthropic keeps live token-by-token streaming while OpenRouter/OpenAI return in one shot. The RFP Builder is a deliberate follow-up (its large streaming generations need a true OpenRouter SSE path before switching), so it is left on its proven Anthropic/OpenAI streaming for now. OpenRouter also supports multiple keys with per-key spend limits, so per-module granular billing is preserved under a single account.

### Forms (`forms/`)
Dynamic form builder and submission system with a full-width dashboard + dedicated edit page.

- **Forms dashboard** (`index.php`): Full-width sortable table of all forms (one row per form chain, showing the current version). Columns: Title, Version pill, Status, Fields count, Submissions count, Last modified (relative time), Modified by, Actions. Search filter, click any row to edit, per-row icons for Fill / Submissions / Delete.
- **Form editor** (`edit/index.php`): Pretty URL `/forms/edit/?id=X` (or `/forms/edit/` for new). Title + description, drag-drop field list, live preview, sticky-footer Save / Cancel, top-toolbar Properties drawer + Versions dropdown + Save as new version. Field types: text, textarea, email (validated), number (numeric-only), single checkbox (yes/no), multi-checkbox group, radio group, dropdown.
- **AI Assist** (in `edit/index.php`): Top-toolbar button opens a modal where the analyst describes the form (or, when editing an existing form, the change they want) in plain English. A streaming Claude call generates a proposal which is shown for review with a per-field diff before the analyst clicks Apply — nothing touches the editor without consent. Per-module billing: configurable provider/model/key under Forms → Settings → AI.
- **Versioning** (`edit/index.php`): Save updates the current version in place; *Save as new version* clones the form forward into a new chain entry. Versions dropdown lists every snapshot; older versions are read-only.
- **Filler** (`fill.php`): A4-style form rendering with company logo (alignment configurable). Per-type validation (email format, numeric only, required-empty for multi-select, etc.).
- **Submissions** (`submissions.php`): Table view of all submissions. Click rows for detail modal. Date range filtering. CSV export with UTF-8 BOM for Excel compatibility.
- **Settings**: Gear icon in sidebar opens settings modal. Configurable logo alignment (left, centre, right) applied to both preview and fill-in views.
- **Field types**: `text`, `textarea`, `checkbox`, `dropdown`
- **Help Guide** (`help.php`): Full-page guide with left-pane navigation, scroll-spy, and 7 sections covering the form builder, field types, filling in forms, submissions, CSV export, settings, and quick tips.

### Contracts (`contracts/`)
Supplier and contract lifecycle management with configurable rich text terms.

- **Dashboard** (`index.php`): Left sidebar with overview stats (contracts, active, expiring, suppliers, contacts), quick links, and universal search across contracts, suppliers, and contacts. Main area shows the full contracts table.
- **Add/Edit** (`edit.php`): Sectioned form with contract details, dates, financial, documents, and terms & data protection fields. Below the main form, a "Contract Terms Detail" section displays configurable TinyMCE rich text tabs for detailed contract terms (e.g. Special terms, KPIs, SLAs).
- **View** (`view.php`): Read-only detail view with all fields organised into sections. Contract terms content displayed in read-only tabs. Header buttons to **Create Task** and **Add to Calendar** open inline modals that prefill sensible defaults from the contract (title, description, due date = notice date or end date, assignee = contract owner) and link the new item back to the contract via `contract_id`. Below the contract details, **Related Tasks** and **Related Calendar Events** sections list everything currently linked to the contract.
- **Suppliers** (`suppliers/`): Supplier register with legal/trading names, registration details, address, type/status, questionnaire tracking, and comments.
- **Contacts** (`contacts/`): Supplier contacts with name, job title, email, direct dial, and switchboard fields.
- **Settings** (`settings/`): Tabbed management of supplier types, supplier statuses, contract statuses, payment schedules, and contract term tabs.
- **Help** (`help.php`): Guided help page with left-pane navigation, scroll-spy, and 7 sections covering contract management, rich text terms, suppliers, contacts, settings configuration, and best practice tips.
- **RFP Builder** (`rfp-builder/`): AI-powered procurement requirements builder. Upload departmental feedback documents (one per dept), AI extracts every requirement / pain point / challenge, then a single AI consolidation pass deduplicates across departments, proposes 8-20 RFP categories, assigns priorities, and flags genuine contradictions. Manual editing tools (edit / split / merge / add custom / conflict resolve) refine the consolidated set. Lock to gate Phase 4 generation: introduction + scope + response instructions framing plus per-category sections, all streamed live (claude.ai-style SSE) with live progress trackers, TinyMCE editing, version history with restore, Pass 4 restyle that re-applies the style guide without changing meaning, and a print-friendly preview page for PDF export. Phase 5 supplier scoring with click-to-light 0-5 score boxes (red→green gradient), per-category running averages, full-screen radar chart, and multi-analyst rollup. Cross-supplier compare page with big-number cards, multi-supplier radar overlay, and category winners table marking the leader and gap. Coverage heatmap (category × department), AI activity panel + full filterable audit trail, and an in-app help guide.

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
- **Screen recordings**: One-click HTML5 screen capture via `getDisplayMedia()` + `MediaRecorder` from the new-ticket form &mdash; no plugins, no third-party tools, no Loom install. Users pick whether to include microphone audio (off by default), record for up to 5 minutes with a live countdown, preview the result, then attach it to their ticket. Browser produces MP4/H.264 directly on modern Chrome/Edge 134+ and falls back to WebM/VP9 elsewhere &mdash; both play natively in the analyst's reading pane. Files land on disk under `recordings/{ticket_id}/` (never as DB blobs) and stream via a range-aware endpoint, so the analyst's `<video>` can seek without downloading the whole file. iOS Safari has no `getDisplayMedia` support, so the Record button is hidden on those browsers.
- **Ticket Detail** (`ticket.php`): View full ticket conversation thread and non-internal notes. Internal analyst notes are hidden from the portal. Any screen recordings the user attached appear as inline `<video controls>` cards above the conversation.
- **User Avatar Menu**: Initials circle in the header with dropdown for account management, MFA setup, password change, and logout.
- **My Account**: Users can set a preferred name (e.g. "Ed" instead of "Ed Mozley") and change their password.
- **Multi-Factor Authentication**: TOTP-based MFA using the same core libraries (`includes/totp.php`, `includes/encryption.php`) as the analyst system. Users can enable/disable MFA from their account menu.

### LMS (`lms/`)
Learning Management System with SCORM course player and progress tracking.

- **Course Management**: Upload SCORM 1.1, 1.2, and 2004 ZIP packages. Manifest is auto-parsed to detect version and launch URL.
- **SCORM Player** (`player.php`): Full-viewport iframe with dual JavaScript API bridge — exposes both `window.API` (SCORM 1.x) and `window.API_1484_11` (SCORM 2004) so courses find whichever they look for.
- **Progress Tracking**: Per-user status (not started, incomplete, completed, passed, failed), scores, bookmarks, suspend data, and resume support. All CMI data stored as key/value pairs.
- **Learning Groups**: Create groups of analysts with many-to-many membership. Assign courses to groups with optional deadlines.
- **Admin Dashboard**: Four tabs — Courses, Groups, Assignments, Progress. Progress tab shows every assigned analyst's completion status with overdue highlighting. Filterable by course, group, or status.
- **Learner Data Viewer**: View button per learner showing quiz responses with correct/incorrect badges, objectives, scores, suspend data with syntax-highlighted JSON, and all raw CMI elements.

### Process Mapper (`process-mapper/`)
Visual flowchart builder for documenting processes and workflows.

- **Dot-grid canvas** with 20px snap-to-grid on all movements
- **User-definable step types** (`process-mapper/settings/`): the toolbar, the detail-panel **Type** dropdown and the right-click *Create new* submenu are all rendered from the `process_step_types` lookup table. Each type is a name, a shape picked from 12 built-in geometries (rectangle, rounded, pill, circle, diamond, parallelogram, trapezoid, hexagon, document, cylinder, cloud, subroutine) and a colour that seeds new steps of that type. Ships with four built-in types — Process, Decision, Terminal, Document — protected from deletion but freely renamable/recolourable; custom types can be added, edited, reordered and deactivated (deactivated types stay in the detail-panel dropdown with a `*` suffix so existing steps' stored type is still selectable, but drop out of the toolbar + context menu). The Settings page (the module's first) has a 12-shape visual picker and a colour input; the four API endpoints (`get` / `save` / `delete` / `reorder_step_types.php`) mirror the Tasks-module lookup pattern. Canvas steps now carry `data-shape="<shape>"` (was `data-type`); shape geometry comes from shared `[data-shape="..."]` rules in `process-mapper.css` so steps and Settings-page previews use the same primitives.
- **Connectors**: Drag from edge handles to draw arrows between steps. Optional text labels on connectors (double-click to edit).
- **Right-click to branch off a step**: Right-clicking any step opens a context menu with a *Create new* submenu listing every active step type from Settings. Picking one drops a new step of that type just to the right of the clicked step, already connected from it, with the detail panel open and the cursor in the label box — the fastest way to build a flow out left-to-right. The new step inherits whichever swimlane / group it lands in, the placement nudges downward to avoid overlapping an existing step, and the submenu flips leftward when there isn't room on the right. Right-clicking a step no longer starts a drag.
- **Multi-select**: Ctrl+click to toggle, rubber-band drag to select a region, Ctrl+A to select all
- **Arrow key nudge**: Move selected steps by one grid unit with cursor keys
- **Slide-in detail panel**: Click a step to edit label, type, colour, description, and view/edit its connectors
- **Save/load**: Full persistence with sidebar listing all saved processes
- **Autosave**: Optional toolbar toggle that debounces a save ~2s after the last edit. Live Word-style status indicator beside the toggle cycles through `✓ Saved` / `Unsaved` / `Saving…` / `⚠ Save failed — retry`. Toggle state persists per-analyst via `user_preferences` (key: `process_mapper_autosave`). The manual Save button still works regardless of toggle state.
- **Groups**: Optional labelled coloured rectangles that sit behind steps. Click `Group` in the toolbar to drop one at the canvas centre, drag to move, drag the corner handle to resize, double-click to rename. Groups **own their contents** via a nullable `group_id` on `process_steps`: drop a step inside a group → step gets the group's id; drag the group → contained steps move with it; resize the group so a step falls outside → that step's `group_id` clears on mouseup; delete the group → contained steps survive untouched but lose their `group_id`. When groups overlap, the smallest (by area) wins for ownership — useful when you want to highlight a sub-flow inside a broader group. Persisted in the `process_groups` table (id, process_id, label, color, color2, x, y, width, height); step membership is captured by `process_steps.group_id` and round-trips through `save.php` via a `groupIdMap[oldRef -> realId]` so newly-created groups in the same save are referenced correctly by their member steps.
- **Gradient fills** on both steps and groups. Detail panel's Colour input grows a `Gradient` checkbox plus a second-colour picker; when on, the shape renders as `linear-gradient(135deg, color, color2)` for a subtle diagonal fade. Stored as a separate `color2` column (nullable — null = solid as before). The default second colour seeded into the picker when you tick the box is a 40-unit darker shade of the primary colour so the gradient looks sensible out of the gate, but you can change it to anything.
- **Swimlanes**: Horizontal bands stacked top-to-bottom across the canvas, with a left-edge label header showing the lane name (vertically). Steps gain a nullable `lane_id` that auto-assigns based on where the step ends up after each drag. Drag a lane's header up/down to reorder — all steps in every affected lane reflow to stay anchored to their lane's band. Drag the bottom edge of a lane to resize it — lanes below shift, and steps in them follow. Per-lane gradient fills supported via the same `color2` mechanic as steps and groups. Delete a lane: it disappears, the lanes below shift up to close the gap, and steps that belonged to it have their `lane_id` cleared but keep their position so nothing is lost. Persisted in the `process_lanes` table (id, process_id, label, color, color2, display_order, height); lane id flows through `save.php` as a temp-to-real mapping so newly-created lanes in the same save are referenced correctly by step `lane_id`.
- **Rich step right-click menu**: Right-click any step for a dense menu of editing shortcuts grouped into four sections. **Edit label / Add note / Link to URL…** — quick rename, drop a sticky-note annotation tethered to the step, and prompt-set a URL link that adds a chain-link badge in the step's top-right corner (click to open in a new tab; gracefully validated to `http(s)://` only). **Create new… / Change to… / Connect to… / Reverse connection… / Delete all connections** — type submenus (driven by `process_step_types`), click-to-connect mode, plus connector tools: *Reverse connection* opens a submenu listing each incident connector by the other end's label with an arrow showing direction (greyed when the step has no connections); *Delete all connections* asks for confirmation and removes every incident edge. **Cut / Copy / Paste / Duplicate / Copy formatting / Apply formatting** — full clipboard semantics: Cut visually fades the source via `.pm-step.is-cut` and removes it only on Paste; Copy persists so it can be pasted repeatedly; Paste drops the new step at the original right-click cursor position; Duplicate offsets by 40px; Copy / Apply formatting paints colour + gradient between steps (paint-job clipboard, distinct from the step clipboard). **Delete…** — confirms then removes the step and its connectors. Cut is reversible via Escape (un-fades, clears the clipboard). Menu items are dynamically enabled/disabled and shown/hidden via `showContextMenu()` based on current state (no connections → disabled; nothing copied → no Apply; clipboard empty → no Paste).
- **URL link per step**: A `url` column on `process_steps` (VARCHAR(500) NULL) stores an optional link. The right-click *Link to URL…* item or the detail-panel *Link* input sets it; the canvas step gains a small chain-link badge in its top-right corner; clicking the badge opens in a new tab (`target="_blank" rel="noopener noreferrer"`) without triggering a step drag. The `.is-exporting` chrome-hider strips the badge from PNG / PDF exports.
- **Sticky-note annotations** (`process_annotations`): independent free-form notes that sit above steps on the canvas (z-index 4). Drop one via the step's right-click *Add note* item; drag to reposition, resize via the bottom-right handle, double-click to focus the text area; selection slides in a dedicated detail panel with text / colour / position / size. Persisted alongside steps in the same save transaction. Schema: `id, process_id, text, x, y, width, height, color (default #fff59d sticky yellow), color2`. Stickies don't participate in connectors or lane/group ownership — they're pure visual annotation.
- **Export to PNG / PDF / Mermaid**: Toolbar `Export` button opens a modal with three format tiles. **PNG** and **PDF** capture the current diagram via `html2canvas` (vendored, same as Network Mapper #257) at 2× resolution for crisp print/slide output; PDF is wrapped via `jsPDF` onto an A4 page with portrait/landscape auto-picked by the diagram's aspect ratio and proportional fit-to-page with a 24pt margin. The capture rect is the bounding box of all steps + groups (with 40px padding); when swimlanes are present the rect extends to `x=0, y=0` so lane headers and the top of the first lane aren't cropped. An `.is-exporting` class hides edit-time chrome during capture — edge handles, selection rings, the rubber-band box, the empty-state placeholder, the dot-grid background — so the export is what you'd want to share, not what you're editing. Files name themselves after the process title slug (e.g. *Incident triage* → `incident-triage.png`). **Mermaid** generates [Mermaid](https://mermaid.js.org/) flowchart markup ready to paste into any Markdown editor that supports it (GitHub READMEs, GitHub/GitLab wikis, Notion, Confluence, Obsidian, Mermaid Live Editor). Lanes become `subgraph` blocks with `flowchart LR` direction so they stack vertically like horizontal swimlanes. Each step's **shape** (not its type name) decides the Mermaid syntax — so custom user-defined types map cleanly via their chosen shape: rectangle → `["label"]`, rounded → `("label")`, pill → `(["label"])`, circle → `(("label"))`, cylinder → `[("label")]`, subroutine → `[["label"]]`, diamond → `{"label"}`, hexagon → `{{"label"}}`, parallelogram → `[/"label"/]`, trapezoid → `[/"label"\]`. Mermaid has no document or cloud primitive in classic syntax, so document collapses onto parallelogram and cloud onto circle as the closest visual matches. Connectors become `-->` arrows (with `-->|"label"|` if they have labels) and Mermaid resolves IDs across subgraph boundaries so cross-lane connectors just work. Hand-placed positions, colours, gradients, and groups don't survive Mermaid — it auto-layouts. PNG/PDF preserve everything visible.

### Workflows (`workflow/`)
Cross-module automation engine — triggers that fire when something happens in another module (a ticket is created, a form is submitted, a task completes), conditions that decide whether to act, and actions that run in order. The strategic Priority 1 from the competitive-gaps roadmap: the single feature whose absence is most likely to lose a deal vs Halo / ServiceNow.

> **Status: Stages 1, 2 & 3 — engine foundation + visual canvas + AI co-author.** Schema (`workflows` + `workflow_executions`), engine class (`workflow/includes/engine.php`) with trigger/operator/action catalogues + `dispatch(event, payload)` + `manualFire(workflowId, payload)`, **Process Mapper-style canvas editor** (dot-grid, snap-to-grid, draggable nodes for trigger / condition / action with distinctive shapes — pill / diamond / rounded rectangle, auto-routed SVG connectors), slide-in detail panel that swaps body based on selection (workflow / trigger / condition / action), list landing page with active/inactive status pills, "Test fire" button for synthetic-payload runs, recent-executions panel, and an **AI co-author** that turns a plain-English description into a structured workflow proposal applied to the canvas in one click (reuses the CMDB AI API key + model). v1 action handler is `log_message` so the engine is exercisable end-to-end without host modules wired up yet — the AI co-author leans on it as a stand-in for unimplemented actions like "send email" with the message documenting the intent. Real action handlers (set ticket status, send email, create task, Graph API actions) + real trigger wiring from host modules + dry-run + Watchtower integration + starter recipes all planned for subsequent commits.

- **A workflow has three parts**: a **trigger** (event-name string like `ticket.created` from the catalogue exposed by the engine), zero or more **conditions** ({field, op, value} objects evaluated against the event payload via dot-notation — `ticket.priority_id equals 1`), and one or more **actions** ({type, args} objects run in order). Conditions use AND semantics; if any fails the run is logged as `skipped`. Actions failing log as `failed` and abort the rest of the chain.
- **Engine contract**: `WorkflowEngine::dispatch($event, $payload)` is the only call host modules need to make — it finds active workflows for the event, evaluates conditions, executes actions, and writes a row to `workflow_executions` for every run (with a JSON `step_log` capturing every condition/action's result, the `trigger_payload` snapshot, the timing, and any error message). Engine failures are swallowed and logged to PHP error_log so a buggy workflow can never break the host module's request.
- **Catalogues live in the engine** (`availableTriggers()`, `availableOperators()`, `availableActions()`, `availableFields($trigger)`) so the editor never drifts out of sync. Adding a trigger = one entry in the catalogue + a single `dispatch()` call from the host module's save flow. Adding an action = one entry in `availableActions()` + one private handler method.
- **All seven triggers fire from their host modules.** `ticket.created` (`api/tickets/create_ticket.php`), `ticket.status_changed` / `ticket.priority_changed` / `ticket.assigned` (`api/tickets/assign_ticket.php`), `form.submitted` (`api/forms/submit_form.php`, after the submission commits — payload includes a `submission.fields` map of every answer keyed by label, plus the first email-type answer as `submission.email`), `task.completed` (`api/tasks/save.php`, only on the transition *into* a closed status), and `change.approved` (fired from both `api/change-management/submit_cab_vote.php` when a CAB vote crosses the approval threshold and `api/change-management/save.php` when a manual status edit moves the change to Approved). Each dispatch is wrapped in a host-side try/catch on top of the engine's own internal swallowing so a workflow can never break the host module's request.
- **Editor**: form-based v1 — name + description + trigger dropdown + dynamic conditions list (field dropdown populated from the engine's per-trigger field hints, operator dropdown, value input that disables for `is_empty` / `is_not_empty`) + dynamic actions list (type dropdown + args JSON input) + active toggle. Right sidebar shows the last 20 executions with status pills and timestamps so you can verify each test fire. **Test fire** button manually dispatches the workflow with an empty payload so you can exercise the engine without waiting on a real event — the run is logged as a normal execution but doesn't increment the workflow's `run_count` counter so the production metrics stay clean.
- **Infinite-loop protection**: because workflow actions mutate host data that re-dispatches events, a workflow could trigger itself — directly (A fires an event that re-runs A), via a cycle (A → B → A), or via fan-out. Execution is synchronous within one request, so the engine guards every run with three request-scoped counters: an **active-workflow stack** (a workflow already running in the current chain is refused re-entry — catches every cycle), a **chain-depth ceiling** (`MAX_CHAIN_DEPTH`, backstop for deep acyclic cascades), and a **per-request run ceiling** (`MAX_RUNS_PER_REQUEST`, backstop for fan-out). A blocked run is recorded as an `aborted` execution row with the reason, so the block is visible in the audit rather than failing silently.
- **JSON in TEXT columns** for `conditions` and `actions` (not normalised tables) so the rule shape can evolve — new operators, new action kinds, new arg shapes — without a schema migration each time.
- **Execution audit**: every run writes to `workflow_executions` with a full step-by-step log, the trigger payload snapshot, start/finish timestamps, status and any error. Future Watchtower integration will surface failed runs as attention cards.

### Network Mapper (`network-mapper/`)
Visual layer over the CMDB for drawing network and architecture diagrams. Diagrams are not standalone artwork — every node is a binding to a real CMDB object, so the diagram stays in sync with what the rest of the platform knows.

> **Status (Phase 1 complete — chunks A + B + C + D)**: working editor end-to-end. Drag a class tile onto the canvas → pick which CMDB object to bind it to → node is placed (snapped to 20px grid), draggable to move, Delete to remove. Planned objects render with dashed border + amber tint matching the CMDB browse styling. Connectors drawable between any two nodes via edge handles, with arrowheads, optional labels, and select/delete. Select a node → detail panel slides in beside the canvas → **Add related objects** pulls CMDB neighbours (outgoing/incoming relationships + property references) into a modal where you tick which to add; selected objects are placed in a ring around the source and connectors are auto-drawn with `cmdb_relationship_id` populated so the line traces back to a real CMDB relationship. Autosave, manual save, save-as-new-version, read-only handling for historical versions all in place.

- **Versioning from day one**: each diagram is a row in a `network_diagrams` chain linked by `parent_diagram_id`. The leaf (no children) is the editable "current" version; older nodes are read-only history. Saving as a new version clones the leaf forward and stamps the old leaf as historical. No branching in v1 — a parent can have at most one child, so a chain is strictly linear. The editor enforces this on the frontend (Save and Save-as-new-version disabled on non-leaves, amber read-only banner shown) and the backend (`save_diagram.php` refuses to write to a non-leaf; `create_version.php` refuses to fork an already-forked parent). A **Versions dropdown** in the editor toolbar lists every version in the chain (newest first) with author + date + Current / Read-only / Viewing pills — click any to jump straight there.
- **Page size guide**: optional dashed cyan outline rendered on the canvas at the chosen paper size — **A4 / A3 / A2 / Letter / Tabloid** × portrait or landscape, plus Off (default). Picked via a Page dropdown in the editor toolbar, persisted per-diagram (`network_diagrams.paper_size` + `paper_orientation`), carried forward on save-as-new-version. Lets analysts compose diagrams inside the printable area before sharing/exporting so nothing gets cropped. Outline renders inside the SVG layer with a soft white fill (lets the dot-grid bleed through to distinguish on-page vs off-page) and a corner label naming the chosen paper. Drives the export bounds — see PNG/PDF export below.
- **PNG / PDF export**: two toolbar buttons (`PNG`, `PDF`) snapshot the diagram for sharing or printing. Capture is WYSIWYG with the page outline: if a paper size is set, the export clips exactly to the page rectangle so what you see inside the outline is what comes out; if no paper size is set, it crops to the bounding box of placed nodes plus 40px padding. Branding header/footer, page outline, connectors, node icons, and labels all render in the output exactly as they appear on screen. PNG is exported at 2× the diagram's native resolution (via html2canvas's `scale: 2`) so the result is crisp on retina screens and when zoomed during a presentation; PDF uses jsPDF with the actual paper size + orientation (A4/A3/A2/Letter/Tabloid, portrait or landscape) so the file opens at the right physical dimensions for print. Implementation flow: stash the current zoom/scroll/selection, reset zoom to 1× so the rasteriser captures at native resolution, add an `.is-exporting` class that hides edit-time chrome (selection rings, edge handles, the empty-state placeholder), call `html2canvas(elCanvasInner, { x, y, width, height, scale: 2, backgroundColor: '#fff' })`, then restore everything. Filename slugifies the diagram title + version label so a "Production Network" v2 exports as `production-network-v2.png`. Libraries vendored locally at `assets/js/vendor/` (html2canvas 1.4.1 + jsPDF 2.5.2) so the feature works offline and doesn't depend on a CDN at print time.
- **Header / footer overlay**: optional six-slot branding strip rendered along the top and bottom of the page outline (left / centre / right, header + footer). Each slot is free text mixed with template tokens — `{{logo}}` (the org-wide company logo uploaded at System → Branding), `{{title}}`, `{{author}}`, `{{version}}`, `{{modified}}` — resolved client-side at render time. Org-wide defaults configured at System → Branding apply to every diagram by default; a **Branding** toolbar button opens a modal where any individual diagram can override one or more slots (Word/Google Docs style). Modal placeholders show the org default for each slot so the user sees what they're overriding. **Reset** clears all overrides and re-inherits the org defaults. Per-diagram overrides live on six nullable `VARCHAR(200)` columns on `network_diagrams` (NULL = inherit, '' = explicit blank, anything else = override); carried forward on save-as-new-version. Gated on the page outline being on — without paper bounds the strip has no anchor point. Tokens are HTML-escaped at render; the logo renders as an `<img>` with max-height 28px so it sits neatly inside the strip.
- **Landing page** (`network-mapper/`): grid of cards, one per chain, each showing title, version pill (with chain length when > 1 version), description preview, node + connector counts, author, and updated timestamp. Filter-by-title search. **+ New diagram** modal collects title, description, and an initial version label (default `v1`).
- **Editor** (`network-mapper/diagram.php?id=X`): title bar with a version pill that turns amber for historical versions, metadata row (author / created / updated), left-side **CMDB class palette** (one tile per class with its icon + name + object count, drag onto the canvas to start placing), main canvas with dot-grid background, and an action bar with autosave toggle, status indicator, **Save as new version** button, and **Save** button (also bound to Ctrl+S). Autosave mirrors Process Mapper's pattern: `markDirty()` / 2s debounced `scheduleAutosave()` / status indicator cycling through `Unsaved` / `Saving…` / `✓ Saved` / `⚠ Save failed — retry` / `Autosave off`, toggle state persisted in `user_preferences` (key `network_mapper_autosave`). Autosave defers mid-drag (same fix as Process Mapper #228 — `save()` reloads the diagram, which would destroy an in-progress drag). `beforeunload` fires the browser's leave-prompt if there are unsaved changes. **Save as new version** auto-saves first if dirty (since `create_version.php` clones from the persisted state), then opens a modal pre-filled with the current diagram's metadata and a suggested incremented label (trailing integer in the version label bumps automatically — `v3` → `v4`).
- **Drag-to-canvas → bind → place** (chunk C): drag a class tile onto the canvas, drop opens a CMDB object picker scoped to that class — preloads the class's full object list, type-ahead filters server-side (200ms debounced), arrow keys to navigate + Enter to pick, already-placed objects auto-hide so you can't double-place. After pick, the node renders at the drop coordinates (snapped to the 20px grid, offset so the icon centres on the cursor). **Placed nodes** show the class icon (from the chunk-B icon library) above a 12px label with the object name (truncated to 2 lines). **Planned objects** render with a dashed amber border + soft `#fffbeb` background + italic amber label + a small PLANNED pill, matching the CMDB browse/detail styling — turns the canvas into a visual as-is/to-be map. **Click** selects (cyan border + soft cyan glow), click on empty canvas clears. **Drag** moves the selected node (snapped to grid), `markDirty()` only runs if the node actually moved so a click-without-drag selects but doesn't dirty. **Delete** key removes the selected node and cascades down any incident connectors so `save_diagram.php` doesn't reject the payload. **Save → reload**: every successful save re-fetches the diagram so temp ids (negative numbers used for unsaved nodes) resolve into real auto-increment ids — vital before any connector references a node. Current selection is preserved across the reload by matching on `cmdb_object_id` rather than node id. Read-only versions accept selection but reject drop / drag / delete (the backend would refuse anyway).
- **Connectors** (chunk D part 1): hover or select a node → 4 cyan edge handles appear at the top/right/bottom/left of the icon. Mousedown on a handle starts a connect drag — a dashed cyan temp line tracks the cursor; mouseup on another node creates a connector and selects it. Connectors render as SVG paths with arrowheads (cyan when selected, slate otherwise) in an SVG layer behind the nodes. Endpoint geometry snaps to the side of each icon's bounding box that faces the other node, so lines stay sensible after dragging either end around. **Click** a connector to select (wider hit-area underneath the visible stroke so they're easy to grab), **Delete** removes it, **double-click** opens an inline cyan-bordered text input at the midpoint where you type a label (Enter saves, Esc cancels, blur saves, empty clears) which renders mid-line on a white pill. Self-loops and duplicate connectors (same direction between the same pair) are silently refused. Connectors between two brand-new (unsaved) nodes save in a single round-trip — both `tempId` and the connector's `from_node_id`/`to_node_id` (which carry the same negative tempIds) are sent in the same payload, and `save_diagram.php`'s `nodeIdMap` resolves the refs server-side.
- **Detail panel + Add related objects** (chunk D part 2): selecting any placed node slides a 320px panel in beside the canvas showing the node's name, class, planned status, a deep-link back to the CMDB object page, and every CMDB property that has a value (lazy-loaded from `get_object.php` on selection, hides empty properties, type-aware rendering — boolean as Yes/No, dropdown as a coloured pill matching the option's colour, object_ref as a pink pill linking into CMDB, dates locale-formatted, URLs auto-linked in text fields). The panel's **Add related objects** button opens a modal that calls a new endpoint (`api/network-mapper/get_related_objects.php`) which gathers everything CMDB knows about that object across three buckets — **outgoing relationships** (this depends on X / hosts X), **incoming relationships** (X is hosted by this), and **property references** (other objects that point at this one via an `object_ref` property). Rows render grouped by bucket with the related object's icon + name + class + relationship verb. Objects already on the canvas show a grey "on canvas" badge and a disabled checkbox so they can't be double-placed. Ticking rows enables the **Add N objects** button; confirm bulk-adds them: deduplicated by `cmdb_object_id`, new nodes laid out in a ring around the source node (radius scales with count so big pull-ins don't crowd, angles distributed evenly starting at 12 o'clock), with one connector per ticked path. Direction respects relationship kind (outgoing = src→other; incoming + property-ref = other→src), and `cmdb_relationship_id` is populated for relationship-derived connectors so the line traces back to a real CMDB row. Property-ref connectors leave `cmdb_relationship_id` NULL but carry the property name as their label. The flow turns "CMDB has a TON of info" into **guided graph exploration** — you start from one node and pull in neighbours on demand instead of dumping everything onto the canvas at once. Read-only versions show the panel but the Add button is disabled with a tooltip explaining why.
- **Icon library** (~65 icons): `assets/js/network-mapper-icons.js` ships a `window.NM_ICONS` map + `window.NM_ICON_META` (label + category per icon) + `window.NM_ICON_CATEGORIES` (display order/labels) + `nmRenderIcon(key, size, extra)` helper. Twelve categories: Compute & Servers (server / server-rack / server-blade / server-tower / mainframe / vm / function / workstation), Databases (database / database-cluster / database-cache), Storage (storage / storage-san / storage-tape / backup), Networking (network / router / switch / firewall / load-balancer / proxy / vpn / gateway / wireless-ap / modem / cdn / dns), Security (shield / lock / key / ids / siem), Cloud (cloud / cloud-private / cloud-public / cloud-hybrid / region), Containers (container / container-pod / kubernetes / registry), Applications & Data (application / service / website / api / microservice / queue / cache / dashboard), Endpoints (laptop / mobile / tablet / iot / printer), Monitoring & Ops (monitor / alert / log), People & Org (person / team / org), Files & Generic (document / folder / globe / mail / calendar / box). Feather-style 24×24 viewBox, stroke 1.8, currentColor so they tint with any container. Unknown keys fall back to box. The full set is also seeded into `cmdb_icons` so CMDB Classes settings can pick from the wider library when defining new classes. Multiple variants per concept (3 server styles, 3 database styles, 4 cloud styles, etc.) so users can visually distinguish two objects of the same class on a diagram — e.g. "Production MS SQL" as `database-cluster` vs "Reporting Oracle" as `database` via the per-node icon override.
- **Per-node icon override**: any node on a diagram can override its class's default icon with any of the ~65 keys above. UI lives in the node detail panel — Icon row shows the current icon + a Change button → opens the icon picker modal (12 category-grouped sections, type-ahead filter that matches both the icon key and its label). Picking the class's own default icon stores NULL rather than a redundant override, so the data stays clean. Reset button appears next to Change when an override is active. `network_diagram_nodes.icon_override` is a free-text VARCHAR(100) so any icon key works, not just seeded ones.
- **Schema** (3 tables, all prefixed `network_`): `network_diagrams` (chain root via self-FK `parent_diagram_id` with `ON DELETE SET NULL` so deleting a version doesn't cascade through history), `network_diagram_nodes` (FK to `cmdb_objects` for binding, plus position `x/y`, `size` enum `small/medium/large`, and optional `icon_override` for per-node visual swap), `network_diagram_connectors` (from/to node ids with optional `cmdb_relationship_id` if the line corresponds to a real CMDB relationship, plus free-text `label` and `line_style`). FKs cascade nodes + connectors on diagram delete; node delete cascades its incident connectors.
- **APIs** (`api/network-mapper/`): `list_diagrams.php` (leaf versions only — filters with `WHERE id NOT IN (SELECT parent_diagram_id…)`), `list_versions.php` (root-to-leaf chain ordered oldest first), `get_diagram.php` (single version hydrated with CMDB object name/class/icon/`is_planned` per node), `create_diagram.php`, `create_version.php` (clones forward, refuses to fork an already-forked parent), `save_diagram.php` (transactional with temp→real node id mapping for connector refs; refuses to save non-leaf versions; only updates metadata fields the caller explicitly sends), `delete_diagram.php` (single version delete — older versions in the chain preserved via the `SET NULL` parent_diagram_id behaviour), `get_related_objects.php` (walks `cmdb_object_relationships` outgoing + incoming and `cmdb_object_properties.value_object_id` to return all CMDB neighbours of a given object for the Add-related-objects flow — one row per (object, path) so an object reachable via two paths surfaces as two tickable rows).

### Tasks (`tasks/`)
Kanban-style task management with board, list, calendar, and timeline views for tracking internal work.

- **Board view**: Kanban board with one column per status — columns (and their colour) are driven by Settings → Statuses, so custom statuses get their own column — with drag-and-drop card movement and drag-to-reorder columns (dragging a column header rewrites the status display order)
- **Card quick actions**: Right-click any board card for a context menu — assign analyst, assign team, change status, change priority, or create a subtask — without opening the card
- **Configurable card fields**: Settings → Card toggles which extras show on each board card — priority, assignee, team, start date, due date, description excerpt (first 250 chars), subtask progress, and linked-item indicator — so tasks can be scanned without opening them
- **Tags**: Multi-value labels for cross-cutting themes (e.g. Security, ISO, Environment), managed in Settings → Tags. Per-install display options control where they appear — card chips, sidebar filter, search matching, calendar/timeline — and whether analysts may create new tags inline while editing a task
- **List view**: Sortable table with all task fields
- **Search**: As-you-type search box filters the board and list by task title and description (all words must match), with no server round-trip
- **Calendar view** (`tasks/calendar/`): Month grid of tasks coloured by status. How a multi-day task (one with a start date earlier than its due date) is drawn is configurable in Settings → Calendar — **Deadline chip** (a single chip on the due date), **Spanning bar** (one continuous bar across the range, wrapping at week rows), or **Every day** (a chip in every day cell of the range)
- **Timeline view** (`tasks/timeline/`): Gantt-style horizontal bars from each task's start date to its due date, grouped by assignee, status, or flat, with a today marker and day-width zoom
- **Start & due dates**: Tasks have an optional `start_date` plus the existing `due_date`; together they define the span shown on the calendar and timeline
- **Quick create**: Inline task creation from each board column
- **Detail panel**: Slide-in panel with inline editing, auto-save, and TinyMCE rich text description. Calendar/timeline tasks deep-link to the board with the panel open (`?task=N`)
- **Subtasks**: Two-level hierarchy with checkbox toggling and progress display on parent cards
- **Linking**: Link tasks to tickets or changes via searchable dropdowns
- **Team assignment**: Assign tasks to analysts and teams, filter every view by team/analyst/personal
- **Comments**: Threaded comments on each task
- **Watchtower integration**: Overdue and due-today counts shown on attention dashboard
- **Help guide** (`tasks/help.php`): A built-in scroll-spy guide covering the board, list, calendar, timeline, the task panel, tags and settings, reachable from the Help link in the module header
- **Multilingual**: The whole module (PHP pages and JavaScript) runs through the shared i18n system — `lang/<locale>/tasks.php` for all 20 supported locales; the active language follows the analyst's interface-language preference, with per-key fallback to English

### CMDB (`cmdb/`)
Configuration Management Database — model your IT estate as a graph of typed objects (servers, databases, applications, etc.) with a strict containment hierarchy and a separate user-defined relationships layer. See [`docs/cmdb.md`](docs/cmdb.md) for the full design and roadmap.

- **Browse page** (`cmdb/`): class sidebar with object counts → table of objects in the selected class with name, parent, child count, last updated. Search box filters by name. **+ New** creates an object and opens it for editing.
- **Object detail page** (`cmdb/object.php?id=X`): name editable inline (click and type, save on Enter/blur), class + parent breadcrumb, and three sections — **Properties** (dynamic form built from the class's property definitions, with type-aware inline editors: text input / number / date picker / Yes-No select / dropdown / object-reference picker with autocomplete), **Hierarchy** (parent + children list with clickable navigation), **Relationships** (outgoing and incoming columns side-by-side, each link clickable to navigate, X button to remove, + Add relationship modal with verb picker and global object autocomplete). Object references are rendered as pink pills you can click to drill through. Delete cascades to all descendants (with a clear confirmation showing how many will go).
- **Settings page** with three tabs:
  - **Classes**: define types of things (e.g. Server, Database). Each class has its own auto-generated immutable `class_key` plus an editable display name. Click the property-count badge on any class to manage its properties (label, immutable key, type — text/number/date/boolean/dropdown/object_ref, target class for object references, required flag, dropdown options, display order). Property keys are immutable so renaming a label never breaks references.
  - **Relationship Types**: define the verbs that link objects (e.g. *depends on* ↔ *is depended on by*). Three defaults seeded on first run.
  - **AI Integration**: provider/key/model + custom-instructions textarea + Test connection button, mirroring the established per-feature Anthropic key pattern (separate from RFP AI / Knowledge AI / Reply Cleanup for granular billing visibility).
- **AI Suggest Properties** (in the Properties manager): two-stage wizard — Claude asks 3-5 clarifying questions about the analyst's specific environment (e.g. for *Database*: "What kind — SQL Server / Postgres / Mongo?"), then suggests 6-12 tailored properties with rationale. Object-reference suggestions automatically create the missing target class on the fly (e.g. suggesting "Owner → Person" auto-creates a Person class if one doesn't exist).
- **Tickets ↔ CMDB linking**: tickets get an **Affected CMDB Objects** section in the reading pane — type to search across every CMDB object, click to link, X to unlink. Each linked object renders as a clickable info card showing class + parent context. The CMDB object detail page reciprocally gets an **Activity panel** with two buckets — open tickets (live) and recent closed tickets (capped at 20, total count shown) — each rendered as a card with status pill in the lookup colour, priority, assignee, department, and last-updated/closed date. Clicking any ticket card deep-links to that ticket via `?ticket_id=X`. The AI summary prompt now also pulls open + closed ticket counts so the synthesis can mention things like *"currently has 2 open tickets including a P1 backup failure"* — turning the CMDB into a live operational map rather than a static inventory.
- **Planned objects**: nullable boolean `is_planned` flag on `cmdb_objects` lets you record future-state architecture in the same CMDB as your real estate without forking the data. Toggle in the New Object modal and on the object detail page header. Planned objects render with a dashed border + amber **PLANNED** pill across every surface that shows the object (browse table, detail header, search picker results). Toggle off when the object goes live — single column flip, no migration. The AI summary prompt is taught to surface planned status near the start of the synthesis and frame the sentence in future tense ("will host…", "is proposed to…") so the reader knows immediately the object isn't yet in service. Designed to support the upcoming Network Mapper module where some diagrammed nodes will represent to-be architecture rather than as-is reality.
- **Data model** (8 tables, all prefixed `cmdb_`): icons (curated lookup), classes, class_properties, class_property_options, objects, object_properties, relationship_types, object_relationships. Strict cascade-delete on parent_id (per the design's *ontological dependency* parent semantics). Cycle prevention on parent assignment.

---

## Browser Extension

A Chrome/Edge browser extension that shows your Watchtower dashboard summary in a popup with a badge count for items needing attention.

### Setup

1. **Generate an API key**: Go to **Software > Settings > API Keys**, enter a label (e.g. "Chrome extension"), and click **Generate**. Copy the key.
2. **Install the extension**: Open `chrome://extensions`, enable **Developer mode**, click **Load unpacked**, and select the `browser-extension/` folder from this repository.
3. **Configure**: Click the extension icon, then **Settings**. Enter your FreeITSM server URL and paste the API key. Click **Test** to verify, then **Save**.

### Features

- **Badge count**: Shows the number of items needing attention (urgent tickets, unapproved changes, active incidents, etc.)
- **Popup dashboard**: Compact 8-module summary matching the full Watchtower view
- **Configurable polling**: 1 to 30 minute refresh intervals
- **Rate limited API**: 60 requests per minute per key (configurable in system settings)
- **Works in Chrome and Edge** (Manifest V3)

---

## API Reference

All endpoints live under `api/` and return JSON. Every endpoint requires an active session (`$_SESSION['analyst_id']`) — except the public REST API below and the legacy `api/external/` ingest endpoints.

### REST API v1 (`api/v1/`) — public, key-authenticated

A versioned RESTful API for integrations (monitoring, RMM, scripts, portals). Keys are created under **System → API** with granular per-resource permissions and optional company scoping; authenticate with `Authorization: Bearer fitsm_…`. Front controller (`api/v1/index.php`) + `.htaccess` rewrite gives clean URLs (`/api/v1/tickets/42`); without mod_rewrite the same routes work at `/api/v1/index.php/tickets/42`. Real HTTP status codes; responses are `{data, meta?}` / `{error: {code, message}}`; 60 req/min rate limit per key (overridable per key). Writes behave exactly like the UI: audit rows, template emails, CSAT auto-trigger and workflow-engine events all fire.

Current surface (tickets + assets + problems + changes + knowledge + tasks + cmdb + contracts + calendar + software + service-status + morning-checks + forms + workflow + network-mapper modules): `GET/POST /tickets`, `GET/PATCH/DELETE /tickets/{id}`, `POST /tickets/{id}/restore`, notes (`GET/POST /tickets/{id}/notes`), conversation (`/thread`), history (`/audit`), live SLA (`/sla`), time entries (`GET/POST/DELETE /tickets/{id}/time-entries`), requesters (`GET/POST /users`, `GET/PATCH /users/{id}`); assets: `GET/POST /assets`, `GET/PATCH /assets/{id}` (create/update with `asset_history` audit parity + warranty-calendar re-sync; no delete — the product never deletes assets), assignments with custody logging (`GET/POST /assets/{id}/assignments`, `DELETE .../{user_id}`), `/history`, `/custody`, and agent inventory reads (`/disks`, `/network-adapters`, `/devices`, `/software`), plus lifecycle filters (`warranty_within_days`, `warranty_expired`, `not_seen_days`); problems: `GET/POST /problems`, `GET/PATCH/DELETE /problems/{id}` (PRB-numbering, per-field `problem_audit` parity, closed-status transitions, known-error/RCA fields, **company-scoped** like tickets), append-only journal (`GET/POST /problems/{id}/notes`), `/audit`, and incident/change linking (`POST/DELETE /problems/{id}/tickets[/{id}]`, `/problems/{id}/changes[/{id}]` — same-company rule enforced); changes: `GET/POST /changes`, `GET/PATCH/DELETE /changes/{id}` (CHG-numbering, risk score/level computed like the UI, full `change_audit` parity, `change.approved` workflow event, install-wide like the UI), comments (`GET/POST/DELETE /changes/{id}/comments[/{id}]`), `/audit`, and the **CAB workflow** (`GET/POST /changes/{id}/cab` roster + `POST /changes/{id}/cab/vote` with the UI's exact auto-transition mechanics — required-reject → Draft, all/majority threshold → Approved); knowledge: `GET/POST /knowledge/articles` (keyword search, tag + review-cycle filters, `modified_since` for sync), `GET/PATCH /knowledge/articles/{id}` (view-count bump opt-in via `count_view=true`; `save_as_version` snapshots + bumps like the UI; **auto-regenerates the OpenAI search embedding** when configured), recycle-bin semantics (`DELETE` archives, `/restore`, `/permanent` purge with the UI's archived-only guard + retention auto-purge), version history reads and `GET /knowledge/tags`; tasks: `GET/POST /tasks`, `GET/PATCH/DELETE /tasks/{id}` (board-ordered lists, subtasks via `parent_task_id`, ticket/change/contract links validated — ticket links respect the key's company scope, `task.completed` workflow event on close via PATCH), **`POST /tasks/{id}/move`** (the kanban drag as an API call — column + 0-based position with automatic re-packing), comments (`GET/POST /tasks/{id}/comments`, create-only like the UI); cmdb: classes read (`GET /cmdb/classes[/{id}]` with typed property definitions + dropdown options), objects (`GET/POST /cmdb/objects`, `GET/PATCH/DELETE /cmdb/objects/{id}` — property values validated per type exactly like the UI plus dropdown-option checking, parent cycle-guard, delete removes the descendant tree explicitly), `GET .../impact` (blast radius), relationships (`POST/DELETE .../relationships[/{id}]` — verb or type id, 409 on duplicate triple) and ticket links (`GET/POST/DELETE .../tickets[/{id}]` — **company-scoped**, unlike the internal CMDB-side read); contracts: `GET/POST /contracts`, `GET/PATCH/DELETE /contracts/{id}` (renewal filters mirroring Watchtower — `expiring_within_days`, `notice_within_days`, `expired`; duplicate `contract_number` → 409, an API-only safeguard; delete removes term values + unlinks tasks/calendar/RFPs explicitly), term tabs (`GET/POST /contracts/{id}/terms` per-tab upsert), full **suppliers** CRUD (`POST /suppliers`, `GET/PATCH/DELETE /suppliers/{id}` incl. the `supplies_assets` flag the UI can't set) and supplier **contacts** CRUD (the RFP Builder stays internal-only, unexposed); calendar: `GET/POST /calendar/events`, `GET/PATCH/DELETE /calendar/events/{id}` (the UI's exact window-overlap query; **naive server-local datetimes** matching the UI/ICS model; `source` exposed on reads with generated `asset_warranty` events **read-only via the API** — tighter than the UI) + `GET /calendar-categories`; software: read-only inventory (`GET /software/apps[/{id}[/machines]]` with install counts and the UI's component filters) + licences CRUD (`GET/POST /software/licences`, `GET/PATCH/DELETE .../{id}`) with **computed compliance** (installs vs licensed seats — a view the UI doesn't have) and server-side renewal filters (`renewal_within_days`, `due_soon` per-licence notice period, `renewal_overdue`); service-status: the **health board** (`GET /service-status/services` with each service's derived live status — worst open-incident impact, the dashboard's exact computation), services CRUD, and **incidents CRUD built for monitoring integrations** (`POST /service-status/incidents` with per-service impact levels, PATCH to update/resolve with the UI's exact `resolved_at` stamp-once/clear-on-reopen rule, strict 422s where the UI silently skips); morning-checks: the **day board** (`GET /morning-checks/board` — every active check with its result for a date, the dashboard's exact view) and **`POST /morning-checks/results`, the one-result-per-check-per-day upsert** (status by label or id, requires-notes rule enforced, 201 on first record / 200 on overwrite — built so a script probing backups/mail-flow/VPN can fill the board in before anyone sits down), result history with check/status/date-range/`orphans` filters, checks CRUD (delete removes history transactionally) + `GET /morning-check-statuses`; forms: list/create/update with the UI's **version-chain semantics** (one row per chain, PATCH = in-place save of the editable leaf with the positional field sync, 409 on frozen versions, `POST /forms/{id}/versions` forks like "Save as new version", `?chain=true` deletes a whole chain) and **`POST /forms/{id}/submissions` with full validation parity + the `form.submitted` workflow dispatch** (label-keyed answers — the form-to-tickets automation fires identically from a script), `is_active` settable (the UI can't today); workflow: full automation-rule CRUD (`GET/POST /workflows`, `GET/PATCH/DELETE /workflows/{id}` — trigger events, condition operators and action types **validated against the engine's own catalogues** so nothing unexecutable can be stored, unknown operators a 422 where the UI stores them silently; delete keeps the run history but detaches it cleanly instead of leaving dangling ids), **`POST /workflows/{id}/fire`** (the editor's Test fire — synthetic payload, real actions, run stats untouched), read-only **execution history** (`GET /workflows/{id}/executions`, `GET /workflow-executions[/{id}]` with status/trigger/date filters, `?orphaned=true` for runs whose workflow was deleted, full per-step logs on single reads) and machine-readable catalogues (`GET /workflow-triggers` with per-field types + valid operators, `GET /workflow-actions` with full args specs) so a client can build valid rules without guessing; network-mapper: **built for agentic discovery tools that keep network documentation alive** — diagrams CRUD with the module's version-chain semantics (leaf editable, `POST /network-diagrams/{id}/versions` snapshots, leaf-only delete + `?chain=true`), **fully hydrated reads for machine understanding** (`GET /network-diagrams/{id}`: every node with its CMDB object's name/class/icon/planned flag + optional full CI properties, connectors with endpoint objects and relationship verbs, a computed layout bounding box), **incremental node/connector operations with stable ids** (`POST/PATCH/DELETE .../nodes[/{id}]`, `.../connectors[/{id}]` — the editor can only full-replace; connectors addressable by object id with `cmdb_relationship_id: "auto"` binding to the real CMDB relationship; omitted positions auto-place), `GET .../suggestions` (CMDB neighbours of on-diagram objects not yet drawn — the "what's missing" feed), `?contains_object_id` to find every diagram a CI appears on, and strict 422s where the editor silently skips bad rows; reference data (`/analysts`, `/companies`, `/statuses`, `/priorities`, `/ticket-types`, `/origins`, `/departments`, `/asset-types`, `/asset-statuses`, `/asset-locations`, `/suppliers`, `/problem-statuses`, `/problem-priorities`, `/change-statuses`, `/change-types`, `/change-priorities`, `/change-impacts`, `/change-categories`, `/knowledge/tags`, `/task-statuses`, `/task-priorities`, `/task-tags`, `/workflow-triggers`, `/workflow-actions`, `/cmdb-icons`) and `GET /ping`. Assets are install-wide (no `tenant_id`), so key company-scoping applies to tickets and problems. Full interactive docs at **System → API → Documentation** — a searchable three-pane reference with per-endpoint error tables, clickable worked examples, request samples in seven languages (cURL, PowerShell, PHP, Python, C#, Ruby, JavaScript) that rebuild live as you fill in parameters, and live responses from your own install.

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
| `create_ticket.php` | POST | Create manual ticket (stamps the chosen send-from `mailbox_id` on the initial email) |
| `get_sendable_mailboxes.php` | GET | Mailboxes the active company can send replies from (pinned + shared, active + signed-in) — powers the New Ticket mailbox picker |
| `delete_ticket.php` | POST | Move a ticket to the trash (soft-delete — restorable) |
| `restore_ticket.php` | POST | Restore a ticket from the trash |
| `permanently_delete_ticket.php` | POST | Permanently delete a trashed ticket + all its data (irreversible) |
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
| `ai_generate.php` | POST | Streaming SSE endpoint — generates a form definition from a plain-English description |
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
- `api/watchtower/` — 1 endpoint for unified attention dashboard aggregation across all modules
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
- Single sign-on via OpenID Connect (OIDC) — multiple identity providers (Keycloak, Microsoft Entra, Okta, Google Workspace, Authentik, etc.) configurable side by side, alongside local login (see *Single Sign-On* below)
- Session-based authentication on all pages and API endpoints
- PDO prepared statements throughout (SQL injection prevention)
- Output encoding with `htmlspecialchars()` (XSS prevention)
- Client-side escaping via DOM `textContent` → `innerHTML` pattern
- OAuth 2.0 for Microsoft 365 and Google Workspace email integration
- Forgot password flow with secure email reset links (1-hour expiry, single-use tokens)
- IP-based brute force protection: escalating bans for IPs attempting logins against non-existent or locked accounts (configurable thresholds, 24-hour bans)
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

### Single Sign-On (SSO / OIDC)
- **Generic OpenID Connect** — one implementation works for any compliant identity provider (Keycloak, Microsoft Entra/Azure AD, Okta, Auth0, Google Workspace, Authentik, …). Configuration is driven by the provider's discovery document (`/.well-known/openid-configuration`), so the admin only supplies a display name, issuer URL, client ID and client secret.
- **Multiple providers at once** — each provider is a row in `auth_providers` with its own enable switch, so different cohorts of users can be migrated to different IdPs in parallel (e.g. a phased rollout or side-by-side pilots).
- **Configured under System → Single Sign-On** — add/edit providers (with a discovery *Test* button), copy the redirect URI to register in the IdP, and set two global break-glass switches: a master *Enable single sign-on* kill switch and *Allow local login*. Existing analysts are assigned to a provider via a *Sign-in method* dropdown in Tickets → Settings → Analysts (this is how pre-existing users are migrated to SSO; brand-new users can be auto-created by JIT instead).
- **Login flow** — the login page leads with an **email-first** router (type your email → routed to your provider automatically, or fall back to the local form), with provider buttons as a shortcut. The sign-in itself is Authorization Code + PKCE (S256), with `state` (CSRF) and `nonce` (replay) protection. The ID token's signature is validated against the provider's JWKS using the vendored `firebase/php-jwt` library; issuer, audience, nonce and expiry are all checked.
- **Account mapping** — an IdP identity is matched to an analyst by a stored identity link (`provider`+`subject`) or by email. **Just-in-time provisioning** (per-provider toggle) can auto-create the analyst on first login, granting a configurable set of default modules (blank = full access). **Strict isolation**: an analyst may only sign in via the provider they're assigned to, so an SSO login can never silently take over another account.
- **Email verification** — an explicit `email_verified: false` from the IdP is always refused. Providers that omit the claim entirely (e.g. Okta's org authorization server, which never sends it; Keycloak/Entra do) are accepted by default, so sign-in works out of the box. A per-provider **Require a verified-email claim** toggle (off by default) lets admins demand an explicit `email_verified: true` for IdPs where users can self-register with unverified addresses.
- **Break-glass** — when SSO is active the page leads with email-first + provider buttons and the local username/password form is tucked behind a "Sign in with a local account" link. Turning *Allow local login* off hides that link too (SSO-only experience), but local auth is never hard-disabled: `?local=1` and the master kill switch always restore it, so no one can be locked out if an IdP is down or misconfigured.
- **Single logout** — signing out of FreeITSM also ends the session at the identity provider (via its `end_session_endpoint`). SSO users skip the local TOTP/MFA step — the identity provider owns MFA.
- **Self-service portal SSO** — the same providers also serve the requester portal (`self-service/login.php`). Its login page gets the identical email-first router + provider buttons + local break-glass, and the one OIDC callback is portal-aware (a `portal=self-service` flag carried through the flow) so it resolves against the requester `users` table, sets the self-service session, and lands the user in the portal. Requester identities are linked in `user_sso_identities` (the mirror of `analyst_sso_identities`); a requester is pinned to a provider via `users.auth_provider_id`. Because requesters self-onboard (a ticket-created contact starts passwordless and unassigned), a requester matched by **verified email** who is still unassigned is **auto-claimed** onto the provider on first SSO sign-in — and JIT provisioning (the provider's *auto-create* toggle) creates a brand-new requester. Analysts and requesters are separate identity spaces, so the same email resolves independently on each portal.
- **Multi-tenant portal SSO (MSP installs)** — a provider can be **owned by a client company** (`auth_providers.tenant_id`; NULL = global/MSP-internal, e.g. analyst sign-in). On a multi-tenant install (N>1) the portal login is **email-first only** — no provider buttons up front (they'd leak every client's IdP) — and routes **email domain → company → that company's enabled IdP(s)**, reusing the same domain/sender mapping as inbound email routing. A company with **0** IdPs falls to local password, **1** routes straight through, **2+** shows a **tenant-scoped picker** (e.g. a client mid-migration between Entra and Okta); the per-user pin makes that picker a once-only first-login step. The analyst login only ever shows global providers. **Invisible at N=1** — single-company installs keep the buttons-up-front behaviour. This routes per company using each provider's own credentials; the credential-free "MSP multi-tenant app + consent" onboarding (one MSP-owned app clients consent to, keyed on the token's tenant claim) is a further layer, designed in `docs/design/`.
- **Client secrets** are encrypted at rest (AES-256-GCM) and never returned to the browser.

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
