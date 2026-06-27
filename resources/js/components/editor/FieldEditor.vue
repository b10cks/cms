<script setup lang="ts">
import type { Component } from 'vue'

import AssetBlock from '~/components/editor/AssetBlock.vue'
import BlocksBlock from '~/components/editor/BlocksBlock.vue'
import IconBlock from '~/components/editor/IconBlock.vue'
import BooleanBlock from '~/components/editor/BooleanBlock.vue'
import DateBlock from '~/components/editor/DateBlockEditor.vue'
import LinkBlock from '~/components/editor/LinkBlock.vue'
import MarkdownBlock from '~/components/editor/MarkdownBlock.vue'
import MetaBlock from '~/components/editor/MetaBlock.vue'
import MultiAssetsBlock from '~/components/editor/MultiAssetsBlock.vue'
import NumberBlock from '~/components/editor/NumberBlock.vue'
import OptionBlock from '~/components/editor/OptionBlock.vue'
import OptionsBlock from '~/components/editor/OptionsBlock.vue'
import ReferenceBlock from '~/components/editor/ReferenceBlock.vue'
import RichTextBlock from '~/components/editor/RichTextBlock.vue'
import TableBlock from '~/components/editor/TableBlock.vue'
import TextareaBlock from '~/components/editor/TextareaBlock.vue'
import TextBlock from '~/components/editor/TextBlock.vue'
import { AvatarList } from '~/components/ui/avatar'
import type {
  CollaborationPresenceUser,
  ContentFieldFocusPayload,
  ContentFieldUpdatePayload,
} from '~/composables/useContentLiveCollaboration'

import FieldComments from '../comments/FieldComments.vue'

const editors = {
  text: TextBlock,
  textarea: TextareaBlock,
  markdown: MarkdownBlock,
  richtext: RichTextBlock,
  option: OptionBlock,
  options: OptionsBlock,
  link: LinkBlock,
  boolean: BooleanBlock,
  blocks: BlocksBlock,
  number: NumberBlock,
  asset: AssetBlock,
  multiAsset: MultiAssetsBlock,
  multi_assets: MultiAssetsBlock,
  icon: IconBlock,
  reference: ReferenceBlock,
  references: ReferenceBlock,
  meta: MetaBlock,
  date: DateBlock,
  table: TableBlock,
} satisfies Partial<Record<CanonicalSchemaTypeName | LegacySchemaTypeName, Component>>

const props = defineProps<{
  item: SchemaType & { key: string }
  itemId: string
  modelValue: Record<string, unknown>
  spaceId: string
  pathSegments?: Array<string | number>
  activeCollaborators?: CollaborationPresenceUser[]
  readOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
  createTemplate: [blockId: string, content: Record<string, unknown>]
  fieldUpdate: [payload: ContentFieldUpdatePayload]
  fieldFocus: [payload: ContentFieldFocusPayload]
}>()

const { t } = useI18n()

const markFieldDirty = inject<((path: string) => void) | undefined>('markFieldDirty', undefined)
const getFieldError = inject<((path: string) => string | null) | undefined>(
  'getFieldError',
  undefined
)
const shouldShowFieldError = inject<((path: string) => boolean) | undefined>(
  'shouldShowFieldError',
  undefined
)
const getVisibleValidationEntries = inject<
  ((prefix?: string) => Array<{ path: string; messages: string[] }>) | undefined
>('getVisibleValidationEntries', undefined)
const fieldPath = computed(() => `content.${(props.pathSegments || []).map(String).join('.')}`)
const fieldError = computed(() => getFieldError?.(fieldPath.value) || null)
const showFieldError = computed(() => shouldShowFieldError?.(fieldPath.value) || false)
const nestedValidationPrefix = computed(() => `${fieldPath.value}.`)
const shouldBlockPointerEvents = computed(() => props.readOnly && props.item.type !== 'blocks')

const nestedValidationEntries = computed(() => {
  if (props.item.type !== 'blocks') return []

  return getVisibleValidationEntries?.(nestedValidationPrefix.value) || []
})
const hasNestedFieldErrors = computed(() => nestedValidationEntries.value.length > 0)
const showValidationState = computed(() => showFieldError.value || hasNestedFieldErrors.value)

// const updatePreviewItem = inject<(data: never) => void>('updatePreviewItem')

const fieldValue = computed({
  get() {
    return props.modelValue[props.item.key]
  },
  set(newValue) {
    const previousValue = props.modelValue[props.item.key]
    if (newValue === previousValue) return

    const updatedModel = { ...props.modelValue }

    if (Array.isArray(newValue)) {
      updatedModel[props.item.key] = [...newValue]
    } else {
      updatedModel[props.item.key] = newValue
    }

    // Emit the update with the new object
    emit('update:modelValue', updatedModel)
    markFieldDirty?.(fieldPath.value)
    emit('fieldUpdate', {
      debounceMs: fieldDebounceMs.value,
      itemId: props.itemId,
      field: props.item.key,
      previousValue,
      value: newValue,
    })
  },
})

const handleCreateTemplate = (blockId: string, content: Record<string, unknown>): void => {
  emit('createTemplate', blockId, content)
}

const handleNestedFieldUpdate = (payload: ContentFieldUpdatePayload): void => {
  emit('fieldUpdate', payload)
}

const handleNestedFieldFocus = (payload: ContentFieldFocusPayload): void => {
  emit('fieldFocus', payload)
}

const isFocused = ref(false)
const tracksOwnFocus = computed(() => props.item.type !== 'blocks')
const fieldDebounceMs = computed(() => {
  switch (props.item.type) {
    case 'richtext':
      return 500
    case 'markdown':
    case 'textarea':
      return 250
    default:
      return 150
  }
})

const collaborationColor = computed(() => props.activeCollaborators?.[0]?.color || null)
const showContainerHighlight = computed(
  () => tracksOwnFocus.value && !!props.activeCollaborators?.length
)

const collaborationStyle = computed(() => {
  if (!collaborationColor.value) return undefined

  return {
    '--collaboration-color': collaborationColor.value,
    '--collaboration-color-soft': `${collaborationColor.value}20`,
  }
})

const handleFocusIn = () => {
  if (!tracksOwnFocus.value) return
  if (isFocused.value) return

  isFocused.value = true
  emit('fieldFocus', {
    itemId: props.itemId,
    field: props.item.key,
    focused: true,
  })
}

const handleFocusOut = (event: FocusEvent) => {
  if (!tracksOwnFocus.value) return

  const nextTarget = event.relatedTarget as Node | null
  const currentTarget = event.currentTarget as HTMLElement | null

  if (nextTarget && currentTarget?.contains(nextTarget)) {
    return
  }

  if (!isFocused.value) return

  isFocused.value = false
  emit('fieldFocus', {
    itemId: props.itemId,
    field: props.item.key,
    focused: false,
  })
}

onBeforeUnmount(() => {
  if (!tracksOwnFocus.value) return
  if (!isFocused.value) return

  emit('fieldFocus', {
    itemId: props.itemId,
    field: props.item.key,
    focused: false,
  })
})
</script>

<template>
  <div
    :data-field-path="fieldPath"
    :data-validation-visible="showFieldError ? 'true' : undefined"
    :class="[
      'relative group/field flex-1 min-w-0',
      showContainerHighlight ? 'collaboration-field-active' : '',
    ]"
    :style="collaborationStyle"
    @focusin="handleFocusIn"
    @focusout="handleFocusOut"
  >
    <div
      v-if="activeCollaborators?.length"
      class="absolute top-3 right-3 z-20"
    >
      <AvatarList
        :users="activeCollaborators"
        :max="3"
        size="sm"
        tooltip-side="left"
        class="rounded-full bg-background/90 px-1 py-0.5 shadow-sm backdrop-blur"
      />
    </div>
    <FieldComments
      :item-id="itemId"
      :field="item.key"
    />
    <component
      :is="editors[item.type]"
      v-if="item.type in editors"
      v-model="fieldValue"
      :item="item as any"
      :path-prefix="pathSegments"
      :space-id="spaceId"
      :read-only="props.readOnly"
      :class="shouldBlockPointerEvents ? 'pointer-events-none opacity-70' : undefined"
      @create-template="handleCreateTemplate"
      @field-update="handleNestedFieldUpdate"
      @field-focus="handleNestedFieldFocus"
    />
    <div v-else>Unknown editor type: {{ item.type }}</div>
    <div
      v-if="showValidationState"
      class="mt-2 space-y-1"
    >
      <div
        v-if="showFieldError && fieldError"
        class="rounded-md border border-destructive/20 bg-destructive/5 px-3 py-2 text-sm text-destructive"
      >
        {{ fieldError }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.collaboration-field-active :deep(input),
.collaboration-field-active :deep(textarea),
.collaboration-field-active :deep(select),
.collaboration-field-active :deep(button[role='combobox']),
.collaboration-field-active :deep(.border-input),
.collaboration-field-active :deep(.border-input-border),
.collaboration-field-active :deep(.ProseMirror) {
  outline: 2px solid var(--collaboration-color);
}
</style>
