<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useSortable } from '@vueuse/integrations/useSortable'
import { Button } from '~/components/ui/button'
import SelectField from '~/components/ui/form/SelectField.vue'
import { Input } from '~/components/ui/input'

const props = defineProps<{ value: OptionSchema; name: string }>()
const list = ref<HTMLDivElement | null>(null)

const emit = defineEmits<{
  (e: 'update:item-value', key: string, value: unknown): void
}>()

useSortable(list, props.value.options, {
  handle: '[draggable]',
  animation: 150,
  onEnd: () => {
    emit('update:item-value', 'options', [...props.value.options])
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
      ) as HTMLElement
      newInput?.focus()
    })
  }
}

function removeOption(index: number) {
  emit(
    'update:item-value',
    'options',
    props.value.options.filter((_, i) => i !== index)
  )

  nextTick(() => {
    if (props.value.options.length > 0) {
      const newFocusIndex = Math.min(index, props.value.options.length - 1)
      const inputToFocus = document.querySelector(
        `#options-list-${props.name} [data-row="${newFocusIndex}"][data-col="0"]`
      ) as HTMLElement
      inputToFocus?.focus()
    }
  })
}

function handleKeyDown(event: KeyboardEvent, rowIndex: number, colIndex: number) {
  const lastRowIndex = props.value.options.length - 1

  switch (event.key) {
    case 'ArrowUp':
      if (rowIndex > 0) {
        event.preventDefault()
        const upElement = document.querySelector(
          `#options-list-${props.name} [data-row="${rowIndex - 1}"][data-col="${colIndex}"]`
        ) as HTMLElement
        upElement?.focus()
      }
      break

    case 'ArrowDown':
      if (rowIndex < lastRowIndex) {
        event.preventDefault()
        const downElement = document.querySelector(
          `#options-list-${props.name} [data-row="${rowIndex + 1}"][data-col="${colIndex}"]`
        ) as HTMLElement
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
      } else {
        const nextRowElement = document.querySelector(
          `#options-list-${props.name} [data-row="${rowIndex + 1}"][data-col="${colIndex}"]`
        ) as HTMLElement
        nextRowElement?.focus()
      }
      break
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <div class="rounded-xl bg-background py-3">
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
            class="shrink-0 cursor-ns-resize hover:text-primary"
            draggable
          />
          <Input
            v-model="option.name"
            :data-row="index"
            data-col="0"
            :placeholder="$t('labels.blocks.options.label')"
            @blur="() => handleBlur(option)"
            @keydown="(e: KeyboardEvent) => handleKeyDown(e, index, 0)"
          />
          <Input
            v-model="option.value"
            :data-row="index"
            data-col="1"
            :placeholder="$t('labels.blocks.options.value')"
            @blur="() => handleBlur(option)"
            @keydown="(e: KeyboardEvent) => handleKeyDown(e, index, 1)"
          />
          <button
            type="button"
            class="text-gray-4 ml-auto cursor-pointer p-2 hover:text-red-500 focus:text-red-500"
            aria-label="Remove option"
            tabindex="-1"
            @click="() => removeOption(index)"
            @keydown.enter.prevent="() => removeOption(index)"
          >
            <Icon name="lucide:trash-2" />
          </button>
        </div>
      </div>
      <div class="px-3">
        <Button
          class="flex cursor-pointer gap-2"
          type="button"
          @click="() => addOption(true)"
        >
          <Icon name="lucide:plus" />
          <span>{{ $t('actions.blocks.options.add') }}</span>
        </Button>
      </div>
    </div>

    <SelectField
      name="default"
      :label="$t('labels.blocks.fields.default')"
      :model-value="value.default"
      :options="
        value.options.map((option) => ({
          value: option.value?.length ? option.value : null,
          label: option.name,
        }))
      "
      @update:model-value="emit('update:item-value', 'default', $event)"
    />
  </div>
</template>
