<script setup lang="ts">
import { computed } from 'vue'
import { CheckboxField } from '~/components/ui/form'
import ComboboxField from '~/components/ui/form/ComboboxField.vue'
import type { ComboboxOption } from '~/components/ui/form/ComboboxField.vue'

defineProps<{ value: BlocksSchema }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const route = useRoute()

const { useBlocksQuery } = useBlocks(route.params.space as string)
const { useBlockTagsQuery } = useBlockTags(route.params.space as string)

const { data: blocks } = useBlocksQuery({ per_page: 1000 })
const { data: blockTags } = useBlockTagsQuery({ per_page: 1000 })

const blockOptions = computed(
  (): ComboboxOption<string>[] =>
    blocks.value?.data.map(({ slug, name }) => ({
      value: slug,
      label: name,
    })) || []
)

const blockTagOptions = computed(
  (): ComboboxOption<string>[] =>
    blockTags.value?.data.map(({ name }) => ({
      value: name,
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
  <div class="flex flex-col gap-3">
    <CheckboxField
      name="restrict_blocks"
      :model-value="value.restrict_blocks"
      :label="$t('labels.blocks.fields.restrict_blocks')"
      @update:model-value="emit('update:item-value', 'restrict_blocks', $event)"
    />

    <ComboboxField
      v-if="value.restrict_blocks"
      name="block_whitelist"
      :model-value="value.block_whitelist"
      :label="$t('labels.blocks.fields.blockWhitelist')"
      placeholder="labels.blocks.fields.blockWhitelistPlaceholder"
      :description="$t('labels.blocks.fields.blockWhitelistDescription')"
      :options="blockOptions"
      :filter-fn="filterBlocks"
      multiple
      searchable
      :empty-text="$t('labels.blocks.fields.blockWhitelistEmpty')"
      @update:model-value="emit('update:item-value', 'block_whitelist', $event)"
    />

    <ComboboxField
      v-if="value.restrict_blocks"
      name="tag_whitelist"
      :model-value="value.tag_whitelist"
      :label="$t('labels.blocks.fields.tagWhitelist')"
      placeholder="labels.blocks.fields.tagWhitelistPlaceholder"
      :description="$t('labels.blocks.fields.tagWhitelistDescription')"
      :options="blockTagOptions"
      :filter-fn="filterBlocks"
      multiple
      searchable
      :empty-text="$t('labels.blocks.fields.tagWhitelistEmpty')"
      @update:model-value="emit('update:item-value', 'tag_whitelist', $event)"
    />
  </div>
</template>
