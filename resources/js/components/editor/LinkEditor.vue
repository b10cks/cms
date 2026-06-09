<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import ContentPicker from '~/components/editor/ContentPicker.vue'
import { ArrayInputField, FormField, InputField, TextField } from '~/components/ui/form'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'

type LinkType = 'url' | 'email' | 'internal'

const props = defineProps<{
  modelValue?: LinkValue | null
  spaceId: string
  disabled?: boolean
  allowEmail?: boolean
  allowQueryParams?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: LinkValue | null]
}>()

const { t } = useI18n()

const { useContentMenuQuery } = useContentMenu(props.spaceId)
const { data: contentMenu } = useContentMenuQuery()

const createDefaultUrlLink = (): UrlLinkValue => ({
  type: 'url',
  url: '',
  target: '_self',
})

// Internal state
const localValue = ref<LinkValue>({
  ...createDefaultUrlLink(),
})

const showInternalPicker = ref(false)

// Sync with prop
watch(
  () => props.modelValue,
  (newValue) => {
    if (newValue) {
      localValue.value = { ...newValue }
    } else {
      localValue.value = createDefaultUrlLink()
    }
  },
  { immediate: true, deep: true }
)

// Computed properties
const linkTypes = computed(() => [
  { value: 'url', label: t('labels.link.types.url') },
  ...(props.allowEmail !== false ? [{ value: 'email', label: t('labels.link.types.email') }] : []),
  { value: 'internal', label: t('labels.link.types.internal') },
])

const targetOptions = computed(() => [
  { value: 'default', label: t('labels.link.targets.default') },
  { value: '_self', label: t('labels.link.targets._self') },
  { value: '_blank', label: t('labels.link.targets._blank') },
  { value: '_parent', label: t('labels.link.targets._parent') },
  { value: '_top', label: t('labels.link.targets._top') },
])

// Event handlers
const updateValue = () => {
  emit('update:modelValue', { ...localValue.value })
}

const handleTypeChange = (newType: LinkType) => {
  switch (newType) {
    case 'url':
      localValue.value = createDefaultUrlLink()
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

const isEmailAddress = (value: string): boolean => {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim())
}

const handleUrlChange = (url: string) => {
  if (localValue.value.type === 'url') {
    const trimmed = url.trim()
    localValue.value.url = isEmailAddress(trimmed) ? `mailto:${trimmed}` : url
    updateValue()
  }
}

const handleEmailChange = (email: string) => {
  if (localValue.value.type === 'email') {
    localValue.value.email = email
    updateValue()
  }
}

const handleEmailFieldChange = (
  key: keyof Pick<EmailLinkValue, 'subject' | 'body' | 'cc' | 'bcc'>,
  value: string
) => {
  if (localValue.value.type === 'email') {
    localValue.value[key] = value
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

const paramsToRows = (params?: Record<string, string>): Record<string, unknown>[] =>
  Object.entries(params ?? {}).map(([key, value]) => ({ key, value }))

const rowsToParams = (rows: Record<string, unknown>[]): Record<string, string> =>
  Object.fromEntries(rows.map((r) => [String(r.key), String(r.value)]))

const paramsRows = computed({
  get: () => {
    if (localValue.value.type === 'internal' || localValue.value.type === 'url') {
      return paramsToRows(localValue.value.params)
    }
    return []
  },
  set: (rows: Record<string, unknown>[]) => {
    if (localValue.value.type === 'internal' || localValue.value.type === 'url') {
      localValue.value.params = rows.length ? rowsToParams(rows) : undefined
      updateValue()
    }
  },
})

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
}

const getSelectedContentName = () => {
  if (localValue.value.type === 'internal' && localValue.value.content && contentMenu.value) {
    const item = contentMenu.value[localValue.value.content]
    return item?.name || t('labels.references.unknownContent')
  }
  return ''
}
</script>

<template>
  <div class="space-y-4 border-l-1 border-l-border pl-3">
    <FormField
      name="link-type"
      :label="t('labels.link.type')"
    >
      <Select
        :model-value="localValue.type"
        :disabled="disabled"
        @update:model-value="handleTypeChange(String($event) as LinkType)"
      >
        <SelectTrigger>
          <SelectValue :placeholder="t('labels.link.typePlaceholder')" />
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
        :label="t('labels.link.url')"
        :placeholder="t('labels.link.urlPlaceholder')"
        :disabled="disabled"
        @update:model-value="handleUrlChange(String($event))"
      />
      <FormField
        name="target"
        :label="t('labels.link.target')"
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
        :label="t('labels.link.rel')"
        :placeholder="t('labels.link.relPlaceholder')"
        :disabled="disabled"
        @update:model-value="handleRelChange(String($event))"
      />
      <ArrayInputField
        v-if="allowQueryParams"
        v-model="paramsRows"
        name="link-params"
        :label="t('labels.link.params')"
        :add-button-text="null"
        :disabled="disabled"
        :columns="[
          {
            key: 'key',
            label: t('labels.link.paramKey'),
            type: 'text',
            placeholder: t('labels.link.paramKeyPlaceholder'),
            required: true,
          },
          {
            key: 'value',
            label: t('labels.link.paramValue'),
            type: 'text',
            placeholder: t('labels.link.paramValuePlaceholder'),
          },
        ]"
      />
    </template>
    <template v-if="localValue.type === 'email'">
      <InputField
        name="link-email"
        :model-value="localValue.email || ''"
        :label="t('labels.link.email')"
        type="email"
        :placeholder="t('labels.link.emailPlaceholder')"
        :disabled="disabled"
        @update:model-value="handleEmailChange(String($event))"
      />
      <InputField
        name="link-email-subject"
        :model-value="localValue.subject || ''"
        :label="t('labels.link.subject')"
        :placeholder="t('labels.link.subjectPlaceholder')"
        :disabled="disabled"
        @update:model-value="handleEmailFieldChange('subject', String($event))"
      />
      <TextField
        name="link-email-body"
        :model-value="localValue.body || ''"
        :label="t('labels.link.body')"
        :placeholder="t('labels.link.bodyPlaceholder')"
        :disabled="disabled"
        :rows="4"
        @update:model-value="handleEmailFieldChange('body', String($event))"
      />
      <InputField
        name="link-email-cc"
        :model-value="localValue.cc || ''"
        :label="t('labels.link.cc')"
        :placeholder="t('labels.link.recipientPlaceholder')"
        :disabled="disabled"
        @update:model-value="handleEmailFieldChange('cc', String($event))"
      />
      <InputField
        name="link-email-bcc"
        :model-value="localValue.bcc || ''"
        :label="t('labels.link.bcc')"
        :placeholder="t('labels.link.recipientPlaceholder')"
        :disabled="disabled"
        @update:model-value="handleEmailFieldChange('bcc', String($event))"
      />
    </template>
    <template v-if="localValue.type === 'internal'">
      <FormField
        name="content"
        :label="t('labels.link.content')"
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
              {{ t('labels.link.noContentSelected') }}
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
        :label="t('labels.link.target')"
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
      <ArrayInputField
        v-if="allowQueryParams"
        v-model="paramsRows"
        name="link-params"
        :label="t('labels.link.params')"
        :add-button-text="null"
        :disabled="disabled"
        :columns="[
          {
            key: 'key',
            label: t('labels.link.paramKey'),
            type: 'text',
            placeholder: t('labels.link.paramKeyPlaceholder'),
            required: true,
          },
          {
            key: 'value',
            label: t('labels.link.paramValue'),
            type: 'text',
            placeholder: t('labels.link.paramValuePlaceholder'),
          },
        ]"
      />
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
