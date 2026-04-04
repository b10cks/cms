<script setup lang="ts">
import { computed } from 'vue'

import { ComboboxField } from '~/components/ui/form'
import type { ComboboxOption } from '~/components/ui/form/ComboboxField.vue'

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
    <ComboboxField
      :model-value="value.block_whitelist"
      name="block_whitelist"
      :label="$t('labels.blocks.fields.blockWhitelist')"
      :placeholder="$t('labels.blocks.fields.blockWhitelistPlaceholder')"
      :options="blockOptions"
      :filter-fn="filterBlocks"
      multiple
      searchable
      :empty-text="$t('labels.blocks.fields.blockWhitelistEmpty')"
      @update:model-value="emit('update:item-value', 'block_whitelist', $event)"
    />
  </div>
</template>
