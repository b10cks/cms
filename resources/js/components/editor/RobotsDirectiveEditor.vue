<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import {
  Combobox,
  ComboboxAnchor,
  ComboboxEmpty,
  ComboboxGroup,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
} from '~/components/ui/combobox'
import FormField from '~/components/ui/form/FormField.vue'
import {
  TagsInput,
  TagsInputInput,
  TagsInputItem,
  TagsInputItemDelete,
  TagsInputItemText,
} from '~/components/ui/tags-input'

const ROBOTS_DIRECTIVES = [
  'all',
  'index',
  'noindex',
  'follow',
  'nofollow',
  'none',
  'noarchive',
  'nosnippet',
  'noimageindex',
  'notranslate',
  'nositelinkssearchbox',
  'indexifembedded',
] as const

const ROBOTS_ORDER = [...ROBOTS_DIRECTIVES]
const ROBOTS_FAMILIES: Array<readonly string[]> = [
  ['all', 'none'],
  ['index', 'noindex'],
  ['follow', 'nofollow'],
]
const CUSTOM_DIRECTIVE_PATTERN = /^[a-z][a-z0-9-]*(?::[a-z0-9-]+)?$/i

const props = defineProps<{
  modelValue?: string | null
  name: string
  label: string
  tooltip?: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: string | null): void
}>()

const { t } = useI18n()
const searchValue = ref('')
const selectedValues = ref<string[]>([])

const isRecognizedDirective = (value: string): boolean => {
  return ROBOTS_DIRECTIVES.includes(value as (typeof ROBOTS_DIRECTIVES)[number])
}

const normalizeDirectiveTokens = (tokens: string[]): string[] => {
  const normalized: string[] = []

  for (const rawToken of tokens) {
    const token = rawToken.trim().toLowerCase()

    if (!token || !CUSTOM_DIRECTIVE_PATTERN.test(token)) {
      continue
    }

    const family = ROBOTS_FAMILIES.find((values) => values.includes(token))
    if (family) {
      for (const sibling of family) {
        const existingIndex = normalized.indexOf(sibling)
        if (existingIndex !== -1) {
          normalized.splice(existingIndex, 1)
        }
      }
    }

    const existingIndex = normalized.findIndex((value) => value === token)
    if (existingIndex !== -1) {
      normalized.splice(existingIndex, 1)
    }

    normalized.push(token)
  }

  return normalized.sort((left, right) => {
    const leftIndex = ROBOTS_ORDER.indexOf(left as (typeof ROBOTS_DIRECTIVES)[number])
    const rightIndex = ROBOTS_ORDER.indexOf(right as (typeof ROBOTS_DIRECTIVES)[number])

    if (leftIndex !== -1 || rightIndex !== -1) {
      if (leftIndex === -1) {
        return 1
      }

      if (rightIndex === -1) {
        return -1
      }

      return leftIndex - rightIndex
    }

    return left.localeCompare(right)
  })
}

const tokenizeDirectives = (value: string | null | undefined): string[] => {
  return String(value ?? '')
    .split(',')
    .map((token) => token.trim().toLowerCase())
    .filter(Boolean)
}

const filteredOptions = computed(() => {
  const selected = new Set(selectedValues.value)
  const search = searchValue.value.trim().toLowerCase()

  return ROBOTS_DIRECTIVES.filter((directive) => {
    if (selected.has(directive)) {
      return false
    }

    if (!search) {
      return true
    }

    return directive.includes(search)
  })
})

const customOption = computed(() => {
  const value = searchValue.value.trim().toLowerCase()

  if (!value || !CUSTOM_DIRECTIVE_PATTERN.test(value)) {
    return null
  }

  if (selectedValues.value.includes(value) || isRecognizedDirective(value)) {
    return null
  }

  return value
})

const emitNormalizedValue = (tokens: string[]): void => {
  const normalized = normalizeDirectiveTokens(tokens)

  selectedValues.value = normalized
  emit('update:modelValue', normalized.length ? normalized.join(',') : null)
}

const addDirective = (directive: string): void => {
  emitNormalizedValue([...selectedValues.value, directive])
  searchValue.value = ''
}

const removeDirective = (directive: string): void => {
  emitNormalizedValue(selectedValues.value.filter((value) => value !== directive))
}

const handleInputKeydown = (event: KeyboardEvent): void => {
  if (event.key !== 'Enter') {
    return
  }

  if (!customOption.value) {
    return
  }

  event.preventDefault()
  addDirective(customOption.value)
}

watch(
  () => props.modelValue,
  (value) => {
    selectedValues.value = normalizeDirectiveTokens(tokenizeDirectives(value))
  },
  { immediate: true }
)
</script>

<template>
  <FormField
    :name="name"
    :label="label"
    :tooltip="tooltip"
  >
    <Combobox
      :model-value="selectedValues"
      :disabled="disabled"
      class="w-full"
    >
      <ComboboxAnchor as-child>
        <TagsInput
          :model-value="selectedValues"
          :disabled="disabled"
          class="pl-2"
        >
          <TagsInputItem
            v-for="directive in selectedValues"
            :key="directive"
            :value="directive"
          >
            <TagsInputItemText>{{ directive }}</TagsInputItemText>
            <TagsInputItemDelete @click="removeDirective(directive)" />
          </TagsInputItem>
          <ComboboxInput
            v-model="searchValue"
            :placeholder="t('labels.contents.fields.meta.robotsPlaceholder')"
            :disabled="disabled"
            as-child
            @keydown="handleInputKeydown"
          >
            <TagsInputInput />
          </ComboboxInput>
        </TagsInput>
      </ComboboxAnchor>
      <ComboboxList>
        <ComboboxEmpty>
          {{ t('labels.contents.fields.meta.robotsEmpty') }}
        </ComboboxEmpty>
        <ComboboxGroup>
          <ComboboxItem
            v-if="customOption"
            :value="customOption"
            @select.prevent="addDirective(customOption)"
          >
            {{ t('labels.contents.fields.meta.robotsUseCustom', { directive: customOption }) }}
          </ComboboxItem>
          <ComboboxItem
            v-for="directive in filteredOptions"
            :key="directive"
            :value="directive"
            @select.prevent="addDirective(directive)"
          >
            {{ directive }}
          </ComboboxItem>
        </ComboboxGroup>
      </ComboboxList>
    </Combobox>
  </FormField>
</template>
