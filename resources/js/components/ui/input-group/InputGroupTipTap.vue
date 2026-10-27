<script setup lang="ts">
import { Extension, type JSONContent } from '@tiptap/core'
import Placeholder from '@tiptap/extension-placeholder'
import { PluginKey } from '@tiptap/pm/state'
import { StarterKit } from '@tiptap/starter-kit'
import Suggestion, { exitSuggestion } from '@tiptap/suggestion'
import type { SuggestionProps } from '@tiptap/suggestion'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import { useEventListener } from '@vueuse/core'

import { AiMention, type AiMentionItem } from '~/components/editor/extensions/AiMention'

const modelValue = defineModel<string>({ default: '' })

const props = withDefaults(
  defineProps<{
    placeholder?: string
    disabled?: boolean
    mentionItems?: AiMentionItem[]
    commands?: TipTapCommandItem[]
  }>(),
  {
    placeholder: '',
    disabled: false,
    mentionItems: () => [],
    commands: () => [],
  }
)

const emit = defineEmits<{
  (e: 'submit'): void
  (e: 'command', item: TipTapCommandItem): void
}>()

const mentionPluginKey = new PluginKey('aiMention')
const listboxId = useId()
const suggestionPosition = ref({ left: '0px', top: '0px', maxHeight: '256px', transform: '' })

const updateSuggestionPosition = () => {
  const rect = suggestionProps.value?.clientRect?.()
  if (!rect) return
  const above = rect.top >= 272
  const availableHeight = above ? rect.top - 16 : window.innerHeight - rect.bottom - 16
  suggestionPosition.value = {
    left: `${Math.max(8, Math.min(rect.left, window.innerWidth - 232))}px`,
    top: `${above ? rect.top - 8 : rect.bottom + 8}px`,
    maxHeight: `${Math.max(0, Math.min(256, availableHeight))}px`,
    transform: above ? 'translateY(-100%)' : '',
  }
}

useEventListener(window, 'resize', updateSuggestionPosition)
useEventListener(window, 'scroll', updateSuggestionPosition, true)

const plainTextDocument = (text: string): JSONContent => ({
  type: 'doc',
  content: text.split('\n').map((line) => ({
    type: 'paragraph',
    content: line ? [{ type: 'text', text: line }] : [],
  })),
})

/** A `/` command offered at the start of the input, a skill in the AI composer. */
export interface TipTapCommandItem {
  id: string
  label: string
  description?: string
}

interface SuggestionOption {
  key: string
  group: string
  label: string
  description?: string
  value: AiMentionItem | TipTapCommandItem
}

type ActiveSuggestion =
  | { kind: 'mention'; props: SuggestionProps<AiMentionItem> }
  | { kind: 'command'; props: SuggestionProps<TipTapCommandItem> }

const active = shallowRef<ActiveSuggestion | null>(null)
const suggestionProps = computed(() => active.value?.props ?? null)
const suggestionOpen = computed(() => active.value !== null)

const MENTION_GROUPS = {
  content: 'components.aiMention.content',
  'draft-content': 'components.aiMention.draftContent',
  block: 'components.aiMention.blocks',
  asset: 'components.aiMention.assets',
  document: 'components.aiMention.documents',
} as const satisfies Record<AiMentionItem['type'], string>

/** Options in display order, so the keyboard index matches what is shown. */
const filteredItems = computed<SuggestionOption[]>(() => {
  const current = active.value
  if (!current) return []
  const search = current.props.query.toLowerCase()

  if (current.kind === 'command') {
    return props.commands
      .filter((item) => item.label.toLowerCase().includes(search) || item.id.toLowerCase().includes(search))
      .map((item) => ({ key: `command-${item.id}`, group: 'command', label: item.label, description: item.description, value: item }))
  }

  const matches = props.mentionItems
    .filter((item) => item.label.toLowerCase().includes(search) || item.id.toLowerCase().includes(search))
    .slice(0, 20)
  return (Object.keys(MENTION_GROUPS) as AiMentionItem['type'][]).flatMap((type) =>
    matches
      .filter((item) => item.type === type)
      .map((item) => ({ key: `${type}-${item.id}`, group: type, label: item.label, value: item }))
  )
})

const groups = computed(() => {
  const result: Array<{ key: string; label: string | null; options: Array<SuggestionOption & { index: number }> }> = []
  filteredItems.value.forEach((option, index) => {
    const last = result.at(-1)
    if (last?.key === option.group) {
      last.options.push({ ...option, index })
      return
    }
    const label = option.group in MENTION_GROUPS ? MENTION_GROUPS[option.group as AiMentionItem['type']] : null
    result.push({ key: option.group, label, options: [{ ...option, index }] })
  })
  return result
})

const selectedIndex = ref(0)

watch(filteredItems, () => {
  selectedIndex.value = 0
})

const selectItem = (index: number) => {
  const option = filteredItems.value[index]
  const current = active.value
  if (!option || !current) return
  if (current.kind === 'command') current.props.command(option.value as TipTapCommandItem)
  else current.props.command(option.value as AiMentionItem)
}

const upHandler = () => {
  if (!filteredItems.value.length) return
  selectedIndex.value =
    (selectedIndex.value + filteredItems.value.length - 1) % filteredItems.value.length
}

const downHandler = () => {
  if (!filteredItems.value.length) return
  selectedIndex.value = (selectedIndex.value + 1) % filteredItems.value.length
}

const commandPluginKey = new PluginKey('aiCommand')

/** Shared keyboard and lifecycle handling for both suggestion kinds. */
const suggestionRenderer = <T,>(kind: ActiveSuggestion['kind'], pluginKey: PluginKey) => () => ({
  onStart: (p: SuggestionProps<T>) => {
    active.value = { kind, props: p } as ActiveSuggestion
    updateSuggestionPosition()
  },
  onUpdate: (p: SuggestionProps<T>) => {
    active.value = { kind, props: p } as ActiveSuggestion
    updateSuggestionPosition()
  },
  onKeyDown: ({ event }: { event: KeyboardEvent }) => {
    if (!suggestionOpen.value || event.isComposing || event.keyCode === 229) return false

    if (event.key === 'ArrowUp') {
      upHandler()
      return true
    }
    if (event.key === 'ArrowDown') {
      downHandler()
      return true
    }
    if (event.key === 'Enter' && filteredItems.value.length) {
      selectItem(selectedIndex.value)
      return true
    }
    if (event.key === 'Escape') {
      exitSuggestion(editor.value!.view, pluginKey)
      return true
    }
    return false
  },
  onExit: () => {
    active.value = null
  },
})

/** `/` at the start of the input picks a command; the typed query is removed. */
const CommandSuggestion = Extension.create({
  name: 'aiCommand',
  addProseMirrorPlugins() {
    return [
      Suggestion<TipTapCommandItem>({
        editor: this.editor,
        char: '/',
        startOfLine: true,
        pluginKey: commandPluginKey,
        allow: () => props.commands.length > 0,
        items: () => [],
        command: ({ editor: current, range, props: item }) => {
          current.chain().focus().deleteRange(range).run()
          emit('command', item)
        },
        render: suggestionRenderer<TipTapCommandItem>('command', commandPluginKey),
      }),
    ]
  },
})

const editor = useEditor({
  content: plainTextDocument(modelValue.value),
  editable: !props.disabled,
  extensions: [
    StarterKit.configure(),
    Placeholder.configure({
      placeholder: () => props.placeholder,
      showOnlyWhenEditable: true,
      showOnlyCurrent: true,
      emptyNodeClass: 'is-editor-empty',
      emptyEditorClass: 'is-editor-empty',
    }),
    AiMention.configure({
      suggestion: {
        char: '@',
        pluginKey: mentionPluginKey,
        items: () => [],
        render: suggestionRenderer<AiMentionItem>('mention', mentionPluginKey),
      },
    }),
    CommandSuggestion,
  ],
  onUpdate: ({ editor: currentEditor }) => {
    modelValue.value = currentEditor.getText()
  },
  editorProps: {
    attributes: {
      class: 'tiptap prose max-w-none focus:outline-none',
      'aria-label': props.placeholder || useI18n().t('components.aiText.inputLabel'),
      'aria-placeholder': props.placeholder,
      role: 'textbox',
      'aria-multiline': 'true',
    },
    handleKeyDown: (view, event) => {
      if (event.isComposing || view.composing || event.keyCode === 229 || props.disabled)
        return false
      if (event.key === 'Enter' && !event.shiftKey && !suggestionOpen.value) {
        emit('submit')
        return true
      }
      return false
    },
  },
})

watch(
  () => modelValue.value,
  (newValue) => {
    if (editor.value && editor.value.getText() !== newValue) {
      editor.value.commands.setContent(plainTextDocument(newValue), { emitUpdate: false })
    }
  }
)

watch(
  () => props.placeholder,
  (newPlaceholder) => {
    if (editor.value) {
      editor.value.setOptions({
        editorProps: {
          attributes: {
            ...editor.value.options.editorProps.attributes,
            'aria-label': newPlaceholder || useI18n().t('components.aiText.inputLabel'),
            'aria-placeholder': newPlaceholder,
          },
        },
      })
      const placeholderExt = editor.value.extensionManager.extensions.find(
        (ext) => ext.name === 'placeholder'
      )
      if (placeholderExt) {
        placeholderExt.options.placeholder = newPlaceholder
        editor.value.view.dispatch(editor.value.state.tr)
      }
    }
  }
)

watch(
  () => props.disabled,
  (disabled) => {
    if (editor.value) {
      editor.value.setEditable(!disabled)
      if (disabled) {
        exitSuggestion(editor.value.view, mentionPluginKey)
        exitSuggestion(editor.value.view, commandPluginKey)
      }
    }
  },
  { immediate: true }
)

watch([suggestionOpen, selectedIndex, filteredItems], () => {
  if (!editor.value) return
  const open = suggestionOpen.value && filteredItems.value.length > 0
  editor.value.setOptions({
    editorProps: {
      attributes: {
        ...editor.value.options.editorProps.attributes,
        'aria-expanded': String(open),
        'aria-controls': open ? listboxId : '',
        'aria-activedescendant': open ? `${listboxId}-${selectedIndex.value}` : '',
      },
    },
  })
})

const clear = () => {
  editor.value?.commands.clearContent()
}

const getText = (): string => {
  return editor.value?.getText() ?? ''
}

const getTextWithMentions = (): string => {
  return (
    editor.value?.getText({
      textSerializers: {
        aiMention: ({ node }) => `@${node.attrs.label}`,
      },
    }) ?? ''
  )
}

const getMentions = (): AiMentionItem[] => {
  const mentions: AiMentionItem[] = []
  const doc = editor.value?.state.doc
  if (!doc) return mentions

  doc.descendants((node) => {
    if (node.type.name === 'aiMention') {
      mentions.push({
        id: node.attrs.id,
        label: node.attrs.label,
        type: node.attrs.mentionType,
        icon: node.attrs.icon,
      })
    }
  })

  return mentions
}

const focus = () => {
  editor.value?.commands.focus()
}

defineExpose({
  clear,
  getText,
  getTextWithMentions,
  getMentions,
  focus,
})
</script>

<template>
  <EditorContent
    data-slot="input-group-control"
    :editor="editor"
    class="tiptap-editor w-full rounded-md p-3 max-h-64 overflow-auto [&_.tiptap]:min-h-10 [&_.tiptap]:outline-none"
  />

  <Teleport to="body">
    <div
      v-if="suggestionOpen && filteredItems.length > 0 && suggestionProps"
      :style="{
        position: 'fixed',
        ...suggestionPosition,
      }"
      class="z-[100] max-h-64 w-64 overflow-auto rounded-md border border-popover-border bg-popover p-1 shadow-md"
      :id="listboxId"
      role="listbox"
      :aria-label="$t('components.aiMention.suggestions')"
    >
      <div
        v-for="group in groups"
        :key="group.key"
        class="mb-1 last:mb-0"
      >
        <div
          v-if="group.label"
          class="px-2 py-1 text-sm text-muted"
        >
          {{ $t(group.label) }}
        </div>
        <button
          v-for="option in group.options"
          :key="option.key"
          :id="`${listboxId}-${option.index}`"
          type="button"
          role="option"
          :aria-selected="selectedIndex === option.index"
          :class="[
            'flex w-full flex-col items-start rounded-sm px-2 py-1.5 text-left text-sm',
            selectedIndex === option.index ? 'bg-accent text-accent-foreground' : 'hover:bg-accent/50',
          ]"
          @mousedown.prevent
          @click="selectItem(option.index)"
          @mouseenter="selectedIndex = option.index"
        >
          <span class="w-full truncate">{{ option.label }}</span>
          <span
            v-if="option.description"
            class="line-clamp-2 text-sm text-muted"
          >
            {{ option.description }}
          </span>
        </button>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
:deep(.tiptap) {
  min-height: 2.5rem;
}

:deep(.tiptap .is-editor-empty:first-child::before) {
  content: attr(data-placeholder);
  display: block;
  width: 100%;
  float: left;
  color: var(--muted);
  pointer-events: none;
  height: 0;
}

:deep(.tiptap:focus) {
  outline: none;
}
</style>
