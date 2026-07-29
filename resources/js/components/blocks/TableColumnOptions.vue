<script setup lang="ts">
import { useSortable } from '@vueuse/integrations/useSortable'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { SelectField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import { useFieldOptionChoices } from '~/composables/useFieldOptionChoices'

const props = defineProps<{
  column: Extract<TableColumn, { type: 'option' }>
  name: string
  readonly?: boolean
}>()

const emit = defineEmits<{
  'update:column': [value: Extract<TableColumn, { type: 'option' }>]
}>()

const route = useRoute()
const spaceId = computed(() => String(route.params.space || ''))
const list = ref<HTMLDivElement | null>(null)

const { useDataSourcesQuery } = useDataSources(spaceId)
const { data: dataSources } = useDataSourcesQuery({ per_page: 1000 })
const { choices, isLoading, isEmpty } = useFieldOptionChoices(
  spaceId,
  computed(() => props.column)
)

const dataSourceOptions = computed(
  () =>
    dataSources.value?.data.map((dataSource) => ({
      value: dataSource.id,
      label: dataSource.name,
    })) || []
)

const resolvedChoiceValues = computed(() => new Set(choices.value.map((choice) => choice.value)))

const emitColumn = (patch: Partial<Extract<TableColumn, { type: 'option' }>>) => {
  emit('update:column', {
    ...props.column,
    ...patch,
  })
}

if (!props.readonly) {
  ;(useSortable as any)(list, props.column.options, {
    handle: '[draggable]',
    animation: 150,
    onEnd: () => emitColumn({ options: [...props.column.options] }),
  })
}

const handleBlur = (option: OptionItem) => {
  if (option.name && !option.value) {
    option.value = option.name
      .toLowerCase()
      .replace(/\s+/g, '-')
      .replace(/[^a-z0-9_-]/g, '')
  }

  emitColumn({ options: [...props.column.options] })
}

const addOption = (focusNew = false) => {
  if (props.readonly) return

  const newIndex = props.column.options.length

  emitColumn({
    options: [...props.column.options, { name: '', value: '' }],
  })

  if (focusNew) {
    nextTick(() => {
      const newInput = document.querySelector(
        `#options-list-${props.name} [data-row="${newIndex}"][data-col="0"]`
      ) as HTMLElement | null
      newInput?.focus()
    })
  }
}

const removeOption = (index: number) => {
  if (props.readonly) return

  emitColumn({
    options: props.column.options.filter((_, optionIndex) => optionIndex !== index),
  })
}

const handleKeyDown = (event: KeyboardEvent, rowIndex: number, colIndex: number) => {
  const lastRowIndex = props.column.options.length - 1

  switch (event.key) {
    case 'ArrowUp':
      if (rowIndex > 0) {
        event.preventDefault()
        const upElement = document.querySelector(
          `#options-list-${props.name} [data-row="${rowIndex - 1}"][data-col="${colIndex}"]`
        ) as HTMLElement | null
        upElement?.focus()
      }
      break
    case 'ArrowDown':
      if (rowIndex < lastRowIndex) {
        event.preventDefault()
        const downElement = document.querySelector(
          `#options-list-${props.name} [data-row="${rowIndex + 1}"][data-col="${colIndex}"]`
        ) as HTMLElement | null
        downElement?.focus()
      } else {
        event.preventDefault()
        addOption(true)
      }
      break
    case 'Tab':
      if (!event.shiftKey && rowIndex === lastRowIndex && colIndex === 1) {
        event.preventDefault()
        addOption(true)
      }
      break
    case 'Enter':
      event.preventDefault()
      if (rowIndex === lastRowIndex) {
        addOption(true)
      }
      break
  }
}

const updateSource = (source: unknown) => {
  const nextSource = source === 'datasource' ? 'datasource' : 'self'

  emitColumn({
    source: nextSource,
    data_source_id: nextSource === 'datasource' ? props.column.data_source_id : null,
    options: [...props.column.options],
  })
}

const updateDataSource = (dataSourceId: unknown) => {
  emitColumn({
    data_source_id: dataSourceId ? String(dataSourceId) : null,
  })
}

watch(
  resolvedChoiceValues,
  (allowedValues) => {
    if ((props.column.source || 'self') === 'datasource' && isLoading.value) return

    const nextOptions =
      (props.column.source || 'self') === 'self'
        ? props.column.options
        : props.column.options.filter((option) => allowedValues.has(option.value))

    if (nextOptions.length === props.column.options.length) return

    emitColumn({
      options: [...nextOptions],
    })
  },
  { immediate: true }
)
</script>

<template>
  <div class="grid gap-4 rounded-lg border border-border/70 bg-background/70 p-4">
    <SelectField
      :name="`${name}-source`"
      :model-value="column.source"
      :disabled="readonly"
      :label="$t('labels.blocks.fields.optionSource')"
      :description="$t('labels.blocks.fields.optionSourceDescription')"
      :options="[
        { value: 'self', label: $t('labels.blocks.fields.optionSourceSelf') },
        { value: 'datasource', label: $t('labels.blocks.fields.optionSourceDatasource') },
      ]"
      @update:model-value="updateSource"
    />

    <div
      v-if="column.source === 'self'"
      class="grid gap-3"
    >
      <div
        :id="`options-list-${name}`"
        ref="list"
        class="grid gap-2"
      >
        <div
          v-for="(option, index) in column.options"
          :key="`${name}-option-${index}`"
          class="flex items-center gap-2 rounded-lg border border-border/60 bg-background px-3 py-2"
        >
          <Icon
            name="lucide:grip-vertical"
            :class="readonly ? 'text-muted-foreground' : 'cursor-ns-resize text-muted-foreground'"
            draggable
          />
          <Input
            v-model="option.name"
            :data-row="index"
            data-col="0"
            :disabled="readonly"
            :placeholder="$t('labels.blocks.options.label')"
            @blur="() => handleBlur(option)"
            @keydown="(event: KeyboardEvent) => handleKeyDown(event, index, 0)"
          />
          <Input
            v-model="option.value"
            :data-row="index"
            data-col="1"
            :disabled="readonly"
            :placeholder="$t('labels.blocks.options.value')"
            @blur="() => handleBlur(option)"
            @keydown="(event: KeyboardEvent) => handleKeyDown(event, index, 1)"
          />
          <button
            type="button"
            :disabled="readonly"
            :aria-label="$t('actions.blocks.options.delete')"
            class="cursor-pointer p-2 text-muted-foreground hover:text-destructive disabled:cursor-not-allowed disabled:opacity-50"
            tabindex="-1"
            @click="removeOption(index)"
          >
            <Icon name="lucide:trash-2" />
          </button>
        </div>
      </div>

      <Button
        type="button"
        :disabled="readonly"
        class="w-fit"
        @click="() => addOption(true)"
      >
        <Icon name="lucide:plus" />
        <span>{{ $t('actions.blocks.table.addOption') }}</span>
      </Button>
    </div>

    <div
      v-else
      class="grid gap-4"
    >
      <SelectField
        :name="`${name}-datasource`"
        :model-value="column.data_source_id"
        :disabled="readonly"
        :label="$t('labels.blocks.fields.dataSource')"
        :description="$t('labels.blocks.fields.dataSourceDescription')"
        placeholder="labels.blocks.fields.dataSourcePlaceholder"
        :options="dataSourceOptions"
        @update:model-value="updateDataSource"
      />

      <div class="rounded-lg border border-border/70 bg-background p-3">
        <p class="text-sm font-semibold text-primary">
          {{ $t('labels.blocks.fields.dataSourcePreview') }}
        </p>
        <p class="mt-1 text-xs text-muted-foreground">
          {{ $t('labels.blocks.fields.dataSourcePreviewDescription') }}
        </p>
        <div class="mt-3 rounded-md bg-surface/60 p-3 text-sm text-muted-foreground">
          <span v-if="isLoading">{{ $t('labels.blocks.fields.dataSourcePreviewLoading') }}</span>
          <span v-else-if="isEmpty">{{ $t('labels.blocks.fields.dataSourcePreviewEmpty') }}</span>
          <div
            v-else
            class="flex flex-wrap gap-2"
          >
            <span
              v-for="choice in choices"
              :key="choice.value"
              class="rounded-full border border-border/70 px-2 py-1 text-xs text-primary"
            >
              {{ choice.label }}
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
