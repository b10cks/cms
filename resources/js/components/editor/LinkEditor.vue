<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import LinkEntryPicker from '~/components/editor/LinkEntryPicker.vue'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import { ArrayInputField, FormField, InputField, TextField } from '~/components/ui/form'
import { Input } from '~/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'
import { emptyLink, hasLinkOptions, isEmptyLink, type LinkKind } from '~/lib/linkValue'

/**
 * A link value: the kind first (entry, URL, email), then its one field and the new-tab
 * checkbox. Target, rel, query params and the email parts sit behind "More options".
 */
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

const kind = ref<LinkKind>(props.modelValue?.type ?? 'internal')
const localValue = ref<LinkValue>(emptyLink(kind.value))
// What each kind held before switching away, so switching back restores it.
const drafts = new Map<LinkKind, LinkValue>()

watch(
  () => props.modelValue,
  (value) => {
    if (value) {
      localValue.value = { ...value }
      kind.value = value.type
    } else if (!isEmptyLink(localValue.value)) {
      localValue.value = emptyLink(kind.value)
    }
  },
  { immediate: true, deep: true }
)

const kinds = computed(() => [
  { value: 'internal' as const, label: t('labels.link.kinds.internal') },
  { value: 'url' as const, label: t('labels.link.kinds.url') },
  ...(props.allowEmail !== false || kind.value === 'email'
    ? [{ value: 'email' as const, label: t('labels.link.kinds.email') }]
    : []),
])

const targetOptions = computed(() => [
  { value: 'default', label: t('labels.link.targets.default') },
  { value: '_self', label: t('labels.link.targets._self') },
  { value: '_blank', label: t('labels.link.targets._blank') },
  { value: '_parent', label: t('labels.link.targets._parent') },
  { value: '_top', label: t('labels.link.targets._top') },
])

const optionsOpen = ref(false)
watch(
  () => hasLinkOptions(localValue.value),
  (has) => {
    if (has) optionsOpen.value = true
  },
  { immediate: true }
)

const updateValue = () => {
  emit('update:modelValue', { ...localValue.value })
}

const switchKind = (next: LinkKind) => {
  if (next === kind.value) return

  drafts.set(kind.value, localValue.value)
  kind.value = next
  localValue.value = drafts.get(next) ?? emptyLink(next)

  // An empty kind clears the link; nothing to clear when there was none.
  if (!isEmptyLink(localValue.value)) updateValue()
  else if (props.modelValue) emit('update:modelValue', null)
}

const isEmailAddress = (value: string): boolean => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim())

const handleUrlChange = (url: string) => {
  if (localValue.value.type !== 'url') return
  const trimmed = url.trim()
  localValue.value.url = isEmailAddress(trimmed) ? `mailto:${trimmed}` : url
  updateValue()
}

const handleEmailChange = (
  key: keyof Pick<EmailLinkValue, 'email' | 'subject' | 'body' | 'cc' | 'bcc'>,
  value: string
) => {
  if (localValue.value.type !== 'email') return
  localValue.value[key] = value
  updateValue()
}

const target = computed(() =>
  localValue.value.type === 'email' ? undefined : localValue.value.target
)

const setTarget = (next: LinkTarget | undefined) => {
  if (localValue.value.type === 'email') return
  localValue.value.target = next
  updateValue()
}

const handleRelChange = (rel: string) => {
  if (localValue.value.type !== 'url') return
  localValue.value.rel = rel || undefined
  updateValue()
}

const selectEntry = (content: string, anchor?: string) => {
  if (localValue.value.type !== 'internal') return
  localValue.value.content = content
  localValue.value.anchor = anchor
  updateValue()
}

const paramsToRows = (params?: Record<string, string>): Record<string, unknown>[] =>
  Object.entries(params ?? {}).map(([key, value]) => ({ key, value }))

const rowsToParams = (rows: Record<string, unknown>[]): Record<string, string> =>
  Object.fromEntries(rows.map((r) => [String(r.key), String(r.value)]))

const paramsRows = computed({
  get: () => (localValue.value.type === 'email' ? [] : paramsToRows(localValue.value.params)),
  set: (rows: Record<string, unknown>[]) => {
    if (localValue.value.type === 'email') return
    localValue.value.params = rows.length ? rowsToParams(rows) : undefined
    updateValue()
  },
})

const paramColumns = computed(() => [
  {
    key: 'key',
    label: t('labels.link.paramKey'),
    type: 'text' as const,
    placeholder: t('labels.link.paramKeyPlaceholder'),
    required: true,
  },
  {
    key: 'value',
    label: t('labels.link.paramValue'),
    type: 'text' as const,
    placeholder: t('labels.link.paramValuePlaceholder'),
  },
])

// An entry link without an entry has nothing to open yet, so its checkbox row waits.
const showControls = computed(
  () => localValue.value.type !== 'internal' || !!localValue.value.content || optionsOpen.value
)
</script>

<template>
  <div class="grid gap-2">
    <Tabs
      :model-value="kind"
      @update:model-value="switchKind($event as LinkKind)"
    >
      <TabsList class="w-fit">
        <TabsTrigger
          v-for="option in kinds"
          :key="option.value"
          :value="option.value"
          :disabled="disabled"
        >
          {{ option.label }}
        </TabsTrigger>
      </TabsList>
    </Tabs>

    <LinkEntryPicker
      v-if="localValue.type === 'internal'"
      :space-id="spaceId"
      :content="localValue.content || undefined"
      :anchor="localValue.anchor"
      :disabled="disabled"
      @select="selectEntry"
    />
    <Input
      v-else-if="localValue.type === 'url'"
      :model-value="localValue.url"
      :placeholder="t('labels.link.urlPlaceholder')"
      :aria-label="t('labels.link.url')"
      :disabled="disabled"
      @update:model-value="handleUrlChange(String($event))"
    />
    <Input
      v-else
      :model-value="localValue.email || ''"
      type="email"
      :placeholder="t('labels.link.emailPlaceholder')"
      :aria-label="t('labels.link.email')"
      :disabled="disabled"
      @update:model-value="handleEmailChange('email', String($event))"
    />

    <div
      v-if="showControls"
      class="flex min-h-8 items-center gap-4"
    >
      <label
        v-if="localValue.type !== 'email'"
        class="flex cursor-pointer items-center gap-2 text-sm text-foreground"
      >
        <Checkbox
          :model-value="target === '_blank'"
          :disabled="disabled"
          @update:model-value="setTarget($event === true ? '_blank' : undefined)"
        />
        {{ t('labels.link.newTab') }}
      </label>
      <Button
        type="button"
        variant="ghost"
        size="xs"
        class="ml-auto text-muted"
        :aria-expanded="optionsOpen"
        @click="optionsOpen = !optionsOpen"
      >
        {{ t('labels.link.moreOptions') }}
        <Icon
          name="lucide:chevron-down"
          :class="['transition-transform duration-100', optionsOpen && 'rotate-180']"
        />
      </Button>
    </div>

    <div
      v-if="showControls && optionsOpen"
      class="grid gap-4 pt-1"
    >
      <template v-if="localValue.type === 'email'">
        <InputField
          name="link-email-subject"
          :model-value="localValue.subject || ''"
          :label="t('labels.link.subject')"
          :placeholder="t('labels.link.subjectPlaceholder')"
          :disabled="disabled"
          @update:model-value="handleEmailChange('subject', String($event))"
        />
        <TextField
          name="link-email-body"
          :model-value="localValue.body || ''"
          :label="t('labels.link.body')"
          :placeholder="t('labels.link.bodyPlaceholder')"
          :disabled="disabled"
          :rows="4"
          @update:model-value="handleEmailChange('body', String($event))"
        />
        <InputField
          name="link-email-cc"
          :model-value="localValue.cc || ''"
          :label="t('labels.link.cc')"
          :placeholder="t('labels.link.recipientPlaceholder')"
          :disabled="disabled"
          @update:model-value="handleEmailChange('cc', String($event))"
        />
        <InputField
          name="link-email-bcc"
          :model-value="localValue.bcc || ''"
          :label="t('labels.link.bcc')"
          :placeholder="t('labels.link.recipientPlaceholder')"
          :disabled="disabled"
          @update:model-value="handleEmailChange('bcc', String($event))"
        />
      </template>
      <template v-else>
        <FormField
          name="target"
          :label="t('labels.link.target')"
        >
          <Select
            :model-value="target || 'default'"
            :disabled="disabled"
            @update:model-value="
              setTarget($event && $event !== 'default' ? ($event as LinkTarget) : undefined)
            "
          >
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="option in targetOptions"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </SelectItem>
            </SelectContent>
          </Select>
        </FormField>
        <InputField
          v-if="localValue.type === 'url'"
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
          :columns="paramColumns"
        />
      </template>
    </div>
  </div>
</template>
