# W9 editor

Evidence for task S2 of [W9](w9-simple-glossary.md). The editor is rebuilt on the row from [w9-model.md](w9-model.md). Visual rules are in `DESIGN.md` under "Glossary editor".

## What an editor can do

**Read the glossary.** One row per meaning. The first column is the default language's Use, or its Avoid wording with a ban mark. One column per language after that. A language with no wording of its own on the page folds away, a button by the filters names it and shows it (`columns=all`), and the language being filtered on never folds.

**See what is left.** One coverage tile per language with its "x of y" figure. A complete language is muted and can't be pressed. A gap is strong, says "n missing" and lists itself when pressed.

**Find things.** Search and "Missing in <language>", both in the URL. `/` focuses search.

**Delete many.** Selection across pages, range with Shift, and one bulk action: delete, naming the count first.

**Edit one row.** Language tabs, then Use, Avoid and Also written as, then one Note and Keep exact. The default language's wording stays as one muted line under Use. Avoid without Use says "Ban: the AI must never write this.", with no separate control. Note counts to 200. An older, longer note reads fine, is not sent unless changed, and asks to be shortened only once edited.

**Not lose work.** Save, Save and next, Save and add another on a new row, Cmd/Ctrl+S, arrows or j/k, focus return, URL state and the unsaved guard all stay. The wording conflict 409 and the `use_avoided` 422 show beside the field and keep the text.

**Fill gaps with AI.** A button on the reference line, only when Use is empty and there is something to translate. It never saves and never overwrites typed text.

**Try a text.** A dialog from the page menu, or from a concept with its wording in that language filled in. It shows the text with matches marked, one flat list of what would be flagged, and each term's one instruction from the server, which opens its concept. A hint says in words that only the AI is told it.

**Other.** Page menu: Import spreadsheet, Export CSV, XLSX, TBX, Try a text, Delete glossary. Read-only shows plain text, now including Note and Keep exact, which were missing. Unused languages and the team language code stay, the code behind a button so it doesn't push Use down.

## Lines

| Compared with | Removed | Added | Net |
| --- | --- | --- | --- |
| S1's head, `resources/js` | 955 | 937 | -18 |
| of which i18n | 215 | 69 | -146 |
| of which code | 740 | 868 | +128 |
| Before W9 (tag `archive/w8-before-simplify`), glossary folder and page | 1395 | 821 | -574 |

The editor folder and page went from 3353 lines before W9 to 2779. **Against S1's head the code grew by 128 lines.** S1 cut it down crudely, and this adds what that left out: inline refusals, the note rules, the ban mark, folding, the visible field sentences and the read-only fields. The simplification shows on screen more than in the count: one filter, one bulk action, no panel under the table. `GlossaryConceptSheet.vue` is still 692 lines, and I would not call it small.

Deleted: `GlossaryFilterSelect`, the `locked` and suggestion props, the policy note, the status, override, shared and chip i18n groups. `GlossaryCategoryField`, `useTermsQuery`, `useSaveTermMutation` and `useDeleteTermMutation` were already gone at S1's head. All `components.aiSettings.glossaries.*` keys still have callers, so they stay.

## Choices worth checking

- **Folding applies to any language with no wording on the page, not only regional ones.** The brief's rule says "a space language". At ten languages, a language that is all "Missing" says what its coverage tile says, and pressing the tile brings the column back. Columns can change between pages, which the brief's "on the page" accepts.
- **Field sentences are visible, not tooltips.** Five one-line sentences are the sheet's whole manual. `DESIGN.md` records this as an exception to the Forms rule.
- **Fill with AI fills Use only.** The brief says Use and note. A row has one note, so there is no second language to translate it from and nothing to write it to. See "Left out".
- **Destructive ink.** `text-destructive` measured 4.49:1 and 4.37:1 at 13px, under AA. Bans, flags and Remove use the strongest ink with the icon in destructive.
- **"Save and add another" stays.** It is not on the brief's keep list, but with no next row on a new concept it is what Cmd/Ctrl+Enter does, and it is how someone types in four terms.
- **The word "Ban" is screen reader only in a narrow list.** At 390 it truncated the wording it marks. The icon stays, with a tooltip.

## Checks

| Check | Result |
| --- | --- |
| `bunx vitest run tests/js/components/ai-settings tests/js/lib` | 1001 pass in 59 files |
| `bunx vue-tsc --noEmit` | Passes |
| `bunx oxlint --deny-warnings` on changed paths | Passes |
| Text contrast from computed colours, list, sheet and Try a text, light and dark | Nothing under 4.5:1 |
| `text-xs`, `·` and customer names in the glossary folder | None |

New tests cover: filters to the URL, ban row display, a note sent only when changed and the long note rule, the 422 and 409 keeping text, the unsaved guard, column folding and the filtered language staying, Try a text from the menu and from a concept, and read-only.

## States viewed

The real `AiGlossaryDetail` in headless Chromium, with real CSS, i18n, router, TanStack Query and API client, against a fake API behind `fetch`, on a scratch Vite server on port 5199, now stopped. Fixtures are synthetic. Every screenshot below was opened and looked at. A script found no page overflow on any of them.

| Width | Mode | Looked at |
| --- | --- | --- |
| 1440 | Light | Four terms: list, sheet, ban sheet, page menu, Try a text, duplicate wording after a 409, unsaved guard, AI fill line, read-only role with sheet, empty. 200 terms: six languages folded, ten languages with gaps, ten unfolded, Missing filter, a long note edited, a regional tab |
| 1440 | Dark | 200 terms: six languages complete, ten languages with gaps, ten-language sheet with an unused language, Try a text, inherited glossary with ban sheet |
| 1440 | Light, German | Ban sheet |
| 1440 | Dark, German | Try a text |
| 390 | Light | Four terms list, ban sheet. 200 terms: six languages with gaps, selection bar. German list |
| 390 | Dark | Four terms list, AI fill line, `use_avoided` 422. 200 terms: ten languages list, ten-language ban sheet, Try a text. Empty |

Exercised by script: j/k and Enter open a row, focus lands on Use, Cmd+Enter moves on, Esc closes and focus returns to the row. From a concept, Try a text prefills that language's wording, and Esc closes the dialog, keeps the sheet and returns focus to the button.

Fixed from looking:

- `max-w-4xl` on the dialog did nothing, because `cn` is plain clsx and `max-w-lg` won. It needs `max-w-4xl!`.
- A ban in the first column had no mark at all.
- A ban's conflict had no message anywhere: the check only compared Use, so the save failed silently.
- The reference and fallback lines repeated each other on a regional tab.
- The too-long note message showed twice.
- "4 concepts" under "Concepts", and a pager on four rows.
- "The AI is told: Hint: ..." read double, so it has its own sentence.

## Not exercised

- **The real app and backend.** The harness has no app shell or real API. `/` is handled in `layouts/default.vue`, which didn't render, so that key wasn't pressed. T1 renders the whole flow.
- **A real AI run.** Fill with AI is asserted in a test against a mocked run. The button was seen, not pressed, in the browser.
- **A real phone or a screen reader.** 390 was a desktop viewport. Labels and `aria-live` regions are in place, but no assistive technology ran.
- **Reduced motion off.** Screenshots ran with reduced motion on.
- **Team glossary in the browser.** "Add a language" was not rendered this time. The code path moved behind a button and tests don't cover it.
- **The older render set.** Some list and sheet screenshots above were taken before the last small copy and contrast edits. The contrast rerun and four final screenshots came after them.

## Left out

- **Note in Fill with AI**, for the reason above. A row's note could go along as context for the translation, but `translate-fields` has no field for that, and I did not widen the skill.

## Follow-ups

- "Concept" and "term" both appear: the UI says concept, the knowledge list says "4 terms".
- A ban served to a regional language shows as a plain fallback in that column when unfolded, with no ban mark.
- `SelectField` still logs a missing `common.select` key in development. It is in the shared component.
- `docs/STATUS.md` is not touched here, because this task's branch is merged by the run above it.
