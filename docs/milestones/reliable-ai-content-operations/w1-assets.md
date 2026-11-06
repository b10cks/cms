# W1 asset evidence and output

Foundation slice of the milestone, not the whole contract. Base commit `5cf074f0`.

## Behavior

**Several inspections in one turn.** `InspectAssetsTool` no longer clears its buffer in
`execute()`. Images buffer by asset ID until `takeImages()`, so skipped files and error
results in the same turn lose nothing. Re-inspecting an asset replaces its image. A turn
attaches at most 12 assets. Further IDs come back under `deferred` with no ledger outcome,
because they are unfinished, not skipped.

**Delivery evidence.** `InspectionImages::delivered()` reads the checkpointed transcript.
An asset counts as delivered when a server-built user message carries its `asset_id` and a
later assistant message exists, meaning the provider answered that request. Tool results
and model text never count. Compaction keeps `asset_id` and `sha256` on the text note, so
evidence survives compaction, checkpoints and recovery.

**Proposal gate.** `ProposeAssetChangesTool` requires delivery for entries that change
something, and refuses per asset under `errors` with no ledger outcome. This also refuses
a proposal in the same turn as its inspection, and one after a crash lost the buffer. The
model then inspects again. An entry that changes nothing and `unchanged` need no image.
Nonvisual changes declare `basis`:

- `metadata` with `sources`: each must hold existing text and not be a patched field.
- `instruction` with a verbatim quote of the user's request or answers. Fenced context,
  documents and image labels do not count.

Each change records `review.evidence`: `{basis: image, run_id, message, sha256}`,
`{basis: metadata, sources}` or `{basis: instruction, quote}`.

**Automatic classification report.** `ClassificationReport` accounts for every requested
key: `written`, `kept`, `intentionally_empty` (decorative alt), `uninferable` (model
returned an empty string), `rejected` with a reason, and `missing`. It also gives `status`
(`complete`, `partial`, `none`), per-language status and `open` fields (`satisfied` when
nothing was requested), and tag detail: `written`, `unknown`, `malformed`, `taxonomy`.
Valid values still save, and existing values stay protected. A value with Avoid wording of
its language is `rejected` with what to write instead, see [W9](w9-model.md#where-tasks-meet-the-glossary).

Where to read it:

- `ai_runs.result.classification` for every classification that got parseable output.
- `metadata.ai_classification.report` on the asset, the open part only, when it was saved.
- `GET /mgmt/v1/ai/assets/classify/{run}`: additive `field_report`, `null` until the run is
  complete, then status counts plus at most 100 open assets and `truncated`.

`classify()` still returns `updated` or `skipped`, `parseResponse()` keeps its shape and
the run counters are unchanged. `updated` includes partial output.

**Taxonomy.** `get_asset_library` keeps `folders` and `tags` and adds `folders_page`,
`tags_page` (`total`, `offset`, `returned`, `next_offset`, `truncated`), `proposed_truncated`
and the inputs `tag_query`, `tag_offset`, `folder_query`, `folder_offset`. Folders now sort
before slicing. The automatic prompt keeps its 200 limit. Above it, tags matching words of
the filename, folder, metadata and placement context come first, then the most used. The
prompt states the subset, a named tag outside the list resolves against the database, and
the report carries `taxonomy` and `unknown`.

## Checks

Run with a temporary `APP_KEY` and a real copied `vendor/`, no `.env`. PHPUnit warns about
the missing `.env` on every test; that warning is not from this change.

- `php artisan test --filter='AiAssetOperationsTest|AssetClassification'`: 54 tests, 272
  assertions, no failures, run twice.
- `php artisan test tests/Feature/Ai`: 126 tests, 759 assertions, no failures.
- `vendor/bin/pint --test` on the changed PHP files: passed.

New tests cover: three calls in a turn with a skip and an error, the turn limit, refusal
in the same turn then success, fabricated attachment claims and a lost buffer, evidence
after compaction and recovery from a checkpoint, the metadata and instruction bases, library
pagination and queries including a literal `_`, partial, rejected, missing and uninferable
reporting through the API, decorative versus missing alt, and tags beyond the prompt limit.

## Compatibility and deployment

No migration, no deploy step. Runs in flight when this ships keep working: older compaction
notes have no `asset_id`, so those assets are inspected again.

## Remaining gaps

- `basis` is declared by the model. Code checks its preconditions, not its truth. A model
  can quote an unrelated part of the request or cite unrelated metadata. The claim is
  recorded for the reviewer, and non-image changes are not flagged out of bulk accept.
  Say if they should be.
- Delivery means the provider accepted and answered a request containing the image. It
  says nothing about what the model understood. No real provider was called.
- An automatic-prompt tag that shares no word with the asset's hints and is outside the
  most used 200 is reachable only if the model names it. The report shows the subset.
- `field_report` needs a complete run and scans `ai_runs` by surface and date. Live partial
  counters during a run would need columns on `asset_classification_runs`.
- Only exercised on SQLite. The JSON path filters and `LIKE ... ESCAPE` are untested on MySQL.
- Wave 2: the generic ledger still records one asset-level outcome. `propose_asset_changes`
  reports `proposed` even if some requested fields or languages were not proposed, and
  `unchanged` needs no image evidence.
- Automatic direct save versus reviewed operations is unchanged.

## Requested integration changes

- `ProposeAssetStructureTool` (not owned): `example_asset_ids` still need no delivered
  image. Use `InspectionImages::delivered($run->transcript)` if examples should prove sight.
- `resources/ai/skills/asset-organization/SKILL.md` (not owned): step 4 could name `basis`
  and "propose after the images arrive". The tool descriptions already say so.
- API specs (not owned): add `field_report` to the classify run response.
- Frontend, wave 4: show `review.evidence` and `field_report`.
- Runtime: keep the rule that assistant messages are appended only after a provider answer
  and are checkpointed before tools run. Delivery evidence depends on it.
