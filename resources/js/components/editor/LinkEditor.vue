<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import ContentPicker from '~/components/editor/ContentPicker.vue'
import { FormField, InputField } from '~/components/ui/form'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'

type LinkType = 'url' | 'email' | 'internal'
type LinkTarget = '_self' | '_blank' | '_parent' | '_top'

interface UrlLink {
  type: 'url'
  url: string
  target?: LinkTarget
  rel?: string
}

interface EmailLink {
  type: 'email'
  email?: string
}

interface InternalLink {
  type: 'internal'
  content: string
  anchor?: string
  target?: LinkTarget
}

type LinkValue = UrlLink | EmailLink | InternalLink

const props = defineProps<{
  modelValue?: LinkValue | null
  spaceId: string
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: LinkValue | null]
}>()

// Content tree composable - using the same pattern as ContentTree component
const { useContentMenuQuery, getRootItems, getChildren } = useContentMenu(props.spaceId)
const { data: contentMenu } = useContentMenuQuery()
const rootItems = computed(() => getRootItems(contentMenu.value) || [])

// Internal state
const localValue = ref<LinkValue>({
  type: 'url',
  url: '',
  target: '_self',
})

const showInternalPicker = ref(false)
const showElementsForContent = ref<string | null>(null)

// Sync with prop
watch(
  () => props.modelValue,
  (newValue) => {
    if (newValue) {
      localValue.value = { ...newValue }
    } else {
      localValue.value = { type: 'url', url: '', target: '_self' }
    }
  },
  { immediate: true, deep: true }
)

// Computed properties
const linkTypes = computed(() => [
  { value: 'url', label: 'URL' },
  { value: 'email', label: 'Email' },
  { value: 'internal', label: 'Internal Page' },
])

const targetOptions = computed(() => [
  { value: 'default', label: 'Default' },
  { value: '_self', label: 'Same Window' },
  { value: '_blank', label: 'New Window' },
  { value: '_parent', label: 'Parent Frame' },
  { value: '_top', label: 'Top Frame' },
])

// Get content elements for selected page (mock implementation)
const getContentElements = (contentId: string) => {
  return [
    { id: 'header', name: 'Header Section' },
    { id: 'main-content', name: 'Main Content' },
    { id: 'sidebar', name: 'Sidebar' },
    { id: 'footer', name: 'Footer Section' },
  ]
}

// Event handlers
const updateValue = () => {
  emit('update:modelValue', localValue.value)
}

const handleTypeChange = (newType: LinkType) => {
  switch (newType) {
    case 'url':
      localValue.value = { type: 'url', url: '' }
      break
    case 'email':
      localValue.value = { type: 'email', email: '' }
      break
    case 'internal':
      localValue.value = { type: 'internal', content: '' }
      break
  }
  updateValue()
}

const handleUrlChange = (url: string) => {
  if (localValue.value.type === 'url') {
    localValue.value.url = url
    updateValue()
  }
}

const handleEmailChange = (email: string) => {
  if (localValue.value.type === 'email') {
    localValue.value.email = email
    updateValue()
  }
}

const handleTargetChange = (target: string) => {
  if (localValue.value.type === 'url' || localValue.value.type === 'internal') {
    localValue.value.target = target === 'default' ? undefined : (target as LinkTarget)
    updateValue()
  }
}

const handleRelChange = (rel: string) => {
  if (localValue.value.type === 'url') {
    localValue.value.rel = rel || undefined
    updateValue()
  }
}

const selectContent = (contentId: string) => {
  if (localValue.value.type === 'internal') {
    localValue.value.content = contentId
    localValue.value.anchor = undefined
    updateValue()
  }
  showInternalPicker.value = false
}

const selectContentWithAnchor = (contentId: string, anchorId: string) => {
  if (localValue.value.type === 'internal') {
    localValue.value.content = contentId
    localValue.value.anchor = anchorId
    updateValue()
  }
  showInternalPicker.value = false
  showElementsForContent.value = null
}

const toggleElementsView = (contentId: string) => {
  showElementsForContent.value = showElementsForContent.value === contentId ? null : contentId
}

const getSelectedContentName = () => {
  if (localValue.value.type === 'internal' && localValue.value.content && contentMenu.value) {
    const item = contentMenu.value[localValue.value.content]
    return item?.name || 'Unknown Page'
  }
  return ''
}
</script>

<template>
  <div class="space-y-4 border-l-1 border-l-border pl-3">
    <FormField
      name="link-type"
      label="Link Type"
    >
      <Select
        :model-value="localValue.type"
        :disabled="disabled"
        @update:model-value="handleTypeChange(String($event) as LinkType)"
      >
        <SelectTrigger>
          <SelectValue placeholder="Select link type" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem
            v-for="type in linkTypes"
            :key="type.value"
            :value="type.value"
          >
            {{ type.label }}
          </SelectItem>
        </SelectContent>
      </Select>
    </FormField>
    <template v-if="localValue.type === 'url'">
      <InputField
        name="link-url"
        :model-value="localValue.url"
        label="URL"
        placeholder="https://example.com"
        :disabled="disabled"
        @update:model-value="handleUrlChange(String($event))"
      />
      <FormField
        name="target"
        label="Target"
      >
        <Select
          :model-value="localValue.target || 'default'"
          :disabled="disabled"
          @update:model-value="handleTargetChange(String($event || 'default'))"
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in targetOptions"
              :key="String(option.value)"
              :value="option.value"
            >
              {{ option.label }}
            </SelectItem>
          </SelectContent>
        </Select>
      </FormField>
      <InputField
        name="link-rel"
        :model-value="localValue.rel || ''"
        label="Rel Attribute"
        placeholder="nofollow, noopener, etc."
        :disabled="disabled"
        @update:model-value="handleRelChange(String($event))"
      />
    </template>
    <template v-if="localValue.type === 'email'">
      <InputField
        name="link-email"
        :model-value="localValue.email || ''"
        label="Email Address"
        type="email"
        placeholder="example@domain.com"
        :disabled="disabled"
        @update:model-value="handleEmailChange(String($event))"
      />
    </template>
    <template v-if="localValue.type === 'internal'">
      <FormField
        name="content"
        label="Content"
      >
        <div class="flex gap-2">
          <button
            type="button"
            class="flex min-h-[2.5rem] flex-1 items-center rounded-md border border-input-border bg-input px-3 py-2"
            :disabled="disabled"
            @click="showInternalPicker = true"
          >
            <span
              v-if="localValue.content"
              class="text-input-foreground flex items-center gap-1 truncate text-sm font-semibold"
            >
              {{ getSelectedContentName() }}
              <template v-if="localValue.anchor">
                <span>#{{ localValue.anchor }}</span>
              </template>
            </span>
            <span
              v-else
              class="text-muted-foreground text-sm"
            >
              No content selected
            </span>
            <Icon
              name="lucide:search"
              class="ml-auto"
            />
          </button>
        </div>
      </FormField>
      <FormField
        name="target"
        label="Target"
      >
        <Select
          :model-value="localValue.target || 'default'"
          :disabled="disabled"
          @update:model-value="handleTargetChange(String($event || 'default'))"
        >
          <SelectTrigger>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem
              v-for="option in targetOptions"
              :key="String(option.value)"
              :value="option.value"
            >
              {{ option.label }}
            </SelectItem>
          </SelectContent>
        </Select>
      </FormField>
    </template>
    <ContentPicker
      v-model:open="showInternalPicker"
      :space-id="spaceId"
      :title="$t('labels.link.selectContent')"
      :show-elements="true"
      @content-select="selectContent"
      @content-with-anchor-select="selectContentWithAnchor"
    />
  </div>
</template>
