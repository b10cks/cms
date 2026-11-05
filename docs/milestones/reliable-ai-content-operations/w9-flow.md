# W9 F1 flow

Implements [W9](w9-simple-glossary.md) task F1. Built on `79d31bb3`. Starting a task is one action, the copy says what happens, and a person publishing a page sees what AI flagged on it.

## Decisions for Mike

- **Publishing clears a flag.** The task names one way a flag clears: the wording leaves the field. I added a second: publishing the draft that holds it, because a person just read the warning and went on. Without it, wording someone chose to keep would mark the tree and hold a thread in "needs you" forever, with nothing to dismiss. Say if you want flags to survive a publish. Then a dismiss, or some other way out, has to exist.
- **A cleared flag stays cleared.** Restoring an older version that still has the wording raises no new flag. Keeping it live would mean re-checking every old item on every save.
- **An open conflict refuses writes from any task,** not only the task that hit it. An old conflict nobody decides keeps that field closed to agents. That is what "stays open for a person" says, and the tree shows it. W8's table row "an old conflict in another changeset no longer holds up a new task" no longer holds.

## Behaviour as built

**Copy.** `AiBulkDialog` reads from `components.aiBulk.descriptions.<surface>`: content says it is written to the drafts straight away and nothing is published, assets and data entries say changes wait for you. "Start on 1 entry" is plural aware in all six catalogs. An estimate no longer reads $0.00: under a cent reads "Under $0.01", and zero reads "Not known for this model", since zero means no price is known. The grep found three more stale lines, all fixed: `cancelConfirm` in `fr`, `es`, `ru`, `tr` ("stay for review", already right in `en` and `de`), and the schedules description in `en`, `de` and the four others ("puts its proposals in the inbox", and the inbox is gone). `pending_review` ("Another proposal is waiting for review") stays, since it is true of proposals. `fr`, `es`, `ru` and `tr` carry English for the new keys, like their neighbours. Nobody reviewed them, and nobody reviewed my `de` copy either.

**One Start.** "Plan as operation" is gone. `lib/aiBulk.ts` decides: past the run's entry limit (`truncated`, against `EntryScope::MAX_ENTRIES`, 500) or estimated above `max_usd`, the button says "Review plan", one line above the footer says why, and it opens `AiOperationDialog` with the prompt and skill carried over. There is no new $2.00: `max_usd` is `config('ai.runs.max_usd')`, the figure "The run stops at" reads. Everything else runs as before.

A filter scope with no IDs, such as a whole data source, could not reach the plan before. It can now, since the operation API takes `filters`. Other entry points to `AiOperationDialog`: `AssetFolderTree` opens it directly for a folder. It is single-purpose, so it stays. `ContentTree`, `AssetGrid` and the data source page reach it only through `AiBulkDialog`.

**Flags.** A stored count, `ai_changeset_items.open_findings` (migration `0000_00_00_000028`), because thread status is SQL. `needs_review` and the header's attention count run in the database, so a PHP wording check could not feed them without breaking the list's order and limit. That made the column the simpler design.

- Set in `AcceptAiChangesetItems`, the one write path, to the item's blocking findings. A newer write of a field closes earlier flags on it, including overlapping block list paths, so wording kept through a correction counts once.
- `Content`'s `saved` hook calls `ContentFlags::versionMoved()` when `current_version_id` or `published_version_id` changes. If the draft is the published version, flags close. Otherwise it re-reads the document and checks each finding at its path: banned wording stands while it occurs, in any case, and a protected name stands while it is missing, in exact case. Cleared findings get `cleared: true` and stay on the item. All version moves save the model, so all paths are covered, including restoring a version, publish, schedule and release. `RunSpaceMigration` writes rows without events, and migrated changesets are not part of it.
- Soft-deleting an entry closes its flags.
- The hook runs in `rescue()`: a flag's bookkeeping never fails a person's save.
- `AiChangesetItem::standingFindings()` is the one reader. The item resource, the receipt's counts and the thread card all use it, so a cleared flag disappears everywhere.

**Tree.** `GET ai/content-flags` returns figures per entry, whole space, because the tree loads the whole menu at once. It is kept out of the cached menu, so a flag change needs no menu cache bust. A row shows a 14px triangle in `text-warning-ink` with `role="img"`, an aria label and a tooltip such as "1 conflict, 3 flagged wordings". A translation's flags show on its entry's row, since translations have no rows of their own. Figures refresh whenever the tree's menu data changes, which every save and broadcast does, and on any thread decision.

**Publish.**

| Path | What it does |
| --- | --- |
| Editor Publish, Cmd+Shift+P | Asks for fresh figures. With flags, opens the publish dialog with the warning. Without, publishes at once as before |
| Tree Publish, its shortcut, a multi-selection | The same |
| Publish with message, schedule (editor and tree) | Warning in the dialog |
| Publish with children | Count in the existing confirm |
| Version history, publishing the current version | Confirm with a count. An older version gets none, since flags describe the draft |
| Mass edit, save and publish | Confirm with a count |
| Release commit, which is what publishes a release | Confirm with a count, from `GET ai/content-flags?release_id=` |

The warning (`ContentFlagsNotice`, in the shared `Alert`) reads `Flagged: "klimaneutral" in Title`, with the replacement if the glossary has one, and "You can still publish." A flagged row links to `/content/<id>?field=<path>`, and the editor opens the block and focuses the field through the existing `selectField`. A conflict row opens its thread in the dock, where it is decided. No gate was added on the server or the client.

**Needs you.** `AiConversation::reviewSql()` and `entry_count` count a changeset with open flags. `DecidableItems` stays as it is, because flagged wording is no decision item. The thread card has no Accept for it and links to the draft. `ContentFlags` broadcasts `AiThreadChanged` when a flag clears, so the dock updates without a refresh.

**Retries.** `ProposalTool::leftForPerson()` finds open conflicts on the entry that overlap the fields a call names, from any changeset. `propose_update`, `propose_translation`, `propose_create` with `translation_of` and `propose_translation_metadata` refuse those fields and write the rest. The result keeps the existing shape: `conflicts` with the open item's ID, and `conflict_note`, which now says not to write again. `ChangesetRecorder` no longer deletes a conflict on a new write. The tool descriptions, the operation prompt and the `meta-tags` and `find-untranslated` skills say the same. `translate-fields` never mentioned conflicts.

The ledger: an entry that is one piece now settles as `conflict` when any field in the call was held, where before it settled as `written` if any other field landed. That was a dishonest edge in W8 and "stays honest" covers it. Metadata and translation units report `conflict` per part, and a metadata result's `written` no longer lists held keys.

**Data entry translation.** The stream's `done` payload gains `glossary_findings`, collected from each batch run's own result. Nothing else in the stream changed, and text still saves directly. The data source page shows them in `AiRunFindings`, the same alert `MetaBlock` uses.

**Receipt.** `GET …/changes` entries gain `flagged_wording`, and the row reads `Flagged: "klimaneutral", "gratis"` without expanding. The list is cut off with a title tooltip when long.

## Query cost

Scratch MySQL 26.10.0, InnoDB, synthetic table of 200,000 items with the migration's indexes, 4,000 flagged and 400 conflicts, far more flags than a real space should hold. EXPLAIN and `SHOW PROFILES`:

| Query | When | Plan | Time |
| --- | --- | --- | --- |
| Flagged per entry | Tree load | Range on `(open_findings, target_id)` | 36 ms at 4,000 flagged rows |
| Conflicts per entry | Tree load | Range on `(status, target_id)` | 3 ms at 400 rows |
| Open items of one entry | Every version move | Lookup on `(target_type, target_id)` | 0.2 ms |
| Open flags of one changeset | Per thread row in status | Covering range on `(changeset_id, open_findings)` | Reads 40 rows here, not timed |

Cost follows the flags, not the space: no document is opened, and a test pins two queries however many entries are flagged. It is its own request and never holds up the tree.

**Cost on every save.** One extra indexed read per version move. `ContentPublishingVersioningTest` pins exact budgets, so I moved them from 18 to 19 and 28 to 29 and renamed the tests. This is a deliberate change to a pinned budget, so please look at it.

## Deploying

Space migrations don't run on deploy. `0000_00_00_000028` needs `spaces:repair-databases` by hand after tagging, like every migration on this branch. Until then AI writes and the thread list fail on the missing column. People can still save, because the hook's failure is rescued and reported, and they can still publish, because a failed flag check lets the publish go on. No backfill: items written before this column were accepted by a person.

## Checks

```text
vendor/bin/phpunit tests/Feature/Ai tests/Feature/Mgmt tests/Unit/Services/Ai tests/Feature/Mcp tests/Feature/Documentation   OK, 1158 tests, 8122 assertions, 1 skipped
bunx vitest run tests/js/components/ai tests/js/lib tests/js/composables                                                     173 files, 4,581 tests passed
bunx vue-tsc --noEmit                                                                                                        clean
bunx oxlint --deny-warnings on changed paths                                                                                 clean
vendor/bin/pint --test on changed PHP                                                                                        passed
php artisan docs:generate                                                                                                    spec regenerated
```

`tests/js/components/ContentTree*` matches nothing: no test mounts the tree. The mark's data and label are tested in `lib/aiContentFlags`, and I looked at the rendered mark.

**Visual.** Real `ContentTree`, `AiBulkDialog` and `PublishDialog` with real CSS on a throwaway Vite page on port 5198 with a mocked API, cached Chromium. Viewed: tree mark light and dark, Ask AI with a tiny estimate, "Review plan" with its line, German Ask AI with an unknown price, the publish warning light, dark and German. No console errors from the code under test. Looking found one defect: the warning used the alert's `text-warning`, too faint on light per DESIGN.md, so its text is now `text-warning-ink`. My server is stopped and the page was outside the repo.

## Not exercised

- **The authenticated app.** The tree was rendered with a mocked API, so these were never clicked in a browser: the tree's Publish opening the dialog, the editor's Publish, `?field=` focusing a field, and the conflict row opening the dock. `?field=` has unit tests for the path and reuses `selectField`, but no test mounts that page, and I never saw it focus a field.
- **Version history, mass edit and release confirms.** They typecheck and use the same helper, but no test or browser ran them.
- **The data source page's alert.** The stream is tested end to end, the alert component is tested, and the page wiring only typechecks.
- A real Reverb broadcast, so the dock and tree updating live. Real providers, so whether a model obeys `conflict_note`. Refusal in code holds either way.
- Tests ran on SQLite. On MySQL I measured the queries on a scratch table, not the real thread status SQL, which only ran on SQLite. Postgres spaces were not run.
- `propose_translation_metadata` refusal has no test of its own. `propose_update` and `propose_translation` do, and it shares `leftForPerson()`.

## Limits

- A publish warning can name at most 50 entries' details, and shows six rows with "and N more". The count is always right.
- A create item's flag (a new locale entry) checks the whole document, since it has no path. It names no field, and if the same wording later comes through a field write it counts twice.
- A conflict stays when a person edits the field themselves. It is only closed by a decision, as before.
- A conflict on an entry that was later deleted still holds its thread in "needs you", as before: it can be rejected from the thread.
- The field-level meta conflict check is by field path, not by key, so one conflict on a meta field holds all its keys.

## Follow-ups noticed

- `ai/content-flags` isn't in the MCP registry or the SDK. SDK changes are out of scope for this milestone, so say if the local MCP needs it.
- `releases.vue` has a `handlePublish` that nothing calls, and its cancel and delete confirms carry hardcoded English.
- `Content`'s `saved` hook reads `isDirty('slug')` after save, worth checking on its own.
- `docs/STATUS.md` still lists "whether flagged wording counts as needs you" and "whether a retry may overwrite" as open. Both are settled by revision 4. I left the file to whoever integrates, since S1 runs in parallel.
