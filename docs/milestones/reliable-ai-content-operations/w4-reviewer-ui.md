# W4 reviewer UI for semantic review and overrides

Frontend slice on `1cf02857`. It does not complete the brief. No backend, shared locale JSON, `plugins/i18n.ts`, generated spec, MCP, operation panel or receipt file changed.

## Behavior

**Review state.** `AiSemanticState` shows one status per item: running, no conflicts, flagged, couldn't finish, no review needed, not reviewed. A proposal with no `review.semantic` reads as not reviewed. Findings show the rule, supporting quote and explanation. Inferred audience, product, evidence and uncertainty show below them. Limitations use local copy for known codes and the server's message for others, and an unattributed finding keeps its raw claim. No percentages or scores. Snapshot, rule version and review time sit behind a "Knowledge used" line, with `review.policy` and a `derived_from` count. Asset `review.evidence` shows as one muted line. `derived_from` is only displayed: D10 stays open.

**Deterministic findings.** An open item's findings of the applied text each take a reason field. Accept sends nothing while a reason is blank or whitespace, then marks the fields and focuses the first. There is no tick. Reasons are trimmed and only reasons for findings on screen are sent. They stay until the item is decided, so a conflict repost sends them again. A decided item shows its committed `review.overrides`.

**Edited text.** `itemTextReview` compares the edit with `semantic_edit.value`:

| Phase | Meaning | Accept |
| --- | --- | --- |
| `proposal` | Text is the proposal, its own result applies | Enabled |
| `unchecked` | No result speaks for this text. The proposal's result is not shown as its result | Enabled, posts values |
| `rechecking` | Under review, or just sent and no refetch has shown it | Disabled |
| `rechecked` | Result for this exact text | Enabled, needs a click |

- Nothing accepts by itself. Polling only updates what shows. The second post sends the same values and reasons.
- Changing the text after a result returns to `unchecked`, so the result stops showing and the next accept starts a new check.
- A 422 on `overrides.<item>.<finding>` shows its fields with no toast, using the item's finding if it has that ID and the server's message if not. Those findings bind only the text they were raised for. A proposal's findings don't bind an edit, and show as "found in the proposed text".
- A stored `semantic_edit.value` returns to the editor after a reload. Discarding it is remembered, so a refetch doesn't restore it. Reasons do not survive a reload: the server names the findings again, so there is no loop.
- Each accept ignores a repeat while a post is in flight, and the text box is disabled during it. A late `rechecking` response is tied to the text it sent.

**Bulk.** `needsIndividualLook` mirrors the server: any `semantic_edit`, or a status other than `clean` and `not_applicable`. A legacy item stays eligible, as on the server, and still reads as not reviewed. Individual accept of flagged, limited or pending items needs nothing extra.

- Accept all sends edits only for eligible items, keeps edits the server left open (they used to be cleared), and reports `rechecking` in a toast.
- A group accept posts each unsent edit in its groups first. If one needs a reason it stops and warns, because that edit holds no server state and the group accept would apply the unedited proposal over it. Edits already sent are not reposted, and the server keeps them out of the group.

**Refresh.** `reviewPollInterval` drives the thread, review group and review item queries: 3 s for an edit under review, 15 s for a proposal's pending review, because a paused operation can hold one for a long time. Thread broadcasts still refetch too.

**Memory output.** `AiRunSemanticNotice` shows `result.semantic_review` in `FlattenedLocalization` and the mass edit page, the two `translate` surfaces, with findings and unverified terms per unit. It polls the run while pending, because the review lands after the text is already in the editor. A run with no such result shows nothing. Selection rewrites and provider-written inline translations start no review. Their items read "Not reviewed" in thread review.

## Files

`lib/aiChangesets.ts`, `lib/aiRun.ts`, `composables/useAiThreads.ts`, `components/ai/AiChangesetItem.vue`, thread `AiThreadChangeset`, `AiThreadEntryCard`, `AiReviewItemList`, `AiReviewGroup`, `AiOperationReview`, plus `FlattenedLocalization.vue` and `mass-edit.vue` (notice only). New in `components/ai/threads`: `AiSemanticState`, `AiItemReview`, `AiFindingOverrides`, `AiSemanticSummary`, `AiRunSemanticNotice`, `useItemDecisions.ts`, `useReviewI18n.ts`, `i18n/{en,de}.json`.

Edits moved from `v-model:edits` through four components into `useItemDecisions`, which `AiThreadChangeset` and `AiOperationReview` each provide. The operation panel mounts `AiOperationReview` on its own, so it can't rely on a parent. `aiThreads.ts` needed no change. `es`, `fr`, `ru` and `tr` fall back to English.

## API contracts consumed

Accept `overrides`, `rechecking`, keyed 422s, `glossary_findings[].id`, `review.semantic`, `semantic_edit`, `overrides`, `evidence`, `policy`, `derived_from`, flag codes (shown through the state, not repeated as notes), run `result.semantic_review`. No backend flaw blocks this.

## Integration requests

The operation delegate owns these files, so nothing here touches them.

1. Receipt counts, in `AiOperationReceipt.vue`:
   ```vue
   <AiSemanticSummary v-if="operation.counts.semantic_review" :counts="operation.counts.semantic_review" />
   ```
   Add `semantic_review?: Partial<Record<AiSemanticStatus, number>>` to the counts type in `aiOperations.ts`. Keep it apart from `counts.coverage`. These are stored counts, and edit rechecks don't update them.
2. Panel wording: while `current_run_id` is a review run (`setup.operation.phase: review`), don't call it a batch. That needs `setup.operation.phase` on the run resource if it isn't there. I did not check this against the parent's uncommitted resource patch.

## Checks

```text
bunx vitest run tests/js/lib tests/js/components/ai tests/js/composables/useAiRun.test.ts   69 files, 1,052 tests passed
bunx vue-tsc --noEmit                                                                       clean
bunx oxlint --deny-warnings <changed files>                                                 clean
```

New: `tests/js/components/ai/threads/AiChangesetReview.test.ts`, additions to `tests/js/lib/aiChangesets.test.ts`. The wider run caught one real crash, `AiOperationPanel` mounting the review without its state, fixed as described above.

## Visual check

Real components and CSS in a throwaway Vite page with a mocked API, driven by Playwright on headless Chromium. Own server PID 93070 on port 5199, stopped, fixture deleted. PID 79171 on port 3000 was left alone. No preview tools.

Inspected: all review states (dark 520, light 390), reason errors and a 422-named finding with focus (dark 520), rechecking, rechecked and limited edits (German, light 520), accepted override and three asset evidence kinds (dark 390), summary card (light 520), memory notice (German, dark 520), knowledge disclosure (dark 520). No horizontal overflow and no console errors.

Fixed by looking: a doubled period in the kept-finding line.

## Not verified

- The real app with a backend, real broadcasts and real review runs.
- The notice inside `FlattenedLocalization` and the mass edit page: typechecked, not rendered there.
- The review inside the real dock, and widths other than 390 and 520.
- German wording has not been read by a native speaker.

## Known limits

- An Accept all that leaves an edit open because of findings says nothing, since the response doesn't count those. The edit stays on its card, and accepting it individually names the findings. A `left_open` count would fix this.
- `semantic_edit` holds one state. If a second reviewer edits the same item, the first reviewer's text reads `unchecked` again.
- A limited review has no retry control. Editing the text is the only way to start another, as the backend notes.
- The 422 message for a new finding is server English until the server returns the finding itself.
