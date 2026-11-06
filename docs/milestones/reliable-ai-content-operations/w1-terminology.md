# W1 terminology and memory evidence

Source commit: `5cf074f0` on `feat/ai-content-operations`. Wave 1 assignment only. Later waves are not done.

Owned paths changed: `Knowledge/GlossaryMatcher`, `GlossaryChecker`, `GlossaryTerm`, `Tools/LookupGlossaryTool`, `Translation/TranslationMemory`, `Translation/TranslationLineage` (minimal), `tests/Unit/Services/Ai/GlossaryTermTest.php`, `tests/Feature/Ai/AiTranslationMemoryTest.php`.

## Behavior

### Target locale selection

`GlossaryTerm::translationFor($locale)` is the one rule. The checker and the lookup tool both use it.

1. The exact normalized locale wins (`de_AT` equals `DE-at`), whatever the map order.
2. Otherwise the entry for the base language applies (`de` serves `de-CH`).
3. Otherwise null. A sibling region never stands in, and a region never serves its base language.

Blank entries count as absent. If two spellings of one locale collide, the first in sorted key order wins, so the result does not depend on order. Base fallback uses the first subtag only: `zh-Hant-TW` falls back to `zh`, not `zh-Hant`.

The checker now treats any change of normalized locale as needing wording, so `de-AT` to `de-DE` is checked. The same locale requires nothing, and forbidden variants still apply. A base entry also applies to a same-language adaptation, because it is the same rule.

Source matching is unchanged: `GlossaryMatcher::match` and `sameLanguage` still compare per language.

### Translation memory

`TranslationMemory::record` pairs the value in the published target version with the source that value has as lineage. It no longer reads the canonical's published text as the pair's source.

- Stale republish: source A, target-A, publish source B, republish target-A. The unit stays A to target-A, and B to target-A is never stored.
- Up to date: a target changed after B records B to the new target and replaces the field's old pair (one row per field, as before).
- Published versus draft: `TranslationLineage::of` accepts a `version` per entry, and history walks back from it. A newer target draft does not affect the pair.
- A draft written against A and published after B pairs with A.
- A target written against an unpublished source draft pairs with that draft text, not the published source.
- AI proposals pair with the source they were proposed from, including reviewer edits and when the source moved before acceptance. Origin is `ai_accepted`, now also for accepted new entries and composite fields. Text matching stays for accepted proposals older than lineage rows.
- Human translations stay in memory through history-derived lineage, with origin `human`.
- Blank or same-as-source pairs and units with no datable origin are left out.

`TranslationLineage::of` now ignores rows of proposals that are pending, rejected or in conflict. Without this, an unaccepted proposal holding the current text would have verified B to target-A. This also affects `TranslationWork`: such a value is now judged by its history, so it shows as stale where it should.

## Interfaces for later work

Alias work:

- `GlossaryTerm::$term` and `GlossaryMatcher::match` are source-side matching. `translationFor` and `$forbidden` are output-side. Source aliases should be a new source-side field fed into matching, not entries in `translations` or `forbidden`.
- `GlossaryTerm::toArray` and `fromArray` are the frozen run shape, so new fields must be added to both and be optional for runs already frozen.

Policy revision work:

- `TranslationMemory::record(Content): int` is the only writer. `exact()` and `fuzzy()` are the only readers, so applicability filtering belongs in those two.
- `translation_units` holds no policy or glossary revision. `content_id`, `field_path`, `origin` and `updated_at` are all a filter has today. Whether a term correction hides, flags or keeps a memory hit is not decided here. Wave 2 needs that decision and a schema change.
- `TranslationLineage::of(entries)` returns `source_hash`, `source_text` and `origin` per unit. `origin` and the optional `version` are additive.

## Requested integration change

`app/Services/Ai/Run/ContextBuilder.php` (around line 330) is not mine. Its glossary prompt still loops with `sameLanguage`, so `{de-AT, de-DE}` tells the model both wordings for `de-DE`. Replace the `foreach` in the `else` branch with:

```php
$required = $term->translationFor($targetLocale);
if ($required !== null) {
    $rules[] = "write \"{$required}\" in {$targetLocale}";
}
```

Until this lands, the prompt and the checker can disagree.

## Checks

SQLite in memory, PHP 8.5.12, PHPUnit 13.4.5, no paid calls.

- `vendor/bin/phpunit tests/Unit/Services/Ai tests/Feature/Ai/AiTranslationMemoryTest.php tests/Feature/Ai/AiTranslationOperationsTest.php tests/Feature/Ai/AiChangesetTest.php tests/Feature/Ai/AiLearningTest.php tests/Feature/Ai/AiOperationTest.php tests/Feature/Ai/AiSpaceDatabaseTest.php tests/Feature/Ai/AiSettingsListTest.php tests/Feature/Mcp/AiMcpToolsTest.php`: 137 tests, 614 assertions, all pass.
- `GlossaryTermTest` and `AiTranslationMemoryTest` against the old `app/` code: 21 of 24 fail. The 12 glossary tests fail, 10 of them on the missing method, and 9 memory tests fail on behavior. The 3 that pass are the 2 existing memory tests and the up-to-date pair, which the old code already got right.
- Removing the accepted-only filter fails the pending and rejected cases.
- `vendor/bin/pint --test` on the changed files.

## Deployment

No migration. Wrong pairs already stored stay until that translation is published again or the backfill runs. Run `php artisan ai:backfill-translation-memory` by hand after deploying. It rewrites units per field and stores derived `translation_sources` rows, and it is safe to rerun.

## Limits and provenance gaps

- History infers lineage from timestamps. An editor who fixes a typo in target-A after the source moved to B creates B to target-A-fixed, and nothing in the data tells this from a real retranslation. `TranslationWork` reads it the same way. Fixing this needs an explicit statement from the editor or a recorded source version at save time, which is outside this slice.
- `sourceVersionAt` takes the newest source version at that moment, not the one that was actually current, so a restored older source version is misread. A target older than every source version is attributed to the source's first version. Both exist already and are also used by staleness checks.
- Timestamps have one-second resolution, so a source and target saved in the same second pair with the newer source version.
- A translation that stays correct for a slightly edited source is not re-paired until its text changes.
- A target written against an unpublished source draft is recorded, since the published target is the approved side and the pair is what was translated. A canonical that was never published still records nothing, as before.
- Number and date units are no longer recorded, because lineage covers prose only (`TranslationUnits::PROSE_TYPES`). Existing rows for them are removed on re-record. Translation tasks only use prose units. A flat-field run could lose an exact hit on such a value, which I consider negligible.
- Not verified: MySQL. The new `NOT EXISTS` clause and FULLTEXT path did not run there. No production data or real provider was exercised.
- D10 and metadata dependency invalidation are untouched.
