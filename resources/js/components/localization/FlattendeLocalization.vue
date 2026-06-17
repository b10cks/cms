<script setup lang="ts">
import type { Component, Ref } from 'vue'
import { computed, inject, ref, watch } from 'vue'
import { toast } from 'vue-sonner'

import type { SpaceAiConfig } from '~/api/resources/ai'
import Icon from '~/components/Icon.vue'
import MetaLocalization from '~/components/localization/MetaLocalization.vue'
import { Badge } from '~/components/ui/badge'
import SplitButton from '~/components/ui/button/SplitButton.vue'
import { DropdownMenuItem } from '~/components/ui/dropdown-menu'
import { CheckboxField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import { useAiConfigs } from '~/composables/useAiModels'
import { useAiTranslation } from '~/composables/useAiTranslation'
import {
  isFieldVisible,
  normalizeSchema,
  normalizeSchemaType,
} from '~/composables/useContentSchemaState'
import { ensureTableValue, getTableColumns } from '~/lib/tableField'

import LinkLocalization from './LinkLocalization.vue'
import MarkdownLocalization from './MarkdownLocalization.vue'
import RichTextLocalization from './RichTextLocalization.vue'
import TextareaLocalization from './TextareaLocalization.vue'
import TextLocalization from './TextLocalization.vue'

type LocalizableSchema = SchemaType & { translatable?: boolean }

interface BlockStamp {
  pathIndex: number
  id: string
  block: string
}

interface TranslatableField {
  key: string
  path: Array<string | number>
  fieldName: string
  schemaItem: LocalizableSchema
  originalValue: unknown
  translatedValue: unknown
  isTranslated: boolean
  isOrphaned: boolean
  isOrphanedBlock?: boolean
  tablePath?: Array<string | number>
  tableColumnKey?: string
  tableRowId?: string | null
  blockStamps?: BlockStamp[]
}

interface BlockItem {
  block?: string
  [key: string]: unknown
}

interface MetaValue {
  title?: string
  description?: string
  canonical?: string | Record<string, unknown> | null
  robots?: string
  ogTitle?: string
  ogDescription?: string
}

interface RichTextSegment {
  path: Array<string | number>
  text: string
}

interface TranslationUnit {
  id: string
  source: string
  fieldIdentifier: string
  apply: (content: Record<string, unknown>, translation: string) => void
}

interface SpaceSettings {
  default_language: string
}

interface Space {
  settings: SpaceSettings
}

const localizers: Partial<Record<CanonicalSchemaTypeName, Component>> = {
  text: TextLocalization,
  textarea: TextareaLocalization,
  markdown: MarkdownLocalization,
  richtext: RichTextLocalization,
  link: LinkLocalization,
  meta: MetaLocalization,
}

function resolveLocalizerComponent(fieldType: string): Component | null {
  const normalizedType = normalizeSchemaType(fieldType)
  return normalizedType ? (localizers[normalizedType] ?? null) : null
}

const props = defineProps<{
  originalContent: Record<string, unknown>
  translationContent: Record<string, unknown>
  blockSchema: Record<string, LocalizableSchema>
  spaceId: string
  targetLanguage: string
  getBlockSchema?: (
    blockSlug: string
  ) => { schema: Record<string, LocalizableSchema>; name: string } | undefined
}>()
const emit = defineEmits<{
  'update:translationContent': [value: Record<string, unknown>]
}>()

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(props.spaceId) as { data: Ref<Space> }
const markFieldDirty = inject<((path: string) => void) | undefined>('markFieldDirty', undefined)
const getFieldError = inject<((path: string) => string | null) | undefined>(
  'getFieldError',
  undefined
)
const shouldShowFieldError = inject<((path: string) => boolean) | undefined>(
  'shouldShowFieldError',
  undefined
)

const showUntranslatedOnly = ref(false)
const searchQuery = ref('')
const { t } = useI18n()
const { useAiConfigsQuery } = useAiConfigs(computed(() => props.spaceId))
const { data: aiConfigs, isLoading: isLoadingAiConfigs } = useAiConfigsQuery()
const selectedConfigId = ref<string | null>(null)
const { streamTranslation, isStreaming: isTranslating } = useAiTranslation(
  computed(() => props.spaceId)
)
const translationProgress = ref<{ applied: number; total: number } | null>(null)

function extractCompletedTranslations(partial: string): Record<string, string> {
  const result: Record<string, string> = {}
  const regex = /"((?:[^"\\]|\\.)+)"\s*:\s*"((?:[^"\\]|\\.)*)"/g
  let match
  while ((match = regex.exec(partial)) !== null) {
    const key = match[1].replace(/\\"/g, '"').replace(/\\\\/g, '\\')
    const value = match[2]
      .replace(/\\"/g, '"')
      .replace(/\\n/g, '\n')
      .replace(/\\t/g, '\t')
      .replace(/\\\\/g, '\\')
    result[key] = value
  }
  return result
}
const sourceLanguage = computed((): string => space.value?.settings?.default_language || '')

const defaultAiConfig = computed<SpaceAiConfig | null>(() => {
  return aiConfigs.value?.find((config) => config.is_default) ?? null
})

const selectedAiConfig = computed<SpaceAiConfig | null>(() => {
  if (!selectedConfigId.value) return null
  return aiConfigs.value?.find((config) => config.id === selectedConfigId.value) ?? null
})

const canTranslateWithAI = computed(() => {
  return !isTranslating.value && !!selectedConfigId.value
})

watch(
  () => defaultAiConfig.value,
  (config) => {
    if (config && !selectedConfigId.value) {
      selectedConfigId.value = config.id
    }
  },
  { immediate: true }
)

const machineTranslatedFields = ref(new Set<string>())
const translationDraft = ref<Record<string, unknown>>({})

const translatableFields = ref<TranslatableField[]>([])
const metaTranslatableKeys = ['title', 'description', 'ogTitle', 'ogDescription'] as const
const emptyRichTextDocument = {
  type: 'doc',
  content: [
    {
      type: 'paragraph',
      content: [],
    },
  ],
} as Record<string, unknown>

const cloneTranslationContent = (value: Record<string, unknown> | undefined) =>
  JSON.parse(JSON.stringify(value || {})) as Record<string, unknown>

const cloneValue = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T

const isNonEmptyString = (value: unknown): value is string =>
  typeof value === 'string' && value.trim().length > 0

const isObjectRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

const isRichTextDocument = (value: unknown): value is Record<string, unknown> =>
  isObjectRecord(value) && value.type === 'doc'

const isLinkValue = (value: unknown): value is LinkValue => {
  if (!isObjectRecord(value) || typeof value.type !== 'string') {
    return false
  }

  switch (value.type) {
    case 'url':
      return typeof value.url === 'string'
    case 'email':
      return true
    case 'internal':
      return typeof value.content === 'string'
    default:
      return false
  }
}

const normalizeMetaValue = (value: unknown): MetaValue =>
  (isObjectRecord(value) ? cloneValue(value as MetaValue) : {}) as MetaValue

const normalizeRichTextValue = (
  value: unknown,
  fallback?: Record<string, unknown>
): Record<string, unknown> => {
  if (isRichTextDocument(value)) {
    return cloneValue(value)
  }

  if (fallback && isRichTextDocument(fallback)) {
    return cloneValue(fallback)
  }

  return cloneValue(emptyRichTextDocument)
}

const getValueAtPath = (value: unknown, path: Array<string | number>): unknown => {
  let current = value

  for (const segment of path) {
    if (current == null || typeof current !== 'object') {
      return undefined
    }

    current = (current as Record<string | number, unknown>)[segment]
  }

  return current
}

const setValueAtPath = (value: unknown, path: Array<string | number>, nextValue: unknown) => {
  if (!path.length || value == null || typeof value !== 'object') {
    return
  }

  let current = value as Record<string | number, unknown>

  for (let i = 0; i < path.length - 1; i++) {
    const segment = path[i]
    const nextSegment = path[i + 1]

    if (current[segment] == null || typeof current[segment] !== 'object') {
      current[segment] = typeof nextSegment === 'number' ? [] : {}
    }

    current = current[segment] as Record<string | number, unknown>
  }

  current[path[path.length - 1]] = nextValue
}

const collectRichTextSegments = (
  value: unknown,
  path: Array<string | number> = [],
  segments: RichTextSegment[] = [],
  parentTypes: string[] = []
): RichTextSegment[] => {
  if (Array.isArray(value)) {
    value.forEach((entry, index) => {
      collectRichTextSegments(entry, [...path, index], segments, parentTypes)
    })
    return segments
  }

  if (!isObjectRecord(value)) {
    return segments
  }

  const nodeType = typeof value.type === 'string' ? value.type : undefined
  const marks = Array.isArray(value.marks) ? value.marks : []
  const isCodeText = marks.some((mark) => isObjectRecord(mark) && mark.type === 'code')
  const skipTranslation =
    parentTypes.includes('codeBlock') || nodeType === 'codeBlock' || isCodeText

  if (!skipTranslation && isNonEmptyString(value.text)) {
    segments.push({
      path: [...path, 'text'],
      text: value.text,
    })
  }

  if (Array.isArray(value.content)) {
    collectRichTextSegments(
      value.content,
      [...path, 'content'],
      segments,
      nodeType ? [...parentTypes, nodeType] : parentTypes
    )
  }

  return segments
}

const hasTranslatedValue = (schemaItem: LocalizableSchema, value: unknown): boolean => {
  const normalizedType = normalizeSchemaType(schemaItem.type)

  if (normalizedType === 'meta') {
    const metaValue = normalizeMetaValue(value)
    return metaTranslatableKeys.some((key) => isNonEmptyString(metaValue[key]))
  }

  if (normalizedType === 'richtext') {
    return collectRichTextSegments(normalizeRichTextValue(value)).some((segment) =>
      isNonEmptyString(segment.text)
    )
  }

  if (normalizedType === 'link') {
    if (!isLinkValue(value)) {
      return false
    }

    switch (value.type) {
      case 'url':
        return isNonEmptyString(value.url)
      case 'email':
        return isNonEmptyString(value.email)
      case 'internal':
        return isNonEmptyString(value.content)
      default:
        return false
    }
  }

  return isNonEmptyString(value)
}

const isFieldTranslated = (
  schemaItem: LocalizableSchema,
  originalValue: unknown,
  translatedValue: unknown
): boolean => {
  const normalizedType = normalizeSchemaType(schemaItem.type)

  if (normalizedType === 'meta') {
    const originalMeta = normalizeMetaValue(originalValue)
    const translatedMeta = normalizeMetaValue(translatedValue)

    return metaTranslatableKeys
      .filter((key) => isNonEmptyString(originalMeta[key]))
      .every((key) => isNonEmptyString(translatedMeta[key]))
  }

  if (normalizedType === 'richtext') {
    const originalDocument = normalizeRichTextValue(originalValue)
    const translatedDocument = normalizeRichTextValue(translatedValue)
    const originalSegments = collectRichTextSegments(originalDocument).filter((segment) =>
      isNonEmptyString(segment.text)
    )

    if (originalSegments.length === 0) return false

    return originalSegments.every((segment) => {
      const translatedText = getValueAtPath(translatedDocument, segment.path)
      return isNonEmptyString(translatedText) && translatedText !== segment.text
    })
  }

  if (normalizedType === 'link') {
    return hasTranslatedValue(schemaItem, translatedValue)
  }

  return isNonEmptyString(translatedValue)
}

const normalizeTranslatedFieldValue = (field: TranslatableField, value: unknown): unknown => {
  const normalizedType = normalizeSchemaType(field.schemaItem.type)

  if (normalizedType === 'meta') {
    return normalizeMetaValue(value)
  }

  if (normalizedType === 'richtext') {
    return normalizeRichTextValue(value)
  }

  if (normalizedType === 'link') {
    return isLinkValue(value) ? cloneValue(value) : null
  }

  return typeof value === 'string' ? value : ''
}

const createTableTextSchema = (name: string): TextSchema =>
  ({
    type: 'text',
    name,
    translatable: true,
  }) as TextSchema

const getTranslatedTableHeaderValue = (value: unknown, columnKey: string): string => {
  if (!isObjectRecord(value) || !isObjectRecord(value.header)) {
    return ''
  }

  return typeof value.header[columnKey] === 'string' ? String(value.header[columnKey]) : ''
}

const getTranslatedTableCellValue = (value: unknown, rowId: string, columnKey: string): string => {
  if (!isObjectRecord(value) || !Array.isArray(value.rows)) {
    return ''
  }

  const row = value.rows.find(
    (entry) => isObjectRecord(entry) && typeof entry.id === 'string' && entry.id === rowId
  ) as Record<string, unknown> | undefined

  if (!row || !isObjectRecord(row.cells)) {
    return ''
  }

  return typeof row.cells[columnKey] === 'string' ? String(row.cells[columnKey]) : ''
}

const buildRichTextTranslationBase = (
  originalValue: unknown,
  translatedValue: unknown
): Record<string, unknown> => {
  const originalDocument = normalizeRichTextValue(originalValue)
  const nextDocument = cloneValue(originalDocument)
  const currentDocument = normalizeRichTextValue(translatedValue)

  collectRichTextSegments(originalDocument).forEach((segment) => {
    const currentText = getValueAtPath(currentDocument, segment.path)
    if (isNonEmptyString(currentText) && currentText !== segment.text) {
      setValueAtPath(nextDocument, segment.path, currentText)
    }
    // untranslated segments retain original text from the clone
  })

  return nextDocument
}

watch(
  () => props.translationContent,
  (nextTranslationContent) => {
    translationDraft.value = cloneTranslationContent(nextTranslationContent)
  },
  { immediate: true }
)

const getBlockItemId = (item: unknown): string | null =>
  isObjectRecord(item) && typeof item.id === 'string' && item.id !== '' ? item.id : null

const traverseContent = (
  original: Record<string, unknown>,
  translation: Record<string, unknown>,
  schema: Record<string, LocalizableSchema>,
  currentPath: Array<string | number> = [],
  result: TranslatableField[] = [],
  blockStamps: BlockStamp[] = []
): TranslatableField[] => {
  if (typeof original !== 'object' || original === null) {
    return result
  }

  const normalizedSchema = normalizeSchema(schema)
  const originalScope = original as Record<string, unknown>
  const translationScope = translation as Record<string, unknown>

  Object.entries(normalizedSchema).forEach(([key, schemaItem]) => {
    const path = [...currentPath, key]
    if (!isFieldVisible(schemaItem, normalizedSchema, originalScope, originalScope)) return

    const originalValue = originalScope[key]

    if (schemaItem.type === 'blocks' && Array.isArray(originalScope[key])) {
      const originalBlockItems = (originalScope[key] as BlockItem[]) || []
      const translatedBlockItems = (translationScope[key] as BlockItem[]) || []
      const translatedIndexById = new Map<string, number>()
      translatedBlockItems.forEach((item, index) => {
        const id = getBlockItemId(item)
        if (id) translatedIndexById.set(id, index)
      })
      let nextAppendIndex = translatedBlockItems.length

      originalBlockItems.forEach((originalBlockItem: BlockItem, originalIndex) => {
        if (!originalBlockItem || !originalBlockItem.block) return

        const blockSlug = originalBlockItem.block
        const blockSchemaItem = props.getBlockSchema ? props.getBlockSchema(blockSlug) : undefined
        if (!blockSchemaItem?.schema) return

        const originalBlockId = getBlockItemId(originalBlockItem)
        const existingTranslatedIndex = originalBlockId
          ? translatedIndexById.get(originalBlockId)
          : undefined

        let translatedIndex: number
        if (existingTranslatedIndex !== undefined) {
          translatedIndex = existingTranslatedIndex
        } else {
          // Positional fallback: if the item at the same position has no id it is the
          // un-stamped counterpart of this source block, not a new slot to append.
          const itemAtPosition = translatedBlockItems[originalIndex]
          if (isObjectRecord(itemAtPosition) && !getBlockItemId(itemAtPosition)) {
            translatedIndex = originalIndex
          } else {
            translatedIndex = nextAppendIndex
            nextAppendIndex += 1
          }
        }

        const blockPath = [...path, translatedIndex]
        const translatedBlockItem = translatedBlockItems[translatedIndex] || {}
        const nextBlockStamps = originalBlockId
          ? [...blockStamps, { pathIndex: blockPath.length - 1, id: originalBlockId, block: blockSlug }]
          : blockStamps

        traverseContent(
          originalBlockItem as Record<string, unknown>,
          translatedBlockItem as Record<string, unknown>,
          blockSchemaItem.schema,
          blockPath,
          result,
          nextBlockStamps
        )
      })

      // Detect orphaned blocks: present in translation but not in source (by ID)
      const originalBlockIds = new Set<string>()
      originalBlockItems.forEach((item) => {
        const id = getBlockItemId(item)
        if (id) originalBlockIds.add(id)
      })

      translatedBlockItems.forEach((translatedBlockItem, translatedItemIndex) => {
        const translatedBlockId = getBlockItemId(translatedBlockItem)
        if (!translatedBlockId || originalBlockIds.has(translatedBlockId)) return

        const blockSlug =
          typeof translatedBlockItem.block === 'string' ? translatedBlockItem.block : ''
        const blockSchemaItem = props.getBlockSchema ? props.getBlockSchema(blockSlug) : undefined
        const blockName = blockSchemaItem?.name || blockSlug || 'Block'

        result.push({
          key: `orphaned-block-${translatedBlockId}`,
          path: [...path, translatedItemIndex],
          fieldName: blockName,
          schemaItem: { type: 'text', name: blockName, translatable: true } as LocalizableSchema,
          originalValue: null,
          translatedValue: translatedBlockItem,
          isTranslated: false,
          isOrphaned: true,
          isOrphanedBlock: true,
          blockStamps,
        })
      })
    } else if (schemaItem.type === 'table' && schemaItem.translatable) {
      const tableSchema = schemaItem as TableSchema
      const originalTable = ensureTableValue(tableSchema, originalValue)
      const tablePath = [...path]

      if (tableSchema.has_thead) {
        getTableColumns(tableSchema).forEach((column) => {
          const fieldSchema = createTableTextSchema(
            `${schemaItem.name || key}: ${column.label || column.key}`
          )
          const sourceValue = originalTable.header[column.key] || column.label || column.key
          const translatedHeaderValue = getTranslatedTableHeaderValue(
            translationScope[key],
            column.key
          )

          result.push({
            key: `${key}-header-${column.key}`,
            path: [...path, 'header', column.key],
            fieldName: `${schemaItem.name || key}: ${column.label || column.key}`,
            schemaItem: fieldSchema,
            originalValue: sourceValue,
            translatedValue: translatedHeaderValue,
            isTranslated: isFieldTranslated(fieldSchema, sourceValue, translatedHeaderValue),
            isOrphaned: !hasTranslatedValue(fieldSchema, sourceValue) && hasTranslatedValue(fieldSchema, translatedHeaderValue),
            tablePath,
            tableColumnKey: column.key,
            tableRowId: null,
            blockStamps,
          })
        })
      }

      originalTable.rows.forEach((row) => {
        getTableColumns(tableSchema)
          .filter((column) => column.type === 'text')
          .forEach((column) => {
            const fieldSchema = createTableTextSchema(
              `${schemaItem.name || key}: ${column.label || column.key}`
            )
            const sourceValue =
              typeof row.cells[column.key] === 'string' ? row.cells[column.key] : ''
            const translatedCellValue = getTranslatedTableCellValue(
              translationScope[key],
              row.id,
              column.key
            )

            result.push({
              key: `${key}-row-${row.id}-${column.key}`,
              path: [...path, 'rows', row.id, column.key],
              fieldName: `${schemaItem.name || key}: ${column.label || column.key}`,
              schemaItem: fieldSchema,
              originalValue: sourceValue,
              translatedValue: translatedCellValue,
              isTranslated: isFieldTranslated(fieldSchema, sourceValue, translatedCellValue),
              isOrphaned: !hasTranslatedValue(fieldSchema, sourceValue) && hasTranslatedValue(fieldSchema, translatedCellValue),
              tablePath,
              tableColumnKey: column.key,
              tableRowId: row.id,
              blockStamps,
            })
          })
      })
    } else if ('translatable' in schemaItem && schemaItem.translatable) {
      const translatedValue = translationScope[key]
      const normalizedTranslatedValue = normalizeTranslatedFieldValue(
        {
          key,
          path,
          fieldName: schemaItem.name || key,
          schemaItem,
          originalValue,
          translatedValue,
          isTranslated: false,
        },
        translatedValue
      )

      result.push({
        key,
        path,
        fieldName: schemaItem.name || key,
        schemaItem,
        originalValue,
        translatedValue: normalizedTranslatedValue,
        isTranslated: isFieldTranslated(schemaItem, originalValue, normalizedTranslatedValue),
        isOrphaned: !hasTranslatedValue(schemaItem, originalValue) && hasTranslatedValue(schemaItem, normalizedTranslatedValue),
        blockStamps,
      })
    }
  })

  return result
}

watch(
  [() => props.originalContent, translationDraft, () => props.blockSchema],
  () => {
    if (!props.originalContent || !props.blockSchema) {
      translatableFields.value = []
      return
    }

    translatableFields.value = traverseContent(
      props.originalContent as Record<string, unknown>,
      translationDraft.value,
      props.blockSchema
    )
  },
  { immediate: true, deep: true }
)

const filteredFields = computed(() => {
  return translatableFields.value.filter((field) => {
    if (showUntranslatedOnly.value && field.isTranslated && !field.isOrphaned) {
      return false
    }

    if (searchQuery.value) {
      const searchLower = searchQuery.value.toLowerCase()
      return (
        field.fieldName.toLowerCase().includes(searchLower) ||
        field.path.join(' > ').toLowerCase().includes(searchLower)
      )
    }

    return true
  })
})

const getFieldIdentifier = (field: TranslatableField): string => {
  return `${field.path.join('-')}-${field.key}`
}

const getValidationPath = (field: TranslatableField): string => {
  const path = field.tablePath || field.path
  return `content.${path.join('.')}`
}

const getValidationError = (field: TranslatableField): string | null =>
  getFieldError?.(getValidationPath(field)) || null

const shouldShowValidationError = (field: TranslatableField): boolean =>
  shouldShowFieldError?.(getValidationPath(field)) || false

const isMachineTranslated = (field: TranslatableField): boolean => {
  return machineTranslatedFields.value.has(getFieldIdentifier(field))
}

const applyBlockStamps = (
  content: Record<string, unknown>,
  path: Array<string | number>,
  blockStamps?: BlockStamp[]
) => {
  blockStamps?.forEach((stamp) => {
    const item = getValueAtPath(content, path.slice(0, stamp.pathIndex + 1))
    if (isObjectRecord(item)) {
      if (item.id === undefined) item.id = stamp.id
      if (item.block === undefined) item.block = stamp.block
    }
  })
}

const applyTranslatedValue = (
  content: Record<string, unknown>,
  field: TranslatableField,
  newValue: unknown
) => {
  if (field.tablePath && field.tableColumnKey) {
    const tableValue = getValueAtPath(content, field.tablePath)
    const nextTableValue = isObjectRecord(tableValue) ? cloneValue(tableValue) : {}

    if (field.tableRowId === null) {
      const nextHeader = isObjectRecord(nextTableValue.header)
        ? cloneValue(nextTableValue.header)
        : {}
      nextHeader[field.tableColumnKey] = newValue
      nextTableValue.header = nextHeader
    } else {
      const nextRows = Array.isArray(nextTableValue.rows) ? cloneValue(nextTableValue.rows) : []
      const existingIndex = nextRows.findIndex(
        (row) => isObjectRecord(row) && row.id === field.tableRowId
      )
      const nextRow =
        existingIndex >= 0 && isObjectRecord(nextRows[existingIndex])
          ? cloneValue(nextRows[existingIndex] as Record<string, unknown>)
          : { id: field.tableRowId, cells: {} }
      const nextCells = isObjectRecord(nextRow.cells) ? cloneValue(nextRow.cells) : {}

      nextCells[field.tableColumnKey] = newValue
      nextRow.cells = nextCells

      if (existingIndex >= 0) {
        nextRows[existingIndex] = nextRow
      } else {
        nextRows.push(nextRow)
      }

      nextTableValue.rows = nextRows
    }

    setValueAtPath(content, field.tablePath, nextTableValue)
    applyBlockStamps(content, field.tablePath, field.blockStamps)
    return
  }

  let current: Record<string, unknown> | unknown[] = content

  for (let i = 0; i < field.path.length - 1; i++) {
    const pathPart = field.path[i]
    const nextPathPart = field.path[i + 1]
    const nextPathSegment = String(nextPathPart)
    const currentValue =
      current instanceof Array
        ? current[Number(pathPart)]
        : (current as Record<string, unknown>)[pathPart]

    if (currentValue == null) {
      const nextValue = Number.isInteger(parseInt(nextPathSegment, 10)) ? [] : {}
      if (current instanceof Array) {
        current[Number(pathPart)] = nextValue
      } else {
        ;(current as Record<string, unknown>)[pathPart] = nextValue
      }
    }

    current =
      current instanceof Array
        ? (current[Number(pathPart)] as Record<string, unknown> | unknown[])
        : ((current as Record<string, unknown>)[pathPart] as Record<string, unknown> | unknown[])

    if (current instanceof Array) {
      const nextIndex = parseInt(nextPathSegment, 10)
      if (!Number.isNaN(nextIndex) && nextIndex >= current.length) {
        for (let j = current.length; j <= nextIndex; j++) {
          current.push({})
        }
      }
    }
  }

  const finalKey = field.path[field.path.length - 1]
  if (current instanceof Array) {
    current[Number(finalKey)] = newValue
  } else {
    current[String(finalKey)] = newValue
  }

  applyBlockStamps(content, field.path, field.blockStamps)
}

const removeOrphanedBlock = (field: TranslatableField): void => {
  const arrayPath = field.path.slice(0, -1)
  const itemIndex = field.path[field.path.length - 1] as number
  const nextDraft = cloneTranslationContent(translationDraft.value)
  const array = getValueAtPath(nextDraft, arrayPath)
  if (!Array.isArray(array)) return
  array.splice(itemIndex, 1)
  markFieldDirty?.(getValidationPath(field))
  emitTranslationContent(nextDraft)
}

const removeOrphanedEntry = (field: TranslatableField): void => {
  if (field.isOrphanedBlock) {
    removeOrphanedBlock(field)
  } else {
    updateTranslatedValue(field, normalizeTranslatedFieldValue(field, null))
  }
}

const emitTranslationContent = (nextTranslationContent: Record<string, unknown>) => {
  translationDraft.value = nextTranslationContent
  emit('update:translationContent', nextTranslationContent)
}

const getFieldValue = (content: Record<string, unknown>, field: TranslatableField): unknown => {
  if (field.tablePath && field.tableColumnKey) {
    const tableValue = getValueAtPath(content, field.tablePath)

    return field.tableRowId === null
      ? getTranslatedTableHeaderValue(tableValue, field.tableColumnKey)
      : field.tableRowId
        ? getTranslatedTableCellValue(tableValue, field.tableRowId, field.tableColumnKey)
        : null
  }

  return getValueAtPath(content, field.path)
}

const updateTranslatedValue = (field: TranslatableField, newValue: unknown): void => {
  const fieldToUpdate = translatableFields.value.find(
    (f) => f.key === field.key && JSON.stringify(f.path) === JSON.stringify(field.path)
  )

  if (fieldToUpdate) {
    fieldToUpdate.translatedValue = newValue
    fieldToUpdate.isTranslated = isFieldTranslated(field.schemaItem, field.originalValue, newValue)
  }

  const nextTranslationContent = cloneTranslationContent(translationDraft.value)
  applyTranslatedValue(nextTranslationContent, field, newValue)
  markFieldDirty?.(getValidationPath(field))
  emitTranslationContent(nextTranslationContent)
}

const buildTranslationUnits = (): TranslationUnit[] => {
  const units: TranslationUnit[] = []
  let unitIndex = 0
  const nextUnitId = () => `f${++unitIndex}`

  translatableFields.value.forEach((field) => {
    const normalizedType = normalizeSchemaType(field.schemaItem.type)
    const fieldIdentifier = getFieldIdentifier(field)

    if (normalizedType === 'meta') {
      const originalMeta = normalizeMetaValue(field.originalValue)
      const translatedMeta = normalizeMetaValue(field.translatedValue)

      metaTranslatableKeys.forEach((key) => {
        const source = originalMeta[key]
        if (!isNonEmptyString(source) || isNonEmptyString(translatedMeta[key])) {
          return
        }

        units.push({
          id: nextUnitId(),
          source,
          fieldIdentifier,
          apply: (content, translation) => {
            const nextMeta = normalizeMetaValue(getFieldValue(content, field))
            nextMeta[key] = translation
            applyTranslatedValue(content, field, nextMeta)
          },
        })
      })

      return
    }

    if (normalizedType === 'richtext') {
      const originalDocument = normalizeRichTextValue(field.originalValue)
      const currentTranslatedDoc = normalizeRichTextValue(
        field.translatedValue as Record<string, unknown>
      )

      collectRichTextSegments(originalDocument).forEach((segment) => {
        // Skip whitespace-only source segments
        if (!isNonEmptyString(segment.text) || !segment.text.trim()) return

        const translatedText = getValueAtPath(currentTranslatedDoc, segment.path)
        // Skip if already genuinely translated (non-empty and different from source)
        if (isNonEmptyString(translatedText) && (translatedText as string) !== segment.text) return

        units.push({
          id: nextUnitId(),
          source: segment.text,
          fieldIdentifier,
          apply: (content, translation) => {
            const nextDocument = buildRichTextTranslationBase(
              field.originalValue,
              getFieldValue(content, field)
            )
            setValueAtPath(nextDocument, segment.path, translation)
            applyTranslatedValue(content, field, nextDocument)
          },
        })
      })

      return
    }

    if (
      !isNonEmptyString(field.originalValue) ||
      hasTranslatedValue(field.schemaItem, field.translatedValue)
    ) {
      return
    }

    units.push({
      id: nextUnitId(),
      source: field.originalValue,
      fieldIdentifier,
      apply: (content, translation) => {
        applyTranslatedValue(content, field, translation)
      },
    })
  })

  return units
}

const applyTranslatedValues = (
  translatedTexts: Record<string, string>,
  translationUnitsById: Map<string, TranslationUnit>
) => {
  const nextTranslationContent = cloneTranslationContent(translationDraft.value)
  let hasUpdates = false

  Object.entries(translatedTexts).forEach(([translationUnitId, translation]) => {
    const unit = translationUnitsById.get(translationUnitId)
    if (!unit || !isNonEmptyString(translation)) {
      return
    }

    try {
      unit.apply(nextTranslationContent, translation)
      machineTranslatedFields.value.add(unit.fieldIdentifier)
      hasUpdates = true
    } catch {
      // silently skip units that fail to apply
    }
  })

  if (hasUpdates) {
    emitTranslationContent(nextTranslationContent)
  }
}

function stripCodeFences(content: string): string {
  return content
    .replace(/^```(?:json|javascript|js)?\s*\n?/i, '')
    .replace(/\n?```\s*$/i, '')
    .trim()
}

// Unwrap AI responses that may nest translations under a wrapper key
// e.g. { "translations": { ... } } or { "data": { ... } }
function findTranslationsObject(parsed: unknown): Record<string, string> | null {
  if (typeof parsed !== 'object' || parsed === null) return null

  const obj = parsed as Record<string, unknown>

  // Check if it's already a flat string map
  const values = Object.values(obj)
  if (values.length > 0 && values.every((v) => typeof v === 'string')) {
    return obj as Record<string, string>
  }

  // Look one level deep for a nested object that is a flat string map
  for (const v of values) {
    if (typeof v === 'object' && v !== null) {
      const nested = v as Record<string, unknown>
      const nestedValues = Object.values(nested)
      if (nestedValues.length > 0 && nestedValues.every((nv) => typeof nv === 'string')) {
        return nested as Record<string, string>
      }
    }
  }

  return null
}

const translateWithAI = async (configId: string | null = selectedConfigId.value): Promise<void> => {
  if (!configId) {
    toast.error(t('components.aiText.noConfigSelected'))
    return
  }
  const translationUnits = buildTranslationUnits()
  const fieldsToTranslate = Object.fromEntries(
    translationUnits.map((unit) => [unit.id, unit.source] as const)
  )
  const translationUnitsById = new Map(translationUnits.map((unit) => [unit.id, unit] as const))
  const fieldCount = translationUnits.length

  if (fieldCount === 0) {
    toast.info('No untranslated content found')
    return
  }

  const appliedKeys = new Set<string>()
  let accumulated = ''
  translationProgress.value = { applied: 0, total: fieldCount }

  // Apply only entries not yet applied — let updateTranslatedValues handle field matching
  const applyNew = (entries: Record<string, string>) => {
    const fresh: Record<string, string> = {}
    for (const [k, v] of Object.entries(entries)) {
      if (!appliedKeys.has(k)) {
        fresh[k] = v
        appliedKeys.add(k)
      }
    }
    if (Object.keys(fresh).length > 0) {
      applyTranslatedValues(fresh, translationUnitsById)
      translationProgress.value = { applied: appliedKeys.size, total: fieldCount }
    }
  }

  await streamTranslation(
    {
      source: sourceLanguage.value,
      target: props.targetLanguage,
      fields: fieldsToTranslate,
      config_id: configId,
    },
    {
      onDelta: (chunk) => {
        accumulated += chunk
        applyNew(extractCompletedTranslations(accumulated))
      },
      onDone: (content) => {
        try {
          const raw = JSON.parse(stripCodeFences(content || accumulated))
          const translations = findTranslationsObject(raw)
          if (translations) {
            applyNew(translations)
          }
          const count = appliedKeys.size
          if (count > 0) {
            toast.success(`Successfully translated ${count} item${count !== 1 ? 's' : ''}`)
          } else {
            toast.error(
              t('composables.aiTranslation.error', { error: 'No fields could be matched' })
            )
          }
        } catch {
          if (appliedKeys.size > 0) {
            toast.success(`Translated ${appliedKeys.size} item${appliedKeys.size !== 1 ? 's' : ''}`)
          } else {
            toast.error(t('composables.aiTranslation.error', { error: 'Invalid JSON response' }))
          }
        }
        translationProgress.value = null
      },
      onError: (error) => {
        translationProgress.value = null
        toast.error(t('composables.aiTranslation.error', { error }))
      },
    }
  )
}

const translationStats = computed(() => {
  const total = translatableFields.value.length
  const translated = translatableFields.value.filter((f) => f.isTranslated).length
  const percentage = total > 0 ? Math.round((translated / total) * 100) : 0
  const machineTranslated = machineTranslatedFields.value.size

  return {
    total,
    translated,
    percentage,
    machineTranslated,
  }
})
</script>

<template>
  <div class="w-full">
    <div class="mb-4 flex flex-wrap items-center justify-between">
      <div class="flex items-center gap-4">
        <div class="space-y-1">
          <h3 class="text-sm font-semibold">Translation Progress</h3>
          <div class="flex items-center gap-2">
            <div class="h-2 w-24 overflow-hidden rounded-full bg-elevated">
              <div
                class="h-full bg-green-600"
                :style="`width: ${translationStats.percentage}%`"
              />
            </div>
            <span class="text-xs font-semibold text-muted">
              {{ translationStats.translated }}/{{ translationStats.total }} fields ({{
                translationStats.percentage
              }}%)
            </span>
          </div>
        </div>
      </div>

      <div class="flex items-center gap-3">
        <div class="flex flex-col items-end gap-1">
          <SplitButton
            size="sm"
            variant="default"
            :primary-action="() => translateWithAI()"
            :disabled="!canTranslateWithAI"
            :has-menu="aiConfigs?.length > 1"
            :menu-disabled="isLoadingAiConfigs || !aiConfigs?.length"
            :loading="isTranslating"
          >
            <Icon
              v-if="!isTranslating"
              name="lucide:sparkles"
              class="text-ai"
            />
            <span>{{
              isTranslating
                ? $t('components.flattenedLocalization.translating')
                : $t('components.flattenedLocalization.translate')
            }}</span>
            <template #menu>
              <DropdownMenuItem
                v-for="config in aiConfigs"
                :key="config.id"
                :disabled="isTranslating"
                @select="translateWithAI(config.id)"
              >
                <div class="flex items-center gap-2">
                  <span class="font-medium">
                    {{ config.name }}
                  </span>
                  <Badge
                    v-if="config.is_default"
                    size="sm"
                    >Default</Badge
                  >
                </div>
              </DropdownMenuItem>
            </template>
          </SplitButton>
          <Transition
            enter-active-class="transition-all duration-200 ease-out"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition-all duration-150 ease-in"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
          >
            <span
              v-if="translationProgress"
              class="ai-animate-text text-xs text-muted"
              >{{
                $t('components.flattenedLocalization.translationProgress', translationProgress)
              }}</span
            >
          </Transition>
        </div>

        <Input
          v-model="searchQuery"
          placeholder="Search fields..."
        />
        <CheckboxField
          v-model="showUntranslatedOnly"
          name="untranslated"
          label="Show untranslated only"
        />
      </div>
    </div>
    <div class="grid gap-3">
      <div
        v-for="field in filteredFields"
        :key="`${field.path.join('-')}-${field.key}`"
        :data-field-path="getValidationPath(field)"
        :data-validation-visible="shouldShowValidationError(field) ? 'true' : undefined"
        :class="field.isOrphaned ? 'rounded-md border border-amber-500/40 bg-amber-500/5 px-3 pb-1' : undefined"
      >
        <div class="-mb-2 pt-2">
          <h4 class="flex items-baseline gap-2">
            <span class="font-semibold text-primary">{{ field.fieldName }}</span>
            <span class="text-2xs opacity-50 font-mono">
              {{ field.path.join('.') }}
            </span>
            <span
              v-if="field.isOrphaned"
              class="ml-auto flex items-center gap-2"
            >
              <span class="rounded bg-amber-500/15 px-1.5 py-0.5 text-2xs font-medium text-amber-500">
                {{ $t('components.flattenedLocalization.orphanedField') }}
              </span>
              <button
                type="button"
                class="flex items-center gap-1 rounded px-1.5 py-0.5 text-2xs font-medium text-amber-500 hover:bg-amber-500/15 transition-colors"
                :title="$t('components.flattenedLocalization.removeOrphanedField')"
                @click="removeOrphanedEntry(field)"
              >
                <Icon
                  name="lucide:trash-2"
                  class="size-3"
                />
                {{ $t('components.flattenedLocalization.removeOrphanedField') }}
              </button>
            </span>
          </h4>
        </div>
        <div>
          <div
            v-if="field.isOrphanedBlock"
            class="py-2 text-sm text-muted"
          >
            {{ $t('components.flattenedLocalization.orphanedBlockDescription') }}
          </div>
          <component
            :is="resolveLocalizerComponent(field.schemaItem.type)"
            v-else-if="resolveLocalizerComponent(field.schemaItem.type)"
            :item="field.schemaItem"
            :original-value="field.originalValue"
            :model-value="field.translatedValue"
            :disabled="isTranslating"
            :is-machine-translated="isMachineTranslated(field)"
            :space-id="props.spaceId"
            :error="shouldShowValidationError(field) ? getValidationError(field) : null"
            @update:model-value="(newValue: unknown) => updateTranslatedValue(field, newValue)"
          />
          <div
            v-else
            class="grid grid-cols-2 gap-4 px-4 py-2 text-muted italic"
          >
            <div class="rounded border border-elevated bg-gray-850 p-2">
              {{ field.originalValue }}
            </div>
            <div class="rounded border border-elevated bg-gray-850 p-2">
              <Input
                :value="field.translatedValue"
                @input="
                  (e: Event) => updateTranslatedValue(field, (e.target as HTMLInputElement).value)
                "
              />
              <div class="mt-2 text-xs text-muted">
                No specialized editor for type: {{ field.schemaItem.type }}
              </div>
            </div>
          </div>
        </div>
        <div
          v-if="
            !resolveLocalizerComponent(field.schemaItem.type) &&
            shouldShowValidationError(field) &&
            getValidationError(field)
          "
          class="mt-2 rounded-md border border-destructive/20 bg-destructive/5 px-3 py-2 text-sm text-destructive"
        >
          {{ getValidationError(field) }}
        </div>
      </div>
      <div
        v-if="filteredFields.length === 0"
        class="p-8 text-center text-muted"
      >
        <div v-if="translatableFields.length === 0">
          No translatable fields found in this content.
        </div>
        <div v-else-if="showUntranslatedOnly">
          No untranslated fields found. All fields have been translated!
        </div>
        <div v-else-if="searchQuery">No fields match your search query.</div>
      </div>
    </div>
  </div>
</template>
