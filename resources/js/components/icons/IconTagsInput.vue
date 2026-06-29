<script setup lang="ts">
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

const props = defineProps<{
  modelValue: string[]
  spaceId: string
  name: string
  label?: string
  placeholder?: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: string[]): void
}>()

const { t } = useI18n()
const { useIconTagsQuery } = useIcons(props.spaceId)
const { data: availableTags } = useIconTagsQuery()

const searchValue = ref('')

const filteredOptions = computed(() => {
  const selected = new Set(props.modelValue)
  const search = searchValue.value.trim().toLowerCase()

  return (availableTags.value ?? []).filter((tag) => {
    if (selected.has(tag)) return false
    if (search && !tag.toLowerCase().includes(search)) return false
    return true
  })
})

const newTagOption = computed(() => {
  const value = searchValue.value.trim()
  if (!value) return null
  if (props.modelValue.includes(value)) return null
  if ((availableTags.value ?? []).includes(value)) return null
  return value
})

const addTag = (tag: string) => {
  const trimmed = tag.trim()
  if (!trimmed || props.modelValue.includes(trimmed)) return
  emit('update:modelValue', [...props.modelValue, trimmed])
  searchValue.value = ''
}

const removeTag = (tag: string) => {
  emit('update:modelValue', props.modelValue.filter((t) => t !== tag))
}

const handleKeydown = (event: KeyboardEvent) => {
  if (event.key !== 'Enter' || !newTagOption.value) return
  event.preventDefault()
  addTag(newTagOption.value)
}
</script>

<template>
  <FormField
    :name="name"
    :label="label"
  >
    <Combobox
      :model-value="modelValue"
      :disabled="disabled"
      class="w-full"
    >
      <ComboboxAnchor as-child>
        <TagsInput
          :model-value="modelValue"
          :disabled="disabled"
          class="pl-2"
        >
          <TagsInputItem
            v-for="tag in modelValue"
            :key="tag"
            :value="tag"
          >
            <TagsInputItemText>{{ tag }}</TagsInputItemText>
            <TagsInputItemDelete @click="removeTag(tag)" />
          </TagsInputItem>
          <ComboboxInput
            v-model="searchValue"
            :placeholder="placeholder ?? t('labels.icons.tagsPlaceholder')"
            :disabled="disabled"
            as-child
            @keydown="handleKeydown"
          >
            <TagsInputInput />
          </ComboboxInput>
        </TagsInput>
      </ComboboxAnchor>
      <ComboboxList>
        <ComboboxEmpty>{{ t('labels.icons.tagsEmpty') }}</ComboboxEmpty>
        <ComboboxGroup>
          <ComboboxItem
            v-if="newTagOption"
            :value="newTagOption"
            @select.prevent="addTag(newTagOption)"
          >
            {{ t('labels.icons.tagsCreate', { tag: newTagOption }) }}
          </ComboboxItem>
          <ComboboxItem
            v-for="tag in filteredOptions"
            :key="tag"
            :value="tag"
            @select.prevent="addTag(tag)"
          >
            {{ tag }}
          </ComboboxItem>
        </ComboboxGroup>
      </ComboboxList>
    </Combobox>
  </FormField>
</template>
