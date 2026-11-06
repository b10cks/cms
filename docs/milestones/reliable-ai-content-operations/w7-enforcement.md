# W7 T1: glossary enforcement

Implements the settled rules in [W7](w7-glossary-amendments.md) for the checker, prompt, lookup, semantic review, acceptance, the meta button and the reviewer UI.

## What changed

**Protected names.** `GlossaryTerm::protectedWording` is the one implementation, used by `GlossaryChecker`, `ContextBuilder::glossarySection` and `LookupGlossaryTool` (and through it `get_translation_task`). Translating uses the target language's wording when it has one and otherwise the source wording. Within one locale the term keeps its own wording. Lookup returns the target wording as `required`, and `do_not_translate` only when the name keeps its wording.

**Hints.** `GlossaryChecker::isBlocking` and `blocking` key on the finding type, so stored findings read correctly with no migration. `missing_term` is the hint. The calls per consumer:

| Consumer | Call |
| --- | --- |
| `AcceptanceReview` | Blocking only: no reason asked or recorded for a hint |
| `DecidableItems::needsIndividualLook` (and `OperationReview` through it) | Blocking only |
| `TranslationMemory` exact and fuzzy | Rejects on blocking findings only |
| `ChangesetRecorder`, `TranslationProposer`, `ProposeTranslationMetadataTool`, `AiRunService` | Keep storing every finding, so the UI can show hints |
| `ProposesTranslations` | Returns every finding; the note tells the agent to change nothing for a hint |
| `ImpactDetector` | Unchanged: a finding is candidate evidence for maintenance discovery, which decides nothing |
| `AiChangesetItemResource`, API schema | Same data, the docs say which type is a hint |

**Triggers.** The checker matches on a term's own wording only. Aliases still activate a term through `PolicySnapshot::activate`, so the prompt, `lookup_glossary`, `get_translation_task` and semantic relevance are unchanged. The forbidden loop is unchanged.

**Semantic review.** `SemanticSubjects::relevant` adds the output language's terms with banned wording, in the same entry shape and attribution, with `forbidden`, `case_sensitive` and a `banned_only` mark. The skill now says banned wording includes inflected forms, compounds and paraphrases.

- Limits: a text carries at most `ai.semantic_review.max_banned_terms` (40) in a quarter of `max_chars`, those sharing a four-letter word start with the text first. The count that did not fit is recorded as `banned_terms_omitted` and shown under the review state. `SemanticReviews::pick` counts a rule shared by several subjects once, so subjects still share a run.
- No double report: `judged()` drops a finding that quotes a listed word exactly, because the checker already reports it.
- Context: uncertain audience or product no longer limits a subject whose rules are all `banned_only`. Otherwise every proposal of a space with banned terms could leave a bulk accept for that reason alone.
- Review runs no longer activate glossary terms in their own prompt. Their context quotes all those terms, which would have added them to the prompt a second time.

**Prompt notes.** `GlossaryTerm::guidance` puts `knowledge.notes` before the definition inside the 500-character limit. Terms without notes keep `note`. Lookup now applies the same limit, it had none before.

**Meta button.** `MetaBlock.vue` sends the page language as `source_locale`. A `meta-tags` run with a `page` context freezes an empty glossary source, so only banned wording is checked. Findings show between the preview and the fields, name the word and the replacement when known, can be dismissed, return on the next generation and block nothing.

**Reviewer UI.** A hint renders as a muted info line in `AiChangesetItem`, takes no reason in `useItemDecisions`, does not leave a bulk accept in `needsIndividualLook` and does not count in the summary's warnings. Banned wording without a replacement no longer renders as `use ""`, in every surface that shows findings. The protected-name copy no longer says "untranslated", which is false for a name with its own wording. `es`, `fr`, `ru` and `tr` hold the same English strings as before, kept in step.

## A defect found on the way

`ContextBuilder::locales` read `default_language.iso`, but the setting is a plain string, so the fallback was always `en`. A run that names no language in a space with another default was checked as English, and status-carrying banned terms in the real language were skipped. It now uses `getDefaultLanguage()`. This affects the rich text menu, the canvas and thread runs, in the right direction.

`MetaBlock.vue` was the only caller passing only `language`. See "Left as they are".

## Tests changed for the old rule

- `aliases_activate_a_term_without_becoming_forbidden_or_approved_output`: an alias no longer triggers the checks, a term's own wording does.
- `memory_hits_the_current_glossary_invalidates_are_not_reused` and `a_run_asks_the_provider_for_a_stale_exact_hit_and_records_why`: a hint keeps a pair, banned wording rejects it.
- `large_reference_text_stays_out_of_the_prompt_and_the_run_setup`: the start of the notes now enters the prompt, up to the limit.

## Checks

| Check | Result |
| --- | --- |
| `vendor/bin/phpunit tests/Unit/Services/Ai tests/Feature/Ai tests/Feature/Mcp tests/Feature/Documentation` | 452 tests, 5036 assertions, pass. 1 skipped: `OperationsMirrorTest` needs the SDK checkout next to the repo |
| `bunx vitest run` on `tests/js/lib`, `components/ai`, `components/editor`, `components/ai-settings`, `useAiRun` | 1085 pass |
| `bunx vue-tsc --noEmit` | Pass |
| `bunx oxlint --deny-warnings` on changed TS and Vue | Pass |
| `vendor/bin/pint --test` on changed PHP | Pass |
| Render | Real `MetaBlock.vue` and `AiThreadChangeset.vue` with app CSS in headless Chromium, en and de, light mode, own Vite port, stopped after |

`docs/public/specs/mgmt_v1.json` was regenerated. The generator also rewrote the summary of the content translation import route, which has nothing to do with this work, so that hunk was put back. The generator disagrees with the checked-in spec there.

## Not exercised

- A real provider. Semantic behaviour uses fake answers, so how well a model finds paraphrases, or copes with listing up to 40 extra rule IDs per text in `checked`, is unmeasured.
- The render used a faked `useAiRun`, `useAiEnabled` and `useAiModels`, not a backend run. Dark mode was not rendered. The dismiss click is covered by a component test only.
- MySQL. Tests ran on SQLite.

## Left as they are

- `RichTextAiMenu` passes no language, so its run assumes the space default even on a page in another language. It is not the "only `language`" defect and needs the page's language passed in. The canvas and thread runs have the same gap.
- `ChangesetRecorder::findings` uses the run's target locale, not the proposal's own, for a `propose_update` on a page in another language.
- Exact translation memory hits get a semantic review only when they name contextual or conditioned terms, so a stored pair with an inflected banned word and no such term goes unreviewed unless it becomes a proposal.
- A term whose translation changed no longer invalidates memory by itself, as the hint rule requires. Banning the old wording is what rejects those pairs.
- `ImpactDetector` still reports a missing wording as `evidence`, though an inflected form can cause it. Discovery only proposes candidates.
