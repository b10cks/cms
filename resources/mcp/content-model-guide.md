# b10cks Content Modeling Guide

Validated against the CMS source (BlockSchemaRequestValidator, SchemaNormalizer, ContentSchemaValidator).
Derived from a production project with 78 blocks.

---

## Block Types

Every block has a `type` that controls how it appears in the content tree:

| type | Purpose | Example |
|------|---------|---------|
| `root` | Top-level content entries — become pages/documents in the tree | Page, News, Event, Person, Job |
| `nestable` | Reusable components embedded inside other blocks via `blocks` fields | Hero, Button, Accordion, Form |
| `single` | Singleton entries — only one exists per space | Config, Checkout, EmailTemplate |
| `universal` | Like `root` but also surfaced to the AI assistant as a nestable component | LandingSection, Campaign |

**root vs universal**: Both can be placed anywhere in the content tree. `universal` is additionally offered by the built-in AI content generation as a nestable option, making it suitable for blocks that are both standalone pages and embedded components.

**single blocks** model site-wide settings: config containing navigation menus, footer content and other global content settings.

---

## Slug Format

`slug` must match `^[a-z][a-z0-9A-Z]+$`:
- Start with a **lowercase letter**
- Followed by lowercase letters, **uppercase letters**, or digits
- **No hyphens, no underscores** (use camelCase instead: `myBlock`, not `my-block`)
- Max 50 characters
- Must be unique within the space

---

## Field Types

Supported types (validated by the CMS):

| type | Use for | Translatable | Indexed by default |
|------|---------|:-----------:|:-----------------:|
| `text` | Single-line: titles, names, labels | ✓ | ✓ |
| `textarea` | Multi-line plain text: summaries, addresses | ✓ | ✓ |
| `richtext` | Rich HTML content with formatting | ✓ | ✓ |
| `markdown` | Markdown for developer/technical content | ✓ | ✓ |
| `number` | Numeric input | ✓ | — |
| `boolean` | On/off toggle | — | — |
| `option` | Single-select from predefined list | ✓ | — |
| `options` | Multi-select from predefined list | — | — |
| `link` | URL / internal / email / asset link | ✓ | — |
| `asset` | Single media asset (image, video, document) | — | — |
| `multi_assets` | Multiple assets — galleries, sliders | — | — |
| `references` | References to other content entries | — | — |
| `date` | Date or datetime | ✓ | — |
| `meta` | SEO metadata (title, description, OG). One per root block. | ✓ | — |
| `blocks` | Composable nested block slot | — | — |
| `table` | Structured table with typed columns | ✓ | — |
| `icon` | Icon picker — space registry or Iconify public collections | — | — |
| `geo` | Geographic coordinate (lat/lon with optional altitude) | — | — |
| `price` | Multi-currency price object (currency code → amount) | — | — |

**Never-indexable types** (setting `indexable: true` is a validation error): `asset`, `multi_assets`, `icon`, `geo`, `price`, `references`, `boolean`, `options`, `table`

---

## Field Configuration: Key Options

### All fields
- `name` (string) — human-readable label shown in the editor. Defaults to `Str::headline(key)` if omitted.
- `description` (string|null) — optional help text shown under the field
- `required` (boolean, default false) — field must have a value before publishing (save always allowed)
- `translatable` (boolean, default false) — field has per-locale values. Only settable on translatable types (see table above).
- `indexable` (boolean) — include value in search index. Default true for `text`, `textarea`, `richtext`, `markdown`, `meta`. Cannot be set on never-indexable types.
- `default` — default value for the field (type-specific)
- `conditions` — conditional visibility (see below)

### `text` / `textarea` / `markdown` / `richtext` validation
```json
{
  "validation": {
    "min_length": 10,
    "max_length": 500,
    "pattern": "/^[A-Z]/"
  }
}
```
Note: `markdown` and `richtext` do not support `pattern`.

### `number` validation
```json
{ "validation": { "min": 0, "max": 100 } }
```

### `date` validation
```json
{ "validation": { "min": "2024-01-01", "max": "2030-12-31" } }
```

### `option` field (single-select)
```json
{
  "type": "option",
  "options": [
    { "name": "Small", "value": "sm" },
    { "name": "Medium", "value": "md" },
    { "name": "Large", "value": "lg" }
  ],
  "default": "md",
  "exclude_empty_option": false
}
```
Or from a data source:
```json
{ "type": "option", "source": "datasource", "data_source_id": "<ulid>" }
```

### `options` field (multi-select)
Same structure as `option` but value is an array, and `min`/`max` control selection count:
```json
{
  "type": "options",
  "options": [{ "name": "Tag A", "value": "a" }, { "name": "Tag B", "value": "b" }],
  "default": ["a"],
  "validation": { "min": 1, "max": 3 }
}
```
Note: `options` is NOT translatable.

### `boolean` fields
```json
{ "type": "boolean", "default": false, "show_inline": true }
```
Use `show_inline: true` for compact toggle display in the editor.

### `link` fields
```json
{
  "type": "link",
  "email_link_type": true,
  "asset_link_type": true,
  "allow_target_blank": false,
  "show_anchor": false
}
```
Defaults: `email_link_type: true`, `asset_link_type: true`, `allow_target_blank: false`.
Link value shape at runtime: `{type: "url"|"email"|"internal"|"asset", url?, email?, content?, ...}`

### `asset` fields
Value shape: `{ type: "asset", id: "<ulid>" }`

### `multi_assets` fields
Array of asset objects. Validation: `{ min: 1, max: 10 }`

### `references` fields
Array of content entry IDs (strings). Validation: `{ min: 0, max: 5 }`

### `blocks` fields
```json
{
  "type": "blocks",
  "restrict_blocks": true,
  "tag_whitelist": ["Molecule", "Organism"],
  "block_whitelist": ["stickyCta"],
  "validation": { "min": 0, "max": 1 }
}
```
- `restrict_blocks: true` — enables restrictions (master toggle)
- `tag_whitelist` — allow all blocks tagged with any of these tags (additive with `block_whitelist`)
- `block_whitelist` — allow specific block slugs (additive with `tag_whitelist`)
- When `restrict_blocks: false`, all blocks are allowed (open slot)
- Validation `min`/`max` — constrain item count (`max: 1` for singleton slots)
- Always use `restrict_blocks: true` — open slots make content authoring unpredictable

### `meta` fields
```json
{ "type": "meta", "has_og_tags": true, "translatable": true }
```
Use `has_og_tags: true` to expose OG tag fields in the editor.
Value shape at runtime: `{ title?, description?, canonical?, robots?, ogTitle?, ogDescription?, ogImage? }`
Place exactly one `meta` field per `root` block; never use it on `nestable` blocks.

### `table` fields
```json
{
  "type": "table",
  "has_thead": true,
  "columns": [
    { "key": "name", "label": "Name", "type": "text" },
    { "key": "count", "label": "Count", "type": "number" },
    { "key": "active", "label": "Active", "type": "boolean" },
    { "key": "status", "label": "Status", "type": "option", "source": "self",
      "options": [{ "name": "Active", "value": "active" }, { "name": "Inactive", "value": "inactive" }] }
  ],
  "validation": { "min": 1, "max": 50 }
}
```
Column types: `text`, `number`, `boolean`, `option` (with the same source/options config as the option field).
`has_thead`: whether to show editable column header labels.
`validation.min`/`max`: min/max number of rows.
IS translatable (`translatable: true` is valid).

### `icon` fields
```json
{
  "type": "icon",
  "source": "all",
  "allowed_collections": []
}
```
- `source`: where icons can be picked from:
  - `"registry"` — only the space's own uploaded icons
  - `"all"` — registry plus any public Iconify collection
  - `"collections"` — registry plus the allow-listed Iconify collections
- `allowed_collections`: array of Iconify collection names (only relevant when `source: "collections"`)
- Value at runtime: fully-qualified icon name string — `b10cks:{key}` for space registry icons, or `{collection}:{name}` for Iconify icons (e.g. `mdi:home`)
- NOT translatable, NOT indexable.

### `geo` fields
```json
{
  "type": "geo",
  "key_style": "lat_lng",
  "altitude": false,
  "map": true
}
```
- `key_style`: controls the JSON key names of the stored value:
  - `"lat_lng"` (default) → `{ lat, lng, alt }`
  - `"lat_lon"` → `{ lat, lon, alt }`
  - `"latitude_longitude"` → `{ latitude, longitude, altitude }`
- `altitude`: `true` to enable the altitude field (optional in the value, stored as `null` when blank); `false` to omit altitude entirely
- `map`: `true` to show a map picker widget in the editor
- Value at runtime: plain object with the resolved key names → `number | null` (altitude key omitted when `altitude: false`)
- NOT translatable, NOT indexable.

### `price` fields
```json
{
  "type": "price",
  "base_currency": "EUR",
  "currencies": ["USD", "GBP"]
}
```
- `base_currency`: required base currency — 1–3 uppercase letter ISO 4217 code. The editor always shows this first and it is required when the field is set.
- `currencies`: additional optional currencies shown in the editor (same format)
- Value at runtime: `Record<string, number | null>` — currency code → amount (`null` when not filled in)
  - e.g. `{ "EUR": 9.99, "USD": 10.99, "GBP": null }`
- NOT translatable, NOT indexable.

---

## Conditional Visibility (`conditions`)

Show/hide a field based on the value of another field in the same block:

```json
{
  "conditions": {
    "mode": "all",
    "rules": [
      { "field": "variant", "operator": "equals", "value": "hero" },
      { "field": "showImage", "operator": "equals", "value": true }
    ]
  }
}
```
- `mode`: `"all"` (AND) or `"any"` (OR)
- `operator` by field type:
  - `boolean`: `equals`, `not_equals`, `is_empty`, `is_not_empty`
  - `number`, `date`: `equals`, `not_equals`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `is_empty`, `is_not_empty`
  - all others: `equals`, `not_equals`, `in`, `not_in`, `contains`, `is_empty`, `is_not_empty`
- `value` is omitted for `is_empty`/`is_not_empty`

---

## Editor Layout (`editor` array)

Group schema fields into labeled tabs. **Every schema field must appear in exactly one editor tab** — the API rejects schemas where a field is not assigned. If you omit `editor`, the CMS auto-generates a single "General" tab containing all fields.

```json
[
  { "header": "General", "items": ["header", "bodytext", "body"] },
  { "header": "SEO",     "items": ["meta"] },
  { "header": "Style",   "items": ["fullLogo", "topPadding"] }
]
```
Common tab names from the reference project: **General**, **SEO**, **Style**, **Menu**, **Footer**, **Contact**, **Cart**.

---

## Preview Template

Mustache string used in the editor tree view to identify nestable block instances:
```
"preview_template": "{{header}}"
"preview_template": "{{header}} – {{subheader}}"
"preview_template": "{{label}}"
```
Always set `preview_template` on nestable blocks so editors can distinguish items in lists.

---

## Tag System

Tags need to be setup before usage through the block tags semantics. Their primary purpose is to power `tag_whitelist` restrictions on `blocks` fields. Use a consistent naming convention across your space.

The reference project uses an atomic design hierarchy:

| Tag | Role | Real examples |
|-----|------|---------------|
| `Atom` | Smallest primitives, no block children | Button, TextButton, AssetLink, Faq, DefinitionListEntry |
| `Molecule` | Combinations of atoms | SimpleText, Media, YoutubeEmbed, VideoPlayer, Form, Membership |
| `Organism` | Full page sections containing molecules/atoms | Hero, Gallery, Accordion, NewsTeaser, Timeline, CtaGrid |
| `Navigation` | Navigation-specific blocks | Menu, MenuItem, FooterCard |
| `FormField` | Form field components used inside Form's `fields` slot | FormInput, FormTextarea, FormCheckbox, FormSelect, FormUpload |
| `Drawer` | Blocks that can appear in drawer/side-panel areas | SimpleText, YoutubeEmbed, VideoPlayer, Form |
| `Listable` | Item blocks for list/collection containers | CollapsibleContentItem, RadioEvents, ListItem |

**Design principle**: Restrict parent block slots by tag. E.g. a page `body` accepts `Molecule` + `Organism`; a form's `fields` slot uses the `FormField` tag. This keeps slot rules maintenance-free as you add blocks — new blocks auto-qualify just by having the right tag.

---

## Canonical Patterns (from a reference project)

### 1. Standard Page (root)
```json
{
  "name": "Page", "slug": "page", "type": "root", "icon": "file", "color": "#FE4E47",
  "schema": {
    "body":   { "type": "blocks", "name": "Body", "restrict_blocks": true,
                "tag_whitelist": ["Molecule", "Organism"], "block_whitelist": [] },
    "meta":   { "type": "meta", "name": "Meta", "has_og_tags": true, "translatable": true },
    "sticky": { "type": "blocks", "name": "Sticky", "restrict_blocks": true,
                "block_whitelist": ["stickyCta"], "tag_whitelist": [],
                "validation": { "min": 0, "max": 1 } }
  },
  "editor": [
    { "header": "General", "items": ["body", "sticky"] },
    { "header": "SEO",     "items": ["meta"] }
  ]
}
```

### 2. Content Type with Teaser Fields (root — e.g. News, Article)
```json
{
  "name": "News", "slug": "news", "type": "root",
  "schema": {
    "title":       { "type": "text",     "translatable": true, "indexable": true },
    "summary":     { "type": "textarea", "translatable": true, "indexable": true },
    "image":       { "type": "asset" },
    "publishedAt": { "type": "date" },
    "body":        { "type": "blocks", "restrict_blocks": true, "tag_whitelist": ["Molecule", "Organism"], "block_whitelist": [] },
    "meta":        { "type": "meta", "has_og_tags": true, "translatable": true },
    "sticky":      { "type": "blocks", "restrict_blocks": true, "block_whitelist": ["stickyCta"], "tag_whitelist": [], "validation": { "max": 1 } }
  },
  "editor": [
    { "header": "General", "items": ["title", "summary", "image", "publishedAt", "body", "sticky"] },
    { "header": "SEO",     "items": ["meta"] }
  ]
}
```

### 3. Section / Organism Block (nestable)
Standard full-width section with headline, body copy and action buttons:
```json
{
  "name": "Simple Text", "slug": "simpletext", "type": "nestable",
  "tags": ["Molecule", "Organism"],
  "preview_template": "{{header}}",
  "schema": {
    "header":   { "type": "text",     "name": "Header",   "translatable": true, "indexable": true },
    "subheader":{ "type": "text",     "name": "Subheader","translatable": true },
    "bodytext": { "type": "richtext", "name": "Body",     "translatable": true },
    "actions":  { "type": "blocks",   "name": "Actions",  "restrict_blocks": true, "tag_whitelist": ["Atom", "CTA"], "block_whitelist": [] },
    "appends":  { "type": "blocks",   "name": "Appends",  "restrict_blocks": true, "tag_whitelist": ["Molecule"], "block_whitelist": [] }
  },
  "editor": [{ "header": "General", "items": ["header", "subheader", "bodytext", "actions", "appends"] }]
}
```

### 4. List Container + Typed Item (Organism + custom tag)
```json
// Container
{
  "name": "Full Width List", "slug": "fullWidthList", "type": "nestable", "tags": ["Organism"],
  "preview_template": "{{header}}",
  "schema": {
    "header": { "type": "text" },
    "items":  { "type": "blocks", "restrict_blocks": true, "tag_whitelist": ["Listable"], "block_whitelist": [] }
  },
  "editor": [{ "header": "General", "items": ["header", "items"] }]
}
// Item
{
  "name": "Collapsible Item", "slug": "collapsibleContentItem", "type": "nestable", "tags": ["Listable"],
  "preview_template": "{{header}}",
  "schema": {
    "header":    { "type": "text",    "translatable": true },
    "subheader": { "type": "text",    "translatable": true },
    "intro":     { "type": "textarea","translatable": true },
    "content":   { "type": "blocks",  "restrict_blocks": true, "tag_whitelist": ["Molecule"], "block_whitelist": [] }
  },
  "editor": [{ "header": "General", "items": ["header", "subheader", "intro", "content"] }]
}
```

### 5. CTA Atom (nestable — link + label only)
```json
{
  "name": "Button", "slug": "button", "type": "nestable", "tags": ["Atom"],
  "preview_template": "{{label}}",
  "schema": {
    "label":   { "type": "text",    "translatable": true },
    "link":    { "type": "link",    "translatable": true, "allow_target_blank": true },
    "variant": { "type": "option",  "options": [{"name": "Primary","value": "primary"},{"name": "Secondary","value": "secondary"}], "default": "primary" },
    "size":    { "type": "option",  "options": [{"name": "Small","value": "sm"},{"name": "Medium","value": "md"},{"name": "Large","value": "lg"}], "default": "md" },
    "color":   { "type": "option",  "options": [{"name": "Default","value": "default"},{"name": "Accent","value": "accent"}], "default": "default" },
    "disabled":{ "type": "boolean", "default": false, "show_inline": true }
  },
  "editor": [{ "header": "General", "items": ["label", "link", "variant", "size", "color", "disabled"] }]
}
```

### 6. Navigation (Menu → MenuItem)
```json
// Menu
{
  "name": "Menu", "slug": "menu", "type": "nestable", "tags": ["Navigation"],
  "preview_template": "{{header}}",
  "schema": {
    "header": { "type": "text" },
    "items":  { "type": "blocks", "restrict_blocks": true, "block_whitelist": ["menuitem"], "tag_whitelist": [] }
  },
  "editor": [{ "header": "General", "items": ["header", "items"] }]
}
// MenuItem
{
  "name": "Menu Item", "slug": "menuitem", "type": "nestable", "tags": ["Navigation"],
  "preview_template": "{{label}}",
  "schema": {
    "label": { "type": "text", "translatable": true },
    "link":  { "type": "link", "translatable": true }
  },
  "editor": [{ "header": "General", "items": ["label", "link"] }]
}
```
Assign menus to a `single` Config block's fields — keep navigation out of page content.

### 7. Form with Typed Fields (Molecule + FormField tag)
```json
// Form
{
  "name": "Form", "slug": "form", "type": "nestable", "tags": ["Molecule", "Drawer"],
  "schema": {
    "fields":          { "type": "blocks",   "restrict_blocks": true, "tag_whitelist": ["FormField"], "block_whitelist": [] },
    "submit":          { "type": "text",     "translatable": true },
    "receivers":       { "type": "text" },
    "emailSubject":    { "type": "text" },
    "emailText":       { "type": "markdown" },
    "successHeader":   { "type": "text",     "translatable": true },
    "successBodytext": { "type": "textarea", "translatable": true },
    "sendConfirmation":{ "type": "boolean",  "default": false, "show_inline": true },
    "confirmationSubject": { "type": "text" },
    "confirmationText":    { "type": "markdown" }
  },
  "editor": [
    { "header": "General", "items": ["fields", "submit"] },
    { "header": "Email",   "items": ["receivers", "emailSubject", "emailText"] },
    { "header": "Success", "items": ["successHeader", "successBodytext", "sendConfirmation", "confirmationSubject", "confirmationText"] }
  ]
}
// FormInput
{
  "name": "Form Input", "slug": "formInput", "type": "nestable", "tags": ["FormField"],
  "preview_template": "{{label}}",
  "schema": {
    "label":    { "type": "text",    "translatable": true },
    "name":     { "type": "text" },
    "type":     { "type": "option",  "options": [{"name": "Text","value": "text"},{"name": "Email","value": "email"},{"name": "Tel","value": "tel"}] },
    "required": { "type": "boolean", "default": false, "show_inline": true },
    "note":     { "type": "text",    "translatable": true }
  },
  "editor": [{ "header": "General", "items": ["label", "name", "type", "required", "note"] }]
}
```

### 8. Global Config Singleton (single)
```json
{
  "name": "Config", "slug": "config", "type": "single", "icon": "wrench",
  "schema": {
    "siteName":    { "type": "text" },
    "menu":        { "type": "blocks", "restrict_blocks": true, "block_whitelist": ["menu", "menuitem"], "tag_whitelist": [] },
    "metaMenu":    { "type": "blocks", "restrict_blocks": true, "block_whitelist": ["menuitem"], "tag_whitelist": [] },
    "footerLinks": { "type": "blocks", "restrict_blocks": true, "block_whitelist": ["menuitem"], "tag_whitelist": [] },
    "copyright":   { "type": "textarea", "translatable": false }
  },
  "editor": [
    { "header": "General", "items": ["siteName"] },
    { "header": "Menu",    "items": ["menu", "metaMenu"] },
    { "header": "Footer",  "items": ["footerLinks", "copyright"] }
  ]
}
```

---

## Best Practices Checklist

- [ ] Slug is camelCase, starts with lowercase, no hyphens: `myBlock` not `my-block`
- [ ] Every schema field is assigned to exactly one editor tab
- [ ] All user-facing text fields have `translatable: true`
- [ ] Searchable text fields have `indexable: true` (text, textarea, richtext, markdown only)
- [ ] All `blocks` fields have `restrict_blocks: true` — never leave slots open
- [ ] Prefer `tag_whitelist` over `block_whitelist` for flexibility (new blocks auto-qualify by tag)
- [ ] One `meta` field per `root` block, with `has_og_tags: true`; never on `nestable`
- [ ] Use `single` type for exactly one global entry (config, checkout, email template)
- [ ] Set `preview_template` on every `nestable` block
- [ ] Tag blocks with a consistent hierarchy from day one (retrofitting is painful)
- [ ] Atoms are leaf nodes: no nested `blocks` fields in Atom-tagged blocks
- [ ] Use `show_inline: true` on boolean fields for compact editor display
- [ ] Use `max: 1` on blocks fields that should hold exactly one component (sticky CTA, etc.)
