<h1 align="center">FreeITSM</h1>

<p align="center"><strong>Free, open-source IT Service Management — self-hosted, AI-included, no per-seat fees. Ever.</strong></p>

<p align="center">
<a href="https://github.com/edmozley/freeitsm/blob/main/LICENSE"><img src="https://img.shields.io/github/license/edmozley/freeitsm?style=flat-square&color=blue" alt="MIT License"></a>
<img src="https://img.shields.io/badge/PHP-7.4--8.4-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 7.4–8.4">
<img src="https://img.shields.io/badge/MySQL-8.0%2B-4479A1?style=flat-square&logo=mysql&logoColor=white" alt="MySQL 8.0+">
<img src="https://img.shields.io/badge/Docker-Ready-2496ED?style=flat-square&logo=docker&logoColor=white" alt="Docker Ready">
<a href="https://github.com/edmozley/freeitsm/stargazers"><img src="https://img.shields.io/github/stars/edmozley/freeitsm?style=flat-square&color=gold" alt="GitHub stars"></a>
</p>

<p align="center">
🌍 <a href="https://freeitsm.co.uk">freeitsm.co.uk</a> &nbsp;·&nbsp;
📖 <a href="https://github.com/edmozley/freeitsm/wiki">Documentation Wiki</a> &nbsp;·&nbsp;
💬 <a href="https://github.com/edmozley/freeitsm/discussions">Discussions</a> &nbsp;·&nbsp;
🐛 <a href="https://github.com/edmozley/freeitsm/issues">Issues</a>
</p>

---

FreeITSM is a complete web-based ITSM platform: **21 integrated modules** covering tickets, assets, knowledge, changes, problems, tasks, a CMDB, workflows, an LMS and more — plus a **self-service portal** for your end users. It runs on a plain PHP + MySQL stack (WAMP, XAMPP, LAMP, or Docker), so your data stays on your server.

**Why teams pick it:**

- 🆓 **Genuinely free** — MIT licence, no per-seat/per-agent fees, no "Enterprise tier". Everything ships to everyone.
- 🏠 **Self-hosted** — your tickets, your customers' conversations and your knowledge base live in your database, under your backups and your privacy policy.
- 🤖 **AI included, not upsold** — reply cleanup, knowledge Q&A, form generation, course authoring, RCA drafting and more, all bring-your-own-key (Anthropic, OpenAI, or OpenRouter).
- 📥 **Every channel becomes a ticket** — email (Microsoft 365, Gmail, IMAP), WhatsApp, an embeddable web chat widget, and a portal that even staff **without a company email address** can use.

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
</table>

<p align="center"><a href="https://freeitsm.co.uk/screenshots.html"><strong>View all 57 screenshots →</strong></a></p>

## 🚀 Quick Start

The fastest route is Docker — no PHP, MySQL or web server setup required:

```bash
git clone https://github.com/edmozley/freeitsm.git
cd freeitsm
docker compose up -d
```

Then open [http://localhost:8080/setup/](http://localhost:8080/setup/) to verify the installation and create your admin account.

- **Manual install** (WAMP / XAMPP / LAMP): follow the **[Installation guide](https://github.com/edmozley/freeitsm/wiki/Installation)** — prerequisites, database setup, encryption key, and configuration files.
- **First login**: `admin` / `freeitsm` — change it immediately via the account menu.
- **Demo data**: System → Demo Data populates every module with realistic sample data, so you can evaluate with the system feeling alive.

## Modules

| Module | What it does |
|--------|--------------|
| [Watchtower](https://github.com/edmozley/freeitsm/wiki/Watchtower) | Unified attention dashboard — one glance shows what needs you across every module |
| [Tickets](https://github.com/edmozley/freeitsm/wiki/Tickets) | Outlook-style inbox with email, WhatsApp and web chat channels, SLAs, CSAT, canned responses, multi-select bulk actions, AI reply cleanup |
| [Self-Service Portal](https://github.com/edmozley/freeitsm/wiki/Self-Service-Portal) | End-user portal — request catalogue, knowledge, replies, screen recording; works even with no email address |
| [Tasks](https://github.com/edmozley/freeitsm/wiki/Tasks) | Kanban board, list, calendar and timeline views for internal work |
| [Assets](https://github.com/edmozley/freeitsm/wiki/Assets) | Asset register with custody tracking, locations, warranties, vCenter and Intune sync |
| [Knowledge](https://github.com/edmozley/freeitsm/wiki/Knowledge) | Rich-text articles with AI chat, vector search, review workflow and per-audience visibility |
| [Change Management](https://github.com/edmozley/freeitsm/wiki/Change-Management) | ITIL changes with CAB voting, risk matrix and post-implementation review |
| [Problem Management](https://github.com/edmozley/freeitsm/wiki/Problem-Management) | Root causes behind recurring incidents, known errors, AI-assisted RCA |
| [Workflows](https://github.com/edmozley/freeitsm/wiki/Workflows) | Cross-module automation — visual canvas, 138+ triggers, outbound webhooks, AI co-author |
| [CMDB](https://github.com/edmozley/freeitsm/wiki/CMDB) | Typed configuration items with relationships, impact analysis and AI summaries |
| [Network Mapper](https://github.com/edmozley/freeitsm/wiki/Network-Mapper) | Architecture diagrams where every node is bound to a real CMDB object |
| [Calendar](https://github.com/edmozley/freeitsm/wiki/Calendar) | Team calendar with categories, and an iCal feed for your phone |
| [Morning Checks](https://github.com/edmozley/freeitsm/wiki/Morning-Checks) | Daily infrastructure health checks with trend charts and PDF export |
| [Reporting](https://github.com/edmozley/freeitsm/wiki/Reporting) | System logs, audit trails, and an Intune device dashboard with drill-down |
| [Software](https://github.com/edmozley/freeitsm/wiki/Software) | Software inventory from an agent script, plus licence management |
| [Forms](https://github.com/edmozley/freeitsm/wiki/Forms) | Dynamic form builder with AI assist, versioning and submissions reporting |
| [Contracts](https://github.com/edmozley/freeitsm/wiki/Contracts) | Supplier and contract lifecycle, plus an AI-powered RFP Builder |
| [Service Status](https://github.com/edmozley/freeitsm/wiki/Service-Status) | Service health dashboard driven by incident tracking |
| [LMS](https://github.com/edmozley/freeitsm/wiki/LMS) | Author courses in-app (with AI) or upload SCORM; assign, take and track them |
| [Process Mapper](https://github.com/edmozley/freeitsm/wiki/Process-Mapper) | Flowchart builder with swimlanes, custom step types and Mermaid export |
| [System](https://github.com/edmozley/freeitsm/wiki/System) | Administration — analysts, teams, roles, encryption, database verify, demo data |

A **System Wiki** module also auto-documents the codebase from within the app, and a [browser extension](https://github.com/edmozley/freeitsm/wiki/Browser-Extension) puts the Watchtower badge count in your Chrome/Edge toolbar.

## Highlights

- **[REST API](https://github.com/edmozley/freeitsm/wiki/REST-API)** — 200+ key-authenticated endpoints with granular per-key permissions, a live OpenAPI spec, and interactive in-app docs with code samples in seven languages.
- **[Single Sign-On](https://github.com/edmozley/freeitsm/wiki/Single-Sign-On) & [LDAP / Active Directory](https://github.com/edmozley/freeitsm/wiki/LDAP-and-Active-Directory)** — OIDC providers side by side (Keycloak, Entra, Okta, …), or bind straight to your on-prem directory with group-gated just-in-time provisioning. Local login always remains as break-glass.
- **[Security](https://github.com/edmozley/freeitsm/wiki/Security)** — AES-256-GCM encryption at rest for secrets, TOTP MFA, brute-force protection, role-based permissions down to individual settings tabs, and audit trails throughout.
- **[Multi-tenancy](https://github.com/edmozley/freeitsm/wiki/Multi-Tenancy)** — host multiple client companies in one install (built for MSPs), each walled off from the others. Invisible until you add a second company.
- **[Webhooks](https://github.com/edmozley/freeitsm/wiki/Webhooks)** — push any event to Slack, Teams, Discord or any endpoint, with HMAC signing, retries and a delivery dashboard.
- **Internationalisation** — [21 languages](https://github.com/edmozley/freeitsm/wiki/Internationalisation) with per-analyst locale, plus [per-analyst timezones](https://github.com/edmozley/freeitsm/wiki/Timezones-and-Time-Handling), [theming and dark mode](https://github.com/edmozley/freeitsm/wiki/Theming-and-Dark-Mode), and a [mobile-friendly](https://github.com/edmozley/freeitsm/wiki/Mobile-Friendly) core flow.

## Documentation

Everything lives in the **[Documentation Wiki](https://github.com/edmozley/freeitsm/wiki)**:

| Guide | Covers |
|-------|--------|
| [Installation](https://github.com/edmozley/freeitsm/wiki/Installation) | Docker and manual setup, prerequisites, configuration files |
| [Architecture](https://github.com/edmozley/freeitsm/wiki/Architecture) | Technology stack, directory layout, shared components, database conventions |
| [Security](https://github.com/edmozley/freeitsm/wiki/Security) | Authentication, authorisation layers, encryption, going-live checklist |
| [REST API](https://github.com/edmozley/freeitsm/wiki/REST-API) | How the public API works, plus per-module endpoint guides |
| [API Reference](https://github.com/edmozley/freeitsm/wiki/API-Reference) | The internal session-based endpoints behind the UI |

There are also long-form **[deep-dive articles](https://freeitsm.co.uk/deep-dive/)** on the website covering individual features, and a **[release history](https://freeitsm.co.uk/updates.php)**.

**Technology stack:** PHP 7.4–8.4 · MySQL 8.0+ · vanilla JavaScript (no frameworks) · TinyMCE · Apache or any PHP-capable server.

## 👋 From the maintainer

FreeITSM is a one-developer project — your engagement is what keeps it moving:

- ⭐ **If you use FreeITSM, please [star the repo](https://github.com/edmozley/freeitsm/stargazers)** — it's the single biggest signal that the work is landing.
- 📬 **Feedback, ideas, bugs?** Email me directly at [ed@freeitsm.co.uk](mailto:ed@freeitsm.co.uk) — I read every message — or use [Discussions](https://github.com/edmozley/freeitsm/discussions) and [Issues](https://github.com/edmozley/freeitsm/issues).
- 🌍 Mentioning [freeitsm.co.uk](https://freeitsm.co.uk) on Reddit, Hacker News, Spiceworks or LinkedIn genuinely helps and means a lot.

Contributions are welcome — the first external pull request was merged in 2026 and more are encouraged.

## License

[MIT](LICENSE) — free for commercial and personal use.
