# AI V2: native AI integration

Status: approved by Mike on 2026-10-27. Branch: `feat/ai-v2`. Delivery plan: `docs/milestones/ai-v2.md`.

Internal briefing. Excluded from the public docs build (`srcExclude` in `docs/.vitepress/config.ts`).

## Goal

AI in b10cks should feel like part of each feature, not a chat box next to it. One server-side agent loop powers every AI action. Spaces teach it their voice, terminology and reference material through skills and a typed knowledge base. The agent can take on large jobs in the background, and everything it writes arrives as a reviewable proposal.

## Settled decisions

1. One agent and one run service. Entry points stay inside features (field actions, rich text selection, bulk selections, data sources, canvas, assets). The only global surfaces are Cmd+K and the inbox.
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

## Inbox and Cmd+K (W2)

- Inbox page per space: open changesets, learning suggestions (W3), runs awaiting input, running and failed runs. Sidebar badge with the open count, updated over the space channel.
- Cmd+K: new command palette. A free-form task is matched to a skill by description, or runs on the general agent. Scope is the current screen.
- Preflight: a read-only pass resolves the target entries and estimates cost from source token counts and `ModelRegistry` pricing. Tasks touching more than `ai.inline_entry_limit` entries (default 5) always go to the background after confirmation.

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
- Proposals render in place for inline runs and in the inbox for changesets, both on the history diff renderers.
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

## Open decisions

1. PDF text extraction: `smalot/pdfparser` (pure PHP) or `pdftotext` (poppler in the image). Recommendation: `smalot/pdfparser`, no image change.
2. Provider cost reporting: OpenRouter reports cost when usage is requested; Bedrock and OpenAI streaming usage need checking against the installed client. Fallback: tokens times `ModelRegistry` pricing.
3. Prompt caching pass-through on OpenRouter for every listed model.
