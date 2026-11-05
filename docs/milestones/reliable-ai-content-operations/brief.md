# Reliable AI content operations

Revision 4, set by Mike on 2026-11-04 to remove the management burden one input file introduced
(revision 3 and earlier: 2026-11-02 to 2026-11-04). Revision 4's work:
[W9](w9-simple-glossary.md). Publication by a human is the review.
This is the product contract, not a claim that the work is implemented or measured.
Delivery, ownership, evidence and open decisions: [milestone index](README.md).
Builds on [AI content operations](../../concepts/ai-content-operations.md) and
[native AI integration](../../concepts/native-ai-integration.md). Existing UI rules remain in
[DESIGN.md](../../../DESIGN.md).

## Product contract

Starting a task is one action. A small task just runs; one above the run limit or the cost
threshold shows its plan and budget for one approval first. The agent then finishes the work without
further attention, recovers from transient failures and accounts for every requested field
and language. AI writes content directly to drafts. Humans control publication, and that is
where they judge the result. A draft change is distinct from published content.

Only content has a draft layer. Assets, asset folders and tags, data entries and blocks go
live when written, so changes to them stay proposals that a human accepts.

The existing recursive scope, frozen membership, 10,000-item limit, normal authorization,
explicit budget extensions, shared changesets and thread UI remain the foundation.
AI never publishes. No group undo is introduced.

## Included

- Translate existing content trees, including missing locale entries, empty fields and
  translations stale after source changes. Preserve unrelated human edits, structure,
  placeholders, links, markup and non-translatable values.
- Generate page metadata, including metadata derived from proposed translations.
  Preserve unrelated metadata, fill empty values by default and require approved
  overwrite permissions for existing values.
- Classify images and fill asset-level metadata and tags. Preserve existing copy by
  default. Keep the existing approved asset folder/tag planning and move permissions.
- Author glossaries in a concept-first CMS editor and import them from a full-field
  CSV/XLSX sheet, which is the format b10cks sets.
- Provide the same lifecycle through the CMS Management API and local MCP.

## Excluded

- Structural content-tree creation, reorganization, moves and deletion.
- Placement-specific image alt text. Asset descriptions cannot establish suitability
  for every page placement.
- Sibling SDK changes, autonomous publication, automatic budget extensions and a
  second global AI interface.
- Markdown glossary import, glossary revisions, knowledge-impact discovery and semantic
  review. Removed in revision 3; they are in the tag `archive/w7-before-reduction`.
- Glossary statuses, audience labels, categories, per-language overrides and separate
  definitions. Removed in revision 4; they are in the tag `archive/w8-before-simplify`.
- A numeric editorial quality gate or required real-provider evaluation. Editorial
  quality remains manually assessed. Engineering readiness does not imply prose or
  classification accuracy.

## Knowledge authority and imports

The CMS governs published terminology after import. A sheet is an editorial source. Language
variants link through a stable concept identity.

A glossary row is one meaning. Per language it has **Use** (the wording to write), **Avoid**
(wording never to write, where `*` at the end covers longer forms) and **Also written as**
(how a source may spell the same term, such as inflected forms). A row with Avoid and no Use is
a ban. Each row also has **Keep exact** (the name stays as written, in exact case; a language's
own wording still wins, and the source wording stays only when the target language has none)
and one short **Note** that always reaches the AI. Longer material belongs in knowledge documents.

A glossary holds terminology for what gets published. Audience labels and internal vocabulary
are out of scope: a word that may not go out is an Avoid entry, and anything conditional is a note.

The sheet has one row per concept and covers all languages. It applies directly through the
shared import dialog, like every other import: missing rows stay, blank cells keep their value
and removal happens in the editor. Each import writes an audit entry. Glossary languages follow
the space: pickers offer its languages, and runs use the page's real language. Terms in languages
the space has not enabled stay stored and show as unused.

The prompt carries Use, Avoid and the note, and the agent reads what the author wrote, with no
hidden rules. Terms reach the agent whenever it reads an entry, not only when it looks them up.
Exact matches of Avoid wording and changed Keep exact names go back to the agent so it can fix
them, with the row's Use as the replacement. What remains is written to the draft and flagged
where a person publishes. Missing required wording is told to the agent only.

Approved operations retain knowledge and glossary snapshots. Changes record the versions used. New
knowledge does not silently change an approved task. Translation memory learns only from what a
human published, never from an unreviewed AI draft, and must respect current terminology.

## Execution and completion

- Execution ownership lasts through slow provider calls and excludes concurrent
  advancement. Abandoned work is detected and recovered without mistaking a live
  worker for a dead one.
- Retries preserve completed work. Writes, acceptance decisions and creation are
  durable and idempotent at their commit boundaries.
- Every requested entry, locale and field has an explicit outcome: proposed,
  already satisfied, intentionally empty, uninferable, invalid, unfinished or failed.
  Partial output cannot report complete fulfillment. Translate these domain outcomes
  into the existing ledger without losing field-level detail.
- Missing facts block only dependent work. Finish independent work, then collect
  remaining questions together. Resume with the answers and preserve completed work.
- Image-based proposals record evidence that the asset image reached the model.
  Multiple inspections in a turn retain all image evidence. Catalog limits must not
  silently hide relevant taxonomy choices.
- Metadata writes preserve canonical, robots, images and other unrelated values.
  Enforce approved keys, overwrite permissions and field constraints in code.
- Translation memory records verified source/target pairs. Exact locale selection
  precedes explicit base-language fallback; sibling regions are not interchangeable.
- Operation totals stay consistent with reconciled run spend. Estimated and confirmed
  spend remain distinguishable; no automatic budget extension occurs.

Metadata generated from a translation reads that translation's draft. Decision D10 is closed:
with direct writes there is no rejected source translation to depend on.

## Review and evidence

Keep existing threads, feature buttons and normal permissions. A field a human changed since
the agent read it is never overwritten, not on a retry either: it stays open for a person and the
rest continues. Flagged wording and conflicts are visible where a person publishes.
Editor buttons fill their fields and show banned-wording findings inline without blocking.
An operation receipt shows requested coverage, what was written, conflicts, flagged wording,
unanswered questions and spend. The editor can inspect each
field and locale without loading every proposal at once.

Engineering acceptance demonstrates recovery after worker termination, safe
concurrent execution and acceptance, preservation of unrelated content, complete
field/locale accounting, correct image delivery, consistent policy snapshots and
accurate spend reconciliation. Use focused tests and deterministic failure injection.
State what was not exercised, including production database or real-provider behavior.

## Reference material

One customer file (201 concepts, six languages) is a one-off prototype import. Only its
external terminology goes in. A throwaway script outside the repo converts it, and the original
stays outside Git. Use small, synthetic fixtures to prove general behavior. Nothing in the product is shaped
around this brand or its file format.
