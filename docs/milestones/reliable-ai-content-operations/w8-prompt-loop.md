# W8 P1 prompt and loop

Implements [W8](w8-reduce-and-direct-writes.md) task P1, on top of D1 ([direct writes](w8-direct-writes.md)). It changes what the glossary puts in front of the agent and what comes back. It says nothing about provider behavior or editorial quality.

## Numbers

Measured with tinker on the local "W7 prototype glossary" (1,206 term rows, read only, no space context). The original 429-character paragraph is not in the repo, so I wrote a German one of similar density: 427 characters, the terms a catalog like this holds (product parts, a contextual term, a banned term, two common aliases).

| | Before | After |
| --- | --- | --- |
| Terms activated | 21 | 20 |
| `## Glossary` section, characters | 21,702 | 2,412 |
| Per term, characters | 1,033 | 121 |
| Section to text | 51 times | 5.6 times |

The earlier measurement on the real paragraph was 15,865 characters, so this paragraph is a little heavier than that one. The ratio is what counts: about nine times smaller. One of the 2,412 characters is the notes of one contextual term, which keep the existing 500 character cap per side. Without it the section is about 1,300 characters, 65 per term. One term fewer is activated (21 to 20); I did not trace which one the longest match removed.

## Behaviour

**Short prompt.** `GlossaryBrief` renders per term the wording to write, or the protected wording, and the banned wording. No definitions, notes or audience labels. Two exceptions: a contextual term keeps its notes (they are its conditions) and a term whose labels are all internal ("Intern", "Internal", and the same word in other languages) says `applies to: …`. A term found only through an alias moves to a second group: `"Marketing" may refer to: agency, internal.` under one sentence that says this is context and the source's meaning stays. A banned entry of such a term is not listed, unless the source wrote that very wording, and then the line adds "Never write it." One sentence says notes and definitions are in `lookup_glossary`, only when the run has that tool.

**Longest match.** `GlossaryMatcher::find()` returns the spans of each hit. A wording inside a longer wording of another found term is no match, and neither is an alias inside a found wording. A term found by its own wording reports `via: term` even where an alias spells the same text, which fixes the Try a text label. `match()` stays and is built on it, so the checker, the policy and Try a text all see the same hits.

**Terms on read.** `get_entry` returns `glossary` for content, in the entry's language, in the shape of the prompt section, from the run's pinned policy. It is absent when no term matches. The meta-tags skill says to follow it. `lookup_glossary` is unchanged.

**Findings back to the agent.** Every write tool (`propose_update`, `propose_translation`, `propose_create`, `propose_translation_metadata`) returns `glossary_findings` (blocking), `glossary_hints` (missing required wording) and one `glossary_note`: the change is in place, fix it with one more call, once; hints are for the agent only. For a proposal on a target without a draft the note says the proposal is open and flagged. `missing_term` is no longer stored on any item. The recorder, the translation proposer and the metadata tool store blocking findings only. The item resource lists blocking findings only, so a `missing_term` in an old item does not show in the API, the MCP tools, the reviewer, the receipt or the thread. The editor buttons' run result holds blocking findings only. `GlossaryChecker::isBlocking` is still the one rule, with `hints()` as its complement. Try a text still shows hints, under "Only the AI is told this, once. It is never stored or flagged".

**Plural.** Untouched. No stemming.

**Trailing `*` in banned wording.** `GlossaryMatcher::findBanned()`. A word of an entry that ends in `*` and has text before it matches any word that starts with that text. The left boundary stays, case follows the term, and each word of a multi-word entry can end in `*`. A `*` elsewhere is plain text. The finding's `found` is the text that matched for entries with a `*`, and stays the entry for exact ones, so existing finding ids do not change. A concept's own wording for a banned status stays exact: it is compared as the term, whatever it ends in. The sheet keeps the `*` through export and import (tested in both formats). The editor says it in one sentence under the banned field. `docs/guides/glossary-sheet.md`, the template's help sheet, the API guide and the native AI concept doc follow.

**Buttons.** `AiRunFindings.vue` is the alert that lived inline in `MetaBlock.vue`: dismissible, never blocking, back with the next findings. `MetaBlock`, the translate button in `FlattenedLocalization.vue` and AI translate in `mass-edit.vue` use it. Mass edit collects findings across batches and languages and removes duplicates. `RichTextAiMenu.vue` sends the page's language as `source_locale` and `target_locale`. The translate button's prompt is still a flat JSON skill.

**Search ranking.** `GlossaryConceptRows` ranks a row by its best term in any language: exact wording, starts with, contains, then alias or definition, then the usual order. It is the same scan as before, in PHP, so it behaves the same on MySQL and SQLite. No extra query.

Copy is in en and de. The moved `components.aiActions.findingsTitle` and `dismissFindings` and the removed `glossaryHints` follow in fr, es, ru and tr as English, the way their neighbours do.

## Tests

New: `GlossaryMatcherTest` (longest match, own wording over alias, `*`), `GlossaryBriefTest` (a bound on section length per term for a dense synthetic paragraph, contextual notes and internal line, the alias group and banned handling), two in `GlossaryCheckerTest` (`*` finding, hints), `get_entry` terms, `propose_update` findings and hints and nothing stored, an old stored hint not listed, Try a text label and longest match, search ranking, `AiRunFindings.test.ts`.

Changed because the behaviour changed: the three prompt tests in `AiPolicySnapshotTest` (notes and labels are no longer in the prompt), `the_task_brings_glossary_terms…` in `AiTranslationOperationsTest`, the hint test in `AiChangesetReview.test.ts`, `MetaBlock.test.ts` (new selector), and one more `*` entry in the two sheet tests.

## Checks

```text
vendor/bin/phpunit tests/Feature/Ai tests/Unit/Services/Ai tests/Feature/Mcp tests/Feature/Documentation   OK, 465 tests, 5,145 assertions, 1 skipped
bunx vitest run tests/js/components/ai tests/js/components/ai-settings tests/js/components/editor tests/js/components/localization tests/js/lib   71 files, 1,068 tests passed
bunx vue-tsc --noEmit                      clean
bunx oxlint --deny-warnings on changed     clean
vendor/bin/pint --test on changed PHP      passed
php artisan docs:generate                  one description line in mgmt_v1.json
```

`tests/js/components/localization` does not exist, so nothing there ran.

## Not exercised

- A real provider: whether an agent follows the note and fixes once, and whether it uses `get_entry`'s terms.
- The translate button, mass edit and the selection menu in a browser. They are covered by typecheck, and the shared alert by its own test and `MetaBlock`'s. `FlattenedLocalization`, `mass-edit.vue` and `RichTextAiMenu.vue` have no test that mounts them.
- Canvas `content-interaction`: nothing in the frontend starts it, so there is no page language to pass.
- MySQL for the search ranking. It runs in PHP over a scan of the glossary's columns, as the filters did, and was tested on SQLite.
- German copy in the real app.

## Follow-ups noticed

- `get_translation_task` still returns `glossary` as the full lookup shape, with notes and applicability per term. It is a tool result, not the prompt, so I left it. It could use the short shape.
- Try a text's "What the AI would be told" list is built from the lookup result, so it shows notes the prompt no longer carries. Rendering `GlossaryBrief` there would make it exact.
- A write tool can report the same banned wording twice with different `expected`: the recorder checks with the run's locales and the translation proposer with the destination's. The pair was there before. The agent now sees both.
- The meta-tags skill's "Approved operations" text still mentions `derived_from`, which D1 removed.
- `hasContextual` and the internal-use pattern live in `GlossaryBrief`. The audience is free text, so a label in a language the pattern does not cover is not recognised as internal.
