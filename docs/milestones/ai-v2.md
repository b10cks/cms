# Milestone: AI V2

Design: `docs/concepts/native-ai-integration.md`. Integration branch: `feat/ai-v2`.

One milestone in four waves. Each wave ships on its own and leaves `feat/ai-v2` green.

| Wave | Depends on | Can run alongside |
| --- | --- | --- |
| W1 Foundation and knowledge | none | none |
| W2 Changesets, inbox, Cmd+K, background runs | W1 | W4 |
| W3 Translation memory, learning loop, schedules | W2 | none |
| W4 MCP exposure, content as knowledge | W1 | W2 |

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
