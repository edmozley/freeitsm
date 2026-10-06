<?php
/**
 * Where does a record live? Answered ONCE.
 *
 * ⚠️ WHY THIS EXISTS. Three separate places already knew how to build a deep
 * link to a record, and they did not agree:
 *
 *   - NotificationsService::linkFor()  — knew `ticket` and `task`, and returned
 *     NULL for everything else, so a notification about a change or a problem
 *     rendered as href="#" and went nowhere.
 *   - api/system/global_search.php     — knew seven types, with its own URLs.
 *   - anchors written inline across the module JS — whatever that file needed
 *     on the day.
 *
 * The disagreement was not cosmetic. Change Management alone was linked to as
 * `?change_id=`, `?id=`, `?change=` and `?open=`; problems as `?problem_id=` in
 * one map and `?id=` in another. Most of that survived only because the modules
 * are permissive and accept several names.
 *
 * ⚠️ TWO MODULES ARE NOT PERMISSIVE, and both dead links found while sweeping
 * for GH #91 pointed at exactly those two:
 *
 *   - the ticket inbox reads ONLY `ticket_id` (assets/js/inbox.js)
 *   - the tasks board reads ONLY `task`   (assets/js/tasks.js)
 *
 * Get one of those wrong and the browser opens the right module and does
 * nothing at all — a link that looks like it worked, which is why neither was
 * ever reported. Tolerance elsewhere is what hid the drift; these two had none.
 *
 * Every path returned here is relative to BASE_URL — the caller prefixes it.
 * That is the contract both previous maps already used.
 */

/**
 * The canonical deep link for a record, or NULL when the type has no page.
 *
 * NULL is a real answer, not a failure: a lookup row (a ticket status, an
 * impact level) has no page of its own to open. Callers should render such a
 * reference as plain text rather than a dead anchor.
 *
 * @param string $type One of the keys below. Unknown types return NULL.
 * @param int    $id   The record's own id — never a display reference such as
 *                     a ticket NUMBER. The forms approvals list passed a number
 *                     and produced a link that silently did nothing.
 * @return string|null BASE_URL-relative path, e.g. 'tickets/?ticket_id=42'
 */
function entityLink(string $type, int $id): ?string {
    if ($id <= 0) {
        return null;
    }

    switch ($type) {
        // ⚠️ ONLY `ticket_id`. See the file header.
        case 'ticket':
            return 'tickets/?ticket_id=' . $id;

        // ⚠️ ONLY `task`.
        case 'task':
            return 'tasks/?task=' . $id;

        // `problem_id` is canonical; `id` is kept as a legacy fallback by the
        // module itself, so old links elsewhere keep working.
        case 'problem':
            return 'problem-management/?problem_id=' . $id;

        // Accepts change / change_id / id / open. `change_id` is the one the
        // search results have always used, so it is canonical here too.
        case 'change':
            return 'change-management/?change_id=' . $id;

        case 'asset':
            return 'asset-management/?asset_id=' . $id;

        case 'cmdb_object':
            return 'cmdb/object.php?id=' . $id;

        // Accepts `article` or `id`; `article` is canonical.
        case 'knowledge_article':
            return 'knowledge/?article=' . $id;

        case 'contract':
            return 'contracts/view.php?id=' . $id;

        // ⚠️ ONLY `id` — domains/view.php reads exactly that.
        case 'domain':
            return 'domains/view.php?id=' . $id;

        // Projects (3.2.0) - projects/view.php reads `id`.
        case 'project':
            return 'projects/view.php?id=' . $id;

        // People (#153): a person is a row in `users`, a company a row in `tenants`.
        case 'person':
            return 'people/person.php?id=' . $id;

        case 'company':
            return 'people/company.php?id=' . $id;

        // Suppliers and their contacts (#153 step 3): shown in People, kept in Contracts.
        case 'supplier':
            return 'people/supplier.php?id=' . $id;

        case 'supplier_contact':
            return 'people/contact.php?id=' . $id;
    }

    return null;
}

/**
 * Every type this resolver can reach. Handy for tests, and for asserting that a
 * new record type has been added here rather than linked to inline.
 *
 * @return string[]
 */
function entityLinkTypes(): array {
    return [
        'ticket', 'task', 'problem', 'change',
        'asset', 'cmdb_object', 'knowledge_article', 'contract', 'domain', 'project',
        'person', 'company', 'supplier', 'supplier_contact',
    ];
}
