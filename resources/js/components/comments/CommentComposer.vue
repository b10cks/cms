<script setup lang="ts">
import { Mention } from '@tiptap/extension-mention'
import Placeholder from '@tiptap/extension-placeholder'
import { PluginKey } from '@tiptap/pm/state'
import { StarterKit } from '@tiptap/starter-kit'
import { exitSuggestion, type SuggestionProps } from '@tiptap/suggestion'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import { useEventListener } from '@vueuse/core'

import Icon from '~/components/Icon.vue'
import { Avatar } from '~/components/ui/avatar'
import { Button } from '~/components/ui/button'
import { commentTextToDoc, parseCommentBody } from '~/lib/commentMentions'
import { IS_MAC } from '~/lib/shortcuts'
import type { MentionablePerson } from '~/types/comments'

/** Text in the stored format, mentions as `@<id>`. Held by the parent so a draft outlives this composer. */
const model = defineModel<string>({ default: '' })

const props = defineProps<{
  placeholder: string
  submitLabel: string
  /** Rejecting keeps the text and shows the message. */
  submit: (body: string) => Promise<unknown>
  /** Message for a rejected submit, takes `{error}`. */
  failureKey?: string
  /** People the text may already mention, who may not be in the list anymore. */
  knownPeople?: MentionablePerson[]
  cancelable?: boolean
  autofocus?: boolean
}>()

const emit = defineEmits<{ sent: []; cancel: [] }>()

const { t } = useI18n()

const spaceId = inject<string>('spaceId', '')
const contentId = inject<Ref<string | null | undefined>>('contentId', ref(null))
// ProseMirror marks every Escape as handled, so a surrounding popover would never see it.
const dismissSurface = inject<(() => void) | undefined>('dismissCommentSurface', undefined)
const { useMentionablePeopleQuery } = useComments(spaceId, contentId)
const { data: mentionable, isError: peopleFailed } = useMentionablePeopleQuery()

const people = computed(() => [...(mentionable.value ?? []), ...(props.knownPeople ?? [])])

const labelFor = (id: string) =>
  people.value.find((person) => person.id.toLowerCase() === id.toLowerCase())?.name ??
  t('labels.comments.unknownPerson')

const suggestion = shallowRef<SuggestionProps<MentionablePerson> | null>(null)
const selectedIndex = ref(0)
const pickerId = useId()
const pickerStyle = ref<Record<string, string>>({})

const matches = computed(() => {
  if (!suggestion.value) return []
  const search = suggestion.value.query.trim().toLowerCase()

  return (mentionable.value ?? [])
    .filter((person) => person.name.toLowerCase().includes(search))
    .slice(0, 20)
})
const isPickerOpen = computed(() => matches.value.length > 0)
const showPeopleFailure = computed(
  () => !!suggestion.value && !suggestion.value.query && peopleFailed.value
)

watch(matches, () => {
  selectedIndex.value = 0
})

const placePicker = () => {
  const rect = suggestion.value?.clientRect?.()
  if (!rect) return

  const above = rect.top >= 272
  pickerStyle.value = {
    left: `${Math.max(8, Math.min(rect.left, window.innerWidth - 264))}px`,
    top: `${above ? rect.top - 8 : rect.bottom + 8}px`,
    transform: above ? 'translateY(-100%)' : '',
  }
}

useEventListener(window, 'resize', placePicker)
useEventListener(window, 'scroll', placePicker, true)

const showSuggestion = (next: SuggestionProps<MentionablePerson>) => {
  suggestion.value = next
  placePicker()
}

const pick = (index: number) => {
  const person = matches.value[index]
  if (person) suggestion.value?.command({ ...person, label: person.name } as MentionablePerson)
}

const mentionPluginKey = new PluginKey('commentMention')

const handlePickerKey = (event: KeyboardEvent): boolean => {
  if (event.isComposing) return false
  if (event.key === 'Escape') {
    exitSuggestion(editor.value!.view, mentionPluginKey)
    return true
  }
  if (!isPickerOpen.value) return false

  const count = matches.value.length
  if (event.key === 'ArrowDown') selectedIndex.value = (selectedIndex.value + 1) % count
  else if (event.key === 'ArrowUp') selectedIndex.value = (selectedIndex.value + count - 1) % count
  else if (event.key === 'Enter') pick(selectedIndex.value)
  else return false

  return true
}

const sending = ref(false)
const failure = ref<string | null>(null)

const send = async () => {
  const body = model.value.trim()
  if (!body || sending.value) return

  sending.value = true
  failure.value = null
  try {
    await props.submit(body)
    if (model.value.trim() === body) model.value = ''
    emit('sent')
  } catch (error) {
    failure.value = t(props.failureKey ?? 'composables.comments.addError', {
      error: (error instanceof Error && error.message) || 'Unknown error',
    })
  } finally {
    sending.value = false
  }
}

const editor = useEditor({
  content: commentTextToDoc(model.value, labelFor),
  autofocus: props.autofocus ? 'end' : false,
  extensions: [
    // Comments are plain text, so nothing here may turn typing into formatting.
    StarterKit.configure({
      blockquote: false,
      bold: false,
      bulletList: false,
      code: false,
      codeBlock: false,
      heading: false,
      horizontalRule: false,
      italic: false,
      link: false,
      listItem: false,
      listKeymap: false,
      orderedList: false,
      strike: false,
      underline: false,
    }),
    Placeholder.configure({ placeholder: () => props.placeholder }),
    Mention.configure({
      HTMLAttributes: { class: 'rounded bg-chip px-1 text-chip-foreground' },
      renderText: ({ node }) => `@${node.attrs.id}`,
      suggestion: {
        char: '@',
        allowSpaces: true,
        pluginKey: mentionPluginKey,
        items: () => [],
        render: () => ({
          onStart: showSuggestion,
          onUpdate: showSuggestion,
          onKeyDown: ({ event }) => handlePickerKey(event),
          onExit: () => {
            suggestion.value = null
          },
        }),
      },
    }),
  ],
  onUpdate: ({ editor: current }) => {
    model.value = current.getText({ blockSeparator: '\n' })
    failure.value = null
  },
  editorProps: {
    attributes: {
      role: 'textbox',
      'aria-multiline': 'true',
      'aria-label': props.placeholder,
      'aria-keyshortcuts': 'Meta+Enter Control+Enter',
      class: 'focus:outline-none',
    },
    handleKeyDown: (_view, event) => {
      if (event.key === 'Escape' && !isPickerOpen.value) dismissSurface?.()
      if (event.key !== 'Enter' || !(event.metaKey || event.ctrlKey)) return false
      void send()
      return true
    },
  },
})

watch(model, (next) => {
  const current = editor.value
  if (current && current.getText({ blockSeparator: '\n' }) !== next) {
    current.commands.setContent(commentTextToDoc(next, labelFor), { emitUpdate: false })
  }
})

// A draft or an edit can show its mentions before the names have loaded.
watch(people, () => {
  if (parseCommentBody(model.value).some((part) => part.type === 'mention')) {
    editor.value?.commands.setContent(commentTextToDoc(model.value, labelFor), {
      emitUpdate: false,
    })
  }
})

watch([isPickerOpen, selectedIndex], ([open, selected]) => {
  const current = editor.value
  if (!current) return

  current.setOptions({
    editorProps: {
      attributes: {
        ...current.options.editorProps.attributes,
        'aria-expanded': String(open),
        'aria-controls': open ? pickerId : '',
        'aria-activedescendant': open ? `${pickerId}-${selected}` : '',
      },
    },
  })
})

defineExpose({
  focus: () => editor.value?.commands.focus('end'),
})

defineOptions({ inheritAttrs: false })
</script>

<template>
  <div
    class="space-y-2"
    v-bind="$attrs"
  >
    <EditorContent
      :editor="editor"
      class="comment-composer max-h-48 cursor-text overflow-auto rounded-md border border-input-border bg-input text-base text-primary shadow-sm focus-within:ring-2 focus-within:ring-ring"
    />

    <p
      v-if="failure"
      role="alert"
      class="flex items-start gap-2 text-sm text-foreground"
    >
      <Icon
        name="lucide:cloud-off"
        class="mt-0.5 shrink-0 text-destructive"
      />
      <span class="min-w-0">{{ failure }}</span>
    </p>

    <div class="flex items-center justify-end gap-2">
      <span class="mr-auto text-sm text-muted">
        {{ $t('labels.comments.sendHint', { keys: IS_MAC ? '⌘ Enter' : 'Ctrl+Enter' }) }}
      </span>
      <Button
        v-if="cancelable"
        variant="ghost"
        size="sm"
        @click="emit('cancel')"
      >
        {{ $t('actions.cancel') }}
      </Button>
      <Button
        variant="primary"
        size="sm"
        :loading="sending"
        :disabled="!model.trim()"
        @click="send"
      >
        <Icon name="lucide:send" />
        {{ submitLabel }}
      </Button>
    </div>
  </div>

  <Teleport to="body">
    <div
      v-if="isPickerOpen || showPeopleFailure"
      :id="pickerId"
      :role="isPickerOpen ? 'listbox' : 'status'"
      :aria-label="$t('labels.comments.mentionSuggestions')"
      :style="pickerStyle"
      class="fixed z-[100] max-h-64 w-64 overflow-auto rounded-md border border-popover-border bg-popover p-1 shadow-soft-lg"
      @pointerdown.stop
      @mousedown.prevent
    >
      <p
        v-if="!isPickerOpen"
        class="px-2 py-1.5 text-sm text-muted"
      >
        {{ $t('labels.comments.mentionLoadFailed') }}
      </p>
      <button
        v-for="(person, index) in matches"
        :id="`${pickerId}-${index}`"
        :key="person.id"
        type="button"
        role="option"
        tabindex="-1"
        :aria-selected="selectedIndex === index"
        :class="[
          'flex min-h-8 w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1 text-left text-sm',
          selectedIndex === index ? 'bg-state-selected' : 'hover:bg-state-hover',
        ]"
        @click="pick(index)"
        @mouseenter="selectedIndex = index"
      >
        <Avatar
          :name="person.name"
          :avatar="person.avatar"
          size="sm"
          class="!size-5 shrink-0"
        />
        <span class="min-w-0 truncate">{{ person.name }}</span>
      </button>
    </div>
  </Teleport>
</template>

<style scoped>
/* Undo the page-like .ProseMirror defaults from main.css. */
.comment-composer :deep(.tiptap) {
  min-height: 4.5rem;
  max-width: none;
  margin: 0;
}

.comment-composer :deep(.is-editor-empty::after) {
  color: var(--muted);
  opacity: 1;
}

.comment-composer :deep(.tiptap > p) {
  margin: 0;
}
</style>
