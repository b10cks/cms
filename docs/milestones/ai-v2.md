# Milestone: AI V2

Design: `docs/concepts/native-ai-integration.md`. Integration branch: `feat/ai-v2`.

One milestone in four waves. Each wave ships on its own and leaves `feat/ai-v2` green.

| Wave | Depends on | Can run alongside |
| --- | --- | --- |
| W1 Foundation and knowledge | none | none |
| W2 Changesets, inbox, Cmd+K, background runs | W1 | W4 |
| W3 Translation memory, learning loop, schedules | W2 | none |
| W4 MCP exposure, content as knowledge | W1 | W2 |
| W5 Cost, caching, operations, spec | W3 | none |

Status (2026-10-28): W1 to W6 merged into `main`. UI walked through in the browser; one real OpenRouter run verified the loop, proposal and cost reconciliation. SDK changes in `../sdk` are uncommitted on the SDK branch.

## Rules for every wave

- Read `CLAUDE.md` and the design doc first. Follow existing patterns: actions, FormRequests, policies, `BaseResource` resources, TanStack Query composables, i18n in `en` and `de`.
- Use Bun, never npm. Never run prettier; lint with `bun run lint` (oxlint).
- Targeted checks: PHPUnit for touched areas, Vitest for touched components, `bun run typecheck`, `bun run lint`. Tests use a fake driver; no real model calls.
- Tests: one focused test per stated behaviour, sized like neighbouring tests.
- New UI follows `DESIGN.md`.
- Keep `docs/` public pages untouched unless a wave says so; this briefing stays internal.

## W1 Foundation and knowledge

Scope:

1. Port the stash@{2} UI: `components/ui/message`, `components/ui/message-scroller`, `components/ui/questionnaire`, the `InputGroupTipTap`, `InputGroupAddon`, `InputGroup`, `AiText` and `AiConfigSelector` changes, i18n keys, and the four Vitest files. Skip the `posthog-js` bump.
2. Drivers do one turn (`turn()` returning `TurnResult`) for OpenRouter, OpenAI and Bedrock.
3. `AiRunService` with the bounded loop, checkpoints, guards, budgets, cancellation, idempotency, `ask_questions` pause and resume, inline SSE and `RunAgentJob` background execution, `ai_run_events` with catch-up.
4. Tables: `ai_runs`, `ai_run_events`, `ai_conversations`, `ai_messages`, `ai_attachments`.
5. Context builder with frozen revisions and stable prompt order.
6. Skills: disk built-ins, `ai_skills` and `ai_skill_revisions`, `SKILL.md` parsing and validation, resolution with shadowing, `read_skill` and `read_skill_file`, `GET /ai/context`.
7. Knowledge: documents, revisions, chunks, glossaries, terms; draft and publish; `search_knowledge`, `read_knowledge`, `lookup_glossary`; glossary matcher and checker; PDF and DOCX extraction job; glossary CSV/XLSX/TBX import and export.
8. `ai.manage` ability with policies at space and team level.
9. Built-in skills for the six existing features and the selection skills; migrate their callers to `useAiRun`, then remove the legacy stream endpoints and composables.
10. Frontend: `useAiRun`, `AiActions`, rich text selection bubble menu with inline proposals, Skills, Knowledge and Glossary settings pages for spaces and teams, run transcript UI on the ported components, questionnaire flow.

Acceptance:

- A tool-calling run stops at the step budget and reports `partial`.
- A run resumes after a simulated crash from its last checkpoint without repeating a provider call.
- A paused run resumes only with valid answers from its own actor; a second concurrent resume is rejected.
- Space skill shadows the team skill, team shadows ancestor team, ancestor shadows built-in. A disabled space skill falls through to the next layer.
- Invalid `SKILL.md` (bad path, duplicate file, missing name, oversize) is rejected with a field error.
- A run keeps the knowledge revisions it started with after a new revision is published.
- Glossary checker flags a missing required term, a translated do-not-translate term and a forbidden variant.
- `search_knowledge` only returns chunks from published, enabled documents in the actor's resolved scopes.
- Each migrated feature returns the same structured result as before (content, tree, meta tags, translation, data entries, classification).
- A space skill with `surfaces: [selection]` appears in the rich text selection menu.
- Inline run exceeding the wall-time guard continues in the background and the client keeps receiving events in order.

## W2 Changesets, inbox, Cmd+K, background runs

Scope:

1. `ai_changesets` and `ai_changeset_items`; `propose_update`, `propose_create`, `find_entries` with schema validation.
2. Accept and reject per field, entry and changeset; three-way conflict detection; apply through `UpdateContent` and the data entry and asset equivalents, creating drafts.
3. Inline proposals use single-entry changesets; selection actions from W1 move onto this path.
4. Inbox page and sidebar badge, live over the space channel; review UI on the history diff renderers.
5. Cmd+K palette: skill matching, general agent, current-screen scope.
6. Preflight estimate, confirmation, background dispatch above `ai.inline_entry_limit`; per-run USD cap and per-space concurrency limit.
7. Bulk entry points on content lists, data sources and assets start background runs.

Acceptance:

- An invalid patch is returned to the model and never stored.
- Accepting a field whose base is unchanged creates a draft version with that change; nothing is published.
- A field edited after the run read it becomes a conflict; other fields of the same entry still apply.
- A user without edit rights on the target cannot accept its items.
- A background run hitting its USD cap stops with a partial changeset in the inbox.
- Preflight count and estimate match the entries the run then touches.

## W3 Translation memory, learning loop, schedules

Scope:

1. Space migration for `translation_units`; publish listener in `BasePublishAction`; backfill command.
2. Exact match short-circuit and fuzzy examples in the context builder.
3. Learning loop producing inbox suggestions; accept into space or team glossary.
4. `ai_schedules`, scheduler dispatch, creator permission recheck, pause on lost access, schedule settings UI.

Acceptance:

- Publishing a translated entry stores its translation units; republishing unchanged text does not duplicate them.
- An exact TM match fills the field without a provider call.
- Editing accepted AI output yields a suggestion; a term already in the glossary yields none.
- A schedule whose creator lost space access pauses and does not call the provider.

Deploy note: run `spaces:repair-databases` by hand after tagging.

## W4 MCP exposure, content as knowledge

Scope:

1. `ManagementServer` tools `list_skills`, `read_skill`, `search_knowledge`, `lookup_glossary` resolved for the token's user.
2. `search_content` tool over `SearchService`, limited to what the actor can read; available to skills and the general agent.
3. Update `docs/guides/mcp-server.md` for the new MCP tools (public docs, this one is allowed).

Acceptance:

- An MCP token sees the same resolved skills and knowledge as its user in the app.
- `search_content` never returns entries the actor cannot read.

## W5 Cost, caching, operations, spec

Scope:

1. Queued job that reconciles `ai_runs.cost` from OpenRouter's generation lookup after a run finishes. Live caps keep the token estimate.
2. `cache_control` breakpoints after the style guides and after the skill catalog for Anthropic and Gemini models via OpenRouter. Check OpenRouter's docs first; confirm cached tokens show up in reported usage.
3. `app/Mcp/Support/operations.php` and `../sdk/packages/mcp-server/src/operations.ts`: remove the dropped stream operations, add the AI management endpoints (skills, knowledge, glossaries, runs, changesets). The SDK edit stays uncommitted on the SDK's current branch.
4. `search_content` as an MCP tool.
5. Regenerate `docs/public/specs/*.json` once, inspect, commit on its own.

Acceptance:

- A finished run's cost is replaced by the provider figure; a failed lookup keeps the estimate.
- A request for a cache-capable model carries the breakpoints; other models don't.
- Every operation in `operations.php` exists in `operations.ts` and the reverse.
- `search_content` over MCP never returns entries the token's user cannot read.

## Release steps

- After deploying W1: `authorization:sync-roles` (new `ai.manage` and `team.ai.manage` abilities).
- W1 and W2 add management migrations; they run with the deploy.
- After tagging W3: `spaces:repair-databases` (translation memory table).

## W6 Built-in bulk skills and finishing follow-ups

Two agents in parallel.

**A (skills, small fixes, calibration):**

1. Built-in skills usable from Cmd+K, bulk selections and schedules: `translate-fields` and the SEO meta skill gain `cmdk` and `bulk:content` surfaces and `propose_update`; a new `find-untranslated` skill finds entries missing a locale and proposes translations.
2. `extract-glossary-terms` gets a translated label. The Cmd+K dialog gets a `DialogTitle`. Team tab deep links (`/teams/{team}/people|roles|saml|settings|ai`) get server routes.
3. Preflight estimate: average reconciled cost per entry from finished runs, per space and skill; token formula until 5 runs exist.

**B (learning loop, MCP uploads, SDK):**

1. Editor translations through `translate-fields` still land in the open editor but are recorded as single-entry changeset items, so edits before publish feed the learning loop.
2. Selection proposals can be edited before accepting.
3. `mgmt_call` accepts `{filename, content_base64}` file arguments, capped at the app's upload limit; glossary import/export and knowledge upload become operations in `operations.php` and `operations.ts`.
4. SDK: `AiResource` in `mgmt-client` (dead stream methods removed); mcp-server handlers use it; `rawCall` removed. Uncommitted on the SDK's current branch.

Acceptance:

- A schedule using a built-in skill produces a changeset without any space skill.
- Preflight uses the run average once 5 reconciled runs exist and the formula before.
- Editing an AI-translated field before publish yields a learning suggestion.
- A base64 glossary upload over MCP imports its terms; an oversize upload is rejected.
- The SDK typecheck, lint and tests pass, and `OperationsMirrorTest` stays green.

## W7 Threads (V2.1)

Design: "Threads" in the concept doc. Branch `feat/ai-threads`, cut from `feat/ai-ux-simplify`; both merge together after Mike reviews screenshots.

Two agents in parallel, contract first.

**Backend:** migration, `AiConversation` changes, thread creation in the run path, schedule and suggestion threads, status derivation, `ai/threads` endpoints, follow-up memory in the context builder, `AiThreadChanged`, removal of `ai/inbox`.

**Frontend:** dock, thread list, thread view with cards, composer, full page, Cmd+K and Cmd+J, feature buttons onto threads, toasts, removal of the inbox pages and the task dialog, glossary columns with language names.

**After merge (main session):** `operations.php` and SDK `operations.ts`, OpenAPI specs, DESIGN.md "AI surfaces", browser check and screenshots.

Acceptance:

- A run started without `conversation_id` creates a thread owned by the actor; a selection run's thread is quiet.
- A private thread is invisible to another member until shared; a shared thread's follow-up from a non-owner is rejected.
- A schedule's second run appends to the same thread.
- Status derivation returns each of the five states.
- A follow-up run's request carries the earlier messages and the proposal ledger, and drops the oldest turns past the budget.
- `meta.attention` ignores archived and quiet threads.
