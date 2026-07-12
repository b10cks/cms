<script setup lang="ts">
import { useSortable, type UseSortableOptions } from '@vueuse/integrations/useSortable'
import type { Ref } from 'vue'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { CheckboxField, InputField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
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

interface ListStyle {
  name: string
  className: string
  type?: 'bullet' | 'ordered' | 'both'
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

// ─── Feature toggles ──────────────────────────────────────────────────────────
// A feature is enabled unless explicitly set to false, so leaving the map empty
// keeps every button (back-compatible with existing fields).

const FEATURE_GROUPS: { label: string; features: { key: RichTextFeature; label: string }[] }[] = [
  {
    label: 'Text marks',
    features: [
      { key: 'bold', label: 'Bold' },
      { key: 'italic', label: 'Italic' },
      { key: 'underline', label: 'Underline' },
      { key: 'strike', label: 'Strikethrough' },
      { key: 'code', label: 'Inline code' },
    ],
  },
  {
    label: 'Blocks',
    features: [
      { key: 'heading', label: 'Headings' },
      { key: 'blockquote', label: 'Blockquote' },
      { key: 'codeBlock', label: 'Code block' },
      { key: 'horizontalRule', label: 'Horizontal rule' },
      { key: 'table', label: 'Tables' },
    ],
  },
  {
    label: 'Lists',
    features: [
      { key: 'bulletList', label: 'Bullet list' },
      { key: 'orderedList', label: 'Ordered list' },
    ],
  },
  {
    label: 'Links',
    features: [
      { key: 'link', label: 'External link' },
      { key: 'internalLink', label: 'Internal link' },
    ],
  },
]

const isFeatureEnabled = (key: RichTextFeature): boolean => props.value.features?.[key] !== false

const setFeature = (key: RichTextFeature, enabled: boolean) => {
  const next: Partial<Record<RichTextFeature, boolean>> = { ...props.value.features }
  // Store only the disabled features; enabled is the implicit default.
  if (enabled) delete next[key]
  else next[key] = false
  updateItemValue('features', next)
}

// ─── Heading levels ───────────────────────────────────────────────────────────

type HeadingLevel = 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6' | 'p'
const ALL_HEADINGS: HeadingLevel[] = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p']
const DEFAULT_HEADINGS: HeadingLevel[] = ['h1', 'h2', 'h3', 'h4', 'p']

const headingLevels = computed<HeadingLevel[]>(() => props.value.heading_levels ?? DEFAULT_HEADINGS)

const isHeadingEnabled = (level: HeadingLevel): boolean => headingLevels.value.includes(level)

const headingLabel = (level: HeadingLevel): string =>
  level === 'p' ? 'Paragraph' : `Heading ${level.charAt(1)}`

const toggleHeading = (level: HeadingLevel, enabled: boolean) => {
  const set = new Set(headingLevels.value)
  if (enabled) set.add(level)
  else set.delete(level)
  // Keep a canonical, deduplicated order.
  updateItemValue(
    'heading_levels',
    ALL_HEADINGS.filter((h) => set.has(h))
  )
}

// ─── HTML classes ─────────────────────────────────────────────────────────────

const htmlClasses = ref<HtmlClass[]>(props.value.html_classes || [])

// ─── Placeholders ─────────────────────────────────────────────────────────────

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

// ─── List styles ──────────────────────────────────────────────────────────────

const listStyles = ref<ListStyle[]>(props.value.list_styles || [])
const newListStyle = ref<ListStyle>({ name: '', className: '', type: 'both' })
const showAddListStyleForm = ref(false)

const listStyleTypes = [
  { value: 'both', label: 'Bullet & ordered' },
  { value: 'bullet', label: 'Bullet only' },
  { value: 'ordered', label: 'Ordered only' },
]

const addListStyle = () => {
  if (!newListStyle.value.name || !newListStyle.value.className) return

  listStyles.value.push({ ...newListStyle.value })
  updateItemValue('list_styles', listStyles.value)
  newListStyle.value = { name: '', className: '', type: 'both' }
  showAddListStyleForm.value = false
}

const removeListStyle = (index: number) => {
  listStyles.value.splice(index, 1)
  updateItemValue('list_styles', listStyles.value)
}

const updateListStyle = (index: number, key: keyof ListStyle, value: string) => {
  listStyles.value[index] = {
    ...listStyles.value[index],
    [key]: value,
  }
  updateItemValue('list_styles', listStyles.value)
}

// ─── Row editors (HTML classes & placeholders) ────────────────────────────────

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
  () => props.value.list_styles,
  (next) => {
    listStyles.value = next || []
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
      <h4 class="text-sm font-semibold">Toolbar features</h4>
      <p class="text-xs text-muted-foreground">
        Disable a feature to hide its button and stop it entering the content — even via paste.
      </p>
      <div class="grid grid-cols-2 gap-x-6 gap-y-4">
        <div
          v-for="group in FEATURE_GROUPS"
          :key="group.label"
          class="space-y-2"
        >
          <h5 class="text-xs font-semibold text-muted-foreground">{{ group.label }}</h5>
          <CheckboxField
            v-for="feature in group.features"
            :key="feature.key"
            :model-value="isFeatureEnabled(feature.key)"
            :name="`feature-${feature.key}`"
            :label="feature.label"
            @update:model-value="(v) => setFeature(feature.key, Boolean(v))"
          />
        </div>
      </div>
    </div>

    <div
      v-if="isFeatureEnabled('heading')"
      class="space-y-2"
    >
      <h4 class="text-sm font-semibold">Heading levels</h4>
      <p class="text-xs text-muted-foreground">
        Choose which block formats the format dropdown offers.
      </p>
      <div class="grid grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-4">
        <CheckboxField
          v-for="level in ALL_HEADINGS"
          :key="level"
          :model-value="isHeadingEnabled(level)"
          :name="`heading-${level}`"
          :label="headingLabel(level)"
          @update:model-value="(v) => toggleHeading(level, Boolean(v))"
        />
      </div>
    </div>

    <div
      v-if="isFeatureEnabled('bulletList') || isFeatureEnabled('orderedList')"
      class="space-y-2"
    >
      <h4 class="text-sm font-semibold">List styles</h4>
      <p class="text-xs text-muted-foreground">
        Named CSS classes editors can apply to a list. The class is rendered on the
        <code class="font-mono">&lt;ul&gt;</code>/<code class="font-mono">&lt;ol&gt;</code> so your
        frontend can style each variant however it likes.
      </p>
      <div class="space-y-2">
        <div
          v-for="(style, index) in listStyles"
          :key="index"
          class="flex flex-col gap-2 rounded border border-input bg-surface p-3"
        >
          <div class="flex items-end gap-2">
            <InputField
              :model-value="style.name"
              name="list-style-name"
              label="Display Name"
              placeholder="e.g., Checklist"
              @update:model-value="(v) => updateListStyle(index, 'name', v as string)"
            />
            <InputField
              :model-value="style.className"
              name="list-style-class"
              label="CSS Class"
              placeholder="e.g., list-checklist"
              @update:model-value="(v) => updateListStyle(index, 'className', v as string)"
            />
            <Button
              type="button"
              size="icon"
              variant="ghost"
              class="hover:text-destructive"
              @click="removeListStyle(index)"
            >
              <Icon
                name="lucide:trash-2"
                size="0.9rem"
              />
            </Button>
          </div>
          <Select
            :model-value="style.type || 'both'"
            @update:model-value="(v) => updateListStyle(index, 'type', String(v))"
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="option in listStyleTypes"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div
        v-if="showAddListStyleForm"
        class="flex flex-col gap-2 rounded border border-input bg-surface p-3"
      >
        <InputField
          v-model="newListStyle.name"
          name="new-list-style-name"
          label="Display Name"
          placeholder="e.g., Checklist"
        />
        <InputField
          v-model="newListStyle.className"
          name="new-list-style-class"
          label="CSS Class"
          placeholder="e.g., list-checklist"
        />
        <Select v-model="newListStyle.type">
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in listStyleTypes"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </SelectItem>
          </SelectContent>
        </Select>
        <div class="flex gap-2">
          <Button
            type="button"
            size="sm"
            @click="addListStyle"
          >
            Add List Style
          </Button>
          <Button
            type="button"
            size="sm"
            variant="outline"
            @click="showAddListStyleForm = false"
          >
            Cancel
          </Button>
        </div>
      </div>

      <Button
        v-if="!showAddListStyleForm"
        type="button"
        size="sm"
        variant="outline"
        @click="showAddListStyleForm = true"
      >
        <Icon
          name="lucide:plus"
          size="0.9rem"
        />
        Add List Style
      </Button>
    </div>

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
