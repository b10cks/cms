import { watchDebounced } from '@vueuse/core'
import type { Ref } from 'vue'
import { isProxy, toRaw } from 'vue'

import { ensureTableValue, getTableColumns, normalizeSchemaTypeName } from '~/lib/tableField'
import type { ContentResource } from '~/types/contents'

type ScopeValue = Record<string, unknown>
type ValidationErrorMap = Record<string, string[]>
type ValidationEntry = { path: string; messages: string[] }
type ValidationSummary = { isValid: boolean; issueCount: number }

type BlocksMap = Record<string, Pick<BlockResource, 'schema' | 'slug' | 'name'>>

type OptionLikeField =
  | (OptionSchema & { key?: string; validation?: FieldValidation | null })
  | (OptionsSchema & { key?: string; validation?: FieldValidation | null })
  | (Extract<TableColumn, { type: 'option' }> & { validation?: FieldValidation | null })

// `0` and `false` are legitimate option values, so only null/undefined collapse
// to the empty string that marks an option as unusable.
const normalizeOptionValue = (value: unknown): string =>
  value === null || value === undefined ? '' : String(value).trim()

const uniqueNonEmpty = (values: string[]) =>
  values.filter((value, index) => value.length > 0 && values.indexOf(value) === index)

const resolveAllowedOptionValues = (field: OptionLikeField): string[] => {
  const declared = uniqueNonEmpty(
    (field.validation?.allowed_values || []).map(normalizeOptionValue)
  )

  if (declared.length > 0 || field.source === 'datasource') {
    return declared
  }

  return uniqueNonEmpty(
    ((field.options || []) as OptionItem[]).map((option) => normalizeOptionValue(option?.value))
  )
}

interface ValidationStateOptions {
  content: Ref<ContentResource | null>
  blocks: Ref<BlockResource[] | undefined>
  effectiveContent?: Ref<Record<string, unknown> | null | undefined>
  ignoreAbsentNonTranslatableFields?: boolean
}

const INDEXABLE_TYPES: CanonicalSchemaTypeName[] = [
  'text',
  'textarea',
  'markdown',
  'richtext',
  'meta',
]

const TRANSLATABLE_TYPES: CanonicalSchemaTypeName[] = [
  'text',
  'textarea',
  'markdown',
  'richtext',
  'number',
  'link',
  'meta',
  'date',
  'table',
  'plugin',
]

export const normalizeSchemaType = normalizeSchemaTypeName

const ICON_SOURCES: IconFieldSource[] = ['registry', 'all', 'collections']

const normalizeIconSource = (source: unknown): IconFieldSource =>
  ICON_SOURCES.includes(source as IconFieldSource) ? (source as IconFieldSource) : 'all'

const isCanonicalSchemaType = (
  type: CanonicalSchemaTypeName | ''
): type is CanonicalSchemaTypeName => type !== ''

export const normalizeSchemaField = (key: string, field: SchemaType | Record<string, unknown>) => {
  const schemaField = field as SchemaType & Record<string, unknown>
  const type = normalizeSchemaType(schemaField.type)
  const canonicalType = isCanonicalSchemaType(type) ? type : null
  const validation = (schemaField.validation || {}) as FieldValidation
  const optionSource = schemaField.source === 'datasource' ? 'datasource' : 'self'
  const isOptionLike = canonicalType === 'option' || canonicalType === 'options'
  const isIcon = canonicalType === 'icon'
  const isGeo = canonicalType === 'geo'
  const isPrice = canonicalType === 'price'
  const isTable = canonicalType === 'table'
  const isSerial = canonicalType === 'serial'
  const conditions = schemaField.conditions
    ? {
        mode: schemaField.conditions.mode === 'any' ? 'any' : 'all',
        rules: (schemaField.conditions.rules || []).filter((rule: FieldCondition | undefined) =>
          Boolean(rule?.field)
        ),
      }
    : Array.isArray((schemaField as any).dependencies)
      ? {
          mode: 'all' as const,
          rules: (schemaField as any).dependencies.map((rule: any) => ({
            field: String(rule.field || ''),
            operator:
              rule.operator === '='
                ? 'equals'
                : rule.operator === '!='
                  ? 'not_equals'
                  : rule.operator === '>'
                    ? 'gt'
                    : rule.operator === '>='
                      ? 'gte'
                      : rule.operator === '<'
                        ? 'lt'
                        : rule.operator === '<='
                          ? 'lte'
                          : rule.operator === 'empty'
                            ? 'is_empty'
                            : rule.operator === 'not_empty'
                              ? 'is_not_empty'
                              : rule.operator || 'equals',
            value: rule.value,
          }))
            // Same rule as `conditions.rules`: a rule naming no field is dead.
            .filter((rule: FieldCondition) => Boolean(rule.field)),
        }
      : null

  return {
    ...schemaField,
    key,
    type,
    name: schemaField.name || key,
    required: Boolean(schemaField.required),
    translatable: canonicalType
      ? TRANSLATABLE_TYPES.includes(canonicalType) && Boolean(schemaField.translatable)
      : false,
    indexable:
      schemaField.indexable === undefined
        ? Boolean(canonicalType && INDEXABLE_TYPES.includes(canonicalType))
        : Boolean(schemaField.indexable),
    source: isOptionLike
      ? optionSource
      : isIcon
        ? normalizeIconSource(schemaField.source)
        : undefined,
    data_source_id: isOptionLike ? (schemaField.data_source_id ?? null) : undefined,
    key_style: isGeo
      ? ((schemaField.key_style as GeoKeyStyle | undefined) ?? 'lat_lng')
      : undefined,
    altitude: isGeo ? Boolean(schemaField.altitude) : undefined,
    map: isGeo ? ((schemaField.map as boolean | undefined) ?? true) : undefined,
    base_currency: isPrice
      ? ((schemaField.base_currency as string | undefined) ?? 'EUR')
      : undefined,
    currencies: isPrice
      ? ((schemaField.currencies as string[] | undefined) ?? [])
      : undefined,
    has_thead: isTable ? Boolean((schemaField as TableSchema).has_thead) : undefined,
    columns: isTable ? getTableColumns(schemaField as TableSchema) : undefined,
    format: isSerial ? ((schemaField.format as string | undefined) || '{counter}') : undefined,
    scope: isSerial
      ? ((schemaField.scope as SerialScopeDimension[] | undefined) ?? ['block', 'parent'])
      : undefined,
    unique: isSerial
      ? ((schemaField.unique as SerialSchema['unique'] | undefined) ?? 'scope')
      : undefined,
    on_move: isSerial
      ? ((schemaField.on_move as SerialSchema['on_move'] | undefined) ?? 'keep')
      : undefined,
    editable: isSerial ? Boolean(schemaField.editable) : undefined,
    // A serial is readonly unless it was explicitly opened for editing; every
    // other type keeps whatever the schema declared.
    readonly: isSerial ? !schemaField.editable : Boolean(schemaField.readonly),
    conditions,
    validation: {
      ...validation,
      min: validation.min ?? (schemaField as any).min ?? (schemaField as any).minimum,
      max: validation.max ?? (schemaField as any).max ?? (schemaField as any).maximum,
      min_items: validation.min_items ?? (schemaField as any).min,
      max_items: validation.max_items ?? (schemaField as any).max,
      min_length: validation.min_length ?? (schemaField as any).min_length,
      // Deliberately asymmetric, mirroring SchemaField::normalizeValidation on the
      // backend: legacy `maximum` doubles as a text max_length, legacy `minimum`
      // never means a length. Adding a `minimum` fallback here would raise
      // client-only errors the backend does not enforce.
      max_length:
        validation.max_length ?? (schemaField as any).max_length ?? (schemaField as any).maximum,
      allowed_values:
        isOptionLike && optionSource === 'self'
          ? validation.allowed_values ||
            // Plugin fields carry options as a key/value object, not an option list.
            uniqueNonEmpty(
              (Array.isArray((schemaField as any).options)
                ? ((schemaField as any).options as OptionItem[])
                : []
              ).map((option) => normalizeOptionValue(option?.value))
            )
          : validation.allowed_values,
    } satisfies FieldValidation,
    default: isTable
      ? ensureTableValue(schemaField as TableSchema, schemaField.default)
      : schemaField.default,
  }
}

export const normalizeSchema = (schema?: Record<string, SchemaType> | null) => {
  return Object.fromEntries(
    Object.entries(schema || {}).map(([key, field]) => [key, normalizeSchemaField(key, field)])
  ) as Record<string, SchemaType & { key: string }>
}

const unwrapStructuredValue = (value: unknown): unknown => {
  if (Array.isArray(value)) {
    return value.map((item) => unwrapStructuredValue(item))
  }

  if (value && typeof value === 'object') {
    const rawValue = isProxy(value) ? toRaw(value) : value

    return Object.fromEntries(
      Object.entries(rawValue as Record<string, unknown>).map(([key, nestedValue]) => [
        key,
        unwrapStructuredValue(nestedValue),
      ])
    )
  }

  return value
}

const cloneScope = (value: ScopeValue): ScopeValue => unwrapStructuredValue(value) as ScopeValue

const mergeValidationErrorMaps = (...maps: ValidationErrorMap[]): ValidationErrorMap => {
  const merged: ValidationErrorMap = {}

  maps.forEach((map) => {
    Object.entries(map).forEach(([path, messages]) => {
      const normalizedMessages = Array.from(new Set((messages || []).filter(Boolean)))

      if (!merged[path]) {
        merged[path] = normalizedMessages
        return
      }

      merged[path] = Array.from(new Set([...merged[path], ...normalizedMessages]))
    })
  })

  return merged
}

const warnSchemaState = (context: string, error: unknown) => {
  console.warn(`[content-schema] ${context}`, error)
}

const isEmpty = (value: unknown) => value === null || value === undefined || value === ''

const isPrimitive = (value: unknown) =>
  value === null || (typeof value !== 'object' && typeof value !== 'function')

// Loose enough that a numeric 1 still matches a configured '1', strict enough
// that an unset field no longer equals an explicit `null`.
const looseEquals = (actual: unknown, expected: unknown) => {
  if (actual === expected) return true
  if (isEmpty(actual) || isEmpty(expected)) return false
  if (!isPrimitive(actual) || !isPrimitive(expected)) return false

  return String(actual) === String(expected)
}

const toComparableString = (value: unknown) => (isEmpty(value) ? '' : String(value).toLowerCase())

const matchesCondition = (actual: unknown, operator: ConditionOperator, expected?: unknown) => {
  switch (operator) {
    case 'equals':
      return looseEquals(actual, expected)
    case 'not_equals':
      return !looseEquals(actual, expected)
    case 'in':
      return Array.isArray(expected) && expected.includes(actual as never)
    case 'not_in':
      // A malformed rule must not hide the field: nothing is a member of a non-list.
      return !Array.isArray(expected) || !expected.includes(actual as never)
    case 'is_empty':
      return isEmpty(actual) || (Array.isArray(actual) && actual.length === 0)
    case 'is_not_empty':
      return !isEmpty(actual) && (!Array.isArray(actual) || actual.length > 0)
    case 'gt':
      return Number(actual) > Number(expected)
    case 'gte':
      return Number(actual) >= Number(expected)
    case 'lt':
      return Number(actual) < Number(expected)
    case 'lte':
      return Number(actual) <= Number(expected)
    case 'contains':
      return Array.isArray(actual)
        ? actual.includes(expected as never)
        : toComparableString(actual).includes(toComparableString(expected))
    default:
      // An operator this build does not know about must not make the field vanish.
      return true
  }
}

export const isFieldVisible = (
  field: SchemaType & { key?: string },
  schema: Record<string, SchemaType>,
  scope: ScopeValue,
  effectiveScope: ScopeValue = scope
) => {
  try {
    const normalized = normalizeSchemaField(field.key || '', field)
    if (!normalized.conditions?.rules?.length) return true

    const results = normalized.conditions.rules.map((rule: FieldCondition) => {
      const controller = normalizeSchemaField(
        rule.field,
        schema[rule.field] || ({ type: 'text' } as SchemaType)
      )
      const localValue = scope?.[rule.field]
      const source =
        controller.translatable ||
        (localValue === undefined &&
          Boolean(effectiveScope) &&
          Object.prototype.hasOwnProperty.call(effectiveScope, rule.field))
          ? effectiveScope
          : scope

      return matchesCondition(source?.[rule.field], rule.operator, rule.value)
    })

    return normalized.conditions.mode === 'any' ? results.some(Boolean) : results.every(Boolean)
  } catch (error) {
    const fieldKey = (field as { key?: string } | null | undefined)?.key || 'unknown'
    warnSchemaState(`failed to evaluate visibility for field "${fieldKey}"`, error)
    return true
  }
}

const readBlockItemId = (item: unknown): string | null => {
  if (!item || typeof item !== 'object' || Array.isArray(item)) return null
  const id = (item as Record<string, unknown>).id

  return typeof id === 'string' && id !== '' ? id : null
}

// Pruning and validation must pair a translated block item with the same source
// item, or they disagree about a reordered translation: id first, index second.
const pairEffectiveItem = (
  item: ScopeValue,
  effectiveItems: Array<ScopeValue>,
  index: number
): ScopeValue => {
  const itemId = readBlockItemId(item)

  return (
    (itemId ? effectiveItems.find((entry) => readBlockItemId(entry) === itemId) : undefined) ??
    effectiveItems[index] ??
    item
  )
}

const pruneScope = (
  values: ScopeValue,
  schema: Record<string, SchemaType>,
  blocksBySlug: BlocksMap,
  effectiveScope: ScopeValue = values,
  ignoreAbsentNonTranslatableFields = false
) => {
  const next = cloneScope(values)
  // Conditions read a controller from the source document when the local scope
  // has no key for it. A field this pass removed must not resurface there, or a
  // dependency chain (a → b → c) never collapses.
  const visibilityScope: ScopeValue = { ...effectiveScope }

  for (const [key, rawField] of Object.entries(normalizeSchema(schema))) {
    const field = rawField as SchemaType & { key: string }
    if (!isFieldVisible(field, schema, next, visibilityScope)) {
      delete next[key]
      delete visibilityScope[key]
      continue
    }

    const type = normalizeSchemaType(field.type)

    if (ignoreAbsentNonTranslatableFields && !field.translatable && type !== 'blocks') {
      delete next[key]
      continue
    }

    // Clear orphaned translatable field values: source has no value but translation does
    if (ignoreAbsentNonTranslatableFields && field.translatable && type !== 'blocks') {
      const sourceValue = effectiveScope?.[key]
      if (sourceValue === null || sourceValue === undefined) {
        delete next[key]
      }
    }

    if (type !== 'blocks') continue
    if (!Array.isArray(next[key])) continue

    // Remove orphaned block items: present in translation but not in source (by ID)
    if (ignoreAbsentNonTranslatableFields && Array.isArray(effectiveScope?.[key])) {
      const sourceIds = new Set(
        (effectiveScope[key] as Array<unknown>)
          .map((item) => readBlockItemId(item))
          .filter((id): id is string => id !== null)
      )
      if (sourceIds.size > 0) {
        next[key] = (next[key] as Array<unknown>).filter((item) => {
          if (typeof item !== 'object' || item === null || Array.isArray(item)) return false
          const id = (item as Record<string, unknown>).id
          return typeof id !== 'string' || id === '' || sourceIds.has(id)
        })
      }
    }

    next[key] = (next[key] as Array<unknown>).map((item, index) => {
      if (!item || typeof item !== 'object') return item

      const effectiveItems = Array.isArray(effectiveScope[key])
        ? (effectiveScope[key] as Array<ScopeValue>)
        : []
      const effectiveItem = pairEffectiveItem(item as ScopeValue, effectiveItems, index)

      // Stamp block references that are missing id/block by copying them from the
      // matched source item — prevents unstamped stubs from persisting across saves.
      let resolvedItem = item as Record<string, unknown>
      if (ignoreAbsentNonTranslatableFields && effectiveItem) {
        const needsId = typeof resolvedItem.id !== 'string' || resolvedItem.id === ''
        const needsBlock = typeof resolvedItem.block !== 'string' || resolvedItem.block === ''
        if (needsId || needsBlock) {
          resolvedItem = { ...resolvedItem }
          if (needsId && typeof (effectiveItem as any).id === 'string')
            resolvedItem.id = (effectiveItem as any).id
          if (needsBlock && typeof (effectiveItem as any).block === 'string')
            resolvedItem.block = (effectiveItem as any).block
        }
      }

      const blockSlug = String(resolvedItem.block || (effectiveItem as any)?.block || '')
      const block = blocksBySlug[blockSlug]
      if (!block?.schema) return resolvedItem

      return pruneScope(
        resolvedItem,
        block.schema,
        blocksBySlug,
        effectiveItem,
        ignoreAbsentNonTranslatableFields
      )
    })
  }

  return next
}

const validateScope = (
  values: ScopeValue,
  schema: Record<string, SchemaType>,
  blocksBySlug: BlocksMap,
  pathPrefix: Array<string | number> = [],
  effectiveScope: ScopeValue = values,
  ignoreAbsentNonTranslatableFields = false
) => {
  const errors: Record<string, string[]> = {}
  const normalizedSchema = normalizeSchema(schema)
  // Mirrors pruneScope: a field hidden by a condition also disappears from the
  // scope its dependants are evaluated against.
  const visibilityScope: ScopeValue = { ...effectiveScope }

  const pushError = (path: Array<string | number>, message: string) => {
    const key = `content.${path.map(String).join('.')}`
    if (!errors[key]) errors[key] = []
    errors[key].push(message)
  }

  for (const [key, field] of Object.entries(normalizedSchema)) {
    const path = [...pathPrefix, key]
    if (!isFieldVisible(field, schema, values, visibilityScope)) {
      delete visibilityScope[key]
      continue
    }

    const type = normalizeSchemaType(field.type)
    const ignoreCurrentField = ignoreAbsentNonTranslatableFields && !field.translatable

    if (ignoreCurrentField && type !== 'blocks') {
      continue
    }

    // Validate what will actually be saved — the pruned local scope visibility
    // was decided from — rather than the source document.
    const value = values?.[key]
    const validation = field.validation || {}

    if (!ignoreCurrentField && field.required && isEmpty(value)) {
      pushError(path, `${field.name || key} is required.`)
      continue
    }

    if (!ignoreCurrentField && isEmpty(value)) continue

    if (['text', 'textarea', 'markdown', 'richtext'].includes(type)) {
      const length = String(typeof value === 'string' ? value : JSON.stringify(value)).length
      if (validation.min_length && length < Number(validation.min_length)) {
        pushError(
          path,
          `${field.name || key} must be at least ${validation.min_length} characters.`
        )
      }
      if (validation.max_length && length > Number(validation.max_length)) {
        pushError(
          path,
          `${field.name || key} may not be greater than ${validation.max_length} characters.`
        )
      }
      if (validation.pattern) {
        try {
          // Accept both bare JS-style patterns and PHP-delimited ones (`/…/flags`).
          const raw = String(validation.pattern)
          const delimited = /^\/([\s\S]+)\/([a-z]*)$/.exec(raw)
          const pattern = delimited ? new RegExp(delimited[1], delimited[2]) : new RegExp(raw)
          if (!pattern.test(String(value))) {
            pushError(path, `${field.name || key} has an invalid format.`)
          }
        } catch {
          // Ignore invalid patterns in the client and let the backend reject them.
        }
      }
    }

    if (type === 'number') {
      const number = Number(value)
      if (Number.isNaN(number)) {
        pushError(path, `${field.name || key} must be a number.`)
      } else {
        if (validation.min !== undefined && number < Number(validation.min)) {
          pushError(path, `${field.name || key} must be at least ${validation.min}.`)
        }
        if (validation.max !== undefined && number > Number(validation.max)) {
          pushError(path, `${field.name || key} may not be greater than ${validation.max}.`)
        }
      }
    }

    if (type === 'option') {
      const allowedValues = resolveAllowedOptionValues(field as OptionLikeField)

      if (allowedValues.length && !allowedValues.includes(normalizeOptionValue(value))) {
        pushError(path, `${field.name || key} must use an allowed option.`)
      }
    }

    // A non-array value is reported once, by the shared list branch below.
    if (type === 'options' && Array.isArray(value)) {
      const allowedValues = resolveAllowedOptionValues(field as OptionLikeField)

      if (allowedValues.length) {
        const invalidValues = value.filter(
          (entry) => !allowedValues.includes(normalizeOptionValue(entry))
        )

        if (invalidValues.length > 0) {
          pushError(path, `${field.name || key} must only use allowed options.`)
        }
      }
    }

    if (type === 'date' && typeof value === 'string') {
      const timestamp = Date.parse(value)
      if (Number.isNaN(timestamp)) {
        pushError(path, `${field.name || key} must be a valid date.`)
      } else {
        if (validation.min && timestamp < Date.parse(String(validation.min))) {
          pushError(path, `${field.name || key} must be on or after ${validation.min}.`)
        }
        if (validation.max && timestamp > Date.parse(String(validation.max))) {
          pushError(path, `${field.name || key} must be on or before ${validation.max}.`)
        }
      }
    }

    if (type === 'table') {
      if (!value || typeof value !== 'object' || Array.isArray(value)) {
        pushError(path, `${field.name || key} must be a table object.`)
      } else {
        const tableValue = value as Record<string, unknown>
        const header = tableValue.header
        const rows = tableValue.rows
        const columns = getTableColumns(field as TableSchema)
        const columnMap = Object.fromEntries(
          columns.map((column) => [column.key, column])
        ) as Record<string, TableColumn>

        if (!header || typeof header !== 'object' || Array.isArray(header)) {
          pushError(path, `${field.name || key} must contain a valid header object.`)
        } else {
          Object.entries(header as Record<string, unknown>).forEach(([columnKey, headerValue]) => {
            if (!columnMap[columnKey] || typeof headerValue !== 'string') {
              pushError(path, `${field.name || key} contains an invalid header value.`)
            }
          })
        }

        if (!Array.isArray(rows)) {
          pushError(path, `${field.name || key} must contain a valid rows array.`)
        } else {
          const rawMinRows = validation.min ?? field.min
          const rawMaxRows = validation.max ?? field.max
          const minRows =
            rawMinRows === null || rawMinRows === undefined || rawMinRows === ''
              ? null
              : Number(rawMinRows)
          const maxRows =
            rawMaxRows === null || rawMaxRows === undefined || rawMaxRows === ''
              ? null
              : Number(rawMaxRows)

          if (minRows !== null && rows.length < minRows) {
            pushError(path, `${field.name || key} must contain at least ${minRows} rows.`)
          }

          if (maxRows !== null && rows.length > maxRows) {
            pushError(path, `${field.name || key} may not contain more than ${maxRows} rows.`)
          }

          const seenRowIds = new Set<string>()

          rows.forEach((row) => {
            if (!row || typeof row !== 'object' || Array.isArray(row)) {
              pushError(path, `${field.name || key} contains an invalid row.`)
              return
            }

            const rowId = typeof (row as TableRow).id === 'string' ? (row as TableRow).id : ''
            if (!rowId || seenRowIds.has(rowId)) {
              pushError(path, `${field.name || key} rows must have unique ids.`)
              return
            }

            seenRowIds.add(rowId)
            const cells = (row as TableRow).cells

            if (!cells || typeof cells !== 'object' || Array.isArray(cells)) {
              pushError(path, `${field.name || key} row cells must be an object.`)
              return
            }

            Object.entries(cells).forEach(([columnKey, cellValue]) => {
              const column = columnMap[columnKey]
              if (!column) {
                pushError(path, `${field.name || key} contains a cell for an unknown column.`)
                return
              }

              switch (column.type) {
                case 'text':
                  if (typeof cellValue !== 'string') {
                    pushError(path, `${field.name || key} text cells must be strings.`)
                  }
                  break
                case 'number':
                  if (cellValue !== null && typeof cellValue !== 'number') {
                    pushError(path, `${field.name || key} number cells must be a number or null.`)
                  }
                  break
                case 'boolean':
                  if (typeof cellValue !== 'boolean') {
                    pushError(path, `${field.name || key} boolean cells must be true or false.`)
                  }
                  break
                case 'option':
                  if (
                    cellValue !== null &&
                    (typeof cellValue !== 'string' ||
                      (column.source === 'self' &&
                        column.options.length > 0 &&
                        !column.options.some((option) => option.value === cellValue)))
                  ) {
                    pushError(path, `${field.name || key} option cells must use an allowed option.`)
                  }
                  break
              }
            })
          })
        }
      }
    }

    if (
      !ignoreCurrentField &&
      (type === 'options' || type === 'multi_assets' || type === 'references' || type === 'blocks')
    ) {
      if (!Array.isArray(value)) {
        pushError(path, `${field.name || key} must be a list.`)
      } else {
        const rawMinItems = validation.min_items ?? validation.min ?? field.min
        const rawMaxItems = validation.max_items ?? validation.max ?? field.max
        const minItems =
          rawMinItems === null || rawMinItems === undefined || rawMinItems === ''
            ? null
            : Number(rawMinItems)
        const maxItems =
          rawMaxItems === null || rawMaxItems === undefined || rawMaxItems === ''
            ? null
            : Number(rawMaxItems)

        if (minItems !== null && value.length < minItems) {
          pushError(path, `${field.name || key} must contain at least ${minItems} items.`)
        }
        if (maxItems !== null && value.length > maxItems) {
          pushError(path, `${field.name || key} may not contain more than ${maxItems} items.`)
        }
      }
    }

    if (type === 'blocks' && Array.isArray(value)) {
      value.forEach((item, index) => {
        if (!item || typeof item !== 'object') return
        const blockSlug = String((item as any).block || '')
        const block = blocksBySlug[blockSlug]
        if (!block?.schema) return
        const effectiveItems = Array.isArray(effectiveScope[key])
          ? (effectiveScope[key] as Array<ScopeValue>)
          : []
        const effectiveItem = pairEffectiveItem(item as ScopeValue, effectiveItems, index)
        const nestedErrors = validateScope(
          item as ScopeValue,
          block.schema,
          blocksBySlug,
          [...path, index],
          effectiveItem,
          ignoreAbsentNonTranslatableFields
        )

        Object.entries(nestedErrors).forEach(([nestedPath, messages]) => {
          errors[nestedPath] = errors[nestedPath]
            ? Array.from(new Set([...errors[nestedPath], ...messages]))
            : messages
        })
      })
    }
  }

  return errors
}

export const useContentSchemaState = ({
  content,
  blocks,
  effectiveContent: effectiveContentRef,
  ignoreAbsentNonTranslatableFields = false,
}: ValidationStateOptions) => {
  const dirtyFields = ref<Record<string, boolean>>({})
  const submitAttempted = ref(false)
  const serverErrors = ref<ValidationErrorMap>({})
  const effectiveContent = computed<ScopeValue>(
    () =>
      (effectiveContentRef?.value as ScopeValue) || ((content.value?.content || {}) as ScopeValue)
  )

  const blocksBySlug = computed<BlocksMap>(() => {
    const result: BlocksMap = {}
    ;(blocks.value || []).forEach((block) => {
      result[block.slug] = block
    })

    if (content.value?.block?.slug && content.value.block_schema) {
      result[content.value.block.slug] = {
        slug: content.value.block.slug,
        name: content.value.block.name,
        schema: content.value.block_schema,
      }
    }

    return result
  })

  const rootSchema = computed(() => content.value?.block_schema || {})

  const sanitizedContent = computed<Record<string, unknown>>(() => {
    if (!content.value) return {}

    try {
      return pruneScope(
        (content.value.content || {}) as ScopeValue,
        rootSchema.value,
        blocksBySlug.value,
        effectiveContent.value,
        ignoreAbsentNonTranslatableFields
      )
    } catch (error) {
      warnSchemaState('failed to sanitize content; falling back to raw content', error)
      return cloneScope((content.value.content || {}) as ScopeValue)
    }
  })

  const computeClientErrors = (): ValidationErrorMap => {
    if (!content.value) return {}

    try {
      return validateScope(
        sanitizedContent.value,
        rootSchema.value,
        blocksBySlug.value,
        [],
        effectiveContent.value,
        ignoreAbsentNonTranslatableFields
      )
    } catch (error) {
      warnSchemaState('failed to validate content client-side; skipping client errors', error)
      return {}
    }
  }

  // Validation walks (prune + validate) the whole tree, so run it ~300ms after the
  // last edit instead of on every keystroke. Submit paths force a synchronous
  // refresh via refreshClientErrors() so they never act on stale results.
  const clientErrors = ref<ValidationErrorMap>({})
  const refreshClientErrors = () => {
    clientErrors.value = computeClientErrors()
  }
  watchDebounced(
    [() => content.value?.content, rootSchema, blocksBySlug, effectiveContent],
    refreshClientErrors,
    { deep: true, debounce: 300, immediate: true }
  )

  const mergedValidationErrors = computed<ValidationErrorMap>(() =>
    mergeValidationErrorMaps(serverErrors.value, clientErrors.value)
  )
  const validationEntries = computed<ValidationEntry[]>(() =>
    Object.entries(mergedValidationErrors.value)
      .map(([path, messages]) => ({
        path,
        messages,
      }))
      .sort((a, b) => a.path.localeCompare(b.path))
  )
  const validationSummary = computed<ValidationSummary>(() => ({
    isValid: validationEntries.value.length === 0,
    issueCount: validationEntries.value.length,
  }))

  const setServerErrors = (errors: Record<string, string[]>) => {
    serverErrors.value = errors
  }

  const clearServerErrors = () => {
    serverErrors.value = {}
  }

  const markFieldDirty = (path: string) => {
    dirtyFields.value[path] = true
    delete serverErrors.value[path]
  }

  const getFieldError = (path: string) =>
    serverErrors.value[path]?.[0] || clientErrors.value[path]?.[0] || null

  const shouldShowFieldError = (path: string) =>
    Boolean(getFieldError(path) && (dirtyFields.value[path] || submitAttempted.value))

  const getVisibleValidationEntries = (prefix?: string): ValidationEntry[] => {
    const visibleEntries = validationEntries.value.filter(
      (entry) => dirtyFields.value[entry.path] || submitAttempted.value
    )

    if (!prefix) return visibleEntries
    if (prefix.endsWith('.')) {
      return visibleEntries.filter((entry) => entry.path.startsWith(prefix))
    }

    return visibleEntries.filter((entry) => entry.path === prefix)
  }

  const getValidationIssueSignature = () =>
    validationEntries.value.map((entry) => `${entry.path}:${entry.messages[0] || ''}`).join('|')

  const revealValidationState = async () => {
    // Bring debounced validation up to date before surfacing errors to the user.
    refreshClientErrors()

    if (submitAttempted.value) {
      submitAttempted.value = false
      await nextTick()
    }

    submitAttempted.value = true
    await nextTick()
    await nextTick()
  }

  const getClientErrors = () => clientErrors.value

  const validateAllForSubmit = (options?: { silent?: boolean }) => {
    if (!options?.silent) {
      submitAttempted.value = true
    }

    // Never gate a submit on debounced (possibly stale) results.
    refreshClientErrors()
    return Object.keys(clientErrors.value).length === 0
  }

  const getFirstInvalidFieldPath = () => validationEntries.value[0]?.path || null

  const focusFirstInvalidField = async () => {
    // The debounced client errors decide which path to focus, so refresh them
    // before reading — an edit within the last 300ms would otherwise no-op.
    refreshClientErrors()

    const path = getFirstInvalidFieldPath()
    if (!path || typeof document === 'undefined') return

    await revealValidationState()

    const getFieldContainer = (fieldPath: string) => {
      let currentPath = fieldPath

      while (currentPath) {
        const escapedPath =
          typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
            ? CSS.escape(currentPath)
            : currentPath
        const container = document.querySelector<HTMLElement>(`[data-field-path="${escapedPath}"]`)

        if (container) {
          return container
        }

        const separatorIndex = currentPath.lastIndexOf('.')
        if (separatorIndex === -1) {
          break
        }

        currentPath = currentPath.slice(0, separatorIndex)
      }

      return null
    }

    const container = getFieldContainer(path)
    if (!container) return

    container.scrollIntoView({
      behavior: 'smooth',
      block: 'center',
    })

    const focusableSelector =
      'input:not([tabindex="-1"]), textarea:not([tabindex="-1"]), select:not([tabindex="-1"]), button:not([tabindex="-1"]), [contenteditable="true"], [tabindex]:not([tabindex="-1"])'
    const validationTarget = container.querySelector<HTMLElement>('[data-validation-target="true"]')
    const focusable =
      (validationTarget?.matches(focusableSelector) ? validationTarget : null) ||
      validationTarget?.querySelector<HTMLElement>(focusableSelector) ||
      container.querySelector<HTMLElement>(focusableSelector)
    focusable?.focus()
  }

  const resetValidationState = () => {
    dirtyFields.value = {}
    submitAttempted.value = false
    serverErrors.value = {}
    refreshClientErrors()
  }

  return {
    blocksBySlug,
    clientErrors,
    sanitizedContent,
    serverErrors,
    submitAttempted,
    validationSummary,
    markFieldDirty,
    setServerErrors,
    clearServerErrors,
    getFieldError,
    shouldShowFieldError,
    getClientErrors,
    getVisibleValidationEntries,
    getValidationIssueSignature,
    validateAllForSubmit,
    getFirstInvalidFieldPath,
    revealValidationState,
    focusFirstInvalidField,
    resetValidationState,
  }
}
