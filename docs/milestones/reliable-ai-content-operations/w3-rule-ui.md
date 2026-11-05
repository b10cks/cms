# W3 document rule UI evidence

Built on integration commit `7ed2d8ca`. Frontend only. It connects the import review to the document rule backend from `1e28a094`. One slice, it does not complete the brief. No backend, generated spec, MCP registry, operation UI or big locale file changed.

## The problem this closes

The review page labeled document sections "Kept on this draft" and its publish request sent no `document_rule_keys`. The newer backend reads that omission as "publish every added or changed rule", so publishing one term would have published rules nobody reviewed.

`PublishGlossaryImportPayload` now requires all three rule lists, so TypeScript refuses a call that leaves one out. A term-only publication sends `document_rule_keys: []`.

## What an editor can do

- On the draft page, read a **Document rules** panel. It replaces "Document-wide text". Open rules come first and start expanded, then declined ones, then unchanged ones.
- See a changed rule's published text next to the uploaded text. An added rule shows the upload, a removed rule shows the published text. A rule with a published version links to its revision.
- Decide each open rule with one of three radios. The default, **Decide later**, keeps it in the draft. **Publish** or **Remove** selects it. **Keep the published text or rule** (**Do not publish** for an added rule) declines it.
- Choose **Publish** on a rule declined earlier. Leaving it alone keeps it declined. The backend accepts this because the keys are explicit.
- Publish rules alone, terms alone, or both. **Select all that can be published** still covers terms only.
- In the publish dialog, see terms, rules to publish, declined rules and how many stay undecided. Term removals and rule removals have separate fieldsets, each with a tick per item. Unticked rule removals stay in the draft.
- See rule counts in the published notice, the revisions table and the revision sheet. The sheet shows each rule's path, action, source line, and text before and after.
- See open rules in the imports table, so a draft holding only rules no longer reads "No entries".

## Rules the UI keeps

| Situation | Behavior |
| --- | --- |
| Opening the dialog | Re-reads the draft, bypassing the cache, and the selected entries. The dialog and request use those fresh rows |
| Re-read finds a different `base_revision_id` | No dialog. The `stale_base` notice shows, decisions stay on screen, Publish is disabled |
| Re-read finds a closed or replaced draft | The `import_closed` notice shows and the controls go away |
| A rule changed after it was decided | The stored copy of the rule no longer matches. The row is flagged, the dialog names it, and it is not sent until decided again |
| `stale_base` or `stale_entries` from the server | No retry. Refresh is manual, as before |
| Refresh | Clears term selection and rule decisions, with the existing "check your selection again" notice |
| Base sent | The base shown when the dialog opened, never the head |
| Read-only (no manage ability, inherited, closed) | Both sides and the revision link show, with no radios |

Existing term selection across pages, stale entry marking and term removal confirmation did not change. Nothing depends on `useAiEnabled` and the styling stays neutral.

## API integration

- `GlossaryImportResource.document_rules`, typed from `DocumentRuleDiff::of`.
- `GlossaryRevisionResource.document_rule_summary`, and `document_rules` on show and publish, typed from `AiGlossaryRevisionResource` and `PublishGlossaryImport::writeRules`. An empty summary arrives as a JSON `[]`, which `ruleSummary` reads as no changes.
- `document_rule_keys`, `confirm_document_rule_removals` and `dismiss_document_rule_keys` on publish. No new endpoints.
- `useAiGlossaryImports().fetchDraft`, an uncached read that updates the page's draft.

## Decisions worth a look

- **Rule decisions take effect at publication.** The only dismissal API is on publish, and applying each click at once would create a revision per click. Declining therefore goes through the same dialog.
- **A decline-only publication creates a revision with no changes.** That is what `PublishGlossaryImport` does. The dialog says so before confirming and the button reads "Decline 1".
- **`GlossaryImportDocumentSections.vue` became `GlossaryImportDocumentRules.vue`.** Every section has a rule row and removed rules have no section, so the component reads rules only. Its `section` slot and the `glossaryImport.sections.*` keys are gone. `document_sections` stays on the type because the API still returns it.

## Checks

| Check | Result |
| --- | --- |
| `bunx vitest run tests/js/components/ai-settings tests/js/lib/aiGlossaryImport.test.ts` | 7 files, 38 tests pass |
| Focused share: review 18, dialog 4, glossary detail 2, helpers 11 | 35 tests pass |
| `bunx vitest run tests/js/plugins` | 5 tests pass |
| `bunx vue-tsc --noEmit` | No errors |
| `bunx oxlint --deny-warnings` on changed `.ts` and `.vue` files | Exit 0 |
| `en` and `de` module keys compared | Identical |

The 10 new review tests cover: term-only publication with explicit empty lists, select-all leaving rules out, rules-only publication with undecided rules staying, separate rule removal confirmation, decline through dismiss keys, publishing a previously declined rule, a rule changing before the dialog, a moved base found by the re-read, a stale refusal with no retry followed by refresh, and read-only provenance with before and after.

The UI's payload shapes also ran through the rules parsed from `PublishAiGlossaryImportRequest` with a real Laravel validator, no HTTP or database. Term-only, rules-only, rule-removal-only and decline-only pass and `document_rule_keys: []` survives validation. An all-empty payload is a 422, and the UI disables that button. `AiPolicySnapshotTest` covers the backend side of these on real HTTP.

## Visual verification

A scratch Vite on port 3418 (PID recorded, stopped afterward) mounted the real components with the real stylesheet and i18n, and scratch Playwright answered the API with synthetic fixtures. This was not the full app shell, so sidebars and page layout were not in the picture. The script and its files are deleted. The dev server on port 3000 was not touched.

Looked at, no page overflow at any width:

- Draft page: 1440 dark, 390 dark, 390 German light. 1440 light was captured but seen only blurred behind the revision sheet
- Decided rows: 1440 dark
- Publish dialog with terms, rule removal and decline: 1440 dark. Without terms: 390 dark
- Moved rule dialog, re-read stale notice, published notice: 1440 dark
- Read-only: 1024 light
- Revision sheet: 1440 light. Dark was captured but not opened
- Imports and revisions tables: 1440 dark

That found and fixed sentences running together in the published notice and a rule counted as both moved and undecided. It also showed a dead `flex` class on the radio group, because `cn()` does not merge utilities. The vertical layout reads well, so the class went.

Not looked at: German dialog text, the `stale_entries` notice with rules present and the decline-only result. Tests cover those states, so their appearance is unverified.

## Not exercised and known limits

- No end-to-end run from this UI against the real backend. Response fixtures were hand-copied from the resource files. The first thing to run after merging is one upload, then a rule-only publication, in a real browser.
- A revision does not record declines, so a decline-only revision reads as changing nothing. The draft row keeps the Declined state. Recording them needs a backend change.
- Dismissals carry only to a superseding draft, as the backend documents. A declined removal is proposed again by a later upload after the draft closes.
- A renamed heading reads as one removal plus one addition, because the rule key comes from the heading path. Both need a decision.
- Rule text changes show side by side, without a word diff.
- There is no bulk action for rules, so a document with many sections takes one decision each. A "select all added and changed" button would be small, and is left out to keep this slice to explicit review.
