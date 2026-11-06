# Content operations readiness

Reviewed 2026-11-02 against the [confirmed brief](brief.md). Implementation, including the parent integration changes, is committed on
`feat/ai-content-operations`.
Nothing has been merged into main or deployed.

## Recommendation

The platform now has the engineering foundation for long-running content
operations. Use operations for existing-tree translation, asset metadata and page
metadata. Content goes straight to drafts with explicit coverage, evidence, knowledge
versions and exceptions, and asset and data entry changes stay proposals. A person
publishes a draft when happy: publication is the review ([direct writes](w8-direct-writes.md)).

Proceed with a controlled editorial pilot after deployment requirements are met.
This is not evidence of production editorial accuracy. Real-provider quality remains
manual, as agreed.

## What is implemented

| Area | Current behavior | Evidence |
| --- | --- | --- |
| Long-running work | Frozen scope, approved plan and budget, batches, durable leases, worker recovery and bounded provider backoff | [Runtime](w1-runtime.md), [provider recovery](w2-provider-recovery.md) |
| Concurrent writes | Lease takeover and tool writes serialize on the run row. Draft writes, ledger outcomes and checkpoints commit together | [Write fencing](w6-write-fencing.md) |
| Direct writes | Content goes to drafts through the same per-target, atomic path acceptance uses, including new locale entries. A field a person changed since the agent read it stays open as a conflict and the rest continues | [Direct writes](w8-direct-writes.md), [acceptance](w2-acceptance.md) |
| Completion | Requested fields, languages and translation units retain their own outcomes. Queue completion is separate from output coverage | [Completion](w2-completion.md), [operation UI](w3-operation-ui.md) |
| Existing-tree translation | Exact regional terminology, source lineage, preservation checks, missing locale entries and unit-specific exceptions | [Terminology and memory](w1-terminology.md), [composition](w4-translation-metadata.md) |
| Image work | Server-derived image delivery evidence, asset metadata and tags, explicit nonvisual bases, partial field reports | [Assets](w1-assets.md) |
| Metadata | Fill by default, approved overwrites, unrelated keys preserved, nested field requirements and length checks | [Completion](w2-completion.md), [composition](w4-translation-metadata.md) |
| Translation then metadata | Metadata is written from the translated draft, pinned to the version the agent read | [Direct writes](w8-direct-writes.md) |
| Missing facts | Independent work drains first. Grouped answers reopen selected fields or units and preserve completed work | [Questions](w4-grouped-questions.md) |
| Reviewed knowledge | Markdown drafts, explicit language-concept links, reviewable diffs, selected publication and immutable revisions | [Import backend](w2-knowledge-import.md), [import UI](w3-import-ui.md) |
| Contextual rules | Published document rules are frozen at approval. Anything conditional about a term is its short note, which always reaches the agent | [Policy snapshots](w3-policy-snapshots.md), [rule UI](w3-rule-ui.md), [W9 model](w9-model.md) |
| Deterministic violations | On content, exact Avoid wording and changed Keep exact names are stored on the written item, with the row's Use as the replacement, and flagged for whoever publishes. On proposals they need a correction or an explicit reasoned override. Missing Use wording is a hint: no reason, no stop to a bulk accept. Edited text is checked under the proposal's policy when it is accepted | [Enforcement](w7-enforcement.md), [semantic review removed](w8-remove-semantic.md) |
| Knowledge corrections | Deterministic candidate discovery, scoped unapproved maintenance drafts, no automatic paid generation, retained reference revisions | [Maintenance](w4-knowledge-maintenance.md) |
| External agents | Management API and CMS MCP share controllers, policies and validation; generated schemas include the new lifecycle | [API/MCP](w5-api-mcp.md) |

## Using one glossary per language

Import each Markdown language source into a glossary draft with a stable source key
and locale. Review added, changed, removed, conflicting and unlinked concepts. Link
language variants through explicit concept identities or reviewer decisions. Matching
headings alone do not establish that two languages describe the same concept.

A glossary row is one meaning: per language Use, Avoid and Also written as, plus Keep
exact and one short note. Also written as counts as the term itself, so it activates
lookup and checks. Avoid wording in a source activates its row too. A row with Avoid and
no Use is a ban. Exact locales win, with explicit base-language fallback and no
sibling-region guess.

Review document-wide rules separately from terms. Publish selected rules and terms,
confirm removals individually, and leave unresolved choices in the draft. Terms-only
publication sends `document_rule_keys: []`. The CMS governs published knowledge.

At operation approval, freeze the complete resolved knowledge. Later glossary edits
do not change an already approved task. Proposals carry policy provenance; reference
text and source descriptors stay available after a live document is soft-deleted.
Publishing a correction exposes pending changes for each space. An editor starts
discovery, reviews candidates and creates a maintenance draft before approving paid
work. Candidates are evidence, textual matches or uncertain matches, not automatic
proof of a violation.

The import task parsed the supplied German glossary into 188 entries and 451 aliases
in a scratch check. The source itself was not committed or imported into live data.
Unrecognized labels require a mapping or review; importing a document is not proof
that every editorial interpretation is correct.

## Limits that matter

- **Spend is not a hard external billing cap.** Interrupted provider calls can be
  billed without reported usage. Receipts show unconfirmed calls; budgets do not
  extend automatically.
- **A frozen process can hold a database lock.** Write fencing prevents corruption,
  but a process suspended inside a write stalls that run until it resumes, dies or
  its database session ends. A competing worker can wait for the lock timeout.
- **Translation memory has historical ambiguity.** Older human edits infer source
  lineage from history and timestamps. Explicit source versions recorded at editor
  save time would provide stronger guarantees.
- **Discovery is candidate-based.** It covers content prose, translations and
  metadata, including nested fields. Asset text, data entries and entry names are
  not scanned. Rules that cannot be bounded are reported as not searched.
- **Compatibility paths remain.** Older plans without explicit output requirements
  are unverified. Inline rewrites keep their existing behavior. Immediate
  classification refuses Avoid wording per language and reports it, in one pass. The meta button
  and the rich text menu show exact matches only, without blocking.
- **A draft can be wrong until someone publishes.** Nothing checks AI text in between.
  Flagged wording shows in the receipt, and a publish is what makes text public and
  teaches translation memory.
- **API rights remain normal rights.** A client with an owner's credentials can
  approve a task or increase a budget. Client integrations must obtain editor
  approval before those calls. The sibling SDK/stdio package is unchanged.

## Verification and deployment

Engineering checks use fake providers. W5 and W6 also used private MySQL databases,
real queue workers, concurrent processes, SIGKILL and SIGSTOP. W6 reports 192 checks
passing across 147 child processes, including the previously failing stale write.
Final integration checks pass: 495 PHP tests with 5,172 assertions, 1,068 frontend tests, and typecheck. Commands and coverage are recorded in the [delivery index](README.md).

Rendered UI checks used actual components and styles with synthetic APIs. A later
[bounded GPT Luna evaluation](../../evidence/2026-11-04-glossary-provider-verification.json)
used 18 synthetic generations, including two real CMS runs on isolated SQLite.
It is not a representative editorial quality evaluation. No full authenticated
browser-to-backend session, real Reverb/S3 interruption or production-load lock
timing was exercised. Redis, SQS,
database failover and replica lag are not covered by the MySQL harness.

Before taking traffic, apply the normal management migrations and repair space
databases for migrations 000022, 000024 and 000026. The scheduler must execute
`ai:recover-runs`. Run `ai:backfill-translation-memory` explicitly after deployment
to replace historical incorrect memory pairs. No live migration or backfill was run
during this implementation.

Follow-ups outside this delivery include import remapping/discard/search refinements,
decline audit entries, explicit historical editor lineage,
stronger impact-rule attribution and the separately authorized sibling SDK update.
