import type { Ref } from 'vue'
import { isProxy, toRaw } from 'vue'

import type { ContentResource } from '~/types/contents'

type ScopeValue = Record<string, unknown>
type ValidationErrorMap = Record<string, string[]>

type BlocksMap = Record<string, Pick<BlockResource, 'schema' | 'slug' | 'name'>>

interface ValidationStateOptions {
  content: Ref<ContentResource | null>
  blocks: Ref<BlockResource[] | undefined>
  effectiveContent?: Ref<Record<string, unknown> | null | undefined>
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
]

export const normalizeSchemaType = (type?: string | null): CanonicalSchemaTypeName | '' => {
  switch (type) {
    case 'multiAsset':
      return 'multi_assets'
    case 'reference':
      return 'references'
    case 'block':
      return 'blocks'
    case 'blocks':
    case 'text':
    case 'textarea':
    case 'markdown':
    case 'richtext':
    case 'number':
    case 'boolean':
    case 'option':
    case 'options':
    case 'link':
    case 'asset':
    case 'multi_assets':
    case 'references':
    case 'date':
    case 'meta':
      return type
    default:
      return ''
  }
}

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
          })),
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
    source: isOptionLike ? optionSource : undefined,
    data_source_id: isOptionLike ? (schemaField.data_source_id ?? null) : undefined,
    conditions,
    validation: {
      ...validation,
      min: validation.min ?? (schemaField as any).min ?? (schemaField as any).minimum,
      max: validation.max ?? (schemaField as any).max ?? (schemaField as any).maximum,
      min_items: validation.min_items ?? (schemaField as any).min,
      max_items: validation.max_items ?? (schemaField as any).max,
      min_length: validation.min_length ?? (schemaField as any).min_length,
      max_length:
        validation.max_length ?? (schemaField as any).max_length ?? (schemaField as any).maximum,
      allowed_values:
        optionSource === 'self'
          ? validation.allowed_values ||
            ((schemaField as any).options || [])
              .map((option: OptionItem) => option?.value)
              .filter(Boolean)
          : validation.allowed_values,
    } satisfies FieldValidation,
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

const warnSchemaState = (context: string, error: unknown) => {
  console.warn(`[content-schema] ${context}`, error)
}

const isEmpty = (value: unknown) => value === null || value === undefined || value === ''

const matchesCondition = (actual: unknown, operator: ConditionOperator, expected?: unknown) => {
  switch (operator) {
    case 'equals':
      return actual == expected
    case 'not_equals':
      return actual != expected
    case 'in':
      return Array.isArray(expected) && expected.includes(actual as never)
    case 'not_in':
      return Array.isArray(expected) && !expected.includes(actual as never)
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
        : String(actual || '')
            .toLowerCase()
            .includes(String(expected || '').toLowerCase())
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
      const source = controller.translatable ? effectiveScope : scope
      return matchesCondition(source?.[rule.field], rule.operator, rule.value)
    })

    return normalized.conditions.mode === 'any' ? results.some(Boolean) : results.every(Boolean)
  } catch (error) {
    warnSchemaState(`failed to evaluate visibility for field "${field.key || 'unknown'}"`, error)
    return true
  }
}

const pruneScope = (
  values: ScopeValue,
  schema: Record<string, SchemaType>,
  blocksBySlug: BlocksMap,
  effectiveScope: ScopeValue = values
) => {
  const next = cloneScope(values)

  for (const [key, rawField] of Object.entries(normalizeSchema(schema))) {
    const field = rawField as SchemaType & { key: string }
    if (!isFieldVisible(field, schema, next, effectiveScope)) {
      delete next[key]
      continue
    }

    if (normalizeSchemaType(field.type) !== 'blocks') continue
    if (!Array.isArray(next[key])) continue

    next[key] = next[key].map((item, index) => {
      if (!item || typeof item !== 'object') return item
      const blockSlug = String((item as any).block || '')
      const block = blocksBySlug[blockSlug]
      if (!block?.schema) return item

      const effectiveItem = Array.isArray(effectiveScope[key])
        ? (effectiveScope[key] as Array<ScopeValue>)[index] || (item as ScopeValue)
        : (item as ScopeValue)

      return pruneScope(item as ScopeValue, block.schema, blocksBySlug, effectiveItem)
    })
  }

  return next
}

const validateScope = (
  values: ScopeValue,
  schema: Record<string, SchemaType>,
  blocksBySlug: BlocksMap,
  pathPrefix: Array<string | number> = [],
  effectiveScope: ScopeValue = values
) => {
  const errors: Record<string, string[]> = {}
  const normalizedSchema = normalizeSchema(schema)

  const pushError = (path: Array<string | number>, message: string) => {
    const key = `content.${path.map(String).join('.')}`
    if (!errors[key]) errors[key] = []
    errors[key].push(message)
  }

  for (const [key, field] of Object.entries(normalizedSchema)) {
    const path = [...pathPrefix, key]
    if (!isFieldVisible(field, schema, values, effectiveScope)) continue

    const value = (effectiveScope as ScopeValue)[key]
    const validation = field.validation || {}
    const type = normalizeSchemaType(field.type)

    if (field.required && isEmpty(value)) {
      pushError(path, `${field.name || key} is required.`)
      continue
    }

    if (isEmpty(value)) continue

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
          const pattern = new RegExp(validation.pattern)
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

    if (type === 'option' && validation.allowed_values?.length) {
      if (!validation.allowed_values.includes(String(value))) {
        pushError(path, `${field.name || key} must use an allowed option.`)
      }
    }

    if (type === 'options') {
      if (!Array.isArray(value)) {
        pushError(path, `${field.name || key} must be a list.`)
      } else {
        if (validation.allowed_values?.length) {
          const invalidValues = value.filter(
            (entry) => !validation.allowed_values?.includes(String(entry))
          )

          if (invalidValues.length > 0) {
            pushError(path, `${field.name || key} must only use allowed options.`)
          }
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

    if (type === 'options' || type === 'multi_assets' || type === 'references' || type === 'blocks') {
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
        const effectiveItem =
          Array.isArray(effectiveScope[key]) && (effectiveScope[key] as Array<ScopeValue>)[index]
            ? ((effectiveScope[key] as Array<ScopeValue>)[index] as ScopeValue)
            : (item as ScopeValue)

        Object.assign(
          errors,
          validateScope(
            item as ScopeValue,
            block.schema,
            blocksBySlug,
            [...path, index],
            effectiveItem
          )
        )
      })
    }
  }

  return errors
}

export const useContentSchemaState = ({
  content,
  blocks,
  effectiveContent: effectiveContentRef,
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
        effectiveContent.value
      )
    } catch (error) {
      warnSchemaState('failed to sanitize content; falling back to raw content', error)
      return cloneScope((content.value.content || {}) as ScopeValue)
    }
  })

  const clientErrors = computed<ValidationErrorMap>(() => {
    if (!content.value) return {}

    try {
      return validateScope(
        sanitizedContent.value,
        rootSchema.value,
        blocksBySlug.value,
        [],
        effectiveContent.value
      )
    } catch (error) {
      warnSchemaState('failed to validate content client-side; skipping client errors', error)
      return {}
    }
  })

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

  const getClientErrors = () => clientErrors.value

  const validateAllForSubmit = (options?: { silent?: boolean }) => {
    if (!options?.silent) {
      submitAttempted.value = true
    }

    return Object.keys(clientErrors.value).length === 0
  }

  const getFirstInvalidFieldPath = () => {
    const allErrors = Object.keys({
      ...clientErrors.value,
      ...serverErrors.value,
    })

    return allErrors.sort()[0] || null
  }

  const focusFirstInvalidField = async () => {
    const path = getFirstInvalidFieldPath()
    if (!path) return

    await nextTick()

    const escapedPath =
      typeof CSS !== 'undefined' && typeof CSS.escape === 'function' ? CSS.escape(path) : path
    const container = document.querySelector<HTMLElement>(`[data-field-path="${escapedPath}"]`)
    if (!container) return

    container.scrollIntoView({
      behavior: 'smooth',
      block: 'center',
    })

    const focusable = container.querySelector<HTMLElement>(
      'input, textarea, select, button, [contenteditable="true"], [tabindex]:not([tabindex="-1"])'
    )
    focusable?.focus()
  }

  const resetValidationState = () => {
    dirtyFields.value = {}
    submitAttempted.value = false
    serverErrors.value = {}
  }

  return {
    blocksBySlug,
    clientErrors,
    sanitizedContent,
    serverErrors,
    submitAttempted,
    markFieldDirty,
    setServerErrors,
    clearServerErrors,
    getFieldError,
    shouldShowFieldError,
    getClientErrors,
    validateAllForSubmit,
    getFirstInvalidFieldPath,
    focusFirstInvalidField,
    resetValidationState,
  }
}
