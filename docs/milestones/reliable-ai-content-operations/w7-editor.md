# W7 T4: concept editor

Replaces the term table and term dialog on the glossary detail page, for space and team routes. Rules are in [w7-glossary-amendments.md](w7-glossary-amendments.md), the API in [w7-concept-api.md](w7-concept-api.md), the visual rules in `DESIGN.md` under "Glossary editor".

## What an editor can do

**Read the glossary as meanings.** One row per concept with a column per language. The first column is the default language's wording and stays frozen, so that language has no second column. A cell says one of four things: its own wording, a base language serving it (muted, corner arrow), a translation stored from before languages had their own term (muted, link icon), or "Missing". A banned or "only where it fits" concept carries its mark in the frozen cell, so it stays in view when the status column scrolls away.

**See what is left.** Above the table, per language: covered, of total, missing. Pressing one lists its gaps, and opening a concept from that list lands on that language.

**Find things.** Search covers wording, aliases and definitions in any language, on the server, debounced, and `/` focuses it. Status, category, audience and "missing in" filters show live counts. Search, filters, page, page size, the open concept and the language tab all live in the URL.

**Change many at once.** Shift-click or Shift+arrow selects a range, Space or `x` toggles, and the selection holds across pages. The bar sets category, status or audience, or deletes. Destructive and policy-wide actions name the count before they run.

**Work through a list from the keyboard.** Arrows or `j`/`k` move, Enter opens, Esc closes, focus returns to the row. Cmd/Ctrl+S saves, Cmd/Ctrl+Enter saves and goes to the next concept, crossing pages. A saved row that drops out of a filter doesn't make the next one get skipped.

**Edit one concept in a side sheet.** The list stays usable beside it on wide screens, and the sheet is full screen below `lg`. Status is a choice in words of what the AI does. Category is a combobox that can create. Audience is chips with existing values to pick. Language tabs mark filled, missing, base language and differs from shared. Each language has wording, aliases, banned wording, definition and notes, with status and audience overrides behind a disclosure that shows when one is in effect. The default language stays visible as a reference, sticky on screens at least 640px tall.

**Not lose work.** Save is explicit and sends only what changed. Unsaved changes are asked about on close, next, opening another concept and route change. A 409 keeps the text and links to the concept holding the wording, and the same line appears while typing. Text in a chips field is added on blur, on `;` and before a save.

**Fill gaps with AI.** "Fill missing with AI" runs `translate-fields` from the reference language for that tab's empty wording, definition and notes. It fills the form only, marks the result until saved, never overwrites typed text, including text typed during the run, and never saves. The glossary reaches the run the usual way: `ContextBuilder` activates terms from the context strings.

**Try a text.** A panel on the page calls `glossary-check`, with no provider call. It marks matched spans, solid for the term's own wording and dashed for an alias. It lists activated terms with what the AI is told, each opening its concept, and shows findings with blocking ones apart from hints.

**Start from nothing.** A new glossary offers "Add a concept", "Import a sheet" and the template downloads. A filter with no result offers clearing it and creating the searched wording.

**Read without writing.** Inherited glossaries and read-only roles see everything as plain text, with no write controls and a line saying why.

## Rules kept

- Status and audience are the concept's, and a language can override them. An omitted field keeps its value, so the form never flattens a mixed value it wasn't asked to change.
- Aliases are explained as finding the term and never being written. Protected is explained as fixed per language, own wording first.
- A prohibited status makes the wording banned: the banned list shows it as a locked chip and asks nothing extra.
- Copy describes the checker as it is: required wording gives a hint, banned wording needs a fix or a reason, "only where it fits" flags nothing. "What the AI would be told" follows `ContextBuilder::glossarySection`.
- Unused languages stay editable, in a separate tab group labelled "not used by this space". A team glossary can add any language with a validated code. A space uses its languages.
- Nothing names a customer. Fixtures are synthetic.

## Choices worth checking

- **The default language has no column of its own.** The brief lists a concept column and one column per language. With both, the first two columns always repeat. The header reads "Concept, German". A row with no default-language wording shows its first language, muted, with a dashed mark.
- **Frozen first column and horizontal scroll, no column chooser.** Tried at six and ten languages. Scrolling keeps every language reachable, and the status mark in the frozen cell covers what scrolled away. A chooser would add state to save and hide data.
- **Status, category and audience stay after the languages,** as the brief lists them.
- **Non-modal sheet, no page push.** Pushing the page reflows the coverage strip and filters on every open. The overlay shifts nothing, and the frozen column and open-row highlight stay visible.
- **URL parameter `language`, not `lang`.** `useUnsavedChangesGuard` treats a `lang` change as a different document and prompted on every tab switch.
- **"Note beside Save, once"** is read as one note in one place, not as shown once per session.
- **The list's rows are `<tr>` with a link in the name.** Space and arrows work on the row, and a modified click on the name opens the concept in a new tab.
- **A visible explanation under two labels** breaks the tooltip rule in `DESIGN.md` on purpose, for aliases and protected, and `DESIGN.md` records it.

## Changes outside the task's folder

Small additions to T2's API, because the UI can't work without them:

| Addition | Why |
| --- | --- |
| `GET concept-rows/{row}` | A deep link or a link from a 409 or Try a text can name a concept the current page doesn't hold. The service method already existed. |
| `facets.total` | Coverage needs the whole glossary's row count, and `meta.total` follows the filters. |
| `facets.missing` | Coverage figures need what the `missing` filter returns per locale, which `facets.locales` can't give once a base language serves a region. |
| `AiGlossaryCheckResult.findings` typed as `AiGlossaryFinding` | So the existing `isGlossaryHint` and `glossaryFindingText` apply. |

The spec is regenerated, with no unrelated drift this time. The new route is not in the MCP registry.

Also: a `--warning-ink` token in `main.css`, because `text-warning` on a light surface is 2.9:1 and too low for 13px text.

## Checks

| Check | Result |
| --- | --- |
| `bunx vitest run tests/js/components/ai-settings tests/js/lib/glossaryConcepts.test.ts` | 61 pass |
| `bunx vue-tsc --noEmit` | Passes |
| `bunx oxlint --deny-warnings` on changed paths | Passes |
| `vendor/bin/phpunit tests/Feature/Ai/AiGlossaryConceptRowsTest.php tests/Feature/Documentation` | 19 pass |
| `vendor/bin/phpunit tests/Feature/Mcp` | 30 pass, 1 skipped (no SDK checkout beside the worktree) |
| `vendor/bin/pint --test` on changed PHP | Passes |

The tests cover filters to URL, base language against missing, selection range, the count before a bulk delete, empty and filtered-empty offers, read-only, a save sending only what changed, a 409 keeping text, the unsaved guard, AI fill leaving typed text alone and saving nothing, and offsets highlighting after an emoji.

## States viewed

The real `AiGlossaryDetail` in headless Chromium 156, with real CSS, i18n, router, TanStack Query and API client, against a fake API behind `fetch` that implements the concept contract. It ran on its own Vite port with a stand-in for the app shell, and the server is stopped.

| Width | Mode | Looked at |
| --- | --- | --- |
| 1440 | Light | 6 languages list and sheet, 5000 concepts (50 rows in the DOM, 1877 nodes), empty, filtered empty, read-only role list and sheet, team glossary, team new concept |
| 1440 | Dark | 10 languages list and sheet, inherited glossary with its sheet |
| 1440 | Light, German | Sheet |
| 1024 | Light, German | 10 languages list and sheet |
| 1024 | Dark | 10 languages sheet |
| 390 | Light | 6 languages list and sheet, filtered empty |
| 390 | Light, German | Sheet |
| 390 | Dark | 10 languages list, sheet bottom, Try a text, selection bar, pagination footer, empty |

Also rendered and checked by script only, for overflow and console errors, not looked at: 1440 dark with 6 languages, 1440 light with 10, 1024 light with 6, 390 dark sheet with 10.

Sheet states looked at, at 1440 light: long definition with its reference, overrides open, duplicate wording and 409 path, unsaved guard, route guard, banned wording with its locked chip, AI filled fields, category combobox with a new value, a concept that no longer exists. Also the filter dropdown with counts, focus ring on a row, the selection bar, the sheet import dialog from the empty state, and a team language added after an invalid code was refused.

Text contrast was measured from computed colors across the list and sheet in both modes. Everything is at least 4.5:1 except `text-destructive` on "Remove <language>" in light, at 4.49:1, which is the shared token. No page overflow at 390.

Fixed from looking:

- A tab switch prompted about unsaved changes, because of the `lang` parameter.
- The pagination footer overflowed and clipped at 390.
- `text-warning` and `text-muted` contrast on filled surfaces.
- An "Unsaved" badge in white on amber.
- Status cards with uneven spacing.
- The sheet's close button below 24px.
- Chip delete below 24px.
- Paging cleared the selection.

`SelectField` logs a missing `common.select` key in development. That is in the shared component, so it stays.

## Not exercised

- **The real app.** The worktree has no TLS certificates or local app. The real `AppHeader`, sidebar and threads dock were not rendered, nor a real backend behind the screen. The concept routes are covered by feature tests. T5 renders the whole flow.
- **A real AI run.** The fake API answers `translate-fields` over SSE. Whether a real provider returns the flat `fields` shape for these keys follows the skill's rule and was not run.
- **The failed AI path on screen.** It goes through `useAiErrorToast` like other buttons, and no test or render forced it.
- **A real phone.** 390 was a desktop viewport. On a coarse pointer the sheet focuses its title rather than the wording, so the keyboard doesn't open by itself, and that branch was not run.
- **A screen reader.** Keyboard paths and labels were exercised, but no assistive technology was run.
- **Reduced motion by eye.** Screenshots ran with reduced motion on. The default animation was not compared.
- **MySQL and real latency at 5000.** The list was at 5000 against the fake API. T2 measured a single write at 0.8 s and a page at 0.15 s on SQLite.

## Left out

- **Select all matching.** Selection holds across pages and a page at a time, but there is no way to select every row of a filter. `bulk` takes ids and the list gives no id-only read. Worth a small `GET concept-rows/ids` next.
- **Fill missing with AI on team glossaries.** `useAiRun` is space-scoped. A team glossary has no such button.
- **Sorting by a column.** The API has one order, by the default language's term.

## Follow-ups

- `useTermsQuery`, `useSaveTermMutation` and `useDeleteTermMutation` in `useAiKnowledge`, and most of `components.aiSettings.glossaries.*` in the locale files, have no caller now.
- `TablePaginationFooter` doesn't wrap, so other tables clip the pager at 390 as this one did.
- `text-muted` as a placeholder on `bg-input` in dark is 4.2:1 across the app, and `text-destructive` on the sheet background is 4.49:1.
- The new `GET concept-rows/{row}` could join the MCP registry and the SDK.
