interface Schema {
  name: string
  description?: string | null
  required?: boolean
  order?: number
  default?: string | undefined
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
}

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

interface MultiAssetSchema extends Schema {
  type: 'multi_assets'
  file_types: FileTypes[]
  min: number
  max: number
}

interface ReferencesSchema extends Schema {
  type: 'references'
  block_whitelist: string[]
  min: number
  max: number
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

interface RichTextSchema extends Schema {
  type: 'richtext'
  translatable: boolean
  html_classes: HtmlClassConfig[]
  heading_levels?: Array<'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6' | 'p'>
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

interface OptionSchema extends Schema {
  type: 'option'
  options: OptionItem[]
  exclude_empty: boolean
}

type TranslatableSchema =
  | TextSchema
  | TextareaSchema
  | MarkdownSchema
  | RichTextSchema
  | LinkSchema
  | MetaSchema
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
  | AssetSchema
  | MultiAssetsSchema
  | ReferencesSchema
  | DateSchema
  | MetaSchema

interface EditorPage {
  header: string
  items: string[]
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
  schema: Record<string, SchemaType>
  editor: EditorPage[]
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
  schema?: Record<string, SchemaType>
  tags: string[]
  folder_id: string | null
}

interface UpdateBlockPayload {
  icon?: string | null
  color?: string | null
  name?: string
  description?: string | null
  type: 'root' | 'nestable' | 'single' | 'universal'
  schema?: Record<string, SchemaType>
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
  preview_file?: File | null
  block_id?: string | null
}

interface UpdateBlockTemplatePayload {
  name?: string
  icon?: string | null
  color?: string | null
  description?: string | null
  preview_file?: File | null
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
