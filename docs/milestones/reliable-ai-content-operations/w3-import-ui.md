# W3 import UI evidence

Built on `3bedc9b0`. Frontend only, against the import backend from `89ef41fb`. One slice, it does not complete the brief. No backend, generated spec, MCP registry, `lib/aiRun.ts` or operation UI files changed. The six big locale files are untouched.

## What an editor can do

- From a glossary's menu, choose **Import Markdown**: upload or paste, with language, source and an optional field mapping. The result is a draft. A repeated upload says it returned the existing draft, and an already published file says so too.
- On the glossary page, read drafts and revisions in two new panels. Both stay hidden until the glossary has any.
- On the draft page (`…/glossaries/:id/imports/:importId`), read parser warnings, document-wide text and a paginated diff filtered by kind and state. Each row shows the CMS value, the proposal, what changed, the decision and the blockers.
- Open an entry to see both sides, including protected name, exact case, aliases, forbidden wording, applicability, definition, rules and unmapped fields, plus the original section text and its line. Decide there: use the import, new concept, link or keep the CMS. Setting a status clears `status_unmapped`.
- Link through a search of concepts and of terms without a concept, each with per-language wording. If the target already has wording in the draft's language, the row says it gets replaced. Nothing links until the reviewer picks a row and confirms.
- Select across pages and filters, decide a selection in bulk, then publish. The publish dialog re-reads the entries, lists what goes, requires a tick per removal, and names what stays.
- See the new revision after publishing and inspect any revision: before and after per entry, linked updates, source text.

## Rules the UI keeps

- `base_revision_id` is the draft's own base, never the head, so `stale_base` can fire.
- A refusal never retries. `stale_base` and `stale_entries` show a notice with Refresh, and Publish stays disabled until the refresh. `stale_entries` also marks the affected rows. A refresh clears the selection, so nothing carries over unseen.
- If the cached glossary head differs from the draft's base, the same notice shows before any attempt. The server still decides.
- `import_closed` removes the controls after the re-read.
- `Select all that can be published` leaves removals out.
- Read-only: no `ai.manage` or `team.ai.manage`, a closed or replaced draft, or an inherited glossary. An inherited glossary also lost its term buttons, which only ever produced 404s.
- Not gated on `useAiEnabled` and no AI styling: this is knowledge management, not an AI run.
- Manual terms and CSV, XLSX and TBX import work as before.

## Contracts added

Backend contracts are consumed as documented in `w2-knowledge-import.md`. Frontend additions:

- `api/resources/ai-glossary-imports.ts`: request and response types, `AiGlossaryImports`, reached through `api.aiGlossaryImports(owner, glossaryId)`.
- `composables/useAiGlossaryImports.ts`, `lib/aiGlossaryImport.ts`.
- `queryKeys.aiKnowledge(owner)` gains `glossaryImports`, `glossaryImport`, `glossaryImportEntries`, `glossaryConcepts`, `glossaryRevisions`, `glossaryRevision`, all under `glossaries()`.
- `AiGlossaryResource.head_revision_id`, optional.
- Routes `space-settings-ai-glossary-import` (`ai.view`) and `team-ai-glossary-import` (`team.ai.manage`), plus `aiSettingsRoutes().glossaryImport`.
- Copy lives in `i18n/modules/glossary-import/{en,de}.json` under `glossaryImport.*`. `plugins/i18n.ts` registers it. `es`, `fr`, `ru` and `tr` fall back to English.
- `GlossaryImportDocumentSections` has a `section` slot for the later policy task's publication state. It labels the text "Kept on this draft" and claims nothing about publication.

## Backend gaps, not worked around

| Need | Exact addition | Effect today |
| --- | --- | --- |
| Re-map without the file | `POST markdown-imports/{id}/remap` with `mapping`, or `markdown` on the resource | "Upload again with a mapping" prefills everything, but the reviewer must supply the file again |
| Search entries | `?search=` on `GET markdown-imports/{id}/entries` | A 188-entry draft can only be filtered by kind and state |
| Created or returned | `created: bool` on the store response (the 200 and 201 are not visible through `ApiClient`) | The UI compares the ID with the recent list, so a draft older than the first 10 reads as new |
| Discard a draft | `DELETE markdown-imports/{id}` | Only a new upload replaces one |
| Inherited provenance | Inherited reads on `revisions` | An inherited glossary shows a read-only note, its imports stay with the team |
| Search wording in other languages | `GET terms` matches `translations` | Link search finds terms by their own wording only |
| Actor names | `created_by` and `published_by` as names | Not shown, the fields hold IDs |

## Checks

| Check | Result |
| --- | --- |
| `bunx vitest run` on the new tests: review (8), dialog (4), glossary detail (2), helpers (8) | Pass |
| Related: `tests/js/components/ai-settings`, `tests/js/lib`, `tests/js/plugins`, `tests/js/composables` | 161 files, 4486 tests, all pass |
| `bunx vue-tsc --noEmit` | No errors |
| `bunx oxlint --deny-warnings` on changed paths | Exit 0 |

The review tests cover pasting into a draft, resolving and linking, a selected partial publish that keeps the rest, removal confirmation, stale refresh with no retry, read-only scopes and parser warnings. The upload path through `FormData` is typed but only pasted input is driven in tests.

## Visual verification

The repo has no Playwright setup. A scratch Playwright script, since deleted, drove the real app from a private Vite instance on port 3417, with every API call answered by synthetic fixtures. The shared dev server was not touched.

Looked at: the draft page at 1440 dark and light, 1024 dark, 768 light and 390 dark; the entry sheet, link picker, selection bar and publish dialog at 1440 dark and 390 dark; the glossary page at 1440 dark and light and 390 light; the revision sheet at 1440 dark and light; the import dialog at 1440 dark and light. No page overflow at any width.

That found and fixed a truncated panel title and an overflowing selection bar at 390px, a wrapping badge, cramped decision cells on narrow screens, and a stray space in a label.

Not looked at: the error and stale notices, the published notice and German text. Those states are covered by tests only, so their appearance is unverified.

## Not exercised

No end-to-end run against the real backend, because this checkout has no PHP dependencies or database and the task allows no live migrations. Response shapes come from the committed resource files and were hand-copied into fixtures, so a mismatch would surface only at integration. The first thing to run after merging is one real upload, resolve and publish.
