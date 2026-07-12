<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import ContentPicker from '~/components/editor/ContentPicker.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { FormField, InputField } from '~/components/ui/form'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'

import type { LinkApplyPayload, LinkInitial, LinkKind } from './linkTypes'

const props = withDefaults(
  defineProps<{
    open: boolean
    spaceId?: string
    allowUrl?: boolean
    allowInternal?: boolean
    hasSelection?: boolean
    initial?: LinkInitial | null
  }>(),
  {
    spaceId: '',
    allowUrl: true,
    allowInternal: false,
    hasSelection: false,
    initial: null,
  }
)

const emit = defineEmits<{
  'update:open': [value: boolean]
  apply: [payload: LinkApplyPayload]
  remove: []
}>()

const { t } = useI18n()

const { useContentMenuQuery } = useContentMenu(props.spaceId)
const { data: contentMenu } = useContentMenuQuery()

const kind = ref<LinkKind>('url')
const url = ref('')
const content = ref('')
const anchor = ref<string | undefined>(undefined)
const target = ref('default')
const rel = ref('')
const text = ref('')
const showPicker = ref(false)

const editing = computed(() => props.initial != null)
// A brand-new link over a collapsed cursor needs its own visible text.
const showTextField = computed(() => !editing.value && !props.hasSelection)

const targetOptions = computed(() => [
  { value: 'default', label: t('labels.link.targets.default') },
  { value: '_self', label: t('labels.link.targets._self') },
  { value: '_blank', label: t('labels.link.targets._blank') },
  { value: '_parent', label: t('labels.link.targets._parent') },
  { value: '_top', label: t('labels.link.targets._top') },
])

const selectedContentName = computed(() => {
  if (!content.value || !contentMenu.value) return ''
  return contentMenu.value[content.value]?.name || t('labels.references.unknownContent')
})

const canSubmit = computed(() =>
  kind.value === 'url' ? url.value.trim().length > 0 : content.value.length > 0
)

// (Re)seed the form whenever the dialog opens.
watch(
  () => props.open,
  (open) => {
    if (!open) return
    const initial = props.initial
    if (initial) {
      kind.value = initial.kind
      url.value = initial.url || ''
      content.value = initial.content || ''
      anchor.value = initial.anchor
      target.value = initial.target || 'default'
      rel.value = initial.rel || ''
    } else {
      kind.value = props.allowUrl ? 'url' : 'internal'
      url.value = ''
      content.value = ''
      anchor.value = undefined
      target.value = 'default'
      rel.value = ''
    }
    text.value = ''
    showPicker.value = false
  },
  { immediate: true }
)

const isEmailAddress = (value: string): boolean => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim())

const onContentSelect = (contentId: string) => {
  content.value = contentId
  anchor.value = undefined
  showPicker.value = false
}

const onContentWithAnchorSelect = (contentId: string, anchorId: string) => {
  content.value = contentId
  anchor.value = anchorId
  showPicker.value = false
}

const submit = () => {
  if (!canSubmit.value) return

  const normalizedTarget = target.value === 'default' ? null : target.value
  const normalizedRel = rel.value.trim() || null

  if (kind.value === 'url') {
    const trimmed = url.value.trim()
    const href = isEmailAddress(trimmed) ? `mailto:${trimmed}` : trimmed
    emit('apply', {
      kind: 'url',
      url: href,
      target: normalizedTarget,
      rel: normalizedRel,
      text: showTextField.value ? text.value.trim() || href : undefined,
    })
  } else {
    emit('apply', {
      kind: 'internal',
      content: content.value,
      anchor: anchor.value,
      target: normalizedTarget,
      rel: normalizedRel,
      text: showTextField.value ? text.value.trim() || selectedContentName.value || undefined : undefined,
    })
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="emit('update:open', $event)"
  >
    <DialogContent class="sm:max-w-md">
      <DialogHeader>
        <DialogTitle>
          {{ editing ? t('labels.tiptap.link.edit') : t('labels.tiptap.link.insert') }}
        </DialogTitle>
      </DialogHeader>

      <div class="space-y-4">
        <div
          v-if="allowUrl && allowInternal"
          class="grid grid-cols-2 gap-1 rounded-md bg-muted p-1"
        >
          <Button
            type="button"
            size="sm"
            :variant="kind === 'url' ? 'default' : 'ghost'"
            @click="kind = 'url'"
          >
            <Icon name="lucide:link" />
            {{ t('labels.link.types.url') }}
          </Button>
          <Button
            type="button"
            size="sm"
            :variant="kind === 'internal' ? 'default' : 'ghost'"
            @click="kind = 'internal'"
          >
            <Icon name="lucide:file" />
            {{ t('labels.link.types.internal') }}
          </Button>
        </div>

        <template v-if="kind === 'url'">
          <InputField
            v-model="url"
            name="link-url"
            :label="t('labels.link.url')"
            :placeholder="t('labels.link.urlPlaceholder')"
            @keydown.enter.prevent="submit"
          />
        </template>

        <template v-else>
          <FormField
            name="link-content"
            :label="t('labels.link.content')"
          >
            <button
              type="button"
              class="text-input-foreground flex min-h-[2.5rem] w-full items-center gap-2 rounded-md border border-input-border bg-input px-3 py-2 text-sm"
              @click="showPicker = true"
            >
              <span
                v-if="content"
                class="flex items-center gap-1 truncate font-semibold"
              >
                {{ selectedContentName }}
                <span
                  v-if="anchor"
                  class="text-muted-foreground"
                  >#{{ anchor }}</span
                >
              </span>
              <span
                v-else
                class="text-muted-foreground"
              >
                {{ t('labels.link.noContentSelected') }}
              </span>
              <Icon
                name="lucide:search"
                class="ml-auto"
              />
            </button>
          </FormField>
        </template>

        <InputField
          v-if="showTextField"
          v-model="text"
          name="link-text"
          :label="t('labels.tiptap.link.text')"
          :placeholder="t('labels.tiptap.link.textPlaceholder')"
        />

        <FormField
          name="link-target"
          :label="t('labels.link.target')"
        >
          <Select v-model="target">
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
          v-model="rel"
          name="link-rel"
          :label="t('labels.link.rel')"
          :placeholder="t('labels.link.relPlaceholder')"
        />
      </div>

      <DialogFooter class="gap-2 sm:justify-between">
        <Button
          v-if="editing"
          type="button"
          variant="ghost"
          class="text-destructive hover:text-destructive"
          @click="emit('remove')"
        >
          <Icon name="lucide:unlink" />
          {{ t('labels.tiptap.link.remove') }}
        </Button>
        <span v-else />
        <div class="flex gap-2">
          <Button
            type="button"
            variant="outline"
            @click="emit('update:open', false)"
          >
            {{ t('actions.cancel') }}
          </Button>
          <Button
            type="button"
            :disabled="!canSubmit"
            @click="submit"
          >
            {{ t('labels.tiptap.link.apply') }}
          </Button>
        </div>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <ContentPicker
    v-if="spaceId"
    :open="showPicker"
    :space-id="spaceId"
    :show-elements="true"
    :title="t('labels.link.selectContent')"
    @update:open="showPicker = $event"
    @content-select="onContentSelect"
    @content-with-anchor-select="onContentWithAnchorSelect"
  />
</template>
