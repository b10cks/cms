<script setup lang="ts">
import { useSortable } from '@vueuse/integrations/useSortable'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { ComboboxField, SelectField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import { useFieldOptionChoices } from '~/composables/useFieldOptionChoices'

const props = defineProps<{ value: OptionsSchema; name: string; readonly?: boolean }>()
const list = ref<HTMLDivElement | null>(null)
const route = useRoute()
const spaceId = computed(() => String(route.params.space || ''))

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

const { useDataSourcesQuery } = useDataSources(spaceId)
const { data: dataSources } = useDataSourcesQuery({ per_page: 1000 })
const { choices, isLoading, isEmpty } = useFieldOptionChoices(
  spaceId,
  computed(() => props.value)
)

const resolvedChoiceValues = computed(() => new Set(choices.value.map((choice) => choice.value)))
const dataSourceOptions = computed(
  () =>
    dataSources.value?.data.map((dataSource) => ({
      value: dataSource.id,
      label: dataSource.name,
    })) || []
)

if (!props.readonly) {
  ;(useSortable as any)(list, props.value.options, {
    handle: '[draggable]',
    animation: 150,
    onEnd: () => {
      emit('update:item-value', 'options', [...props.value.options])
    },
  })
}

const normalizedDefaultValue = computed({
  get: () => props.value.default || [],
  set: (value: string[]) => {
    emit('update:item-value', 'default', [...value])
  },
})

function handleBlur(option: OptionItem) {
  if (option.name && !option.value) {
    option.value = option.name
      .toLowerCase()
      .replace(/\s+/g, '-')
      .replace(/[^a-z0-9-]/g, '')
  }

  emit('update:item-value', 'options', [...props.value.options])
}

function addOption(focusNew = false) {
  if (props.readonly) return

  const newIndex = props.value.options.length
  emit('update:item-value', 'options', [
    ...props.value.options,
    {
      name: '',
      value: '',
    },
  ])

  if (focusNew) {
    nextTick(() => {
      const newInput = document.querySelector(
        `#options-list-${props.name} [data-row="${newIndex}"][data-col="0"]`
      ) as HTMLElement | null
      newInput?.focus()
    })
  }
}

function removeOption(index: number) {
  if (props.readonly) return

  emit(
    'update:item-value',
    'options',
    props.value.options.filter((_, optionIndex) => optionIndex !== index)
  )
}

function handleKeyDown(event: KeyboardEvent, rowIndex: number, colIndex: number) {
  const lastRowIndex = props.value.options.length - 1

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

watch(
  resolvedChoiceValues,
  (allowedValues) => {
    if ((props.value.source || 'self') === 'datasource' && isLoading.value) return
    const nextDefault = (props.value.default || []).filter((value) => allowedValues.has(value))

    if (nextDefault.length === (props.value.default || []).length) return

    emit('update:item-value', 'default', nextDefault)
  },
  { immediate: true }
)
</script>

<template>
  <div class="flex flex-col gap-6">
    <SelectField
      name="source"
      :model-value="value.source || 'self'"
      :label="$t('labels.blocks.fields.optionSource')"
      :description="$t('labels.blocks.fields.optionSourceDescription')"
      :disabled="readonly"
      :options="[
        {
          value: 'self',
          label: $t('labels.blocks.fields.optionSourceSelf'),
        },
        {
          value: 'datasource',
          label: $t('labels.blocks.fields.optionSourceDatasource'),
        },
      ]"
      @update:model-value="
        emit('update:item-value', 'source', $event === 'datasource' ? 'datasource' : 'self')
      "
    />

    <div
      v-if="(value.source || 'self') === 'self'"
      class="rounded-xl bg-background py-3"
    >
      <div
        :id="`options-list-${name}`"
        ref="list"
        class="mb-3 flex flex-col"
      >
        <div
          v-for="(option, index) in value.options"
          :key="`option-${index}`"
          class="flex items-center gap-2 rounded-xl bg-background px-3 py-1"
        >
          <Icon
            name="lucide:grip-vertical"
            :class="[
              'shrink-0',
              readonly ? 'text-muted-foreground' : 'cursor-ns-resize hover:text-primary',
            ]"
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
            class="ml-auto cursor-pointer p-2 text-muted hover:text-destructive focus:text-destructive disabled:cursor-not-allowed disabled:opacity-50"
            aria-label="Remove option"
            tabindex="-1"
            @click="() => removeOption(index)"
          >
            <Icon name="lucide:trash-2" />
          </button>
        </div>
      </div>
      <div class="px-3">
        <Button
          class="flex cursor-pointer gap-2"
          type="button"
          :disabled="readonly"
          @click="() => addOption(true)"
        >
          <Icon name="lucide:plus" />
          <span>{{ $t('actions.blocks.options.add') }}</span>
        </Button>
      </div>
    </div>

    <div
      v-else
      class="grid gap-4"
    >
      <SelectField
        name="data_source_id"
        :model-value="value.data_source_id"
        :label="$t('labels.blocks.fields.dataSource')"
        :description="$t('labels.blocks.fields.dataSourceDescription')"
        placeholder="labels.blocks.fields.dataSourcePlaceholder"
        :disabled="readonly"
        :options="dataSourceOptions"
        @update:model-value="emit('update:item-value', 'data_source_id', $event || null)"
      />

      <div class="rounded-xl border border-border bg-background p-3">
        <div class="mb-3">
          <h5 class="font-semibold text-primary">
            {{ $t('labels.blocks.fields.dataSourcePreview') }}
          </h5>
          <p class="text-sm text-muted-foreground">
            {{ $t('labels.blocks.fields.dataSourcePreviewDescription') }}
          </p>
        </div>

        <div
          v-if="isLoading"
          class="text-sm text-muted-foreground"
        >
          {{ $t('labels.blocks.fields.dataSourcePreviewLoading') }}
        </div>
        <div
          v-else-if="isEmpty"
          class="text-sm text-muted-foreground"
        >
          {{ $t('labels.blocks.fields.dataSourcePreviewEmpty') }}
        </div>
        <div
          v-else
          class="grid gap-2"
        >
          <div
            v-for="choice in choices"
            :key="choice.value"
            class="flex items-center justify-between rounded-lg border border-border px-3 py-2 text-sm"
          >
            <span>{{ choice.label }}</span>
            <code class="text-xs text-muted-foreground">{{ choice.value }}</code>
          </div>
        </div>
      </div>
    </div>

    <ComboboxField
      v-model="normalizedDefaultValue"
      name="default"
      :label="$t('labels.blocks.fields.default')"
      :disabled="readonly"
      :options="choices.map((choice) => ({ value: choice.value, label: choice.label }))"
      multiple
      searchable
      :loading="isLoading"
      empty-text="labels.blocks.fields.optionPreviewEmpty"
      loading-text="labels.blocks.fields.optionPreviewLoading"
    />
  </div>
</template>
