# Design rules

Rules Mike settled for the CMS UI. Tokens live in `resources/js/assets/css/main.css`. Where this file and a component disagree, this file wins; fix the component.

## Surfaces

- Every panel on a page is a `Panel` (`components/ui/panel`): hairline, card tone, a 56px header with the title at `text-panel` (17px), optional muted meta and actions on the right. Tables and charts that run to the edges use `flush`.
- Never nest a card in a card. A chart component draws only the chart; the page wraps it in one `Panel`.
- Cards keep `shadow-soft-sm` on top of the hairline. This is the project's depth convention (see the elevation comment in `main.css`) and overrides the generic "shadows only on floating layers" rule.
- Tags and chips inside a field use `bg-chip` / `text-chip-foreground`, one step off the field fill in both modes.

## Type

- Body is 15px (`text-base`). The smallest size for readable content is 13px (`text-sm`): labels, hints, table cells, chart axes. No `text-xs` or smaller outside badges.
- Extra sizes only for page titles (`text-2xl`), panel and section titles (`text-panel`) and KPI figures (`text-figure`, 28px).
- Figures: the value is semibold in the strongest ink, the label sits above it, 13px and muted.
- No middle-dot (`·`) separators. Use a comma, a line break or separate elements.

## Trees

- All trees (content, asset folders, tags, collections, block folders) share the tree tokens. The selected row is the field fill (`bg-tree-selected`) with the strongest ink, hover is a lighter step of the same fill (`bg-tree-hover`), drop targets are the blue wash (`bg-tree-drop ring-1 ring-ring/40`). No blue selection border. Settled 2026-10-22, taking the asset tree as the model.
- A tree row is one line. The name truncates, counts and actions keep their place.

## Sidebars

- Every icon rail (the main app sidebar, the content editor's tab rail) uses the sidebar tokens: `bg-sidebar`, `text-sidebar-foreground`, hover `bg-sidebar-accent`, active `bg-sidebar-accent text-sidebar-accent-foreground`.

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

- Every AI conversation and run lives in a thread, shown in the threads dock on the right of every space page. There is no inbox and no AI task dialog. Don't add a second global AI surface; extend the dock or Cmd+K.
- The dock pushes the page on wide screens (resizable, width remembered) and overlays as a sheet below 1280px. Cmd+J toggles it. The header button carries the count of threads that need you; the main sidebar has no AI entry.
- Feature buttons stay inside features. They start threads but open the dock only when there is something to review or answer. Bulk starts are the exception and open the dock on the running thread.
- Proposals are cards in the thread, one per entry. Per-field accept and reject are icon buttons. A field shows a status badge only once it is decided. Cross-locale changes show source and translation side by side, same-locale edits show the word diff. More than five entries collapse into one summary card with Accept all and Reject all.
- The dock copies T3 Code's thread list and composer: quiet, minimal, no borders between rows. Settled 2026-10-27, reference in the concept doc ("UI modelled on T3 Code").
  - List header: ghost search row and a small square-pen icon button for a new thread. No text buttons, no archive switch.
  - Rows are inbox cards: context line with the status (icon and label in the status color) or the relative time on the right, the title, an optional muted meta line.
  - Done work is settled by hand ("Settle" on hover) and moves to the "Settled" shelf. The active list fills the height; the shelf is pinned to the bottom (one header line with a muted count) and opens upward. Settled rows are one line: title and time.
  - Rows use the content tree highlight: `bg-tree-selected text-tree-selected-foreground` for the active row, `hover:bg-tree-hover`.
  - Chrome (thread list, dock and thread headers) sits on `bg-sidebar`; the conversation body on `bg-background`, like the dashboard; the composer is the only card on it.
  - Composer: one rounded-2xl surface with a hairline outline and soft shadow; the config picker on the left of the footer, attach and the round send button on the right. The config picker trigger is a ghost label with a small chevron; its list shows name, model in muted text below, and a check.
  - Skills are not shown up front; `/` opens them.
- AI settings (Skills, Knowledge, Schedules) have three nav entries. Each page has the title with the primary action top right; lists are one table with a type or source column. Glossary detail looks like a data source: one column per language, headed by the language name, not the locale code.

## Team area and provider console

Settled 2026-10-31. Concept: `docs/concepts/team-management.md`.

- The team area and the provider console (`/provider`) use the settings-style left nav (same look as space settings). No header tabs. The hierarchy tree is not a sidebar any more; it lives in the team switcher.
- Team switcher is a search popover (variant A): avatar and name trigger, fuzzy search with `lib/fuzzy-match` and `HighlightedText` like the content tree, recent teams, then the nested tree with space counts. Rejected: a breadcrumb path (B) and a breadcrumb with scoped popovers (C), both crowd the header on deep hierarchies.
- Team Overview: KPI row of `StatTile`s, then the structure panel (org chart of teams with their spaces as leaves, drag a team onto another to move it, confirm lists who gains and loses access), then "Needs attention" and "Activity" side by side.
- Access is drawn the same everywhere: inherited (from a team role) is a dashed outline in muted ink, direct is the solid field fill. The People page has a list view and an access matrix (people by spaces).
- Root context always shows a banner under the header: neutral while viewing (team actions disabled), warning while elevated, AI purple while impersonating.
