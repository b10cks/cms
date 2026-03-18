<script setup lang="ts">
import { PluginKey } from '@tiptap/pm/state'
import Placeholder from '@tiptap/extension-placeholder'
import { StarterKit } from '@tiptap/starter-kit'
import type { SuggestionProps } from '@tiptap/suggestion'
import { EditorContent, useEditor } from '@tiptap/vue-3'
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
  selectedIndex.value =
    (selectedIndex.value + filteredItems.value.length - 1) % filteredItems.value.length
}

const downHandler = () => {
  selectedIndex.value = (selectedIndex.value + 1) % filteredItems.value.length
}

const enterHandler = () => {
  selectItem(selectedIndex.value)
}

const editor = useEditor({
  content: modelValue.value,
  extensions: [
    StarterKit.configure(),
    Placeholder.configure({
      placeholder: props.placeholder,
      showOnlyWhenEditable: true,
      showOnlyCurrent: true,
    }),
    AiMention.configure({
      suggestion: {
        char: '@',
        pluginKey: new PluginKey('aiMention'),
        items: () => [],
        render: () => {
          return {
            onStart: (p: SuggestionProps<AiMentionItem>) => {
              suggestionProps.value = p
              suggestionOpen.value = true
            },
            onUpdate: (p: SuggestionProps<AiMentionItem>) => {
              suggestionProps.value = p
            },
            onKeyDown: ({ event }) => {
              if (!suggestionOpen.value) return false

              if (event.key === 'ArrowUp') {
                upHandler()
                return true
              }
              if (event.key === 'ArrowDown') {
                downHandler()
                return true
              }
              if (event.key === 'Enter') {
                enterHandler()
                return true
              }
              if (event.key === 'Escape') {
                suggestionOpen.value = false
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
      class: 'prose max-w-none focus:outline-none',
      'aria-placeholder': props.placeholder,
      role: 'textbox',
      'aria-multiline': 'true',
    },
    handleKeyDown: (view, event) => {
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
      editor.value.commands.setContent(newValue)
    }
  }
)

watch(
  () => props.placeholder,
  (newPlaceholder) => {
    if (editor.value) {
      const placeholderExt = editor.value.extensionManager.extensions.find(
        (ext) => ext.name === 'placeholder'
      )
      if (placeholderExt) {
        placeholderExt.options.placeholder = newPlaceholder
      }
    }
  }
)

watch(
  () => props.disabled,
  (disabled) => {
    if (editor.value) {
      editor.value.setEditable(!disabled)
    }
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  editor.value?.destroy()
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
    class="tiptap-editor w-full rounded-md p-2 max-h-96 overflow-auto"
  />

  <Teleport to="body">
    <div
      v-if="suggestionOpen && filteredItems.length > 0 && suggestionProps"
      :style="{
        position: 'absolute',
        left: suggestionProps.clientRect?.()?.left + 'px',
        bottom: suggestionProps.clientRect?.()?.height + 'px',
      }"
      class="z-[100] max-h-64 w-56 overflow-auto rounded-md border border-popover-border bg-popover p-1 shadow-md"
      role="listbox"
      :aria-label="$t('components.aiMention.suggestions')"
    >
      <div
        v-if="contentItems.length > 0"
        class="mb-1"
      >
        <div
          class="flex items-center gap-1 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground"
        >
          {{ $t('components.aiMention.content') }}
        </div>
        <button
          v-for="item in contentItems"
          :key="`content-${item.id}`"
          type="button"
          role="option"
          :aria-selected="selectedIndex === getGlobalIndex(item)"
          :class="[
            'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
            selectedIndex === getGlobalIndex(item)
              ? 'bg-accent text-accent-foreground'
              : 'hover:bg-accent/50',
          ]"
          @click="selectItem(getGlobalIndex(item))"
          @mouseenter="selectedIndex = getGlobalIndex(item)"
        >
          <span class="truncate">{{ item.label }}</span>
        </button>
      </div>

      <div v-if="blockItems.length > 0">
        <div
          class="flex items-center gap-1 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground"
        >
          {{ $t('components.aiMention.blocks') }}
        </div>
        <button
          v-for="item in blockItems"
          :key="`block-${item.id}`"
          type="button"
          role="option"
          :aria-selected="selectedIndex === getGlobalIndex(item)"
          :class="[
            'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
            selectedIndex === getGlobalIndex(item)
              ? 'bg-accent text-accent-foreground'
              : 'hover:bg-accent/50',
          ]"
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
:deep(.tiptap-editor) {
  .tiptap {
    min-height: 2.5rem;
  }

  .tiptap p.is-editor-empty:first-child::before {
    content: attr(data-placeholder);
    float: left;
    color: hsl(var(--muted-foreground));
    pointer-events: none;
    height: 0;
  }

  .tiptap:focus {
    outline: none;
  }
}
</style>
