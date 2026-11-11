<?php
/**
 * API: Ticket Checklists / SOP
 * Adheres to FreeITSM standard naming (created_datetime, completed_datetime, completed_by_id, completed_by_name).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/../../config.php";
require_once __DIR__ . "/../../includes/functions.php";
require_once __DIR__ . "/../../includes/tenant_settings.php";
require_once __DIR__ . "/../../includes/services/checklists.php";

$conn = connectToDatabase();

header("Content-Type: application/json");

if (!isset($_SESSION["analyst_id"])) {
    echo json_encode(["success" => false, "error" => "Not authenticated"]);
    exit;
}

require_once __DIR__ . "/../../includes/tenancy.php";
requireModuleAccessJson('tickets');
// GH #217: refused while the checklists module is switched off for the install.
requireModuleOnJson(connectToDatabase(), 'checklists');

$analystId = (int)$_SESSION["analyst_id"];
$analystName = $_SESSION["analyst_name"] ?? ($_SESSION["username"] ?? "Analyst");

/**
 * 🔒 Refuse unless this analyst can reach the ticket. Found while building the
 * task twin of this endpoint (discussion #138): NOTHING here checked company -
 * or even Tickets module access - so any signed-in analyst could read, attach
 * to, tick and remove checklists on another company's ticket by number. The
 * step and checklist actions are addressed by a CHILD id, so they resolve it to
 * its ticket first and gate on that; a child id with no gate of its own is the
 * classic way scoped data leaks. "Not found", never "not yours".
 */
function checklistTicketGate(PDO $conn, int $ticketId): void
{
    if ($ticketId <= 0 || !analystCanAccessTicket($conn, (int) $_SESSION["analyst_id"], $ticketId)) {
        throw new Exception("Ticket not found");
    }
}
/**
 * Returns the ticket a checklist item belongs to, or 0 when the item does not exist - so
 * the caller can run the ticket access gate.
 *
 * @param int $itemId  the ticket_checklist_items id
 */
function checklistTicketForItem(PDO $conn, int $itemId): int
{
    $st = $conn->prepare("SELECT c.ticket_id FROM ticket_checklist_items i JOIN ticket_checklists c ON c.id = i.ticket_checklist_id WHERE i.id = ?");
    $st->execute([$itemId]);
    return (int) ($st->fetchColumn() ?: 0);
}
/**
 * Returns the ticket a checklist belongs to, or 0 when it does not exist - so the caller
 * can run the ticket access gate.
 *
 * @param int $checklistId  the ticket_checklists id
 */
function checklistTicketForChecklist(PDO $conn, int $checklistId): int
{
    $st = $conn->prepare("SELECT ticket_id FROM ticket_checklists WHERE id = ?");
    $st->execute([$checklistId]);
    return (int) ($st->fetchColumn() ?: 0);
}
/**
 * How well a template matches a ticket's text: 5 for its title in the text, 2 per
 * keyword found. The ONE scoring rule - the "best match" suggestion and the
 * right-click menu's order both use it.
 *
 * @param string $ticketText  the ticket's subject and first notes, lower-cased
 * @param array $tpl  a checklist_templates row with title and keywords
 * @return array{score:int, title_match:bool, keywords:int}
 */
function checklistTemplateScore(string $ticketText, array $tpl): array
{
    $score = 0; $titleMatch = false; $kwCount = 0;
    $titleLower = strtolower((string)$tpl['title']);
    if ($titleLower !== '' && str_contains($ticketText, $titleLower)) { $score += 5; $titleMatch = true; }
    if (!empty($tpl['keywords'])) {
        foreach (array_map('trim', explode(',', strtolower((string)$tpl['keywords']))) as $kw) {
            if ($kw !== '' && str_contains($ticketText, $kw)) { $score += 2; $kwCount++; }
        }
    }
    return ['score' => $score, 'title_match' => $titleMatch, 'keywords' => $kwCount];
}

/** A ticket's subject and first five notes, lower-cased - the text templates are matched against. */
function checklistTicketText(PDO $conn, int $ticketId): string
{
    $t = $conn->prepare("SELECT subject FROM tickets WHERE id = ? LIMIT 1");
    $t->execute([$ticketId]);
    $n = $conn->prepare("SELECT note_text FROM ticket_notes WHERE ticket_id = ? ORDER BY id ASC LIMIT 5");
    $n->execute([$ticketId]);
    return strtolower(((string)$t->fetchColumn()) . ' ' . strip_tags(implode(' ', $n->fetchAll(PDO::FETCH_COLUMN))));
}

$action = $_GET["action"] ?? ($_POST["action"] ?? "");

if (!$action) {
    $raw = file_get_contents("php://input");
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $action = $json["action"] ?? "";
            $_POST = array_merge($_POST, $json);
        }
    }
}

// The three actions that change data are never run from a GET, which a link on
// another site can send (csrf.php). The reads stay on GET.
if (in_array($action, ["attach_template", "toggle_item", "set_result", "remove_checklist"], true)) {
    csrfRequireWriteMethod();
}

try {
    switch ($action) {
        case "get_ticket_checklists":
            $ticketId = (int)($_GET["ticket_id"] ?? 0);
            if ($ticketId <= 0) throw new Exception("ticket_id is required");
            checklistTicketGate($conn, $ticketId);

            // 🔴 These used to read COALESCE(created_datetime, created_at). `created_at`
            // is not created by the module bootstrap, by database/freeitsm.sql, or by
            // includes/db_verify_schema.php - so on any install that did not grow from
            // the author's earlier naming, MySQL raised "Unknown column 'created_at'"
            // and this endpoint returned an error for every ticket. See the wiki:
            // Checklists-Module-House-Style, "the fallback to a column that never existed".

            // The gate is resolved against the TICKET's company, not the
            // analyst's active one - on a consolidated board those differ, and
            // the lock a person sees has to be the lock the server will apply.
            $ticketTenantId = ticketTenantId($conn, $ticketId);   // includes/tenant_settings.php

            // GH #271: the shared engine - steps now carry their description and
            // whether they ask for a result, exactly as a task's do.
            echo json_encode([
                "success"            => true,
                "checklists"         => ChecklistsService::ticketChecklists($conn, $ticketId),
                "empty_closure_mode" => ticketChecklistEmptyClosureMode($conn, $ticketTenantId),
            ]);
            exit;

        case "suggest_template":
            $ticketId = (int)($_GET["ticket_id"] ?? 0);
            if ($ticketId <= 0) throw new Exception("ticket_id required");
            checklistTicketGate($conn, $ticketId);

            $ticketText = checklistTicketText($conn, $ticketId);

            $tpls = $conn->query("SELECT id, title, category, description, keywords FROM checklist_templates WHERE scope IN ('ticket', 'both') AND (is_active = 1 OR is_active IS NULL)")->fetchAll(PDO::FETCH_ASSOC);

            $scored = [];

            foreach ($tpls as $tpl) {
                ['score' => $score, 'title_match' => $hasTitleMatch, 'keywords' => $matchedKeywordsCount] = checklistTemplateScore($ticketText, $tpl);

                if ($score >= 2) {
                    // Calculate intuitive Confidence %
                    if ($hasTitleMatch && $matchedKeywordsCount >= 1) {
                        $confidence = min(98, 85 + ($matchedKeywordsCount * 4));
                    } elseif ($hasTitleMatch) {
                        $confidence = 90;
                    } elseif ($matchedKeywordsCount >= 3) {
                        $confidence = 88;
                    } elseif ($matchedKeywordsCount == 2) {
                        $confidence = 80;
                    } else {
                        $confidence = 72;
                    }

                    $tpl['match_score'] = $score;
                    $tpl['confidence_percent'] = $confidence;
                    $scored[] = $tpl;
                }
            }

            usort($scored, function($a, $b) {
                return $b['confidence_percent'] <=> $a['confidence_percent'];
            });

            $topSuggestions = array_slice($scored, 0, 3);

            echo json_encode([
                "success" => true,
                "suggested" => !empty($topSuggestions) ? $topSuggestions[0] : null,
                "suggestions" => $topSuggestions
            ]);
            exit;

        case "context_templates":
            // The inbox's right-click "Attach checklist" submenu: every template a
            // ticket may use, best match first for ONE ticket (the suggestion box's
            // scoring), and whether it is already on every ticket asked about - so the
            // menu can tick it and not attach it twice.
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET["ticket_ids"] ?? ''))))));
            if (!$ids) throw new Exception("ticket_ids is required");
            $ids = array_slice($ids, 0, 500);
            foreach ($ids as $id) checklistTicketGate($conn, $id);
            $tenantId = ticketTenantId($conn, $ids[0]);
            $tpls = $conn->query("SELECT id, title, category, keywords, closure_mode FROM checklist_templates
                                   WHERE scope IN ('ticket', 'both') AND (is_active = 1 OR is_active IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $on = $conn->prepare("SELECT template_id, COUNT(DISTINCT ticket_id) FROM ticket_checklists
                                   WHERE ticket_id IN ($in) AND template_id IS NOT NULL GROUP BY template_id");
            $on->execute($ids);
            $onCount = $on->fetchAll(PDO::FETCH_KEY_PAIR);
            $text = count($ids) === 1 ? checklistTicketText($conn, $ids[0]) : null;
            $out = [];
            foreach ($tpls as $tpl) {
                $out[] = [
                    'id'                     => (int)$tpl['id'],
                    'title'                  => (string)$tpl['title'],
                    'category'               => (string)($tpl['category'] ?? ''),
                    'effective_closure_mode' => ChecklistsService::effectiveClosureMode($conn, (string)($tpl['closure_mode'] ?: 'warn'), $tenantId),
                    'score'                  => $text !== null ? checklistTemplateScore($text, $tpl)['score'] : 0,
                    'attached_all'           => (int)($onCount[$tpl['id']] ?? 0) === count($ids),
                ];
            }
            usort($out, fn($a, $b) => [$b['score'], $a['title']] <=> [$a['score'], $b['title']]);
            echo json_encode(["success" => true, "templates" => $out]);
            exit;

        case "list_templates_for_ticket":
            $ticketId = (int)($_GET["ticket_id"] ?? $_POST["ticket_id"] ?? 0);
            // Same company as the gate itself will use, so the padlock in the
            // attach dialogue promises what the close will actually do.
            if ($ticketId > 0) checklistTicketGate($conn, $ticketId);
            $ticketTenantId = $ticketId > 0 ? ticketTenantId($conn, $ticketId) : null;

            $stmt = $conn->query("SELECT id, title, category, description, keywords, closure_mode FROM checklist_templates WHERE scope IN ('ticket', 'both') AND (is_active = 1 OR is_active IS NULL) ORDER BY category ASC, title ASC");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($templates as &$tpl) {
                $tpl["closure_mode"] = $tpl["closure_mode"] ?: "warn";
                $tpl["effective_closure_mode"] = ChecklistsService::effectiveClosureMode(
                    $conn, $tpl["closure_mode"], $ticketTenantId
                );
            }
            unset($tpl);   // the loop bound it by reference

            echo json_encode(["success" => true, "templates" => $templates]);
            exit;

        case "attach_template":
            $ticketId = (int)($_POST["ticket_id"] ?? 0);
            $templateId = (int)($_POST["template_id"] ?? 0);
            if ($ticketId <= 0 || $templateId <= 0) throw new Exception("ticket_id and template_id required");
            checklistTicketGate($conn, $ticketId);

            // skip_if_attached: the right-click menu on a selection - a ticket that
            // already has this checklist is left alone rather than given a second copy.
            $chkId = ChecklistsService::attachTemplateToTicket($conn, ActorContext::fromSession($conn), $ticketId, $templateId,
                !empty($_POST["skip_if_attached"]));
            echo json_encode(["success" => true, "checklist_id" => $chkId, "skipped" => $chkId === null]);
            exit;

        case "toggle_item":
            $itemId = (int)($_POST["item_id"] ?? 0);
            $completed = (!empty($_POST["completed"]) || !empty($_POST["is_completed"])) ? 1 : 0;
            $responseValue = isset($_POST["response_value"]) ? trim((string)$_POST["response_value"]) : null;

            if ($itemId <= 0) throw new Exception("item_id is required");
            checklistTicketGate($conn, checklistTicketForItem($conn, $itemId));

            // GH #271: the shared engine. It refuses a tick with an empty required
            // value or a result step with no result, stores UTC (GH #126), and
            // writes the step's note on the ticket - which the browser used to do
            // itself after the fact, so a tick from anywhere else left no trace.
            $row = ChecklistsService::toggleTicketItem($conn, ActorContext::fromSession($conn), $itemId, (bool)$completed, $responseValue);
            echo json_encode(["success" => true] + array_intersect_key($row, array_flip([
                "is_completed", "response_value", "completed_by_id", "completed_by_name", "completed_datetime", "result", "exception_note",
            ])));
            exit;

        case "set_result":
            // GH #271: Pass, Fail (with what went wrong) or Not applicable, as on tasks (#181).
            $itemId = (int)($_POST["item_id"] ?? 0);
            if ($itemId <= 0) throw new Exception("item_id is required");
            checklistTicketGate($conn, checklistTicketForItem($conn, $itemId));
            $row = ChecklistsService::setTicketItemResult($conn, ActorContext::fromSession($conn), $itemId,
                (string)($_POST["result"] ?? ""), isset($_POST["exception_note"]) ? (string)$_POST["exception_note"] : null);
            echo json_encode(["success" => true] + array_intersect_key($row, array_flip([
                "is_completed", "completed_by_name", "completed_datetime", "result", "exception_note",
            ])));
            exit;

        case "remove_checklist":
            $chkId = (int)($_POST["checklist_id"] ?? 0);
            if ($chkId <= 0) throw new Exception("checklist_id is required");
            checklistTicketGate($conn, checklistTicketForChecklist($conn, $chkId));

            // 🔴 The children are removed EXPLICITLY, in ChecklistsService: there is no
            // foreign key on ticket_checklist_items.ticket_checklist_id to cascade.
            // House rule: Database-Integrity, "delete children yourself".
            ChecklistsService::removeTicketChecklist($conn, $chkId);
            echo json_encode(["success" => true]);
            exit;

        default:
            echo json_encode(["success" => false, "error" => "Unknown action: " . $action]);
            exit;
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
    exit;
}
