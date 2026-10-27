<script setup lang="ts">
import type { JSONContent } from '@tiptap/core'
import Placeholder from '@tiptap/extension-placeholder'
import { PluginKey } from '@tiptap/pm/state'
import { StarterKit } from '@tiptap/starter-kit'
import { exitSuggestion } from '@tiptap/suggestion'
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
  }>(),
  {
    placeholder: '',
    disabled: false,
    mentionItems: () => [],
  }
)

const emit = defineEmits<{
  (e: 'submit'): void
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

const suggestionProps = ref<SuggestionProps<AiMentionItem> | null>(null)
const suggestionOpen = ref(false)

const filteredItems = computed(() => {
  if (!suggestionProps.value) return []
  const search = suggestionProps.value.query.toLowerCase()
  return props.mentionItems
    .filter(
      (item) => item.label.toLowerCase().includes(search) || item.id.toLowerCase().includes(search)
    )
    .slice(0, 20)
})

const contentItems = computed(() => filteredItems.value.filter((i) => i.type === 'content'))
const draftContentItems = computed(() =>
  filteredItems.value.filter((i) => i.type === 'draft-content')
)
const blockItems = computed(() => filteredItems.value.filter((i) => i.type === 'block'))

const selectedIndex = ref(0)

watch(filteredItems, () => {
  selectedIndex.value = 0
})

const selectItem = (index: number) => {
  const item = filteredItems.value[index]
  if (item && suggestionProps.value) {
    suggestionProps.value.command(item)
  }
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

const enterHandler = () => {
  selectItem(selectedIndex.value)
}

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
        render: () => {
          return {
            onStart: (p: SuggestionProps<AiMentionItem>) => {
              suggestionProps.value = p
              suggestionOpen.value = true
              updateSuggestionPosition()
            },
            onUpdate: (p: SuggestionProps<AiMentionItem>) => {
              suggestionProps.value = p
              updateSuggestionPosition()
            },
            onKeyDown: ({ event }) => {
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
                enterHandler()
                return true
              }
              if (event.key === 'Escape') {
                exitSuggestion(editor.value!.view, mentionPluginKey)
                return true
              }
              return false
            },
            onExit: () => {
              suggestionOpen.value = false
              suggestionProps.value = null
            },
          }
        },
      },
    }),
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
      if (disabled) exitSuggestion(editor.value.view, mentionPluginKey)
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

const getGlobalIndex = (item: AiMentionItem): number => {
  return filteredItems.value.findIndex((i) => i.id === item.id && i.type === item.type)
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
      class="z-[100] max-h-64 w-56 overflow-auto rounded-md border border-popover-border bg-popover p-1 shadow-md"
      :id="listboxId"
      role="listbox"
      :aria-label="$t('components.aiMention.suggestions')"
    >
      <div
        v-if="contentItems.length > 0"
        class="mb-1"
      >
        <div
          class="flex items-center gap-1 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted"
        >
          {{ $t('components.aiMention.content') }}
        </div>
        <button
          v-for="item in contentItems"
          :key="`content-${item.id}`"
          :id="`${listboxId}-${getGlobalIndex(item)}`"
          type="button"
          role="option"
          :aria-selected="selectedIndex === getGlobalIndex(item)"
          :class="[
            'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
            selectedIndex === getGlobalIndex(item)
              ? 'bg-accent text-accent-foreground'
              : 'hover:bg-accent/50',
          ]"
          @mousedown.prevent
          @click="selectItem(getGlobalIndex(item))"
          @mouseenter="selectedIndex = getGlobalIndex(item)"
        >
          <span class="truncate">{{ item.label }}</span>
        </button>
      </div>

      <div
        v-if="draftContentItems.length > 0"
        class="mb-1"
      >
        <div
          class="flex items-center gap-1 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted"
        >
          {{ $t('components.aiMention.draftContent') }}
        </div>
        <button
          v-for="item in draftContentItems"
          :key="`draft-content-${item.id}`"
          :id="`${listboxId}-${getGlobalIndex(item)}`"
          type="button"
          role="option"
          :aria-selected="selectedIndex === getGlobalIndex(item)"
          :class="[
            'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
            selectedIndex === getGlobalIndex(item)
              ? 'bg-accent text-accent-foreground'
              : 'hover:bg-accent/50',
          ]"
          @mousedown.prevent
          @click="selectItem(getGlobalIndex(item))"
          @mouseenter="selectedIndex = getGlobalIndex(item)"
        >
          <span class="truncate">{{ item.label }}</span>
        </button>
      </div>

      <div v-if="blockItems.length > 0">
        <div
          class="flex items-center gap-1 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted"
        >
          {{ $t('components.aiMention.blocks') }}
        </div>
        <button
          v-for="item in blockItems"
          :key="`block-${item.id}`"
          :id="`${listboxId}-${getGlobalIndex(item)}`"
          type="button"
          role="option"
          :aria-selected="selectedIndex === getGlobalIndex(item)"
          :class="[
            'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
            selectedIndex === getGlobalIndex(item)
              ? 'bg-accent text-accent-foreground'
              : 'hover:bg-accent/50',
          ]"
          @mousedown.prevent
          @click="selectItem(getGlobalIndex(item))"
          @mouseenter="selectedIndex = getGlobalIndex(item)"
        >
          <span class="truncate">{{ item.label }}</span>
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
