# W3 operation plan, coverage and retry UI

Frontend slice of the milestone, not the whole contract. Base commit `32a01b9f`. No backend, migration, generated spec, MCP registry, `plugins/i18n.ts` or shared locale change. It does not complete semantic review, policy impact discovery, composition or API/MCP parity.

## Behavior

**Plan editor.** `editablePlan`/`planPayload` carry `outputs` and `metadata`. Null keeps the key out of the payload, so a plan saved without them keeps its hash and stays unverified.

- A plan written from scratch starts explicit: assets with `{fields: [], tags: true}`, a `meta-tags` general task on content with title and description. Other general tasks start without requirements.
- An existing or AI-drafted plan keeps what it says. The editor can turn "Account for each value" or "Generate page metadata" on.
- Assets pick fields from the scope's folders and tags. Tags are forced on and disabled when the plan removes or plans tags, because the server refuses that otherwise. Languages stay in the existing fieldset.
- Metadata picks values (`title`, `description`, `ogTitle`, `ogDescription`), values it may replace, meta fields and entry languages. None picked means all, and the copy says so. `overwrite_keys` is limited to `keys` in the payload.
- Meta field options come from the existing blocks list (`per_page: 100`), filtered to `counts.groups` and `type: meta`. A block past that page has no checkbox, and "none picked" still covers it.
- Server errors for `outputs` and `metadata.*` show on their controls.

**Approved plan.** The read-only plan shows the same requirements as text. A plan with none says results are counted per entry and don't show each field.

**Ledger.** An entry with outputs gets one line, for example "3 of 5 outputs accounted for. Needs an answer: 1", and opens into rows grouped by language. Rows show outcome, reason, note and question. Translation rows show units proposed and remaining and name the open units. Metadata rows read "Search listing: Title". Pagination is unchanged. Rows use the page already loaded.

`outputRows` mirrors `OutputCoverage`: an owed part with no record is `open` while queued and `unfinished` afterwards. Translation entries owe the approved locales unless `expected_parts` says otherwise.

**Receipt.** New section under progress. `operationCoverage` reads `counts.coverage`, never `state`:

| State | When |
| --- | --- |
| `unverified` | no outputs named, `verified: false`, or settled with no stored coverage |
| `counting` | running with outputs named. Figures from an earlier pause are not shown as current |
| `complete` | `complete: true` |
| `incomplete` | `complete: false`, worded per state, exceptions listed first |

A completed operation whose coverage is `false` no longer reads "Done" (new outcome `completed_with_gaps`). An unverified finished operation still reads "Done", with "Coverage isn't verified" directly below it. Proposals, waiting, accepted as drafts and rejected are separate figures, summed from the review groups, and the copy says publishing is a separate step. Blocked outputs show a count and their questions, and the copy says answering isn't available yet. There is no submit control.

**Retry and spend.** `provider_retry_scheduled` is parsed and exposed as `useAiRun().providerRetry`. It is cleared when the run moves on, keeping only unconfirmed calls. `AiProviderRetryNotice` shows the actual time, the attempt and the unconfirmed calls under the budget and in the thread work log. Stored run state has no maximum, so it reads "Attempt 2"; the live event reads "Attempt 2 of 3". `runCostStatus` never returns `confirmed` with unconfirmed calls, and the notice then says "Known costs are confirmed, but...". A stored `provider_unavailable: <ref>` shows the existing localized message.

Running operations fetch their current run every 4 s through a new `useRunQuery`, for the wait state.

**Deferred on purpose.** D10 behavior, grouped answers, acceptance UI, confidence scores and spending guarantees.

## Messages

`resources/js/components/ai/operations/i18n/{en,de}.json`, loaded by `useOperationI18n` as a local `vue-i18n` scope that follows the app locale. `es`, `fr`, `ru` and `tr` fall back to English, which a test covers. No registration is needed. Folding them into the shared catalogs later means nesting them under `components.aiOperations` and translating the other four.

## API contracts consumed

`plan.outputs`, `plan.metadata`, `counts.coverage`, `counts.provider_retries`, `counts.unconfirmed_provider_calls`, item `parts` with `note`/`question`, `expected_parts`, `overwritable`, `units`, run `provider_retry`, `usage.cost_confirmed_at`, event `provider_retry_scheduled`. The types from the parent's `aiOperations.ts` and `aiRun.ts` patches are in this branch. Named additions (`AiAssetOutputs`, `AiMetadataOutputs`, `AiOperationStoredCoverage`, `AiOperationPart`) have the same shapes. `units` is also typed as `[]`, since an unwrapped empty PHP array may arrive that way; the parent's resource casts to object, so both work.

## Integration requests

1. Commit the parent's resource patches. Without them this branch's backend serves no `expected_parts`, `overwritable`, `units`, `provider_retry` or `cost_confirmed_at`: translation rows still work from `parts`, asset and metadata rows fall back to recorded parts only, and no run wait or per-run confirmation shows.
2. When reconciling `aiOperations.ts` and `aiRun.ts`, take this branch's version. It contains the parent's additions.
3. Add an item filter by part outcome (for example `?outcome=blocked`) to `AiOperationController` items. A 10,000-entry ledger has no way to jump to a `blocked` part on a `proposed` entry today, so the receipt can only point at the ledger.
4. Expose operation-level confirmed spend. `budget.spent_usd` has no confirmed flag, so the operation shows the unconfirmed caveat but cannot say estimated or confirmed. Per-run status is computed and shown in the notice only when a run has unconfirmed calls.
5. Expose `max_attempts` in the run's `provider_retry` so a stored run can read "Attempt 2 of 3".
6. Live `counts.coverage` while running (W2 request 4) would replace the `counting` line. Not needed for correctness.

## Checks

From this checkout with its own `bun install --frozen-lockfile`.

```text
bunx vitest run tests/js/lib tests/js/components/ai tests/js/composables/useAiRun.test.ts   64 files, 999 tests passed
bunx vue-tsc --noEmit                                                                       clean
bunx oxlint --deny-warnings <every changed .ts and .vue>                                    clean
```

New tests: `tests/js/lib/aiRun.test.ts`, `tests/js/components/ai/threads/AiThreadWorkLog.test.ts`, additions to `aiOperations.test.ts`, `AiOperationPanel.test.ts` and `useAiRun.test.ts`.

## Visual check

The repo has no e2e or fixture mechanism. I mounted the real `AiOperationPanel` and `AiThreadWorkLog` with the real CSS in a throwaway Vite page with a mocked API, driven by Playwright 1.64 on the cached Chromium. PID 70864 was stopped and the fixture deleted. No preview tools were used.

Inspected: asset plan (dark 520), legacy asset plan (light 520), metadata plan (German, dark 390), partial assets with ledger open (dark 520, light 390), paused translation with units and provider error (dark 520), running with retry wait (light 520), unverified general (dark 520), complete (light 520), work log wait (dark 520). `overflowX` was false in every final capture, in the dark and light runs and at 390 and 520 px.

Found and fixed by looking: a long entry name widened the whole panel because the grid tracks had no `min-w-0`, and outcome words were capitalized mid-sentence in the summary line.

Not checked: 768, 1024 and 1440 widths, which the dock doesn't reach at this panel's width; the panel inside the real dock; real data; the authenticated app.

## Not verified

The real app with a backend, real providers and real retry timing. `field.name` as the meta field label was read from the frontend schema type and exercised on fixtures only. Editorial wording of the German copy was not reviewed by a native reader.

## Follow-ups noticed

- Unchecked switches are very faint in light mode (the existing `Switch`), visible on "Remove tags". Not changed here.
- `common.select` is missing from the English catalog and warns during ledger rendering. It predates this slice.
