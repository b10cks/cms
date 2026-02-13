<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { computed, ref, watch } from 'vue'
import { toast } from 'vue-sonner'
import MetaLocalization from '~/components/localization/MetaLocalization.vue'
import { Button } from '~/components/ui/button'
import { CheckboxField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import type { ApiResponse } from '~/types'
import MarkdownLocalization from './MarkdownLocalization.vue'
import RichTextLocalization from './RichTextLocalization.vue'
import TextareaLocalization from './TextareaLocalization.vue'
import TextLocalization from './TextLocalization.vue'

interface SchemaType {
  type: string
  name?: string
  translatable?: boolean
}

interface TranslatableField {
  key: string
  path: string[]
  fieldName: string
  schemaItem: SchemaType
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

const localizers = {
  text: TextLocalization,
  textarea: TextareaLocalization,
  markdown: MarkdownLocalization,
  richtext: RichTextLocalization,
  meta: MetaLocalization,
}

const props = defineProps<{
  originalContent: Record<string, unknown>
  translationContent: Record<string, unknown>
  blockSchema: Record<string, SchemaType>
  spaceId: string
  targetLanguage: string
  getBlockSchema?: (
    blockSlug: string
  ) => { schema: Record<string, SchemaType>; name: string } | undefined
}>()

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(props.spaceId) as { data: Ref<Space> }

const showUntranslatedOnly = ref(false)
const searchQuery = ref('')
const { client: apiClient } = useApiClient()

const isTranslating = ref(false)
const sourceLanguage = computed((): string => space.value?.settings?.default_language || '')

const machineTranslatedFields = ref(new Set<string>())

const translatableFields = ref<TranslatableField[]>([])

const traverseContent = (
  original: Record<string, unknown>,
  translation: Record<string, unknown>,
  schema: Record<string, SchemaType>,
  currentPath: string[] = [],
  result: TranslatableField[] = []
): TranslatableField[] => {
  if (typeof original !== 'object' || original === null) {
    return result
  }

  Object.entries(original).forEach(([key, value]) => {
    const path = [...currentPath, key]
    const schemaItem = schema[key]

    if (!schemaItem) return

    if (schemaItem.type === 'blocks' && Array.isArray(value)) {
      value.forEach((blockItem: BlockItem, index: number) => {
        if (!blockItem || !blockItem.block) return

        const blockPath = [...path, index.toString()]
        const blockSlug = blockItem.block

        const blockSchemaItem = props.getBlockSchema ? props.getBlockSchema(blockSlug) : undefined
        if (!blockSchemaItem?.schema) return

        const translatedBlockItems = (translation?.[key] as BlockItem[]) || []
        const translatedBlockItem = translatedBlockItems[index] || {}

        traverseContent(
          blockItem as Record<string, unknown>,
          translatedBlockItem as Record<string, unknown>,
          blockSchemaItem.schema,
          blockPath,
          result
        )
      })
    } else if ('translatable' in schemaItem && schemaItem.translatable) {
      const translatedValue = translation?.[key]
      const isTranslated =
        translatedValue !== undefined && translatedValue !== null && translatedValue !== ''

      result.push({
        key,
        path,
        fieldName: schemaItem.name || key,
        schemaItem,
        originalValue: value,
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
      props.blockSchema
    )
  },
  { immediate: true, deep: true }
)

const filteredFields = computed(() => {
  return translatableFields.value.filter((field) => {
    if (field.schemaItem.type === 'block_header') {
      return true
    }

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

const translateWithAI = async (): Promise<void> => {
  const fieldsToTranslate = getUntranslatedFields()

  const fieldCount = Object.keys(fieldsToTranslate).length
  if (fieldCount === 0) {
    toast.info('No untranslated fields found')
    return
  }

  try {
    isTranslating.value = true
    toast.info(
      `Translating ${fieldCount} fields from ${sourceLanguage.value} to ${props.targetLanguage}...`
    )
    const requestData = {
      source: sourceLanguage.value,
      target: props.targetLanguage,
      space_id: props.spaceId,
      fields: fieldsToTranslate,
    }

    const response = await apiClient.post<ApiResponse<Record<string, string>>>(
      '/mgmt/v1/ai/translate',
      requestData,
      {
        query: { spaceId: props.spaceId },
      }
    )
    updateTranslatedValues(response.data)

    toast.success(`Successfully translated ${Object.keys(response.data).length} fields`)
  } catch (error: unknown) {
    const errorMessage = error instanceof Error ? error.message : 'Unknown error'
    toast.error(`Translation failed: ${errorMessage}`)
  } finally {
    isTranslating.value = false
  }
}

const translationStats = computed(() => {
  const fieldItems = translatableFields.value.filter(
    (field) => field.schemaItem.type !== 'block_header'
  )

  const total = fieldItems.length
  const translated = fieldItems.filter((f) => f.isTranslated).length
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
          <span>{{ isTranslating ? 'Translating...' : 'AI Translate' }}</span>
        </Button>

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
      >
        <div class="-mb-2 pt-2">
          <h4 class="font-semibold text-primary">{{ field.fieldName }}</h4>
          <p class="text-sm text-muted">
            {{ field.path.join(' > ') }}
          </p>
        </div>
        <div>
          <component
            :is="localizers[field.schemaItem.type]"
            v-if="field.schemaItem.type in localizers"
            :item="field.schemaItem"
            :original-value="field.originalValue"
            :model-value="field.translatedValue"
            :disabled="isTranslating"
            :is-machine-translated="isMachineTranslated(field)"
            :space-id="props.spaceId"
            @update:model-value="(newValue) => updateTranslatedValue(field, newValue)"
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
