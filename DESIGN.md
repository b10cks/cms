# Design rules

Rules Mike settled for the CMS UI. Tokens live in `resources/js/assets/css/main.css`. Where this file and a component disagree, this file wins; fix the component.

## Surfaces

- Every panel on a page is a `Panel` (`components/ui/panel`): hairline, card tone, a 56px header with the title at `text-panel` (17px), optional muted meta and actions on the right. Tables and charts that run to the edges use `flush`.
- Never nest a card in a card. A chart component draws only the chart; the page wraps it in one `Panel`.
- Cards keep `shadow-soft-sm` on top of the hairline. This is the project's depth convention (see the elevation comment in `main.css`) and overrides the generic "shadows only on floating layers" rule.
- Tags and chips inside a field use `bg-chip` / `text-chip-foreground`, one step off the field fill in both modes.

## Type

- The space and environment badge is 10px uppercase at line height 1, so its height follows its text and not the line around it.
- Body is 15px (`text-base`). The smallest size for readable content is 13px (`text-sm`): labels, hints, table cells, chart axes. No `text-xs` or smaller outside badges.
- Extra sizes only for page titles (`text-2xl`), panel and section titles (`text-panel`) and KPI figures (`text-figure`, 28px).
- Figures: the value is semibold in the strongest ink, the label sits above it, 13px and muted.
- No middle-dot (`·`) separators. Use a comma, a line break or separate elements.

## Contrast and status text

- A fill carrying white text is `bg-accent-fill` (blue-700, 4.5:1), hover `bg-accent-fill-hover`. `--accent` stays for rings, links and thin marks; blue-500 is only 3.0:1 under white.
- Status as text on a plain surface uses the `-ink` tokens (`text-success-ink`, `text-info-ink`, `text-warning-ink`), outline badges included. The `-foreground` tokens are for filled badges.

- Destructive as text is `text-destructive-ink`: field errors, the required mark, destructive buttons. `text-destructive` stays for icons and field edges. Settled 2026-11-05.

## Controls

Settled 2026-11-05.

- Tabs: the active tab is `bg-tab-active` with `shadow-sm` on a `bg-secondary` track. In light that is a white card on gray-100 and must stay lighter than its track; a white track makes the active tab vanish. In dark the active tab goes one step up (gray-700), never down into a hole. Inactive text is `text-foreground`. Hover is the `bg-state-hover` wash. The entry editor's tab bar keeps the older dark look on purpose: the active tab sinks to `--background` on the track (`--editor-tab-active`, `--editor-tab-hover`). Light is the same as other tabs.
- Fields use `border-input-border` (gray-200 light, gray-750 dark) with `shadow-sm` and no hover on the edge: inputs, textareas, comboboxes, selects, tags and date fields, and input groups. This is 1.7:1 on white and 1.15:1 on the dark fill, below 3:1. Mike chose it: a gray-400 and gray-500 field edge with a stronger hover was rated 1 and rejected on 2026-11-05. Do not bring it back.
- Checkboxes, radios, the off switch and the outline toggle keep the 3:1 edge: `border-choice-border`, hover `border-choice-border-hover`. Fields never use these. Boxes and dividers that are not controls use `border-border` or `border-border-strong`.
- A focused field is `ring-2` in `ring` outside its gray edge, with the edge left as it is. An open select or date trigger is the same. A field with an error keeps its red edge and focuses in red. `border-ring` plus `ring-1` was rejected along with the stronger edge. Buttons, checkboxes, radios and switches focus with a 2px `outline-ring` set off from the control, so the ring still shows on a blue fill.
- Field fill text that is not the value (placeholder, read-only) is `text-input-muted`.
- Every button at default size and every field is 36px with an 8px radius. 32px and 28px controls use 6px. Outline buttons draw their edge with `inset-ring`, so they measure the same as filled ones.
- Ghost and outline hover are `bg-state-hover`, pressed and open are `bg-state-selected`, so they hold on any surface. Filled neutral buttons step through `--secondary-hover` and `--secondary-active`.
- The default button is `bg-button`, which is `--secondary` everywhere except the app header. There, in light, it is white with the same `shadow-xs`, because gray-100 on the gray-100 bar has no edge (`--header-button`, 1.28:1 plus the shadow). Dark is unchanged. Other neutral fills on the header do not change with it; badges have their own token, see Data and navigation.
- The divider in a split button is `current` at 20%, so it shows on any fill. Outline split buttons overlap by a pixel.
- `SortSelect` is one field: the select and a `variant="input"` direction toggle share the field edge, shadow and 8px outer radius, joined by a single line. The focused or open half sits on top.

## Overlays

Settled 2026-11-05. Menus, selects, comboboxes, Cmd+K, popovers, tooltips, toasts, dialogs and sheets.

- Everything that floats is `overlay-panel`: popover fill, `border-popover-border`, `shadow-overlay`, 8px radius. In dark mode the soft shadow vanishes, so `shadow-overlay` uses real black and the edge is one step past the fill (gray-700 on gray-800). Dialogs, confirm dialogs, popovers and tooltips use `border-overlay-edge` instead: the same in light, gray-950 in dark, so the hairline is darker than the fill. Mike asked for this on 2026-11-05. Menus, selects, comboboxes, sheets and Cmd+K keep `border-popover-border`. Muted text inside uses `--input-muted`, since `--muted` is 4.18:1 on the dark fill.
- Every list row is `menu-row`, 32px and medium weight. Highlighted is the neutral `bg-state-selected` wash with the strongest ink, so a red Delete and colored block icons keep their color. Rejected: the solid blue highlight, it overrode both. The team switcher keeps its accent fill, settled above.
- The wash is 1.24:1 in light and 1.35:1 in dark, so a keyboard user also gets a 1px `--ring` inset (3.66:1, 3.35:1). `lib/inputModality.ts` tells them apart, because `:focus-visible` also fires under the pointer in a menu. Cmd+K has no ring (`--menu-row-ring`): Mike rejected the blue border on its selected row, the wash alone marks it there. Other menus keep the ring.
- Item icons are muted unless they carry a color, and go to the row's ink when highlighted. A destructive row is `text-destructive`; `menu-row` turns it into the ink, and `--destructive-foreground` on the wash (the ink is 3.48:1 there in dark). Separators are `bg-popover-border`, shortcuts are muted at normal tracking.
- Every scrim is `bg-overlay` with no blur, dialogs and sheets included. Dialogs have a hairline and a 12px radius from `sm` up. Titles are `text-panel` in `text-primary`. Close is the 28px `overlay-close`, centered on the title line.
- A select's list is at least as wide as its trigger.
- Toasts are the popover surface with a neutral edge. Only the icon carries the status color: 16px lucide stroke in the `-ink` token, centered on the first line. Title is 13px medium in the strongest ink, description 13px regular in `--input-muted`. Action and close sit on the first line, 24px with a 6px radius; the action is the `state-selected` wash, close is last and muted, the wash on hover. Every toast has a close button. Rejected: filled toasts, the warning had low contrast; a wash, which turned the warning cream; the colored edge and the filled 20px icons, rated 5.

## Data and navigation

Settled 2026-11-05.

- Badges: neutral badges (`default`, `secondary`, `surface`) have no border. Rejected: the `primary/10` hairline on them, and lifting `surface` a step in dark, so `surface` is `bg-surface` in both themes, type chips in tables included. `default` and `secondary` fill with `bg-badge`, which is `--secondary` except on the app header, where in light it is white (`--header-badge`, 1.28:1 on the bar, text 13.5:1) so Draft still reads. Dark is unchanged. Filled status badges reach AA in both themes: `--warning-background` is yellow-700 and `--info-background` blue-800 in light, the AI badge is `--ai-background`. Outline badges force `!text-*` and, where the sort order loses, `!bg-transparent`: Tailwind's order made `primary` invisible and `warning` filled.
- A settings-style left nav is `nav-row`: 36px, 8px radius, medium, muted icon. Hover is `bg-state-hover`, the current page `bg-state-selected` with the strongest ink and the same weight, so nothing shifts.
- Sortable headers reserve the caret's space and take keyboard focus. Pointing anywhere in the table header shows the muted caret of every sortable column, not only the hovered one; focus shows that column's. The active sort's caret is strongest ink. The header row stays 40px.
- Pagination is ghost, with only the current page filled (`primary`).
- A quiet icon action in a row is `row-action`: 28px, 6px radius, muted, the wash on hover. Block rows use it with no gap between actions, and sit on `bg-card`.
- An image avatar that fails to load falls back to initials. Avatars carry an inset `primary/10` hairline.
- Calendar: today is `text-info-ink` and semibold, hover is the `state-hover` wash, days outside the month are `text-muted` (never an opacity of it, that fails AA).
- Stepper: upcoming is a `border-strong` inset ring with muted text, a finished step's line is `bg-success`.
- The tree's pending dot uses `outline` with an offset, so its gap shows the row behind it. A ring offset painted a fixed color.
- The tree search box is a field: `border-input-border`, `bg-input`, 32px, `border-ring` plus `ring-1` when focused.

## Failed and empty states

- A failed read must not read as an empty list or a zero. Dashboard, assets, blocks, content and knowledge show `QueryErrorState` (one sentence, Retry, `role="alert"`), and counts stay hidden until known. If only one source failed, it is named as missing and the other's rows stay. Inside a panel or table it uses `flush`, so there is no card in a card.
- A search with no hits says so and offers Clear search, never the first-use pitch. An empty area offers the action that fills it as its primary button.

## Trees

- All trees (content, asset folders, tags, collections, block folders) share the tree tokens. The selected row is the field fill (`bg-tree-selected`) with the strongest ink, hover is a lighter step of the same fill (`bg-tree-hover`), drop targets are the blue wash (`bg-tree-drop ring-1 ring-ring/40`). No blue selection border. Settled 2026-10-22, taking the asset tree as the model.
- A tree row is one line. The name truncates, counts and actions keep their place.
- From `md` up the content tree opens at 320px and can't go below 200px. Below `md` it opens at 120px and its width isn't saved.

- The tree row menu runs in groups split by hairlines: View and Edit; Publish, Publish options (with message, with children, schedule, unpublish) and Add to release; Ask AI and Automations, the latter only when one applies; New sub item, Rename, Duplicate; Copy, Cut and Paste (in, after); Delete last.

## Sidebars

- Every icon rail (the main app sidebar, the content editor's tab rail) uses the sidebar tokens: `bg-sidebar`, `text-sidebar-foreground`, hover `bg-sidebar-accent/60`, active `bg-sidebar-accent text-sidebar-accent-foreground`, so hover and active read apart (1.18:1 and 1.33:1 in light, 1.21:1 and 1.46:1 in dark). Settled 2026-11-05.
- The collapsed rail's items are exactly 32px square, centered on x=28. Every trigger in it uses these tokens, never `bg-elevated`, which equals the rail in light.

## Blocks block

Settled 2026-11-05. The blocks field in the entry editor.

- Every list has its own frame so depth reads at a glance: `bg-surface`, a hairline, with white rows on it. Depth 0 is `rounded-2xl px-2`. Nested lists are `rounded-xl px-1.5`, tighter so depth costs less width. Mike settled this on 2026-11-05 after frameless nested lists lost the distinction.
- Depth counts lists in the current column. Rows at depth 0 and 1 expand inline. Rows from depth 2 show a chevron-right and open in the same column through the hash route, with the breadcrumbs to return. An opened item starts again at depth 0. An item with no id has no route, so it stays expandable.
- Going back keeps the list as it was left: expanded rows stay expanded, each item keeps its tab, and the row you came from takes focus again.
- A row that hides its fields says when something inside is wrong: one line under the title with the icon in `text-destructive`. Review validation issues opens the way to the field: focus only if it is on screen, otherwise through the hash route and the tab.
- Adding from a row list that drills in opens the new item.
- A row is two lines: the title bold in strong ink, the block type under it in 13px muted. Mike settled this on 2026-11-05 after a one-line row. Rejected: title and type on one line.
- Row actions stay on the row on hover or focus-within: Hide/Show, Edit nested content, Copy, Cut, Delete, and the menu. Grip and arrow keys move a row. Rejected, Mike 2026-11-05: moving Copy, Cut and Delete into the menu.
- Delete has no confirm. It shows a toast with Undo for 8s, which restores the item at its index through the same block operation path as other edits. The toast goes away with the list or when the entry changes. Undo refuses when the field has filled up meanwhile, and says so.
- There is one add button: a 24px round plus, quiet at rest, accent with the insert line on hover or focus. Between rows it shows on hover or focus. At the end of a list, empty or not, it stays visible in a 28px spot of its own inside that list's frame, so it is clear which list it adds to. Paste is the same button beside it when the clipboard fits. Rejected, Mike 2026-11-05: a persistent outline button, a dashed empty-state button, and a tooltip naming the place.

## Comments

Settled 2026-11-05. Comments on an entry and its fields.

- Two places: the Comments tab in the editor rail reads all of an entry's threads, the field popover comments beside the field. The popover opens to the left of the whole field, anchored to the field and not its small trigger, so it never covers the content being discussed. Where it doesn't fit there, below about 980px, it opens under the field. No other surface, and no anchors on rich text selections.
- A thread in the tab starts with one muted line naming its place, the way the translation view names a field ("Hero 2, Header"). Pressing it goes back to the fields, opens the path, scrolls to the field and opens its popover. Rows at depth 0 and 1 expand, deeper ones open through the hash route. A thread that names only a block ("Hero 2") focuses that block's row. A thread on the whole entry names the entry in plain text, since there is nowhere to go. A block or field that is gone reads "This field no longer exists", and the thread reads on.
- The tab has Open and Resolved tabs with thread counts, Open first. A resolved thread reads at full contrast, the tab is what separates it. In the popover, which mixes both, it says "Resolved". Counts stay hidden until known.
- The field button sits in the label row, 28px, named after the field. With comments it stays visible with the count, in `text-warning-ink` while any is open. Without comments it shows on hover or focus within the field, and only for the innermost field, so a nested list doesn't light up every field in it.
- A comment reads: one 13px row with avatar, name in medium and time in muted, then the body at 15px. No reaction row until there is a reaction. Actions are 28px icon buttons with labels at the end of that row, shown on hover or focus-within.
- The composer is multi-line. Enter makes a new line, Cmd or Ctrl+Enter sends, the send button is always visible. A failed send, reply or edit keeps the text and the composer and says why under it, with no toast. Drafts are held by the entry page: a new comment by entry, item and field, a reply by its thread, an edit by its comment. They outlive closing the popover, a jump and switching tabs. Esc in a reply or edit does nothing, so it can't close the popover around them.
- `@` opens a picker of everyone with access to the space (names and avatars only, no ability needed beyond reading the comments). A mention shows as a chip with the name and is stored as `@<user id>`, the format `MentionExtractor` reads. A person who is gone reads "Unknown person", never the id.
- A failed read shows `QueryErrorState` with `flush`. An empty list is one muted line that says how to start.

## Forms

- Field hints (`description`) render as an info tooltip next to the label, not as text under the field. The text stays in the DOM for screen readers. Errors stay visible under the field.
- Page titles have no subtitle. A description that explains the page goes behind an info icon by the title (`ContentHeader` `description`); "Manage your X" lines are cut, not hidden.
- Settings pages are one document of `SettingsSection`s: title and explanation in a 15rem left column, controls on the right, the commit button in the section footer, sections divided by a hairline.
- Sections built around tables (Editor, Internationalization, Content, Asset library, Access tokens) use `wide`: heading on top, controls across the full width.
- Select and date triggers: the chevron is 16px, muted, 8px from the text and 8px from the right edge.

## Dashboard

Settled 2026-10-22.

- Grouped into sections with a heading each: Content, Delivery, Assets, Team. Each section holds its own KPI tiles and the charts that explain them.
- Keep every figure the space has: entries, blocks, data sources, data entries, redirects, languages, traffic, API requests, average response time, storage, people, events. Decluttering means removing repetition, never data.
- KPI tiles (`StatTile`) carry the history as a sparkline wherever the API has a series (creations per period, traffic, requests, hit-weighted response time). Pointing at it swaps the value for that period. Delivery has no large charts; the sparklines carry it. Changes go through `ChangeIndicator`: an arrow and the signed amount, green for up, red for down.
- Line charts are `TrendChart`: every series has a fading area gradient, zero-based scale whose middle tick is a whole number, 13px axes, a crosshair with the readout above the plot, one tab stop with arrow keys. Shares of a whole (content by type, data entries by source, assets by type) are `DistributionList` (stacked bar plus rows, linked hover), never a pie.
- The activity calendar stays GitHub-style. Cell size is a whole pixel count derived from the panel width, so every gap is exactly 3px; the hovered day's count shows in the line under the grid.
- Chart series colors come from `--series-1` to `--series-4` in that order, validated for colorblind separation in both modes.

## AI surfaces

Settled 2026-10-27. Concept: "Threads" in `docs/concepts/native-ai-integration.md`.

- AI icons are the animated bot set, never lucide sparkles or wands (settled 2026-10-31): `ai:bot` for general entry points, `ai:bot-message` for asking, `ai:bot-star` for skills, generation and classification, `ai:bot-translate` for AI translation. Use them through `<Icon name="ai:…">`. They play on hover of any `icon-anim` ancestor; buttons and menu items already carry it.
- Buttons that run AI are `variant="ai"`, split buttons included (settled 2026-10-31): a purple wash (`--ai-tint`, 10% light, 18% dark) with purple ink (purple-700 in light, for AA on the hover wash), a slightly stronger wash on hover. Dialog confirms that start an AI run use it too, in place of `primary`. Rejected: solid purple, it competes with Publish and the primary header action; a purple outline, the hairline vanishes at 28px in dark mode.
- Models (settled 2026-10-31, rejected: a searchable popover, too cramped for 400+ models):
  - Logos come from Lobe Icons through `<Icon :name="modelIcon(driver, model)">`: the model family's logo (Claude, Gemini), else the provider's, else `ai:bot`. Colour logos in AI settings and the model picker; mono logos in every compact picker and menu.
  - Capabilities are vision, reasoning and tool use, as small icons (`AiCapabilityIcons`). No context window or price in the UI.
  - The model picker is a two-pane dialog: providers with counts on the left (Favourites and All first), fuzzy search and Reasoning/Vision/Tools filter chips on the right, a star to favourite. Names drop OpenRouter's `Vendor: ` prefix.
  - Every config list is `AiConfigMenuItems` (composer, AI text box, split-button menus). Split menus check the default config.
  - Reasoning is Off, Low, Medium, High as three rising bars (`AiReasoningBars`). Each config sets a default (Medium when unset); the composer and AI text box override it per thread; feature buttons always use the default. Models that always reason don't offer Off.
- The content editor has no AI tab. Asking about an entry happens in the dock, with the entry and its last focused field attached. The dock lists threads about the current page first and an empty thread offers up to three skills that fit the page.
- "Enable AI Features" (Settings → AI) switches every AI entry point off, not just greys it out: the header button, the dock and Cmd+J, the AI items in Cmd+K, the rich text AI menu, Ask AI in the content tree, assets and data sources, meta generate, the translate buttons, asset classification and the canvas AI dock. Gate new AI buttons with `useAiEnabled(spaceId)`. AI settings stay reachable so the flag can be turned back on; with AI off the configurations stay listed, dimmed and inert, with a note that they are kept. Settled 2026-10-31.
- Every AI conversation and run lives in a thread, shown in the threads dock on the right of every space page. There is no inbox and no AI task dialog. Don't add a second global AI surface; extend the dock or Cmd+K.
- The dock pushes the page on wide screens (resizable, width remembered) and overlays as a sheet below 1280px. Cmd+J toggles it. The header button carries the count of threads that need you; the main sidebar has no AI entry.
- Feature buttons stay inside features. They start threads but open the dock only when there is something to review or answer. Bulk starts are the exception and open the dock on the running thread.
- AI changes are cards in the thread, one per entry. Cross-locale changes show source and translation side by side, same-locale edits show the word diff. More than five entries collapse into one summary card.
- Content goes straight to its draft, so its fields carry a green "Written to draft" badge and no buttons, and the entry links to its draft and its history. One line says what happened ("Changed 412 entries, 3 conflicts, 7 flagged"), strong in the operation receipt and muted in a thread. Entries with conflicts come first, then flagged wording: those are the only things that need a person. Their counts use `text-warning-ink`. Settled 2026-11-04. Rejected: a group undo, the version history is how to go back.
- What AI left on an entry shows where people publish, and never blocks. In the tree a row carries a 14px warning triangle in `text-warning-ink` with the count as its label and tooltip, a translation's flags on its entry's row. Publish, publish with message and schedule name the wording, field and a link in one warning `Alert` inside the publish dialog (`ContentFlagsNotice`), and a plain Publish opens that dialog only when there is something to name. Publish with children, a version, mass edit and committing a release ask with a count. A flag has no dismiss: it goes when the wording leaves the field or the draft is published. Settled 2026-11-04.
- Ask AI has one primary action. A small task says "Start on 3 entries". Past one run's entry limit or estimated above the run stop it says "Review plan", with one line of why above the footer, and opens the plan and budget. An estimate never reads $0.00: under a cent reads "Under $0.01", no known price reads "Not known for this model". Settled 2026-11-04.
- Proposals (assets, folders, tags, data entries, blocks) keep per-field accept and reject as icon buttons, a status badge only once decided, and Accept all and Reject all past five entries. A conflict keeps its keep-current and use-AI-version buttons.
- The dock copies T3 Code's thread list and composer: quiet, minimal, no borders between rows. Settled 2026-10-27, reference in the concept doc ("UI modelled on T3 Code").
  - List header: ghost search row and a small square-pen icon button for a new thread. No text buttons, no archive switch.
  - Rows are inbox cards: context line with the status (icon and label in the status color) or the relative time on the right, the title, an optional muted meta line.
  - Done work is settled by hand ("Settle" on hover) and moves to the "Settled" shelf. The active list fills the height; the shelf is pinned to the bottom (one header line with a muted count) and opens upward. Settled rows are one line: title and time.
  - Rows use the content tree highlight: `bg-tree-selected text-tree-selected-foreground` for the active row, `hover:bg-tree-hover`.
  - Chrome (thread list, dock and thread headers) sits on `bg-sidebar`; the conversation body on `bg-background`, like the dashboard; the composer is the only card on it.
  - Composer: one rounded-2xl surface with a hairline outline and soft shadow; the config picker on the left of the footer, attach and the round send button on the right. The config picker trigger is a ghost label with the mono model logo and a small chevron; its list shows the mono logo, name, model in muted text below, and a check. For models that reason, the reasoning bars sit next to it. Settled 2026-10-31.
  - Skills are not shown up front; `/` opens them.
  - A paused run's questions are a tray inside the composer, above the editor, split by a hairline, one question at a time. Options are borderless rows with the tree highlight (`bg-tree-selected` selected, `hover:bg-tree-hover`) and a muted number hint on the right; 1 to 9 pick while the editor is empty. Typed text is an own answer and dims the options. Settled 2026-10-31.
  - A turn reads: prompt bubble, work log, answered questions (muted question, answer below), the answer with a hover copy button, sources. The work log and sources are one muted line with a chevron ("Worked for 14s, 4 steps", "2 sources") that opens into an indented list on a hairline.
  - The empty thread is one muted line centered above the composer. The thread header shows the thread's tokens as a muted label; the breakdown is in its tooltip, cost only on metered spaces.
- AI settings (Skills, Knowledge, Schedules) have three nav entries. Each page has the title with the primary action top right; lists are one table with a type or source column. Glossary detail is wording first, see "Glossary editor". An inherited glossary opens to read, and its source column names the team that owns it.

## Translation view

- Source and target columns carry a sticky header with the two language names. A field reads its name and its block ("Header", "Hero 2"), and the raw path is only a tooltip.
- An empty translation looks empty: no source text as placeholder, and a "Missing" mark with the dashed circle in `text-warning-ink`. Source field values are read-only in one muted style. Rich text keeps its disabled editor, and the entry name and slug are still disabled.

## Narrow screens

- Below `md` the asset folders, tags and collections move into a left sheet opened by "Browse folders". Below `md` the team nav is a horizontal scroller above the page. The content editor has no phone layout yet.

## Glossary editor

Settled 2026-11-04 (W9). Each row is one meaning: per language Use, Avoid and Also written as, plus one Note and Keep exact. There are no statuses, categories, audiences or overrides. Rejected: anything that asks a person with four terms to learn more than these five fields.

- People read "term". "Concept" stays a word for the code and the sheet's `concept` column.
- A region's language replaces its base language whole, in the editor and everywhere the AI reads it. A region with no language of its own uses the base. A sibling region never stands in.
- A row with no default wording goes by another language's wording, muted, with that language's name beside it and the dashed mark for the gap, in the list and in the sheet's header. A word from another language must never pass as the default language's.

- One table, sticky header, its own scroll area. The first column is the default language's wording and stays frozen while the other languages scroll under it, so that language has no second column and its header is just the language name. A row with no Use shows its first Avoid wording with a ban icon, and the word "Ban" where the column has room (screen readers always get it).
- A language with no wording of its own in any row on the page folds its column: every cell would say the same thing, and coverage keeps the gap in view. Tried at six and ten languages, with two to three regional languages folded. A button by the filters names up to two of them and shows them (`columns=all` in the URL). The language being filtered on never folds. Rejected: a column chooser, it adds state to save.
- A language cell reads three ways, none by color alone: own wording; muted with a corner arrow for wording a base language serves (`de` for `de-AT`); warning ink with a dashed circle and the word "Missing".
- Coverage sits above the table as one tile per language, always with its "x of y" figure. A complete language is muted and inert. A gap is strong with "n missing" in warning ink, and pressing it filters to its gaps. Languages the space doesn't use are listed apart under "Not used by this space".
- Filters are search (`/`) and "Missing in <language>", nothing else. Selection only deletes. The page menu holds Import spreadsheet, Export, Try a text and Delete glossary. The pager hides up to 25 rows.
- Filling cells goes through a non-modal side sheet: 704px on wide screens with the list usable beside it, full screen below `lg`. The open row uses the tree highlight. Save is explicit, because a save changes live AI policy, and one visible sentence beside Save says what it reaches: new runs of this space or of every space in this team, not runs already started. Rejected: inline cell editing, it hides that a save is a policy change. Rejected: a confirmation step.
- The sheet reads top to bottom: language tabs, Use, Avoid, Also written as, a hairline, Note, Keep exact. Wording comes first on every width. No cards and no status control. Avoid with no Use says "Ban: the AI must never write this." under Use. Refusals on a field (409 wording, 422 Use and Avoid) stay beside that field, and the text stays. A save over a value someone else saved meanwhile is refused above Save: it reloads the row, keeps the typed text, names the fields where their version differs without quoting values, and a second Save replaces those. If the row can't be reloaded the message says so and promises no overwrite. Saves to different fields merge without a word. Rejected: a merge dialog.
- Each sheet field keeps its one sentence visible under the label, an exception to the tooltip rule in Forms: the sheet is five fields and those sentences are the whole manual. Note shows a visible count up to 200. A longer older note reads fine and asks to be shortened only once edited.
- Language tabs wrap, nothing hides in a scroller. Full names from `sm` up, codes on a phone so the tabs don't push Use down. Only a tab that needs a look carries a mark: served by a base language, missing, removed. An asterisk marks unsaved changes in that tab. A team glossary adds a language behind a button.
- The default language's wording stays as one muted line under Use when editing another language. The AI action sits on that line, is named for the language it fills ("Suggest German wording"), fills Use only, and only shows when there is something to fill.
- Text the AI filled stays in the form, unsaved, with a purple "Use suggested by AI, not saved yet" line that goes away once the field is edited or saved. If the glossary flags the suggestion, each finding shows with its wording and replacement, never as a count, and goes with that line.
- Try a text is a dialog, from the page menu or from a concept with its wording in the text. It shows the text with matches marked (chip fill and an underline), one flat list of what would be flagged, and each term's one instruction from the server, which opens its concept. A hint says in words that only the AI is told. Rejected: a panel under the table, it pushed a rarely used tool onto every visit.
- Try a text reads saved wording only, and says so. From a sheet with unsaved changes the action reads "Save and try". In a space it can check this glossary or everything runs in the space use: a term from another glossary names that glossary and does not open. A switched-off glossary says runs do not use it. This dialog and the import dialog open above the sheet. They sit at one z-index, and a teleport's place in the page is set when its component mounts, so both stay after the sheet in the template.
- Keyboard hints appear in the panel header only while focus is in the list, in a spot reserved so nothing moves.
- Warning as text uses `text-warning-ink` (yellow-700 in light, `--warning` in dark). `text-warning` is 2.9:1 on a light surface, too low for 13px text. On a filled surface (table header, tree selection, input) in dark mode `text-muted` is 4.2:1, so text there is `text-foreground`.
- `text-destructive` at 13px is 4.49:1 on the sheet in light and 4.37:1 in dark. Bans, flags and delete actions here use the strongest ink with the icon in `text-destructive`.

## Team area and provider console

Settled 2026-10-31. Concept: `docs/concepts/team-management.md`.

- The team area and the provider console (`/provider`) use the settings-style left nav (same look as space settings). No header tabs. The hierarchy tree is not a sidebar any more; it lives in the team switcher.
- Team switcher keeps the look of the old Select: input-styled trigger with the team icon, name and type badge, items with `py-1.5 pl-2 pr-8`, the active row in the accent fill, a check on the current team, 16px indent per level. Inside sits the content tree search: matches plus their ancestor chain in tree order, ancestors muted, matched characters highlighted, a "1 of 3" counter, ↑/↓ walk the matches. Settled 2026-10-31 after the avatar-chip popover was called a regression. Rejected: a breadcrumb path (B) and a breadcrumb with scoped popovers (C), both crowd the header on deep hierarchies.
- The teams start page (`/teams`) is a dashboard: `StatTile` row across every reachable team, then one card per top team, laid out like a small team overview: header with the team mark, name and type badge, then the overview's figures for the subtree (Members with active, Spaces with published, Sub-teams, values at `text-figure`), sub-team chips, member stack, owner and invite signals. No color wash; the mark carries the team color. Settled 2026-10-31. Teams without a color take a stable palette color from their id. Personal teams without sub-teams are listed compactly below the cards, not as cards. A Tree view lists every team indented.
- Team Overview: KPI row of `StatTile`s, then the structure panel (org chart of teams with their spaces as leaves, drag a team onto another to move it, confirm lists who gains and loses access), then "Needs attention" and "Activity" side by side.
- Access is drawn the same everywhere: inherited (from a team role) is a dashed outline in muted ink, direct is the solid field fill. The People page has a list view and an access matrix (people by spaces).
- The spaces start page lists the selected team's spaces and those of every team below it, grouped by team in tree order (team mark, muted ancestor path, name, count). Search and an All / This team / Sub-teams switch with counts sit above the groups; with no sub-teams there are no group headings.
- Root context always shows a banner under the header: neutral while viewing (team actions disabled), warning while elevated, AI purple while impersonating.
