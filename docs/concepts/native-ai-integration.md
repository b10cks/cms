# AI V2: native AI integration

Status: approved by Mike on 2026-10-27. Branch: `feat/ai-v2`. Delivery plan: `docs/milestones/ai-v2.md`.

Internal briefing. Excluded from the public docs build (`srcExclude` in `docs/.vitepress/config.ts`).

## Goal

AI in b10cks should feel like part of each feature, not a chat box next to it. One server-side agent loop powers every AI action. Spaces teach it their voice, terminology and reference material through skills and a typed knowledge base. The agent can take on large jobs in the background, and everything it writes arrives as a reviewable proposal.

## Settled decisions

1. One agent and one run service. Entry points stay inside features (field actions, rich text selection, bulk selections, data sources, canvas, assets). The only global surfaces are Cmd+K and the threads dock (V2.1, replaces the inbox).
2. The AI never writes content directly. Write tools create proposals: field-level patches pinned to the version they read. Accepting writes a draft through existing actions. Nothing is ever published by AI.
3. Knowledge is typed: a structured glossary, always-on style guides, and reference documents retrieved on demand with citations.
4. Skills and knowledge live in the management DB and resolve space, owning team, ancestor teams (nearest first), system. The nearest entry with the same name or slug shadows the rest.
5. Retrieval starts with MySQL FULLTEXT and title/slug ranking. No embeddings until retrieval checks show misses.
6. Skills use the Agent Skills format (`SKILL.md` with `name` and `description` frontmatter) plus an optional `b10cks:` frontmatter block. Today's AI features become built-in skills.
7. The glossary is enforced: matched terms are injected, output is checked deterministically and violations are flagged, never silently fixed.
8. Translation memory lives in the space DB and is never shared across spaces. Editor corrections of AI output produce glossary suggestions in the inbox.
9. Background and scheduled runs need a preflight estimate and hard caps. Scheduled runs act as their creator, rechecked on every run.
10. Rich text gets selection actions with inline proposals. No ghost-text autocomplete.
11. Quality is covered by unit tests with a fake driver. No model evals.
12. Imported skills are instructions only. Scripts in skill folders are never executed.

## What stays

Provider drivers (OpenRouter, OpenAI, Bedrock), `ModelRegistry` and `ai_models`, `SpaceAiConfig` presets, per-space key provisioning, `aiCredit` quota and spend checks, the untrusted-data fencing in `SystemPromptBuilder`, the SSE transport (`AiSseStream`), `ring-ai` provenance marking, and the existing read tools. No new AI SDK.

The stash@{2} UI work is carried over: `components/ui/message`, `components/ui/message-scroller`, `components/ui/questionnaire`, and the a11y/IME fixes to `InputGroupTipTap`, `InputGroupAddon`, `AiText` and `AiConfigSelector`, with their Vitest tests.

## Run service

### Drivers do one turn

Today `OpenRouterDriver::stream()` runs its own tool loop by recursing after `tool_calls`. That loop has no step bound, writes one assistant message per tool call, drops text written before tool calls, surfaces reasoning as status text and keeps no state after the request.

In V2 a driver performs exactly one provider call:

```php
interface AiDriverInterface
{
    /** One provider call. Yields TextDelta|ToolCallRequested|Usage, returns TurnResult. */
    public function turn(Transcript $transcript, ToolSet $tools, RunOptions $options): Generator;
}
```

`TurnResult` holds the assistant text, all tool calls of the turn and normalized usage (tokens and, where the provider reports it, cost). Reasoning is kept only where a provider requires it to be sent back, never shown to users.

### `AiRunService` owns the loop

```php
public function advance(AiRun $run): Generator
{
    $lock = Cache::lock("ai-run:{$run->id}", 60);
    if (! $lock->get()) throw new RunBusy;

    try {
        while (true) {
            $this->guard($run);                   // cancelled, space access, budgets

            $turn = yield from $this->driver->turn($run->transcript, $run->tools, $run->options);
            $run->recordUsage($turn->usage);
            $run->transcript->appendAssistant($turn->text, $turn->toolCalls);
            $run->checkpoint();

            if ($turn->toolCalls === []) {
                return $this->complete($run, $turn->text);
            }

            foreach ($turn->toolCalls as $call) {
                if ($call->name === 'ask_questions') {
                    return $this->pause($run, $call);
                }
                yield new ToolStarted($call);
                $result = $this->tools->execute($call, $run->actor);
                $run->transcript->appendToolResult($call->id, $result);
                yield new ToolFinished($call, $result->summary());
            }
            $run->checkpoint();
        }
    } finally {
        $lock->release();
    }
}
```

Rules:

- Checkpoint the transcript after every provider turn and after tool results. A crash resumes from the last checkpoint. Never automatically retry a provider call with an ambiguous outcome; it is billable.
- Tool errors (validation, not found, forbidden) go back to the model as tool results. Only infrastructure failures fail the run.
- Guards run between steps: cancellation flag, the actor's current space access, step count, tokens, USD and wall time. A budget hit completes the run with `partial: true`; proposals made so far stay.
- `complete()` validates the final output against the skill's output schema when one is declared.
- `ask_questions` stores a validated questionnaire on the run, sets `awaiting_input` and returns. The answers endpoint validates question and option IDs against the stored questionnaire, appends the exact tool result and calls `advance()` again. No transaction or provider request stays open while waiting.

Run states: `running`, `awaiting_input`, `completed`, `failed`, `cancelled`. Starting and resuming take an idempotency key.

### Where runs execute

The same `advance()` runs in two places; only the event transport differs.

- **Inline (SSE).** The controller iterates the generator into `AiSseStream`. FrankenPHP kills requests at 59s regardless of `set_time_limit`, so inline runs have a wall-time guard of 45s (config). A run that hits it is checkpointed and handed to the queue; the client gets `moved_to_background` and switches to the Reverb channel.
- **Background (queue).** `RunAgentJob` extends `QueuedJob`, runs on the heavy queue and holds the run ID only (never a serialized space model). It sets `SpaceContext`, advances a bounded slice of steps, checkpoints and re-dispatches itself while work remains.

### Events

`run_started`, `text_delta`, `tool_started`, `tool_finished`, `source`, `proposal`, `questionnaire`, `glossary_findings`, `usage`, `moved_to_background`, `completed`, `error`.

Every event has `run_id` and a monotonically increasing `seq`. Background events are stored in `ai_run_events` and broadcast in small batches (IDs and short deltas; Reverb drops messages over 10KB). Clients catch up with `GET /runs/{run}/events?after={seq}`.

## Context builder

Assembles the prompt in a fixed order, stable parts first so provider prompt caching hits:

1. Base instructions for the surface.
2. Resolved style guides.
3. Skill catalog (names and descriptions) and, for an explicitly invoked skill, its full instructions.
4. Glossary terms that match the source text.
5. Translation memory hits (W3).
6. Screen context: block schema, current content, selection, mentioned entries.
7. Untrusted data, fenced with the existing random-suffix tags.

The run freezes the resolved skill, style, glossary and document revision IDs at start. The audit shows exactly what the model saw.

## Tools

A curated set of typed tools, not the 268 operations of the MCP registry. Large tool lists hurt tool choice, and the registry has no JSON schemas.

| Tool | Wave | Purpose |
| --- | --- | --- |
| `get_block_list`, `get_block_schemas` | W1 | Existing |
| `search_assets` | W1 | Existing |
| `get_mentioned_content` | W1 | Existing |
| `get_entry` | W1 | Read one entry's current version (drafts as the actor may see them) |
| `read_skill`, `read_skill_file` | W1 | Progressive skill loading |
| `search_knowledge`, `read_knowledge` | W1 | Bounded excerpts with source metadata |
| `lookup_glossary` | W1 | Terms for a text and locale pair |
| `ask_questions` | W1 | Pause for structured user input |
| `propose_update`, `propose_create` | W2 | Write proposals into the run's changeset |
| `find_entries` | W2 | Resolve a scope (folder, block type, locale gaps) for bulk work |
| `search_content` | W4 | Search the space's own content via `SearchService` |

Every tool executes as the acting user through existing policies. A skill's `tools:` list narrows the set; it can never widen it.

## Skills

### Format

```markdown
---
name: product-description
description: Writes product descriptions in our voice from specs and bullet points.
b10cks:
  surfaces: [field:richtext, field:textarea, selection, bulk:content, cmdk]
  blocks: [product]          # optional filter by block slug
  tools: [get_entry, search_knowledge, lookup_glossary]
  output: text               # text | richtext | json schema reference
  config: product-copy       # optional SpaceAiConfig name
---

Instructions in markdown...
```

Supporting text files sit next to `SKILL.md` and are loaded with `read_skill_file`. Validation: relative paths only, no duplicates, UTF-8, size limits per file and per skill.

### Storage and resolution

- Built-in skills ship as files under `resources/ai/skills/<name>/SKILL.md` and are read from disk as the system layer. No sync command, no deploy step.
- Team and space skills live in `ai_skills` (scope, team/space ID, name, enabled, editable draft, published revision ID, audit actors) and `ai_skill_revisions` (immutable manifest, revision number, hash, publisher, time).
- Resolution: space, owning team, ancestor teams nearest first, then built-ins. Same name shadows. Management screens show the source and what is shadowed.
- Root users manage nothing in the DB for the system layer; built-ins change through code.

### Surfaces make AI native

Feature screens stop hardcoding AI buttons. They call `GET /mgmt/v1/spaces/{space}/ai/context?surface=…&block=…` and render the resolved skills through one `AiActions` component. A space that adds a skill sees it appear in the matching field menus, selection menu, bulk bar or Cmd+K without code changes.

### Built-in skills

The six existing features move onto the run service as built-ins with output schemas: content interaction, content tree generation, meta tags, field translation, data entry translation, asset classification. Their parsing (`lib/aiJson.ts`, `AssetClassificationService::parseResponse`) moves behind those schemas. The legacy endpoints stay until their callers are migrated within W1, then they are removed.

Additional built-ins in W1: rewrite, shorten, expand, fix grammar, apply glossary, translate selection.

## Knowledge base

### Types

| Type | Behaviour |
| --- | --- |
| Style guide | Markdown, always in the prompt for its scope |
| Reference document | Markdown (written in-app or extracted from PDF/DOCX), chunked by heading on publish, retrieved with `search_knowledge` |
| Glossary | Structured terms, matched against the source text and enforced |

### Tables (management DB)

- `ai_knowledge_documents`: scope, team/space ID, slug, title, type (`style`, `reference`), enabled, editable markdown draft, published revision ID, source file (private storage) for uploads, audit actors.
- `ai_knowledge_revisions`: immutable markdown, revision number, hash, publisher, time.
- `ai_knowledge_chunks`: revision ID, heading path, text, position. FULLTEXT index on text and heading.
- `ai_glossaries`: scope, team/space ID, name, enabled.
- `ai_glossary_terms`: glossary ID, source locale, term, translations per locale (JSON), case sensitive, do-not-translate, forbidden variants (JSON), note.

### Glossary enforcement

- A matcher is built per resolved term set and cached by the set's hash. Matching is locale-aware and respects case sensitivity and word boundaries.
- Only matched terms enter the prompt.
- After generation a deterministic checker verifies required target terms, preserved do-not-translate terms and absent forbidden variants. Findings travel as `glossary_findings` and are flagged on the field in the UI. Pure PHP, unit-tested without a model.

### Ingestion

- Markdown editor in-app.
- PDF and DOCX upload: stored privately, text extracted by a queued job into the draft. DOCX via `ZipArchive` and XML. PDF library is an open decision.
- Glossary import and export as CSV, XLSX and TBX, reusing the existing import infrastructure.
- No URL crawling in V2.

## Changesets (W2)

- `ai_changesets`: space ID, run ID, actor, title, status (`open`, `partially_applied`, `applied`, `discarded`), counts.
- `ai_changeset_items`: changeset ID, target type and ID, base version ID, field path (block IDs, never array indexes), before, after, status (`pending`, `accepted`, `rejected`, `conflict`), glossary findings.

Write tools validate patches against the block schema with the same rules content saves use. Invalid patches return to the model as tool errors and never reach review.

Accepting groups items per entry and loads the current version. A field whose current value still equals `before` applies; otherwise the item becomes a conflict shown side by side. Applying goes through `UpdateContent` (and the data entry and asset equivalents) and creates a draft version. Open editors receive the normal version-changed broadcast.

Inline edits on one entry are single-entry changesets reviewed in place. Background runs are reviewed in the inbox. One apply path, one diff UI (the schema-aware history diff renderers), one audit trail.

## Threads (V2.1, replaces the inbox)

Settled with Mike on 2026-10-27. Every AI conversation and run lives in a thread, shown in a dock modelled on agent apps like T3 Code. The inbox, its review page and the Cmd+K task dialog are removed.

### Behaviour

- Dock on every space page, on the right. Wide screens: a resizable split that pushes the page, width remembered. Below 1280px: a sheet over the page. Toggled with Cmd+J and a header button; the button shows how many threads need you. An expand button turns the dock into a full page (`/{space}/threads/{thread}`) with the thread list on the left. `/{space}/inbox` redirects to `/{space}/threads`.
- Every user-started run belongs to a thread. Without a `conversation_id` the server creates one. Inline surfaces (rich text selection, single-field actions) create it `quiet`: listed, never raising attention or opening the dock. Internal runs (learning extraction, automatic asset classification) get no thread.
- Each schedule has one standing thread (`kind = schedule`); each run appends a turn. Learning suggestions live in one standing thread per space (`kind = suggestions`). Both are visible to members with `ai.manage`.
- Chat threads are private to their owner. The owner can share one with the space; then every member who can view the space sees it under "Shared", anyone with edit rights on an entry can accept or reject its cards, and only the owner sends follow-ups.
- A turn is one run: the prompt, the streamed steps, the answer, a questionnaire if it paused, and its changeset as cards. One card per entry with per-field accept and reject as icon buttons; a field shows a status badge only once decided. Cross-locale changes show source and translation side by side; same-locale edits keep the word diff. A turn touching more than five entries renders one summary card (counts, glossary warnings, Accept all, Reject all) with the entries as collapsed rows.
- Composer: a new thread attaches the current screen (open entry, data source, selection) as a removable chip. In an existing thread, a screen that isn't attached shows as a ghost chip to click. `@` mentions entries, assets and knowledge documents, `/` picks a skill, the model picker stays. An empty thread offers the resolved skills of the current surface as chips.
- Cmd+K stays the command palette. Free text offers "Ask AI: …", and skill items start a thread in the dock with the current screen attached. Feature buttons start threads; the dock opens for them only when there is something to review or answer, except bulk starts, which open the dock on the running thread.
- Status, derived and never stored, in priority order: `needs_input` (a run awaits answers), `needs_review` (an open non-inline changeset, or pending suggestions), `running`, `failed` (latest run failed), `done`. The list pins `needs_input` and `needs_review` on top, then sorts by last activity. Threads are archived by hand; archived ones sit behind a "Show archived" toggle. Search matches the title.
- A background thread reaching `needs_input`, `needs_review` or `failed` while it is not the open thread raises a toast with "Open".
- Titles: the skill label plus the scope label, else the first prompt cut to 60 characters. No model call.

### Follow-up memory

A run in a thread sees the thread's earlier user and assistant messages as conversation turns, followed by a proposal ledger inside the untrusted-data fence: per earlier changeset item the entry label, field path and status (`accepted`, `accepted_edited`, `rejected`, `pending`, `conflict`). Tool results are not replayed; the agent re-reads entries through tools. The oldest turns drop first once the history passes `ai.threads.history_tokens` (default 6000, estimated at four characters per token).

### Data

`ai_conversations` gains `kind` (`chat`, `schedule`, `suggestions`, default `chat`), `schedule_id` (nullable, unique, null on delete), `shared`, `quiet`, `archived_at`, `last_activity_at` and `context` (the chips the thread started with, long text JSON). `user_id` becomes nullable (the suggestions thread has no owner). Index on `space_id, last_activity_at`. `last_activity_at` moves on run start, run finish and every changeset or suggestion decision.

### API

All under `spaces/{space}`, behind `space.member`.

- `GET ai/threads?archived=0|1&search=` lists the threads the user can see, at most 100, needs-you first then by `last_activity_at`. Each row: `id`, `kind`, `title`, `status`, `shared`, `quiet`, `archived`, `last_activity_at`, `owner` (`id`, `name`, or null), `is_owner`, `entry_count` (entries with pending items). `meta.attention` counts visible, unarchived, non-quiet threads in `needs_input` or `needs_review`.
- `GET ai/threads/{thread}` returns the row plus `context` and `turns`: per run in start order `{ run, prompt, answer, changeset }`, where `run` is `AiRunResource` (with questionnaire and error) and `changeset` is `AiChangesetResource` with items filtered to target types the user may view. The suggestions thread instead carries `suggestions` and `glossaries`, the payload the inbox used to return.
- `PATCH ai/threads/{thread}` with `title`, `shared` (owner only) and `archived` (owner, or `ai.manage` for schedule and suggestion threads).
- `POST ai/runs` returns or streams the run with its `conversation_id`, including when the server created the thread.
- Removed: `GET ai/inbox`. Changeset accept and reject and suggestion accept stay as they are.
- Broadcast: `AiInboxChanged` becomes `AiThreadChanged` (`ai_thread:changed` on `spaces.{id}.ai`, payload `thread_id` only). Clients refetch through the authorised endpoints.

## Translation memory and learning (W3)

- Space DB table `translation_units`: source hash, source locale, target locale, source text, target text, origin (`human`, `ai_accepted`), content reference, field path, timestamps. FULLTEXT on source text.
- Filled by a listener after publish in `BasePublishAction` by comparing translatable fields across locales. A command backfills from version history.
- Exact matches skip the model call. Fuzzy matches (FULLTEXT top hits) are injected as examples.
- Space migration: run `spaces:repair-databases` by hand after the tag; hosted deploys do not run space migrations.
- Learning loop: when an accepted AI item was edited before acceptance, or an AI-written field is edited before publish, a small model extracts candidate term pairs from the AI text versus the final text. Candidates already in the glossary are dropped; the rest become inbox suggestions that someone with `ai.manage` accepts into space or team scope.

## Scheduled agents (W3)

`ai_schedules`: space ID, creator, skill, saved scope query, cron expression, budget, enabled, last run. The scheduler dispatches due schedules. Each run rechecks the creator's permissions and pauses the schedule with a notice if access is gone. Output is always a changeset.

## MCP and content as knowledge (W4)

- `ManagementServer` gains `list_skills`, `read_skill`, `search_knowledge` and `lookup_glossary`, resolved through the same layers for the token's user.
- `search_content` uses the existing `SearchService` (MySQL FULLTEXT or OpenSearch), limited to what the actor can read.

## Permissions, budgets and audit

- New ability `ai.manage` for team and space skills, knowledge and glossaries. Accepting changeset items needs the same rights as editing the target. Schedules need `ai.manage`.
- Budgets in `config/ai.php` with per-skill overrides: max steps, max tokens, max USD per run, inline wall time, per-space concurrent background runs. The `aiCredit` quota remains the outer limit.
- `ai_runs` is the audit: actor, surface, skill and frozen revisions, config and model, state, transcript checkpoint, pending questionnaire, usage and cost, result or error, timestamps.

## Frontend

- `useAiRun`: one composable with a discriminated event union over SSE and Reverb, with catch-up by `seq`. Replaces the per-feature stream composables as features migrate.
- `AiActions`: renders resolved skills for a surface. The rich text selection menu is a TipTap bubble menu built on it.
- Proposals render in place for inline runs and as cards in their thread otherwise, both on the history diff renderers.
- The dock state (open, width, active thread, pending draft) lives in one module store; the dock is mounted once in the space layout.
- Helpers live in `resources/js/lib/`, not in sibling composables (auto-import trap).
- Settings: Skills, Knowledge and Glossaries pages per space and per team, following DESIGN.md (`SettingsSection`, `wide` for tables, `Panel`).
- All strings in `en` and `de`; other locales copy `en`.

## Known traps

- FrankenPHP 59s request limit: inline runs must stay below it.
- Reverb drops messages over 10KB.
- Never `SerializesModels` a space model in a job; capture IDs and use `SpaceContext::current()`.
- Controllers extending `Controller` are constructed before middleware: no constructor-injected space services.
- Octane rebuilds non-warmed singletons per request: no run state in singletons or config.
- Space migrations do not run on hosted deploys.
- MySQL reorders JSON keys: compare JSON with `canonicalJson` in tests and diffs.
- The local management DB holds production RDS hosts in `space_connections`: scratch scripts use an explicit local connection.

## Decisions settled during delivery

1. PDF text extraction uses `smalot/pdfparser`. No image change.
2. Run cost: live budget caps use tokens times `ModelRegistry` pricing, because the OpenAI client drops OpenRouter's cost field. A queued job reconciles `ai_runs.cost` afterwards from OpenRouter's generation lookup (W5).
3. Prompt caching: `cache_control` breakpoints after the style guides and after the skill catalog for Anthropic and Gemini models via OpenRouter, verified against reported cached tokens (W5).
4. Glossaries have no revisions. A run freezes a snapshot of the terms it matched.
5. `search_content` covers published entries only. Drafts stay reachable through `get_entry` and mentions.
6. Read access for `search_content` is the space-wide `content.view` ability.
7. Rich text selection proposals apply in the open editor and are recorded as single-entry changeset items. A server-side apply would race unsaved edits and live collaborators.
8. Cmd+K extends the existing palette (`components/Command.vue`) instead of adding a second one.
9. Data entries and assets have no drafts; accepting their items saves directly through `UpdateDataEntry` and the asset update action.
10. MCP AI tools need a user, so they work over the HTTP transport only.
11. Preflight estimates calibrate themselves: average reconciled cost per entry per space and skill, token formula until 5 runs exist.
12. File arguments over MCP are base64 (`{filename, content_base64}`), capped at the app's upload limit.
13. Editor translations apply in place like selection proposals and are recorded as single-entry changeset items, so edits feed the learning loop.
