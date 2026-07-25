interface Schema {
  name: string
  description?: string | null
  required?: boolean
  translatable?: boolean
  indexable?: boolean
  order?: number
  default?: unknown
  min?: number | string | null
  max?: number | string | null
  conditions?: FieldConditions | null
  validation?: FieldValidation | null
  /** The value is owned by the system; the editor renders it disabled. */
  readonly?: boolean
}

type CanonicalSchemaTypeName =
  | 'blocks'
  | 'text'
  | 'textarea'
  | 'markdown'
  | 'richtext'
  | 'number'
  | 'boolean'
  | 'option'
  | 'options'
  | 'link'
  | 'asset'
  | 'multi_assets'
  | 'references'
  | 'date'
  | 'meta'
  | 'table'
  | 'icon'
  | 'geo'
  | 'price'
  | 'plugin'
  | 'serial'

type LegacySchemaTypeName = 'multiAsset' | 'reference' | 'block'

type ConditionOperator =
  | 'equals'
  | 'not_equals'
  | 'in'
  | 'not_in'
  | 'is_empty'
  | 'is_not_empty'
  | 'gt'
  | 'gte'
  | 'lt'
  | 'lte'
  | 'contains'

interface FieldCondition {
  field: string
  operator: ConditionOperator
  value?: string | number | boolean | string[] | number[]
}

interface FieldConditions {
  mode: 'all' | 'any'
  rules: FieldCondition[]
}

interface FieldValidation {
  min?: number | string | null
  max?: number | string | null
  min_length?: number
  max_length?: number
  min_items?: number
  max_items?: number
  pattern?: string
  allowed_values?: string[]
}

interface BlocksSchema extends Schema {
  type: 'blocks'
  restrict_blocks: boolean
  block_whitelist: string[]
  restrict_tags: boolean
  tag_whitelist: string[]
}

interface DateSchema extends Schema {
  type: 'date'
  translatable: boolean
  format: 'date' | 'time' | 'datetime-local'
  min?: string
  max?: string
  use_current_as_default: boolean
}

interface LinkSchema extends Schema {
  type: 'link'
  translatable: boolean
  asset_link_type: boolean
  email_link_type: boolean
  allow_target_blank: boolean
  allow_query_params: boolean
}

type LinkTarget = '_self' | '_blank' | '_parent' | '_top'

interface UrlLinkValue {
  type: 'url'
  url: string
  target?: LinkTarget
  rel?: string
  params?: Record<string, string>
}

interface EmailLinkValue {
  type: 'email'
  email?: string
  subject?: string
  body?: string
  cc?: string
  bcc?: string
}

interface InternalLinkValue {
  type: 'internal'
  content: string
  anchor?: string
  target?: LinkTarget
  params?: Record<string, string>
}

type LinkValue = UrlLinkValue | EmailLinkValue | InternalLinkValue

interface MetaSchema extends Schema {
  type: 'meta'
  translatable: boolean
  has_og_tags: boolean
}

type FileTypes = 'image' | 'video' | 'audio' | 'document' | 'archive' | 'other' | 'all'

interface AssetSchema extends Schema {
  type: 'asset'
  file_types: FileTypes[]
  folder_id?: string | null
}

interface MultiAssetsSchema extends Schema {
  type: 'multi_assets' | 'multiAsset'
  file_types: FileTypes[]
  min: number | null
  max: number | null
}

// Where the `icon` field may pick from:
// - 'registry'    : only the space's own uploaded icons
// - 'all'         : the registry plus any public Iconify collection
// - 'collections' : the registry plus the allow-listed Iconify collections
type IconFieldSource = 'registry' | 'all' | 'collections'

interface IconSchema extends Schema {
  type: 'icon'
  source: IconFieldSource
  allowed_collections: string[]
}

// Storage-key preset for the `geo` field — decides the JSON keys the value is stored under:
// - 'latitude_longitude' : { latitude, longitude, altitude }
// - 'lat_lng'            : { lat, lng, alt }
// - 'lat_lon'            : { lat, lon, alt }
type GeoKeyStyle = 'latitude_longitude' | 'lat_lng' | 'lat_lon'

interface GeoSchema extends Schema {
  type: 'geo'
  key_style: GeoKeyStyle
  altitude: boolean
  map: boolean
}

interface PriceSchema extends Schema {
  type: 'price'
  base_currency: string
  currencies: string[]
}

// Custom field rendered by a space-registered field plugin (sandboxed iframe).
interface PluginSchema extends Schema {
  type: 'plugin'
  translatable: boolean
  plugin_handle: string
  options: Record<string, string>
}

type SerialScopeDimension = 'space' | 'block' | 'parent' | 'language' | 'year' | 'month'

interface SerialSchema extends Schema {
  type: 'serial'
  /** Token pattern rendered once, when the entry is created. */
  format: string
  /** Which counter the number is drawn from. */
  scope: SerialScopeDimension[]
  /** How far the rendered value has to stay unique. */
  unique: 'scope' | 'block' | 'space' | 'none'
  on_move: 'keep' | 'reallocate'
  editable: boolean
}

interface ReferencesSchema extends Schema {
  type: 'references' | 'reference'
  block_whitelist: string[]
  min: number | null
  max: number | null
}

interface TextSchema extends Schema {
  type: 'text'
  translatable: boolean
}

interface TextareaSchema extends Schema {
  type: 'textarea'
  translatable: boolean
}

interface MarkdownSchema extends Schema {
  type: 'markdown'
  translatable: boolean
}

interface HtmlClassConfig {
  name: string
  className: string
  css?: string
}

interface PlaceholderConfig {
  key: string
  label: string
}

/** Toolbar features that a richtext field can individually enable or disable. */
type RichTextFeature =
  | 'bold'
  | 'italic'
  | 'underline'
  | 'strike'
  | 'code'
  | 'heading'
  | 'bulletList'
  | 'orderedList'
  | 'blockquote'
  | 'codeBlock'
  | 'horizontalRule'
  | 'link'
  | 'internalLink'
  | 'table'

/**
 * A named list style the editor can apply to a bullet/ordered list. `className`
 * is emitted as a plain `class` on the `<ul>`/`<ol>` so any CSS framework can
 * style it; `type` limits which list kinds the style is offered for.
 */
interface ListStyleConfig {
  name: string
  className: string
  type?: 'bullet' | 'ordered' | 'both'
}

interface RichTextSchema extends Schema {
  type: 'richtext'
  translatable: boolean
  html_classes: HtmlClassConfig[]
  heading_levels?: Array<'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6' | 'p'>
  placeholders?: PlaceholderConfig[]
  /** Per-feature toggles. Absent or `true` ⇒ enabled (back-compatible default). */
  features?: Partial<Record<RichTextFeature, boolean>>
  list_styles?: ListStyleConfig[]
}

interface NumberSchema extends Schema {
  type: 'number'
}

interface BooleanSchema extends Schema {
  type: 'boolean'
  show_inline: boolean
}

interface OptionItem {
  name: string
  value: string
}

type OptionSource = 'self' | 'datasource'

interface OptionSchema extends Schema {
  type: 'option'
  options: OptionItem[]
  source: OptionSource
  data_source_id: string | null
  exclude_empty: boolean
  default: string | null
}

interface OptionsSchema extends Schema {
  type: 'options'
  options: OptionItem[]
  source: OptionSource
  data_source_id: string | null
  default: string[]
  min: number | null
  max: number | null
  required: boolean
}

type TableColumn =
  | { key: string; label: string; type: 'text' }
  | { key: string; label: string; type: 'number' }
  | {
      key: string
      label: string
      type: 'option'
      source: 'self' | 'datasource'
      options: OptionItem[]
      data_source_id: string | null
    }
  | { key: string; label: string; type: 'boolean' }

interface TableRow {
  id: string
  cells: Record<string, string | number | boolean | null>
}

interface TableValue {
  header: Record<string, string>
  rows: TableRow[]
}

interface TableSchema extends Schema {
  type: 'table'
  translatable: boolean
  has_thead: boolean
  min: number | null
  max: number | null
  columns: TableColumn[]
  default: TableValue
}

type TranslatableSchema =
  | TextSchema
  | TextareaSchema
  | MarkdownSchema
  | RichTextSchema
  | LinkSchema
  | MetaSchema
  | DateSchema
  | TableSchema
  | PluginSchema
type SchemaType =
  | BlocksSchema
  | LinkSchema
  | TextSchema
  | TextareaSchema
  | MarkdownSchema
  | RichTextSchema
  | NumberSchema
  | BooleanSchema
  | OptionSchema
  | OptionsSchema
  | AssetSchema
  | MultiAssetsSchema
  | ReferencesSchema
  | DateSchema
  | MetaSchema
  | TableSchema
  | IconSchema
  | GeoSchema
  | PriceSchema
  | PluginSchema
  | SerialSchema

interface EditorPage {
  header: string
  items: string[]
}

interface BlockSettings {
  /**
   * Token pattern new entries seed their slug from. Null keeps the default,
   * which is the entry name.
   */
  slug_pattern?: string | null
}

interface BlockResource {
  id: string
  slug: string
  icon?: string | null
  color?: string | null
  name: string
  description: string
  type: 'root' | 'nestable' | 'single' | 'universal'
  preview_template?: string
  preview_file?: string | null
  schema: Record<string, SchemaType>
  editor: EditorPage[]
  settings?: BlockSettings
  tags: string[]
  folder_id: string | null
  templates_count?: number
  created_at: string
  updated_at: string
}

interface CreateBlockPayload {
  icon?: string | null
  color?: string | null
  name: string
  description?: string | null
  type: 'root' | 'nestable' | 'single' | 'universal'
  preview_file?: string | null
  schema?: Record<string, SchemaType>
  editor?: EditorPage[]
  settings?: BlockSettings
  tags: string[]
  folder_id: string | null
}

interface UpdateBlockPayload {
  icon?: string | null
  color?: string | null
  name?: string
  description?: string | null
  type: 'root' | 'nestable' | 'single' | 'universal'
  preview_file?: string | null
  schema?: Record<string, SchemaType>
  editor?: EditorPage[]
  settings?: BlockSettings
  tags: string[]
  folder_id: string | null
}

// Block Template Types
interface BlockTemplate {
  id: string
  name: string
  icon?: string | null
  color?: string | null
  description?: string | null
  content: Record<string, any>
  preview_file?: string | null
  block_id?: string | null
  created_by?: {
    id: string
    name: string
    email: string
    avatar?: string | null
  } | null
  created_at: string
  updated_at: string
}

interface CreateBlockTemplatePayload {
  name: string
  icon?: string | null
  color?: string | null
  description?: string | null
  content: Record<string, any>
  preview_file?: string | null
  block_id?: string | null
}

interface UpdateBlockTemplatePayload {
  name?: string
  icon?: string | null
  color?: string | null
  description?: string | null
  preview_file?: string | null
}

// Block Version Types
interface BlockVersionData {
  external_id?: string | null
  slug: string
  name: string
  icon?: string | null
  color?: string | null
  description?: string | null
  type: 'root' | 'nestable' | 'single' | 'universal'
  preview_template?: string | null
  preview_file?: string | null
  schema?: Record<string, SchemaType> | null
  editor?: EditorPage[] | null
  tags?: string[]
  folder_id?: string | null
}

interface BlockVersion {
  id: string
  block_id: string
  parent_id?: string | null
  data: BlockVersionData
  commit_message?: string | null
  created_by?: {
    id: string
    name: string
    email: string
    avatar?: string | null
  } | null
  created_at: string
}

interface UpdateBlockVersionPayload {
  commit_message?: string | null
}
