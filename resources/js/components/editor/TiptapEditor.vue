<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Link } from '@tiptap/extension-link'
import { Table } from '@tiptap/extension-table'
import { TableCell } from '@tiptap/extension-table-cell'
import { TableHeader } from '@tiptap/extension-table-header'
import { TableRow } from '@tiptap/extension-table-row'
import { Underline } from '@tiptap/extension-underline'
import { StarterKit } from '@tiptap/starter-kit'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import ContentPicker from '~/components/editor/ContentPicker.vue'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { InternalLink, type InternalLinkAttrs } from './extensions/InternalLink'
import { TextClass } from './extensions/TextClass'

interface HtmlClass {
  name: string
  className: string
  css?: string
}

const props = withDefaults(
  defineProps<{
    modelValue: Record<string, unknown>
    htmlClasses?: HtmlClass[]
    spaceId?: string
    headingLevels?: Array<'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6' | 'p'>
  }>(),
  {
    htmlClasses: () => [],
    spaceId: undefined,
    headingLevels: () => ['h1', 'h2', 'h3', 'h4', 'p'],
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
}>()

const contentPickerOpen = ref(false)
const linkInSelection = ref<InternalLinkAttrs | null>(null)

const getHeadingLabel = (level: 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6' | 'p'): string => {
  if (level === 'p') return 'Paragraph'
  return `Heading ${level.charAt(1)}`
}

const getHeadingIcon = (level: 'h1' | 'h2' | 'h3' | 'h4' | 'h5' | 'h6' | 'p'): string => {
  if (level === 'p') return 'lucide:pilcrow'
  return `lucide:heading-${level.charAt(1)}`
}

const currentHeading = computed(() => {
  if (editor.value?.isActive('paragraph')) {
    return 'p'
  }
  for (const level of [1, 2, 3, 4, 5, 6]) {
    if (editor.value?.isActive('heading', { level })) {
      return `h${level}` as const
    }
  }
  return null
})

const headingDisplayLabel = computed(() => {
  if (!currentHeading.value) {
    return 'Format'
  }
  return getHeadingLabel(currentHeading.value)
})

const editor = useEditor({
  content: props.modelValue,
  extensions: [
    StarterKit.configure({
      heading: {
        levels: [1, 2, 3, 4, 5, 6],
      },
    }),
    Underline,
    Link.configure({
      openOnClick: false,
      autolink: true,
    }),
    InternalLink,
    TextClass,
    Table.configure({
      resizable: true,
      handleWidth: 4,
      cellMinWidth: 50,
      lastColumnResizable: true,
      allowTableNodeSelection: true,
    }),
    TableRow,
    TableHeader,
    TableCell,
  ],
  onUpdate: ({ editor: currentEditor }) => {
    emit('update:modelValue', currentEditor.getJSON())
  },
})

const applyClass = (className: string) => {
  if (!editor.value) return
  editor.value.chain().focus().toggleMark('textClass', { class: className }).run()
}

const openInternalLinkPicker = () => {
  if (!editor.value || !props.spaceId) return
  linkInSelection.value = null
  contentPickerOpen.value = true
}

const onInternalLinkSelect = (contentId: string) => {
  if (!editor.value) return
  const linkData: InternalLinkAttrs = { content: contentId }
  editor.value.chain().focus().setInternalLink(linkData).run()
  contentPickerOpen.value = false
}

const onInternalLinkWithAnchorSelect = (contentId: string, anchorId: string) => {
  if (!editor.value) return
  const linkData: InternalLinkAttrs = { content: contentId, anchor: anchorId }
  editor.value.chain().focus().setInternalLink(linkData).run()
  contentPickerOpen.value = false
}

const removeInternalLink = () => {
  if (!editor.value) return
  editor.value.chain().focus().unsetInternalLink().run()
}

const insertExternalLink = () => {
  if (!editor.value) return
  const url = prompt('Enter URL:')
  if (url) {
    editor.value.chain().focus().setLink({ href: url }).run()
  }
}

watch(
  () => props.modelValue,
  (newValue) => {
    if (!editor.value) return
    const isSame = JSON.stringify(editor.value.getJSON()) === JSON.stringify(newValue)
    if (!isSame) {
      editor.value.commands.setContent(newValue)
    }
  },
  { deep: true }
)

onBeforeUnmount(() => {
  editor.value?.destroy()
})
</script>

<template>
  <div class="flex flex-col rounded border border-input bg-surface">
    <div class="flex flex-wrap gap-1 border-b border-input p-2">
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Bold (Ctrl+B)"
        :class="editor?.isActive('bold') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleBold().run()"
      >
        <Icon name="lucide:bold" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Italic (Ctrl+I)"
        :class="editor?.isActive('italic') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleItalic().run()"
      >
        <Icon name="lucide:italic" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Underline (Ctrl+U)"
        :class="editor?.isActive('underline') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleUnderline().run()"
      >
        <Icon name="lucide:underline" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Strikethrough"
        :class="editor?.isActive('strike') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleStrike().run()"
      >
        <Icon name="lucide:strikethrough" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Inline Code"
        :class="editor?.isActive('code') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleCode().run()"
      >
        <Icon name="lucide:code-2" />
      </Button>
      <DropdownMenu>
        <DropdownMenuTrigger as-child>
          <Button
            size="xs"
            variant="outline"
            class="gap-1"
            :title="`Current: ${headingDisplayLabel}`"
          >
            <Icon :name="getHeadingIcon(currentHeading || 'p')" />
            {{ headingDisplayLabel }}
            <Icon
              name="lucide:chevron-down"
              size="0.8rem"
            />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem
            v-for="level in headingLevels"
            :key="level"
            :class="
              (level === 'p'
                ? editor?.isActive('paragraph')
                : editor?.isActive('heading', { level: parseInt(level.charAt(1)) })) &&
              'bg-primary text-primary-foreground'
            "
            @click="
              level === 'p'
                ? editor?.chain().focus().setParagraph().run()
                : editor
                    ?.chain()
                    .focus()
                    .toggleHeading({ level: parseInt(level.charAt(1)) })
                    .run()
            "
          >
            <Icon
              :name="getHeadingIcon(level)"
              class="mr-2"
            />
            {{ getHeadingLabel(level) }}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Bullet List"
        :class="editor?.isActive('bulletList') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleBulletList().run()"
      >
        <Icon name="lucide:list" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Ordered List"
        :class="editor?.isActive('orderedList') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleOrderedList().run()"
      >
        <Icon name="lucide:list-ordered" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Code Block"
        :class="editor?.isActive('codeBlock') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleCodeBlock().run()"
      >
        <Icon name="lucide:code" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Blockquote"
        :class="editor?.isActive('blockquote') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleBlockquote().run()"
      >
        <Icon name="lucide:quote" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Horizontal Rule"
        @click="editor?.chain().focus().setHorizontalRule().run()"
      >
        <Icon name="lucide:minus" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Insert Table"
        @click="
          editor?.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()
        "
      >
        <Icon name="lucide:table" />
      </Button>
      <DropdownMenu>
        <DropdownMenuTrigger as-child>
          <Button
            type="button"
            size="xs"
            variant="outline"
            class="gap-1"
            title="Table Tools"
            :disabled="!editor?.isActive('table')"
          >
            <Icon name="lucide:table-2" />
            <Icon
              name="lucide:chevron-down"
              size="0.8rem"
            />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem
            :disabled="!editor?.can().addColumnBefore()"
            @click="editor?.chain().focus().addColumnBefore().run()"
          >
            <Icon
              name="lucide:columns"
              class="mr-2"
            />
            Add Column Before
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().addColumnAfter()"
            @click="editor?.chain().focus().addColumnAfter().run()"
          >
            <Icon
              name="lucide:columns"
              class="mr-2"
            />
            Add Column After
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().deleteColumn()"
            @click="editor?.chain().focus().deleteColumn().run()"
          >
            <Icon
              name="lucide:trash-2"
              class="mr-2"
            />
            Delete Column
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().addRowBefore()"
            @click="editor?.chain().focus().addRowBefore().run()"
          >
            <Icon
              name="lucide:rows"
              class="mr-2"
            />
            Add Row Before
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().addRowAfter()"
            @click="editor?.chain().focus().addRowAfter().run()"
          >
            <Icon
              name="lucide:rows"
              class="mr-2"
            />
            Add Row After
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().deleteRow()"
            @click="editor?.chain().focus().deleteRow().run()"
          >
            <Icon
              name="lucide:trash-2"
              class="mr-2"
            />
            Delete Row
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().deleteTable()"
            @click="editor?.chain().focus().deleteTable().run()"
          >
            <Icon
              name="lucide:trash-2"
              class="mr-2"
            />
            Delete Table
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().mergeCells()"
            @click="editor?.chain().focus().mergeCells().run()"
          >
            <Icon
              name="lucide:merge"
              class="mr-2"
            />
            Merge Cells
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().splitCell()"
            @click="editor?.chain().focus().splitCell().run()"
          >
            <Icon
              name="lucide:split-square-vertical"
              class="mr-2"
            />
            Split Cell
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().toggleHeaderCell()"
            @click="editor?.chain().focus().toggleHeaderCell().run()"
          >
            <Icon
              name="lucide:heading"
              class="mr-2"
            />
            Toggle Header Cell
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="External Link"
        :class="editor?.isActive('link') && 'bg-primary text-primary-foreground'"
        @click="insertExternalLink"
      >
        <Icon name="lucide:link" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Internal Link"
        :class="editor?.isActive('internalLink') && 'bg-primary text-primary-foreground'"
        :disabled="!props.spaceId"
        @click="openInternalLinkPicker"
      >
        <Icon name="lucide:link-2" />
      </Button>
      <Button
        v-if="editor?.isActive('internalLink')"
        type="button"
        size="toolbar"
        variant="ghost"
        class="hover:text-destructive"
        title="Remove Internal Link"
        @click="removeInternalLink"
      >
        <Icon name="lucide:trash-2" />
      </Button>

      <DropdownMenu v-if="htmlClasses.length > 0">
        <DropdownMenuTrigger as-child>
          <Button
            type="button"
            size="xs"
            variant="outline"
            title="Apply CSS Class"
          >
            <Icon name="lucide:palette" />
            Classes
            <Icon
              name="lucide:chevron-down"
              size="0.8rem"
            />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent class="max-h-96 overflow-y-auto">
          <DropdownMenuItem
            v-for="htmlClass in htmlClasses"
            :key="htmlClass.className"
            @click="applyClass(htmlClass.className)"
          >
            <span class="flex items-center gap-2">
              <span
                class="inline-block h-3 w-3 rounded"
                :class="htmlClass.className"
              />
              {{ htmlClass.name }}
            </span>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Undo"
        :disabled="!editor?.can().undo()"
        @click="editor?.chain().focus().undo().run()"
      >
        <Icon name="lucide:undo-2" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        title="Redo"
        :disabled="!editor?.can().redo()"
        @click="editor?.chain().focus().redo().run()"
      >
        <Icon name="lucide:redo-2" />
      </Button>
    </div>

    <EditorContent :editor="editor" />

    <ContentPicker
      v-if="spaceId"
      :open="contentPickerOpen"
      :space-id="spaceId"
      :show-elements="true"
      title="Select Page or Section"
      @update:open="contentPickerOpen = $event"
      @content-select="onInternalLinkSelect"
      @content-with-anchor-select="onInternalLinkWithAnchorSelect"
    />
  </div>
</template>
