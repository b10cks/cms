<script setup lang="ts">
import { SelectField } from '~/components/ui/form'
import { useFieldOptionChoices } from '~/composables/useFieldOptionChoices'

const modelValue = defineModel<string | null>()

const props = defineProps<{
  item: OptionSchema & { key: string }
  spaceId: string
}>()

const { choices, isLoading } = useFieldOptionChoices(
  computed(() => props.spaceId),
  computed(() => props.item)
)

const selectKey = computed(() => choices.value.map((choice) => choice.value).join('|'))

const value = computed({
  get: () => modelValue.value ?? null,
  set: (nextValue: string | null | undefined) => {
    modelValue.value = nextValue ?? null
  },
})
</script>

<template>
  <SelectField
    :key="selectKey"
    v-model="value"
    :name="item.key"
    :label="item.name || item.key"
    :description="item.description ?? undefined"
    :required="item.required"
    :clearable="!item.required"
    :placeholder="isLoading ? 'labels.blocks.fields.optionPreviewLoading' : 'common.select'"
    :empty-text="
      isLoading
        ? 'labels.blocks.fields.optionPreviewLoading'
        : 'labels.blocks.fields.optionPreviewEmpty'
    "
    :options="choices.map((choice) => ({ label: choice.label, value: choice.value }))"
  />
</template>
