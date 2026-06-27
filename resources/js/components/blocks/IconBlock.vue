<script setup lang="ts">
import { ComboboxField, SelectField } from '~/components/ui/form'
import { useIconifyCollections } from '~/composables/useIconifyCollections'

const { $t } = useI18n()

defineProps<{ value: IconSchema }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const sourceOptions = [
  { value: 'registry' as IconFieldSource, label: $t('labels.blocks.fields.icon.sourceRegistry') },
  { value: 'all' as IconFieldSource, label: $t('labels.blocks.fields.icon.sourceAll') },
  {
    value: 'collections' as IconFieldSource,
    label: $t('labels.blocks.fields.icon.sourceCollections'),
  },
]

const { collections, loading } = useIconifyCollections()

const collectionOptions = computed(() =>
  collections.value.map((collection) => ({
    value: collection.prefix,
    label: `${collection.name} (${collection.prefix})`,
  }))
)
</script>

<template>
  <div class="flex flex-col gap-6">
    <SelectField
      name="icon_source"
      :model-value="value.source"
      :label="$t('labels.blocks.fields.icon.source')"
      :options="sourceOptions"
      @update:model-value="emit('update:item-value', 'source', $event)"
    />
    <ComboboxField
      v-if="value.source === 'collections'"
      name="icon_collections"
      :model-value="value.allowed_collections || []"
      :label="$t('labels.blocks.fields.icon.allowedCollections')"
      :placeholder="$t('labels.blocks.fields.icon.allowedCollectionsPlaceholder')"
      multiple
      searchable
      :options="collectionOptions"
      :loading="loading"
      @update:model-value="emit('update:item-value', 'allowed_collections', $event)"
    />
  </div>
</template>
