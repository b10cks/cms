<script setup lang="ts">
import { useSortable, type UseSortableOptions } from '@vueuse/integrations/useSortable'
import type { Ref } from 'vue'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { Textarea } from '~/components/ui/textarea'

interface HtmlClass {
  name: string
  className: string
  css?: string
}

interface Placeholder {
  key: string
  label: string
}

const props = defineProps<{
  name: string
  value: RichTextSchema
}>()

const emit = defineEmits<{
  'update:item-value': [key: string, value: unknown]
}>()

const updateItemValue = (key: string, value: unknown) => {
  emit('update:item-value', key, value)
}

const htmlClasses = ref<HtmlClass[]>(props.value.html_classes || [])
const placeholders = ref<Placeholder[]>(props.value.placeholders || [])

const htmlClassList = ref<HTMLElement | null>(null)
const placeholderList = ref<HTMLElement | null>(null)

// Rows with a CSS preview start expanded; afterwards it's purely toggle-driven.
const expandedCss = ref(new Set<HtmlClass>())
const initExpandedCss = () => {
  expandedCss.value = new Set(htmlClasses.value.filter((row) => row.css))
}
initExpandedCss()

const toggleCss = (row: HtmlClass) => {
  if (expandedCss.value.has(row)) {
    expandedCss.value.delete(row)
  } else {
    expandedCss.value.add(row)
  }
}

interface RowEditor {
  add: (focusNew?: boolean) => void
  remove: (index: number) => void
  onKeydown: (event: KeyboardEvent, rowIndex: number, colIndex: number) => void
  commit: () => void
}

function createRowEditor<T>(options: {
  rows: Ref<T[]>
  listId: () => string
  key: string
  cols: number
  create: () => T
}): RowEditor {
  const commit = () => updateItemValue(options.key, [...options.rows.value])

  const focusCell = (rowIndex: number, colIndex: number) => {
    const cell = document.querySelector<HTMLElement>(
      `#${options.listId()} [data-row="${rowIndex}"][data-col="${colIndex}"]`
    )
    cell?.focus()
  }

  const add = (focusNew = false) => {
    const newIndex = options.rows.value.length
    options.rows.value.push(options.create())
    commit()

    if (focusNew) {
      nextTick(() => focusCell(newIndex, 0))
    }
  }

  const remove = (index: number) => {
    options.rows.value.splice(index, 1)
    commit()
  }

  const onKeydown = (event: KeyboardEvent, rowIndex: number, colIndex: number) => {
    const lastRowIndex = options.rows.value.length - 1
    const lastColIndex = options.cols - 1

    switch (event.key) {
      case 'ArrowUp':
        if (rowIndex > 0) {
          event.preventDefault()
          focusCell(rowIndex - 1, colIndex)
        }
        break
      case 'ArrowDown':
        event.preventDefault()
        if (rowIndex < lastRowIndex) {
          focusCell(rowIndex + 1, colIndex)
        } else {
          add(true)
        }
        break
      case 'Tab':
        if (!event.shiftKey && rowIndex === lastRowIndex && colIndex === lastColIndex) {
          event.preventDefault()
          add(true)
        }
        break
      case 'Enter':
        event.preventDefault()
        if (rowIndex === lastRowIndex) {
          add(true)
        }
        break
    }
  }

  return { add, remove, onKeydown, commit }
}

const htmlClassEditor = createRowEditor<HtmlClass>({
  rows: htmlClasses,
  listId: () => `html-classes-${props.name}`,
  key: 'html_classes',
  cols: 2,
  create: () => ({ name: '', className: '', css: '' }),
})

const placeholderEditor = createRowEditor<Placeholder>({
  rows: placeholders,
  listId: () => `placeholders-${props.name}`,
  key: 'placeholders',
  cols: 2,
  create: () => ({ key: '', label: '' }),
})

const handleHtmlClassBlur = (row: HtmlClass) => {
  if (row.name && !row.className) {
    row.className = row.name
      .toLowerCase()
      .replace(/\s+/g, '-')
      .replace(/[^a-z0-9-]/g, '')
  }

  htmlClassEditor.commit()
}

const handlePlaceholderBlur = (row: Placeholder) => {
  if (row.label && !row.key) {
    row.key = row.label
      .toLowerCase()
      .replace(/\s+/g, '_')
      .replace(/[^a-z0-9_]/g, '')
  }

  placeholderEditor.commit()
}

// The sortablejs `Options` type doesn't resolve in this project, so the
// Sortable-native options (handle, animation, onEnd) need an explicit cast.
const sortableOptions = (commit: () => void): UseSortableOptions =>
  ({
    handle: '[draggable]',
    animation: 150,
    // useSortable reorders the array on the next tick; commit after it did.
    onEnd: () => nextTick(commit),
  }) as UseSortableOptions

useSortable(htmlClassList, htmlClasses, sortableOptions(htmlClassEditor.commit))
useSortable(placeholderList, placeholders, sortableOptions(placeholderEditor.commit))

// The parent replaces `value` (and its arrays) with fresh identities on external
// changes, while local edits mutate the same array in place - so a shallow watch
// resyncs on external updates without firing on every local keystroke.
watch(
  () => props.value.html_classes,
  (newClasses) => {
    htmlClasses.value = newClasses || []
    initExpandedCss()
  }
)

watch(
  () => props.value.placeholders,
  (newPlaceholders) => {
    placeholders.value = newPlaceholders || []
  }
)
</script>

<template>
  <div class="flex flex-col gap-6">
    <div class="space-y-2">
      <h4 class="text-sm font-semibold">{{ $t('labels.blocks.richtext.htmlClasses.title') }}</h4>
      <div class="rounded-xl bg-background py-3">
        <div
          :id="`html-classes-${name}`"
          ref="htmlClassList"
          class="mb-3 flex flex-col"
        >
          <div
            v-for="(htmlClass, index) in htmlClasses"
            :key="`html-class-${index}`"
            class="flex flex-col rounded-xl bg-background px-3 py-1"
          >
            <div class="flex items-center gap-2">
              <Icon
                name="lucide:grip-vertical"
                class="shrink-0 cursor-ns-resize hover:text-primary"
                draggable
              />
              <Input
                v-model="htmlClass.name"
                :data-row="index"
                data-col="0"
                :placeholder="$t('labels.blocks.richtext.htmlClasses.name')"
                @blur="() => handleHtmlClassBlur(htmlClass)"
                @keydown="(event: KeyboardEvent) => htmlClassEditor.onKeydown(event, index, 0)"
              />
              <Input
                v-model="htmlClass.className"
                :data-row="index"
                data-col="1"
                class="font-mono"
                :placeholder="$t('labels.blocks.richtext.htmlClasses.className')"
                @blur="() => handleHtmlClassBlur(htmlClass)"
                @keydown="(event: KeyboardEvent) => htmlClassEditor.onKeydown(event, index, 1)"
              />
              <button
                type="button"
                :class="[
                  'cursor-pointer p-2 hover:text-primary focus:text-primary',
                  expandedCss.has(htmlClass) ? 'text-primary' : 'text-gray-400',
                ]"
                :aria-label="$t('labels.blocks.richtext.htmlClasses.toggleCss')"
                :title="$t('labels.blocks.richtext.htmlClasses.toggleCss')"
                tabindex="-1"
                @click="() => toggleCss(htmlClass)"
              >
                <Icon name="lucide:paintbrush" />
              </button>
              <button
                type="button"
                class="cursor-pointer p-2 text-gray-400 hover:text-red-500 focus:text-red-500"
                :aria-label="$t('actions.blocks.richtext.removeHtmlClass')"
                tabindex="-1"
                @click="() => htmlClassEditor.remove(index)"
              >
                <Icon name="lucide:trash-2" />
              </button>
            </div>
            <div
              v-if="expandedCss.has(htmlClass)"
              class="mt-1 mb-2 pl-8"
            >
              <Textarea
                v-model="htmlClass.css"
                auto-size
                class="font-mono text-xs"
                :placeholder="$t('labels.blocks.richtext.htmlClasses.css')"
                @blur="() => htmlClassEditor.commit()"
              />
            </div>
          </div>
        </div>
        <div class="px-3">
          <Button
            class="flex cursor-pointer gap-2"
            type="button"
            @click="() => htmlClassEditor.add(true)"
          >
            <Icon name="lucide:plus" />
            <span>{{ $t('actions.blocks.richtext.addHtmlClass') }}</span>
          </Button>
        </div>
      </div>
    </div>

    <div class="space-y-2">
      <h4 class="text-sm font-semibold">{{ $t('labels.blocks.richtext.placeholders.title') }}</h4>
      <p class="text-xs text-muted-foreground">
        {{ $t('labels.blocks.richtext.placeholders.description') }}
        <code class="font-mono">&#123;&#123;first_name&#125;&#125;</code>
      </p>
      <div class="rounded-xl bg-background py-3">
        <div
          :id="`placeholders-${name}`"
          ref="placeholderList"
          class="mb-3 flex flex-col"
        >
          <div
            v-for="(placeholder, index) in placeholders"
            :key="`placeholder-${index}`"
            class="flex items-center gap-2 rounded-xl bg-background px-3 py-1"
          >
            <Icon
              name="lucide:grip-vertical"
              class="shrink-0 cursor-ns-resize hover:text-primary"
              draggable
            />
            <Input
              v-model="placeholder.label"
              :data-row="index"
              data-col="0"
              :placeholder="$t('labels.blocks.richtext.placeholders.label')"
              @blur="() => handlePlaceholderBlur(placeholder)"
              @keydown="(event: KeyboardEvent) => placeholderEditor.onKeydown(event, index, 0)"
            />
            <Input
              v-model="placeholder.key"
              :data-row="index"
              data-col="1"
              class="font-mono"
              :placeholder="$t('labels.blocks.richtext.placeholders.key')"
              @blur="() => handlePlaceholderBlur(placeholder)"
              @keydown="(event: KeyboardEvent) => placeholderEditor.onKeydown(event, index, 1)"
            />
            <button
              type="button"
              class="ml-auto cursor-pointer p-2 text-gray-400 hover:text-red-500 focus:text-red-500"
              :aria-label="$t('actions.blocks.richtext.removePlaceholder')"
              tabindex="-1"
              @click="() => placeholderEditor.remove(index)"
            >
              <Icon name="lucide:trash-2" />
            </button>
          </div>
        </div>
        <div class="px-3">
          <Button
            class="flex cursor-pointer gap-2"
            type="button"
            @click="() => placeholderEditor.add(true)"
          >
            <Icon name="lucide:plus" />
            <span>{{ $t('actions.blocks.richtext.addPlaceholder') }}</span>
          </Button>
        </div>
      </div>
    </div>
  </div>
</template>
