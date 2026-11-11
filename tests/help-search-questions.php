<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * The help assistant's question set (wiki Help-Assistant-Design): realistic
 * questions, each with every section that would be a right answer. Shared by
 * tests/help-search.php and later steps' benchmarks, so every step is measured
 * against the same questions.
 *
 * kind: plain | vague (no words shared with the answer) | exact (a name) | lang
 */
$T = 'tickets/help.php#'; $S = 'system/help/';
// [kind, locale, question, the module asked from (or null), right answers...]
return [
    ['plain', 'en', 'how do I create a ticketing department', null, $T . 'settings', $S . 'teams.php#departments'],
    ['plain', 'en', "reset an analyst's password", null, $S . 'analysts.php#passwords'],
    ['plain', 'en', 'add a new analyst', null, $S . 'analysts.php#adding'],
    ['plain', 'en', 'import assets from a spreadsheet', null, 'asset-management/help-custom-fields.php#import'],
    ['plain', 'en', 'print QR code labels for our laptops', null, 'asset-management/help.php#asset-labels'],
    ['plain', 'en', 'what is a CAB and when do I need one', null, 'change-management/help.php#cab'],
    ['plain', 'en', 'how do I do a root cause analysis', null, 'problem-management/help.php#rca'],
    ['plain', 'en', 'difference between a problem and an incident', null, 'problem-management/help.php#vs-incidents'],
    ['plain', 'en', 'reopen a closed ticket when the requester replies', null, $T . 'settings'],
    ['plain', 'en', 'email someone when an SLA is breached', null, 'tickets/help-sla.php#breach-notifications'],
    ['plain', 'en', 'connect a Microsoft 365 mailbox', null, 'tickets/help-mailbox-auth.php#azure-setup', 'tickets/help-mailbox-auth.php#add-mailbox', 'tickets/help-mailbox-auth.php#overview'],
    ['plain', 'en', 'collect email from a Google Workspace mailbox', null, 'tickets/help-mailbox-auth.php#google'],
    ['plain', 'en', 'add a signature to email templates', null, 'tickets/help-email-templates.php#signatures'],
    ['plain', 'en', 'which merge codes can I use in a template', null, 'tickets/help-email-templates.php#merge-codes'],
    ['plain', 'en', 'send a customer satisfaction survey', null, $T . 'csat'],
    ['plain', 'en', 'make a ticket confidential', null, $T . 'confidential'],
    ['plain', 'en', 'connect WhatsApp to tickets', null, $T . 'whatsapp'],
    ['plain', 'en', 'show my tickets in my Outlook calendar', null, 'tickets/help-calendar-sync.php#connection', 'tickets/help-calendar-sync.php#choices', 'tickets/help-calendar-sync.php#overview', 'tickets/help-calendar-sync.php#switch-on'],
    ['plain', 'en', 'get a knowledge article approved before it is published', null, 'knowledge/help.php#review-workflow'],
    ['plain', 'en', 'who can see a knowledge article', null, 'knowledge/help.php#permissions'],
    ['plain', 'en', 'ask AI a question about the knowledge base', null, 'knowledge/help.php#ask-ai'],
    ['plain', 'en', 'run a workflow when a ticket is created', null, 'workflow/help.php#triggers', 'workflow/help.php#anatomy', 'workflow/help.php#templates'],
    ['plain', 'en', 'post to Slack when a ticket comes in', null, 'workflow/help-webhooks.php#quickstart', $S . 'integrations.php#slack', 'system/integrations/help.php?provider=slack#overview'],
    ['plain', 'en', 'sign webhook payloads with HMAC', null, 'workflow/help-webhooks.php#signing', $S . 'webhooks.php#security'],
    ['plain', 'en', 'repeat a task every week', null, 'tasks/help.php#repeats'],
    ['plain', 'en', 'upload a SCORM course', null, 'lms/help.php#scorm', 'lms/help.php#uploading'],
    ['plain', 'en', 'remind people to finish their training', null, 'lms/help.php#reminders'],
    ['plain', 'en', 'track software licences', null, 'software/help.php#licences'],
    ['plain', 'en', 'how is project health worked out', null, 'projects/help.php#health'],
    ['plain', 'en', 'compare a project budget with actual spend', null, 'projects/help.php#budget'],
    ['plain', 'en', 'warn me before a domain certificate expires', null, 'domains/help.php#certs', 'domains/help.php#alerts'],
    ['plain', 'en', 'find look-alike phishing domains', null, 'domains/help.php#lookalikes'],
    ['plain', 'en', 'who can open a file in Files', null, 'files/help.php#permissions'],
    ['plain', 'en', 'draw a network diagram', null, 'network-mapper/help.php#creating', 'network-mapper/help.php#overview'],
    ['plain', 'en', 'create an API key', null, $S . 'api.php#keys'],
    ['plain', 'en', 'connect an AI assistant over MCP', null, $S . 'api.php#mcp'],
    ['plain', 'en', 'turn on HTTPS in Docker', null, $S . 'docker.php#steps'],
    ['plain', 'en', 'back up the encryption key', null, $S . 'encryption.php#backup'],
    ['plain', 'en', 'lock an account after failed sign-ins', null, $S . 'security.php#lockout'],
    ['plain', 'en', 'restrict which modules an analyst can use', null, $S . 'modules.php#matrix', $S . 'analysts.php#access', $S . 'teams.php#modules'],
    ['plain', 'en', 'change my interface language', null, $S . 'preferences.php#language'],
    ['plain', 'en', 'run database verification after upgrading', null, $S . 'db-verify.php#running', $S . 'db-verify.php#overview'],
    ['plain', 'en', 'remove the demo data', null, $S . 'demo-data.php#remove'],
    ['plain', 'en', "route email from a customer's domain to their company", null, $S . 'companies.php#email'],
    ['plain', 'en', 'raise a Jira issue from a ticket', null, 'system/integrations/help.php?provider=jira#raise', 'system/integrations/help.php?provider=jira#overview'],
    ['plain', 'en', 'turn Zabbix alerts into incidents', null, 'system/integrations/help.php?provider=zabbix#overview', 'system/integrations/help.php?provider=zabbix#mediatype', 'system/integrations/help.php?provider=zabbix#action'],
    ['plain', 'en', 'score supplier responses to a tender', null, 'contracts/rfp-builder/help.php#p5', 'contracts/rfp-builder/help.php#multi-analyst'],
    ['vague', 'en', 'someone left the company - what happens to their tickets', null, $S . 'analysts.php#leavers', 'starters/help.php#leaving'],
    ['vague', 'en', "my colleague can't see the Assets menu", null, $S . 'modules.php#effect', $S . 'modules.php#matrix', $S . 'analysts.php#access', $S . 'teams.php#modules'],
    ['vague', 'en', 'my boss wants a weekly PDF of how we are doing', null, 'reporting/help.php#report-packs'],
    ['vague', 'en', 'the clock on a ticket keeps running over the weekend', null, 'tickets/help-sla.php#building-blocks', 'tickets/help-sla.php#behaviour-settings'],
    ['vague', 'en', 'tickets nobody is looking after', null, $S . 'orphaned-tickets.php#overview', $S . 'orphaned-tickets.php#cause'],
    ['vague', 'en', 'stop the little pop-ups in the corner', null, $S . 'preferences.php#toasts'],
    ['exact', 'en', 'Feature Bingo', null, $S . 'feature-bingo.php#overview'],
    ['exact', 'en', 'Warbot', 'war-room', 'war-room/help.php#warbot'],
    ['exact', 'en', 'MoSCoW', null, 'projects/help.php#scope'],
    ['exact', 'en', 'RACI', null, 'projects/help.php#raci'],
    ['exact', 'en', 'Enforce approvals', null, 'change-management/help.php#evidence'],
    ['exact', 'en', 'CardDAV', null, $S . 'sso.php#carddav', $S . 'sso.php#carddav-setup', 'tickets/help-calendar-sync.php#caldav'],
    ['lang', 'de', 'Wie erstelle ich eine Abteilung?', null, $T . 'settings', $S . 'teams.php#departments'],
    ['lang', 'de', 'Wie sende ich eine Zufriedenheitsumfrage?', null, $T . 'csat'],
    ['lang', 'es', '¿Cómo envío una encuesta de satisfacción?', null, $T . 'csat'],
    ['lang', 'es', 'Restablecer la contraseña de un analista', null, $S . 'analysts.php#passwords'],
    ['lang', 'en', 'Hogyan hozzak létre jegykezelő részleget?', null, $T . 'settings', $S . 'teams.php#departments'],
    ['lang', 'en', 'Jak zresetować hasło analityka?', null, $S . 'analysts.php#passwords'],
];
