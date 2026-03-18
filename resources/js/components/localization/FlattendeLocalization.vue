<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Component, Ref } from 'vue'
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import MetaLocalization from '~/components/localization/MetaLocalization.vue'
import { Button } from '~/components/ui/button'
import { CheckboxField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import {
  isFieldVisible,
  normalizeSchema,
  normalizeSchemaType,
} from '~/composables/useContentSchemaState'
import { useAiTranslation } from '~/composables/useAiTranslation'

import MarkdownLocalization from './MarkdownLocalization.vue'
import RichTextLocalization from './RichTextLocalization.vue'
import TextareaLocalization from './TextareaLocalization.vue'
import TextLocalization from './TextLocalization.vue'

type LocalizableSchema = SchemaType & { translatable?: boolean }


interface TranslatableField {
  key: string
  path: string[]
  fieldName: string
  schemaItem: LocalizableSchema
  originalValue: unknown
  translatedValue: unknown
  isTranslated: boolean
}


interface BlockItem {
  block?: string
  [key: string]: unknown
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


const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(props.spaceId) as { data: Ref<Space> }


const showUntranslatedOnly = ref(false)
const searchQuery = ref('')
const { t } = useI18n()
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


const machineTranslatedFields = ref(new Set<string>())


const translatableFields = ref<TranslatableField[]>([])


const traverseContent = (
  original: Record<string, unknown>,
  translation: Record<string, unknown>,
  effective: Record<string, unknown>,
  schema: Record<string, LocalizableSchema>,
  currentPath: string[] = [],
  result: TranslatableField[] = []
): TranslatableField[] => {
  if (typeof original !== 'object' || original === null) {
    return result
  }


  const normalizedSchema = normalizeSchema(schema)
  const originalScope = original as Record<string, unknown>
  const translationScope = translation as Record<string, unknown>
  const effectiveScope = effective as Record<string, unknown>


  Object.entries(normalizedSchema).forEach(([key, schemaItem]) => {
    const path = [...currentPath, key]
    if (!isFieldVisible(schemaItem, normalizedSchema, originalScope, effectiveScope)) return

    const originalValue = originalScope[key]
    const effectiveValue = effectiveScope[key]

    if (schemaItem.type === 'blocks' && Array.isArray(effectiveValue)) {
      effectiveValue.forEach((blockItem: BlockItem, index: number) => {
        if (!blockItem || !blockItem.block) return

        const blockPath = [...path, index.toString()]
        const blockSlug = blockItem.block

        const blockSchemaItem = props.getBlockSchema ? props.getBlockSchema(blockSlug) : undefined
        if (!blockSchemaItem?.schema) return

        const originalBlockItems = (originalScope[key] as BlockItem[]) || []
        const translatedBlockItems = (translationScope[key] as BlockItem[]) || []
        const originalBlockItem = originalBlockItems[index] || {}
        const translatedBlockItem = translatedBlockItems[index] || {}
        const effectiveBlockItem = {
          ...(originalBlockItem as Record<string, unknown>),
          ...(translatedBlockItem as Record<string, unknown>),
        }

        traverseContent(
          originalBlockItem as Record<string, unknown>,
          translatedBlockItem as Record<string, unknown>,
          effectiveBlockItem,
          blockSchemaItem.schema,
          blockPath,
          result
        )
      })
    } else if ('translatable' in schemaItem && schemaItem.translatable) {
      const translatedValue = translationScope[key]
      const isTranslated =
        translatedValue !== undefined && translatedValue !== null && translatedValue !== ''

      result.push({
        key,
        path,
        fieldName: schemaItem.name || key,
        schemaItem,
        originalValue,
        translatedValue: translatedValue || '',
        isTranslated,
      })
    }
  })


  return result
}


watch(
  [() => props.originalContent, () => props.translationContent, () => props.blockSchema],
  () => {
    if (!props.originalContent || !props.blockSchema) {
      translatableFields.value = []
      return
    }

    translatableFields.value = traverseContent(
      props.originalContent as Record<string, unknown>,
      props.translationContent as Record<string, unknown>,
      {
        ...(props.originalContent as Record<string, unknown>),
        ...(props.translationContent as Record<string, unknown>),
      },
      props.blockSchema
    )
  },
  { immediate: true, deep: true }
)


const filteredFields = computed(() => {
  return translatableFields.value.filter((field) => {
    if (showUntranslatedOnly.value && field.isTranslated) {
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


const isMachineTranslated = (field: TranslatableField): boolean => {
  return machineTranslatedFields.value.has(getFieldIdentifier(field))
}


const updateTranslatedValue = (field: TranslatableField, newValue: unknown): void => {
  const fieldToUpdate = translatableFields.value.find(
    (f) => f.key === field.key && JSON.stringify(f.path) === JSON.stringify(field.path)
  )


  if (fieldToUpdate) {
    fieldToUpdate.translatedValue = newValue
    fieldToUpdate.isTranslated = newValue !== '' && newValue !== null && newValue !== undefined


    let current: Record<string, unknown> = props.translationContent


    for (let i = 0; i < field.path.length - 1; i++) {
      const pathPart = field.path[i]


      if (current[pathPart] === undefined) {
        if (Number.isInteger(parseInt(field.path[i + 1]))) {
          current[pathPart] = []
        } else {
          current[pathPart] = {}
        }
      }


      if (Array.isArray(current[pathPart])) {
        const nextIndex = parseInt(field.path[i + 1])
        const currentArray = current[pathPart] as unknown[]
        if (!isNaN(nextIndex) && nextIndex >= currentArray.length) {
          for (let j = currentArray.length; j <= nextIndex; j++) {
            currentArray.push({})
          }
        }
      }


      current = current[pathPart] as Record<string, unknown>
    }


    const finalKey = field.path[field.path.length - 1]
    current[finalKey] = newValue
  }
}


const updateTranslatedValues = (translatedTexts: Record<string, string>): void => {
  Object.entries(translatedTexts).forEach(([fieldPath, translation]) => {
    const pathParts = fieldPath.split('-')
    const key = pathParts.pop() as string

    const field = translatableFields.value.find(
      (f) => f.key === key && JSON.stringify(f.path.slice(0, -1)) === JSON.stringify(pathParts)
    )

    if (field) {
      machineTranslatedFields.value.add(getFieldIdentifier(field))
      updateTranslatedValue(field, translation)
    }
  })
}


const getUntranslatedFields = (): Record<string, string> => {
  const untranslatedFields: Record<string, string> = {}


  translatableFields.value
    .filter(
      (field) =>
        !field.isTranslated &&
        typeof field.originalValue === 'string' &&
        field.originalValue.trim() !== ''
    )
    .forEach((field) => {
      const fieldPath = [...field.path.slice(0, -1), field.key].join('-')
      untranslatedFields[fieldPath] = field.originalValue as string
    })


  return untranslatedFields
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


const translateWithAI = async (): Promise<void> => {
  const fieldsToTranslate = getUntranslatedFields()
  const fieldCount = Object.keys(fieldsToTranslate).length

  if (fieldCount === 0) {
    toast.info('No untranslated fields found')
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
      updateTranslatedValues(fresh)
      translationProgress.value = { applied: appliedKeys.size, total: fieldCount }
    }
  }

  await streamTranslation(
    {
      source: sourceLanguage.value,
      target: props.targetLanguage,
      fields: fieldsToTranslate,
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
            toast.success(`Successfully translated ${count} field${count !== 1 ? 's' : ''}`)
          } else {
            toast.error(t('composables.aiTranslation.error', { error: 'No fields could be matched' }))
          }
        } catch {
          if (appliedKeys.size > 0) {
            toast.success(`Translated ${appliedKeys.size} field${appliedKeys.size !== 1 ? 's' : ''}`)
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
          <Button
            size="sm"
            :disabled="isTranslating"
            @click="translateWithAI"
          >
            <Icon
              v-if="isTranslating"
              name="lucide:loader"
              class="animate-spin text-ai"
            />
            <Icon
              v-else
              name="lucide:sparkles"
              class="text-ai"
            />
            <span>{{ isTranslating ? $t('components.flattenedLocalization.translating') : $t('components.flattenedLocalization.translate') }}</span>
          </Button>
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
            >{{ $t('components.flattenedLocalization.translationProgress', translationProgress) }}</span>
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
        :data-field-path="`content.${field.path.join('.')}`"
      >
        <div class="-mb-2 pt-2">
          <h4 class="flex items-baseline gap-2">
            <span class="font-semibold text-primary">{{ field.fieldName }}</span>
            <span class="text-2xs opacity-50 font-mono">
              {{ field.path.join('.') }}
            </span>
          </h4>
        </div>
        <div>
          <component
            :is="resolveLocalizerComponent(field.schemaItem.type)"
            v-if="resolveLocalizerComponent(field.schemaItem.type)"
            :item="field.schemaItem"
            :original-value="field.originalValue"
            :model-value="field.translatedValue"
            :disabled="isTranslating"
            :is-machine-translated="isMachineTranslated(field)"
            :space-id="props.spaceId"
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
