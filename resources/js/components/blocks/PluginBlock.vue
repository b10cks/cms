<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { SelectField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import { useFieldPlugins } from '~/composables/useFieldPlugins'

const props = defineProps<{ value: PluginSchema }>()

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const { $t } = useI18n()
const route = useRoute()
const spaceId = computed(() => String(route.params.space || ''))

const { useFieldPluginsQuery } = useFieldPlugins(spaceId)
const { data: pluginList } = useFieldPluginsQuery({ per_page: 100 })

const pluginOptions = computed(() =>
  (pluginList.value?.data ?? []).map((plugin) => ({
    value: plugin.handle,
    label: plugin.is_active
      ? `${plugin.name} (${plugin.handle})`
      : `${plugin.name} (${plugin.handle}) — ${$t('labels.blocks.fields.plugin.deactivated')}`,
  }))
)

const selectedPlugin = computed(() =>
  pluginList.value?.data.find((plugin) => plugin.handle === props.value.plugin_handle)
)

// Options are edited as rows so keys can be renamed without losing order.
type OptionRow = { key: string, value: string }

const rows = ref<OptionRow[]>(Object.entries(props.value.options ?? {}).map(([key, value]) => ({ key, value })))

const rowsToOptions = (): Record<string, string> => {
  const options: Record<string, string> = {}
  for (const row of rows.value) {
    if (row.key.trim() !== '') options[row.key.trim()] = row.value
  }
  return options
}

// Skip echoes of our own emit so in-progress rows (empty key) survive parent updates.
watch(
  () => props.value.options,
  (options) => {
    if (JSON.stringify(options ?? {}) === JSON.stringify(rowsToOptions())) return
    rows.value = Object.entries(options ?? {}).map(([key, value]) => ({ key, value }))
  }
)

const emitRows = () => {
  emit('update:item-value', 'options', rowsToOptions())
}

const addRow = () => {
  rows.value.push({ key: '', value: '' })
}

const removeRow = (index: number) => {
  rows.value.splice(index, 1)
  emitRows()
}

const selectPlugin = (handle: unknown) => {
  emit('update:item-value', 'plugin_handle', handle)

  // Pre-seed the options table with the plugin's manifest defaults.
  const manifest = pluginList.value?.data.find((plugin) => plugin.handle === handle)?.manifest
  if (manifest?.options?.length && rows.value.length === 0) {
    rows.value = manifest.options.map((option) => ({
      key: option.key,
      value: option.default ?? '',
    }))
    emitRows()
  }
}
</script>

<template>
  <div class="flex flex-col gap-6">
    <SelectField
      name="plugin_handle"
      :model-value="value.plugin_handle"
      :label="$t('labels.blocks.fields.plugin.selectPlugin')"
      :description="$t('labels.blocks.fields.plugin.selectPluginDescription')"
      :options="pluginOptions"
      @update:model-value="selectPlugin"
    />

    <div
      v-if="selectedPlugin && (!selectedPlugin.is_active || (selectedPlugin.status !== 'published' && !selectedPlugin.dev_mode))"
      class="rounded-md border border-amber-300 bg-amber-50 p-2 text-sm text-amber-700"
    >
      {{ !selectedPlugin.is_active
        ? $t('labels.blocks.fields.plugin.deactivatedWarning')
        : $t('labels.blocks.fields.plugin.notPublishedWarning') }}
    </div>

    <div class="flex flex-col gap-2">
      <span class="text-sm font-medium">{{ $t('labels.blocks.fields.plugin.options') }}</span>
      <div
        v-for="(row, index) in rows"
        :key="index"
        class="flex items-center gap-2"
      >
        <Input
          v-model="row.key"
          :placeholder="$t('labels.blocks.fields.plugin.optionKey')"
          @change="emitRows"
        />
        <Input
          v-model="row.value"
          :placeholder="$t('labels.blocks.fields.plugin.optionValue')"
          @change="emitRows"
        />
        <Button
          variant="ghost"
          size="icon"
          type="button"
          @click="removeRow(index)"
        >
          <Icon name="lucide:trash-2" />
        </Button>
      </div>
      <Button
        variant="outline"
        size="sm"
        type="button"
        class="self-start"
        @click="addRow"
      >
        <Icon name="lucide:plus" />
        {{ $t('labels.blocks.fields.plugin.addOption') }}
      </Button>
    </div>
  </div>
</template>
