<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { CheckboxField, InputField } from '~/components/ui/form'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'

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
const newClass = ref<HtmlClass>({ name: '', className: '', css: '' })
const showAddForm = ref(false)

const addHtmlClass = () => {
  if (!newClass.value.name || !newClass.value.className) return

  htmlClasses.value.push({ ...newClass.value })
  updateItemValue('html_classes', htmlClasses.value)
  newClass.value = { name: '', className: '', css: '' }
  showAddForm.value = false
}

const removeHtmlClass = (index: number) => {
  htmlClasses.value.splice(index, 1)
  updateItemValue('html_classes', htmlClasses.value)
}

const updateHtmlClass = (index: number, key: keyof HtmlClass, value: string) => {
  htmlClasses.value[index] = {
    ...htmlClasses.value[index],
    [key]: value,
  }
  updateItemValue('html_classes', htmlClasses.value)
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

// ─── Placeholders ─────────────────────────────────────────────────────────────

const placeholders = ref<Placeholder[]>(props.value.placeholders || [])
const newPlaceholder = ref<Placeholder>({ key: '', label: '' })
const showAddPlaceholderForm = ref(false)

const addPlaceholder = () => {
  if (!newPlaceholder.value.key || !newPlaceholder.value.label) return

  placeholders.value.push({ ...newPlaceholder.value })
  updateItemValue('placeholders', placeholders.value)
  newPlaceholder.value = { key: '', label: '' }
  showAddPlaceholderForm.value = false
}

const removePlaceholder = (index: number) => {
  placeholders.value.splice(index, 1)
  updateItemValue('placeholders', placeholders.value)
}

const updatePlaceholder = (index: number, field: keyof Placeholder, value: string) => {
  placeholders.value[index] = {
    ...placeholders.value[index],
    [field]: value,
  }
  updateItemValue('placeholders', placeholders.value)
}

// The parent replaces `value` (and its arrays) with fresh identities on external
// changes, while local edits mutate the same array in place - so a shallow watch
// resyncs on external updates without firing on every local keystroke.
watch(
  () => props.value.html_classes,
  (newClasses) => {
    htmlClasses.value = newClasses || []
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
  <div class="space-y-6">
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
      <h4 class="text-sm font-semibold">HTML Classes</h4>
      <div class="space-y-2">
        <div
          v-for="(htmlClass, index) in htmlClasses"
          :key="index"
          class="flex flex-col gap-2 rounded border border-input bg-surface p-3"
        >
          <div class="flex items-end gap-2">
            <InputField
              :model-value="htmlClass.name"
              name="class-name"
              label="Display Name"
              placeholder="e.g., Highlight"
              @update:model-value="(v) => updateHtmlClass(index, 'name', v as string)"
            />
            <InputField
              :model-value="htmlClass.className"
              name="class-value"
              label="CSS Class"
              placeholder="e.g., bg-yellow-100"
              @update:model-value="(v) => updateHtmlClass(index, 'className', v as string)"
            />
            <Button
              type="button"
              size="icon"
              variant="ghost"
              class="hover:text-destructive"
              @click="removeHtmlClass(index)"
            >
              <Icon
                name="lucide:trash-2"
                size="0.9rem"
              />
            </Button>
          </div>
          <InputField
            :model-value="htmlClass.css"
            name="css-preview"
            label="CSS Preview (optional)"
            placeholder="e.g., background-color: yellow;"
            @update:model-value="(v) => updateHtmlClass(index, 'css', v as string)"
          />
        </div>
      </div>

      <div
        v-if="showAddForm"
        class="flex flex-col gap-2 rounded border border-input bg-surface p-3"
      >
        <InputField
          v-model="newClass.name"
          name="new-class-name"
          label="Display Name"
          placeholder="e.g., Highlight"
        />
        <InputField
          v-model="newClass.className"
          name="new-class-value"
          label="CSS Class"
          placeholder="e.g., bg-yellow-100"
        />
        <InputField
          v-model="newClass.css"
          name="new-css-preview"
          label="CSS Preview (optional)"
          placeholder="e.g., background-color: yellow;"
        />
        <div class="flex gap-2">
          <Button
            type="button"
            size="sm"
            @click="addHtmlClass"
          >
            Add Class
          </Button>
          <Button
            type="button"
            size="sm"
            variant="outline"
            @click="showAddForm = false"
          >
            Cancel
          </Button>
        </div>
      </div>

      <Button
        v-if="!showAddForm"
        type="button"
        size="sm"
        variant="outline"
        @click="showAddForm = true"
      >
        <Icon
          name="lucide:plus"
          size="0.9rem"
        />
        Add HTML Class
      </Button>
    </div>

    <div class="space-y-2">
      <h4 class="text-sm font-semibold">Placeholders</h4>
      <p class="text-xs text-muted-foreground">
        Define variables editors can insert as tokens (e.g.
        <code class="font-mono">&#123;&#123;first_name&#125;&#125;</code>).
      </p>
      <div class="space-y-2">
        <div
          v-for="(placeholder, index) in placeholders"
          :key="index"
          class="flex items-end gap-2 rounded border border-input bg-surface p-3"
        >
          <InputField
            :model-value="placeholder.key"
            name="placeholder-key"
            label="Key"
            placeholder="e.g., first_name"
            class="font-mono"
            @update:model-value="(v) => updatePlaceholder(index, 'key', v as string)"
          />
          <InputField
            :model-value="placeholder.label"
            name="placeholder-label"
            label="Label"
            placeholder="e.g., First Name"
            @update:model-value="(v) => updatePlaceholder(index, 'label', v as string)"
          />
          <Button
            type="button"
            size="icon"
            variant="ghost"
            class="hover:text-destructive"
            @click="removePlaceholder(index)"
          >
            <Icon
              name="lucide:trash-2"
              size="0.9rem"
            />
          </Button>
        </div>
      </div>

      <div
        v-if="showAddPlaceholderForm"
        class="flex flex-col gap-2 rounded border border-input bg-surface p-3"
      >
        <InputField
          v-model="newPlaceholder.key"
          name="new-placeholder-key"
          label="Key"
          placeholder="e.g., first_name"
        />
        <InputField
          v-model="newPlaceholder.label"
          name="new-placeholder-label"
          label="Label"
          placeholder="e.g., First Name"
        />
        <div class="flex gap-2">
          <Button
            type="button"
            size="sm"
            @click="addPlaceholder"
          >
            Add Placeholder
          </Button>
          <Button
            type="button"
            size="sm"
            variant="outline"
            @click="showAddPlaceholderForm = false"
          >
            Cancel
          </Button>
        </div>
      </div>

      <Button
        v-if="!showAddPlaceholderForm"
        type="button"
        size="sm"
        variant="outline"
        @click="showAddPlaceholderForm = true"
      >
        <Icon
          name="lucide:plus"
          size="0.9rem"
        />
        Add Placeholder
      </Button>
    </div>
  </div>
</template>
