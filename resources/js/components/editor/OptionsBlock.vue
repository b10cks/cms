<script setup lang="ts">
import { useFieldOptionChoices } from '~/composables/useFieldOptionChoices'
import { ComboboxField } from '~/components/ui/form'

const modelValue = defineModel<string[]>()

const props = defineProps<{
  item: OptionsSchema & { key: string }
  spaceId: string
}>()

const { choices, isLoading } = useFieldOptionChoices(
  computed(() => props.spaceId),
  computed(() => props.item)
)

const value = computed({
  get: () => modelValue.value || [],
  set: (nextValue: string[]) => {
    modelValue.value = [...nextValue]
  },
})
</script>

<template>
  <ComboboxField
    v-model="value"
    :name="item.key"
    :label="item.name || item.key"
    :description="item.description ?? undefined"
    :required="item.required"
    :options="choices.map((choice) => ({ label: choice.label, value: choice.value }))"
    :loading="isLoading"
    placeholder="common.select"
    empty-text="labels.blocks.fields.optionPreviewEmpty"
    loading-text="labels.blocks.fields.optionPreviewLoading"
    multiple
    searchable
  />
</template>
