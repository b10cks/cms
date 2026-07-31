import { normalizeSchemaType } from '~/composables/useContentSchemaState'
import { ensureTableValue } from '~/lib/tableField'

type BlockLookup = Record<string, Pick<BlockResource, 'slug' | 'schema'>>
type ContentScope = Record<string, unknown>

const cloneDefaultValue = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T

const hasSingleReferenceSemantics = (field: ReferencesSchema) => {
  const validationMax = field.validation?.max_items ?? field.validation?.max
  const max = field.max ?? validationMax
  return Number(max) === 1
}

const formatCurrentDateForField = (field: DateSchema) => {
  const now = new Date()
  const pad = (value: number) => String(value).padStart(2, '0')

  const year = now.getFullYear()
  const month = pad(now.getMonth() + 1)
  const day = pad(now.getDate())
  const hours = pad(now.getHours())
  const minutes = pad(now.getMinutes())

  switch (field.format) {
    case 'time':
      return `${hours}:${minutes}`
    case 'datetime-local':
      return `${year}-${month}-${day}T${hours}:${minutes}`
    case 'date':
    default:
      return `${year}-${month}-${day}`
  }
}

export const createContentDefaultsBlockLookup = (
  blocks: Array<Pick<BlockResource, 'slug' | 'schema'>>,
  extraBlocks: Array<Pick<BlockResource, 'slug' | 'schema'>> = []
): BlockLookup =>
  Object.fromEntries(
    [...blocks, ...extraBlocks].map((block) => [block.slug, { slug: block.slug, schema: block.schema }])
  )

export const resolveFieldInitialValue = (field: SchemaType): unknown => {
  const type = normalizeSchemaType(field.type)
  const defaultValue = field.default

  // Tables are normalized against their columns whether or not a default is
  // configured — a raw default would otherwise reach the editor with stray
  // columns and untyped cells, which the table field cannot render.
  if (type === 'table') {
    return ensureTableValue(field as TableSchema, defaultValue)
  }

  if (defaultValue !== undefined && defaultValue !== null) {
    return cloneDefaultValue(defaultValue)
  }

  if (defaultValue === null) {
    if (type === 'option' || type === 'asset' || type === 'icon' || type === 'link') {
      return null
    }

    if (type === 'references' && hasSingleReferenceSemantics(field as ReferencesSchema)) {
      return null
    }
  }

  switch (type) {
    case 'text':
    case 'textarea':
    case 'markdown':
      return ''
    case 'date':
      return (field as DateSchema).use_current_as_default
        ? formatCurrentDateForField(field as DateSchema)
        : ''
    case 'number':
      return 0
    case 'boolean':
      return false
    case 'option':
      return null
    case 'options':
    case 'blocks':
    case 'multi_assets':
      return []
    case 'references':
      return hasSingleReferenceSemantics(field as ReferencesSchema) ? null : []
    case 'asset':
    case 'link':
      return null
    case 'richtext':
    case 'meta':
      return {}
    default:
      return null
  }
}

export const hydrateContentWithSchema = (
  schema: Record<string, SchemaType> | null | undefined,
  content: ContentScope | null | undefined,
  blockLookup: BlockLookup
): ContentScope => {
  const hydrated = content ? cloneDefaultValue(content) : {}

  for (const [key, rawField] of Object.entries(schema || {})) {
    const field = rawField as SchemaType
    const type = normalizeSchemaType(field.type)
    const hasKey = Object.prototype.hasOwnProperty.call(hydrated, key)

    if (!hasKey) {
      hydrated[key] = resolveFieldInitialValue(field)
    }

    if (type !== 'blocks' || !Array.isArray(hydrated[key])) {
      continue
    }

    hydrated[key] = hydrated[key].map((item) => {
      if (!item || typeof item !== 'object') {
        return item
      }

      const blockSlug = String((item as ContentScope).block || '')
      const block = blockLookup[blockSlug]
      if (!block?.schema) {
        return item
      }

      return hydrateContentWithSchema(block.schema, item as ContentScope, blockLookup)
    })
  }

  return hydrated
}

export const createBlockItemWithDefaults = (
  block: Pick<BlockResource, 'slug' | 'schema'>,
  blockLookup: BlockLookup = {}
) => {
  const ulid = useUlid()

  return {
    id: ulid(),
    block: block.slug,
    ...hydrateContentWithSchema(block.schema, {}, blockLookup),
  }
}
