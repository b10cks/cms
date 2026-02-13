<script setup lang="ts">
import { ComboboxField, InputField } from '~/components/ui/form'
import type { ComboboxOption } from '~/components/ui/form/ComboboxField.vue'
import { computed } from 'vue'

defineProps<{ value: ReferencesSchema }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const route = useRoute()
const { useBlocksQuery } = useBlocks(route.params.space as string)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })

const blockOptions = computed(
  (): ComboboxOption<string>[] =>
    blocks.value?.data.map(({ slug, name }) => ({
      value: slug,
      label: name,
    })) || []
)
const filterBlocks = (
  option: ComboboxOption<string>,
  search: string,
  selectedValues: string[]
): boolean => {
  const searchLower = search.toLowerCase()
  if (selectedValues.includes(option.value)) {
    return false
  }

  return !(
    search &&
    !option.value.toLowerCase().includes(searchLower) &&
    !String(option.label).toLowerCase().includes(searchLower)
  )
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <InputField
      :model-value="value.min"
      name="min"
      type="number"
      :label="$t('labels.blocks.fields.min')"
      @update:model-value="emit('update:item-value', 'min', $event)"
    />
    <InputField
      :model-value="value.max"
      name="max"
      type="number"
      :label="$t('labels.blocks.fields.max')"
      @update:model-value="emit('update:item-value', 'max', $event)"
    />
    <ComboboxField
      :model-value="value.block_whitelist"
      name="block_whitelist"
      :label="$t('labels.blocks.fields.block_whitelist')"
      :placeholder="$t('labels.blocks.fields.block_whitelist_placeholder')"
      :options="blockOptions"
      :filter-fn="filterBlocks"
      multiple
      searchable
      :empty-text="$t('labels.blocks.fields.no_blocks_found')"
      @update:model-value="emit('update:item-value', 'block_whitelist', $event)"
    />
  </div>
</template>
