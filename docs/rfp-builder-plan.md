# RFP Builder — Design Plan

> **Status:** Locked 2026-05-02. Implementation to follow in 6 phases.
> **Lives at:** `/contracts/rfp-builder/` as a feature of the Contracts module.

## Background

The RFP Builder lets you upload requirements documents from multiple internal departments, has AI extract and consolidate the requirements (deduplicating overlaps across departments and flagging contradictions between them), generates a coherent RFP document organised by category, then provides a multi-stakeholder scoring grid for evaluating supplier responses.

The motivating use case: five departments submit requirements docs in completely different formats and turning that into something coherent is a nightmare. The consolidation pass is the differentiating feature — it turns five overlapping, contradicting, differently-phrased lists into one clean prioritised list, with verbatim source quotes preserved for political cover.

This plan was informed by reading an existing PHP prototype the developer previously built (formerly at `c:\Users\edmoz\Downloads\old\itsm-rfp`, ITSM-specific). The new build adapts the proven pieces of that prototype (DOCX parser, two-pass AI prompts, version history, batch-AI progress-modal UX, hash-of-input cache) and adds the consolidation pass that the prototype lacked.

## 1. Page structure

All under `/contracts/rfp-builder/`. URLs use the RFP id where relevant.

| URL | Purpose |
|-----|---------|
| `/contracts/rfp-builder/` | List all RFPs (name, status, supplier count, last activity) + "New RFP" button |
| `/contracts/rfp-builder/{id}/` | Single RFP overview — progress dashboard with phase tiles (docs → extracted → consolidated → output → scoring → decision), token usage, links to next action |
| `/contracts/rfp-builder/{id}/documents/` | Upload `.docx` files, each tagged with internal department, view extracted text |
| `/contracts/rfp-builder/{id}/extracted/` | Pass 1 output — raw extracted requirements per document, edit/verify/delete |
| `/contracts/rfp-builder/{id}/consolidate/` | **The new bit.** AI-merged requirements with source-quote expand, conflict panel, edit/split/merge, "Lock to proceed" button |
| `/contracts/rfp-builder/{id}/document/` | Pass 2 output — generated RFP sections per category, version history, manual edit, restyle, full preview, PDF export |
| `/contracts/rfp-builder/{id}/coverage/` | Heatmap matrix of category × department |
| `/contracts/rfp-builder/{id}/suppliers/` | Invite suppliers (picker over existing + create-prospective inline), set demo dates, notes |
| `/contracts/rfp-builder/{id}/scoring/` | Score requirements for one (supplier, you-as-analyst) pair — long form with live charts |
| `/contracts/rfp-builder/{id}/compare/` | Cross-supplier radar + category winners table |
| `/contracts/rfp-builder/{id}/settings/` | Per-RFP name, status, style-guide override |

System settings (existing Contracts settings page gets a new RFP tab):

- Default style guide (used as base for new RFPs)
- Default Anthropic model
- Default departments (master lookup, used as picker for document upload)

## 2. Schema

13 new tables, all prefixed `rfp_`, all MySQL (InnoDB, utf8mb4).

```
rfps                                 [parent — every other table FKs back]
  id, name, status, contract_id NULL→contracts, chosen_supplier_id NULL→suppliers,
  style_guide TEXT NULL, created_by_analyst_id→analysts, created_at, updated_at
  status: draft | collecting | consolidating | generating | scoring | closed | abandoned

rfp_departments                      [global lookup, NOT per-RFP]
  id, name (UQ), colour, sort_order, is_active

rfp_categories                       [per-RFP, AI-suggested]
  id, rfp_id→rfps, name, description, sort_order, is_active

rfp_documents
  id, rfp_id→rfps, department_id→rfp_departments,
  filename, original_filename, file_path,
  raw_text LONGTEXT, status, uploaded_at, updated_at
  status: uploaded | extracted | processed | error

rfp_extracted_requirements           [Pass 1 output — raw per-doc]
  id, rfp_id→rfps, document_id→rfp_documents,
  requirement_text, requirement_type, source_quote, ai_confidence,
  is_consolidated BIT, created_at, updated_at
  type: requirement | pain_point | challenge

rfp_consolidated_requirements        [Pass 2 output — deduplicated]
  id, rfp_id→rfps, category_id→rfp_categories NULL,
  requirement_text, requirement_type, priority,
  ai_rationale TEXT, is_locked BIT, created_at, updated_at
  priority: critical | high | medium | low

rfp_consolidated_sources             [M:N — which extracted rows feed each consolidated row]
  id, consolidated_id→rfp_consolidated_requirements,
  extracted_id→rfp_extracted_requirements
  UQ(consolidated_id, extracted_id)

rfp_conflicts                        [pairs flagged contradictory]
  id, rfp_id→rfps,
  consolidated_id_a→rfp_consolidated_requirements,
  consolidated_id_b→rfp_consolidated_requirements,
  ai_explanation, resolution, resolution_notes,
  resolved_by_analyst_id→analysts NULL, resolved_at NULL, created_at
  resolution: open | chose_a | chose_b | merged | split | dismissed

rfp_output_sections                  [Pass 3 output — generated RFP sections]
  id, rfp_id→rfps, category_id→rfp_categories,
  section_title, section_content LONGTEXT, version,
  is_manually_edited BIT, requirements_hash VARCHAR(64),
  generated_at, edited_at

rfp_section_history                  [version trail]
  id, section_id→rfp_output_sections, version,
  section_content LONGTEXT, is_manually_edited BIT, created_at

rfp_invited_suppliers                [RFP ↔ supplier link]
  id, rfp_id→rfps, supplier_id→suppliers,
  invited_at, demo_date, notes, UQ(rfp_id, supplier_id)

rfp_scores                           [per analyst, per supplier, per requirement]
  id, rfp_id→rfps, supplier_id→suppliers, analyst_id→analysts,
  consolidated_id→rfp_consolidated_requirements,
  score INT 0..5 NULL, notes, updated_at,
  UQ(rfp_id, supplier_id, analyst_id, consolidated_id)

rfp_processing_log                   [AI cost/audit trail]
  id, rfp_id→rfps, document_id NULL, section_id NULL,
  action, status, details, tokens_in, tokens_out, created_at
  action: extract | consolidate | detect_conflicts | generate_section | restyle_section
```

Goes into `database/freeitsm.sql` and `api/system/db_verify.php` per the mandatory checklist. Two new keys in `system_settings`: `rfp_default_style_guide` (TEXT) and `rfp_anthropic_model` (varchar).

**Things explicitly NOT in this schema** (and why):

- No `rfp_vendors` table — uses the existing `suppliers` table.
- No `rfp_scorers` table — uses the existing `analysts` table.
- No `rfp_settings` table — uses the existing `system_settings`.
- No `coverage` table — derived view (the original prototype had a dead one).

## 3. AI prompts

Four prompts. All call Claude Sonnet via a shared helper (`includes/rfp_ai.php`) that handles auth, prompt caching, retries, and logging to `rfp_processing_log`.

### Pass 1 — Extract (per document)

- **Input:** one document's `raw_text` + its department name.
- **Output:** JSON array of `{requirement_text, requirement_type, source_quote, confidence}`. **No category, no priority** — those are decided in Pass 2 once the AI sees the whole picture.
- **Prompt structure:** lift the prototype's prompt verbatim, drop the category list and priority field. About 80% the same.
- **Cache breakpoint:** the system prompt (everything except the doc text). Cached across the 5+ docs in one RFP.

### Pass 2 — Consolidate + Categorise + Detect Conflicts (single call)

- **Input:** every extracted requirement from every document in the RFP, with `id`, `text`, `type`, `source_quote`, source `department`.
- **Output:** one JSON blob containing:
  - `categories[]` — AI-suggested categories with `{name, description, sort_order}`
  - `consolidated_requirements[]` — `{requirement_text, type, category_index, priority (critical/high/medium/low), source_extracted_ids[], ai_rationale}`
  - `conflicts[]` — `{consolidated_a_index, consolidated_b_index, explanation}`
- **Why one call not three:** the AI doing the merging is the same AI deciding categories and spotting conflicts. Splitting wastes tokens and risks inconsistency (e.g. a conflict pair where the two reqs landed in different categories). One call = coherent output.
- **Token math:** typical case is 50-150 extracted reqs × ~50 words = 5-15K input tokens, output ~10K tokens. Well within Sonnet's window.
- **Cache breakpoint:** the entire system prompt (the meaty consolidation instructions). Cached if user re-runs.
- **Critical UX point:** this output is **proposed**, not committed. UI lets user edit text, change priority, split rows AI merged too aggressively, merge rows AI didn't catch as duplicates, dismiss conflicts. Generation (Pass 3) is gated behind `is_locked = 1` on the consolidated rows.

### Pass 3 — Generate Section (per category)

- **Input:** one category + all its consolidated requirements (with source attribution) + style guide.
- **Output:** HTML for that section (`<h3>` subheadings, `<p>`/`<ul>`/`<li>`, no full-page wrapper).
- **Prompt structure:** lift the prototype's prompt verbatim with two edits:
  - Source departments come from `consolidated_sources` traversal, not directly from the requirement
  - Style guide is appended (system default + per-RFP override merged)
- **Cache breakpoint:** system prompt + style guide. Cached across all 16-ish categories in one "Generate all" run — biggest cache win in the whole app.
- **Skip optimisation:** lifted from prototype — md5 of input requirements stored in `requirements_hash`, regeneration skipped if hash unchanged.

### Pass 4 — Restyle (per section)

- **Input:** existing section HTML + style guide.
- **Output:** style-corrected HTML, same content.
- **Prompt structure:** lifted verbatim from prototype.

## 4. Phased build order

Each phase is self-contained and shippable. The user can use what's built so far while later phases are in flight.

### Phase 1 — Foundation (≈3-5 days)
*Goal: empty shell with DB and routing in place.*

- Schema in `freeitsm.sql` + `db_verify.php`
- New "RFP Builder" entry-point inside the Contracts module
- RFP list page, create/edit/delete RFP, status transitions
- Departments lookup CRUD (under Contracts settings)
- DOCX upload + parser (lifted verbatim from prototype) + raw_text storage
- View raw extracted text for a document
- **Demo:** create an RFP, upload some docs, see them parsed.

### Phase 2 — Pass 1 Extraction (≈3-5 days)
*Goal: per-document AI extraction working.*

- Anthropic helper (`includes/rfp_ai.php`) — encrypted key from `system_settings`, prompt caching, retry on 429/5xx, log to `rfp_processing_log`
- Pass 1 prompt + "Extract requirements" action per document
- Extracted requirements list page with filter (dept, type) + inline edit
- Processing-log surface (token usage per RFP visible somewhere)
- **Demo:** upload 5 docs, hit extract on each, see ~50-150 raw requirements with source quotes.

### Phase 3 — Consolidation + Conflicts (≈1 week — the riskiest phase)
*Goal: the differentiating feature works end-to-end.*

- Pass 2 prompt (the big one) — single-call consolidate + categorise + detect conflicts
- Consolidated requirements page:
  - Inline source-quote expand showing department + verbatim quote
  - Edit consolidated text, change priority, change category
  - Manually split a consolidated row back into N rows
  - Manually merge two consolidated rows
  - "Add custom requirement" for things AI missed entirely
- Conflicts panel:
  - List of flagged pairs with AI's explanation
  - Resolve via: choose A, choose B, merge into one, split, dismiss
  - Resolution rationale captured in audit trail
- "Lock for generation" button — gates Pass 3
- **Recommend** prototyping the Pass 2 prompt at the *end* of Phase 2 with a sample dataset, before committing to Phase 3 UI work. De-risks the riskiest piece.
- **Demo:** the full flow — 5 messy docs in, one clean prioritised list out.

### Phase 4 — Pass 2 Section Generation (≈3-5 days)
*Goal: a coherent RFP document gets generated.*

- Pass 3 prompt + per-category Generate action
- Pass 4 prompt + per-section Restyle action
- Output sections page:
  - Progress modal pattern lifted from prototype (sequential AI calls with row state animations)
  - Per-section manual edit (TinyMCE — already in use elsewhere in FreeITSM)
  - Version history sidebar with restore-to-version
- Full-document preview with TOC sidebar
- PDF export (browser print, lifted from prototype)
- **Demo:** generate the full RFP HTML, edit a section, restyle, export.

### Phase 5 — Suppliers + Scoring (≈1 week)
*Goal: vendor evaluation works.*

- Invite suppliers page — picker over existing `suppliers` + "create new prospective" inline
- Scoring page — pick (supplier, you), see all consolidated requirements, score 0-5
- Live recompute on save — averages by category, overall avg
- Multi-analyst average rollup (the score shown is the mean of all analyst scores)
- Live charts (Chart.js — already used elsewhere)
- Coverage heatmap matrix (category × department)
- **Demo:** score 3 suppliers across a couple of analysts, see scores roll up.

### Phase 6 — Compare + Polish (≈3-5 days)
*Goal: decision support and ship-ready.*

- Compare page — multi-supplier overlapped radar, big-number cards, category winners table
- In-app help page (lift the prototype's help.php as a starting point, rewrite for FreeITSM context)
- Settings tab under Contracts settings: default style guide, default model, departments lookup
- Audit trail page surfacing `rfp_processing_log` (cumulative tokens spent, when, by whom)
- **Demo:** complete end-to-end — upload, extract, consolidate, generate, score, compare, decide.

**Total: roughly 5-6 weeks of focused work**, with shippable milestones at the end of each phase.

## Things to flag before each phase

1. **AI cost.** A full RFP run (5 docs, 100 consolidated reqs, 16 categories) costs roughly £2-5 in Sonnet tokens at current pricing. With prompt caching, more like £0.50-2. Worth showing token counts on the RFP overview page so the user sees what each run costs.
2. **The consolidation prompt is the experimental piece.** It doesn't exist in the prototype, so it'll need iteration with real data. Recommended: pull together a representative test dataset (3-5 anonymised requirement docs from any past RFP-ish exercise) early so we can prototype Phase 3's prompt against real input.
3. **No data migration from the prototype.** The prototype is at a different URL with stale data; nothing carries across.
4. **Phase 1 sub-decision still open:** where exactly does the RFP Builder entry sit in the Contracts UI? Top-level button on the Contracts dashboard alongside "New contract" is the working assumption.

## Decisions log

This plan was locked after a design conversation covering:

- **Vendors vs Suppliers** — reuse `suppliers` table, no new vendor table
- **Categories** — AI-suggested per RFP, not seeded globally
- **Consolidation** — built into v1 (vs bolted on later)
- **Source quotes** — accessible inline from each consolidated requirement (expandable per row)
- **Multi-stakeholder scoring** — multiple analysts each score, average rolled up per cell
- **Style guide** — system default with per-RFP override (option C)
- **Priority tiers** — Critical / High / Medium / Low (vs the prototype's high/med/low)
- **Audit trail** — captured from day one in `rfp_processing_log`
- **AI prompt caching** — added throughout (prototype had none)
- **Authentication** — the prototype was no-auth single-tenant; the FreeITSM build uses the existing analyst session and FK every table to `rfp_id`
