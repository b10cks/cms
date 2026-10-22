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
