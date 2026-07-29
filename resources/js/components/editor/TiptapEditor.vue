<script setup lang="ts">
import type { AnyExtension } from '@tiptap/core'
import type { Level } from '@tiptap/extension-heading'
import { Table } from '@tiptap/extension-table'
import { TableCell } from '@tiptap/extension-table-cell'
import { TableHeader } from '@tiptap/extension-table-header'
import { TableRow } from '@tiptap/extension-table-row'
import { DOMParser as ProseMirrorDOMParser } from '@tiptap/pm/model'
import { StarterKit } from '@tiptap/starter-kit'
import { EditorContent, useEditor } from '@tiptap/vue-3'

import LinkDialog from '~/components/editor/LinkDialog.vue'
import type { LinkApplyPayload, LinkInitial } from '~/components/editor/linkTypes'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

import { InternalLink } from './extensions/InternalLink'
import { ListStyle } from './extensions/ListStyle'
import { transformPastedHtml } from './extensions/pasteCleanup'
import { PlaceholderToken } from './extensions/PlaceholderToken'
import { TextClass } from './extensions/TextClass'

interface HtmlClass {
  name: string
  className: string
  css?: string
}

interface Placeholder {
  key: string
  label: string
}

const props = withDefaults(
  defineProps<{
    modelValue: Record<string, unknown>
    htmlClasses?: HtmlClass[]
    spaceId?: string
    disabled?: boolean
    headingLevels?: HeadingLevel[]
    placeholders?: Placeholder[]
    features?: Partial<Record<RichTextFeature, boolean>>
    listStyles?: ListStyleConfig[]
  }>(),
  {
    htmlClasses: () => [],
    disabled: false,
    spaceId: undefined,
    headingLevels: () => ['h1', 'h2', 'h3', 'h4', 'p'],
    placeholders: () => [],
    features: () => ({}),
    listStyles: () => [],
  }
)

// A feature is on unless the field config explicitly disables it, so existing
// fields (no `features` map) keep every button.
const isEnabled = (feature: RichTextFeature): boolean => props.features?.[feature] !== false

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
}>()

const { t } = useI18n()

const linkDialogOpen = ref(false)
const linkDialogInitial = ref<LinkInitial | null>(null)
const linkHasSelection = ref(false)
const isApplyingExternalContent = ref(false)
const isBroken = ref(false)
// Identity of the doc we last emitted, so the modelValue watcher can skip the
// round-trip of our own edit instead of re-diffing the whole document.
let lastEmittedValue: Record<string, unknown> | null = null

const emptyDoc = { type: 'doc', content: [{ type: 'paragraph', content: [] }] }

const isValidDoc = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' &&
  value !== null &&
  !Array.isArray(value) &&
  (value as Record<string, unknown>).type === 'doc'

const headingLevelNumber = (level: HeadingLevel): Level => Number(level.charAt(1)) as Level

const getHeadingLabel = (level: HeadingLevel): string => {
  if (level === 'p') return t('labels.tiptap.headings.paragraph')
  return t('labels.tiptap.headings.heading', { level: level.charAt(1) })
}

const getHeadingIcon = (level: HeadingLevel): string => {
  if (level === 'p') return 'lucide:pilcrow'
  return `lucide:heading-${level.charAt(1)}`
}

const currentHeading = computed<HeadingLevel | null>(() => {
  if (editor.value?.isActive('paragraph')) {
    return 'p'
  }
  for (const level of [1, 2, 3, 4, 5, 6]) {
    if (editor.value?.isActive('heading', { level })) {
      return `h${level}` as HeadingLevel
    }
  }
  return null
})

const headingDisplayLabel = computed(() => {
  if (!currentHeading.value) {
    return t('labels.tiptap.toolbar.format')
  }
  return getHeadingLabel(currentHeading.value)
})

// Disabling a feature drops its extension (not just its button) so the node/mark
// can't slip in via paste or input rules either.
const buildExtensions = (): AnyExtension[] => {
  const starterKitConfig: Record<string, unknown> = {
    heading: isEnabled('heading') ? { levels: [1, 2, 3, 4, 5, 6] } : false,
    link: isEnabled('link') ? { openOnClick: false, autolink: true } : false,
  }
  const toggleable: RichTextFeature[] = [
    'bold',
    'italic',
    'underline',
    'strike',
    'code',
    'bulletList',
    'orderedList',
    'blockquote',
    'codeBlock',
    'horizontalRule',
  ]
  for (const feature of toggleable) {
    if (!isEnabled(feature)) starterKitConfig[feature] = false
  }

  const extensions: AnyExtension[] = [
    StarterKit.configure(starterKitConfig),
    TextClass,
    PlaceholderToken,
  ]

  if (isEnabled('internalLink')) extensions.push(InternalLink)
  if (isEnabled('bulletList') || isEnabled('orderedList')) extensions.push(ListStyle)
  if (isEnabled('table')) {
    extensions.push(
      Table.configure({
        resizable: true,
        handleWidth: 4,
        cellMinWidth: 50,
        lastColumnResizable: true,
        allowTableNodeSelection: true,
      }),
      TableRow,
      TableHeader,
      TableCell
    )
  }

  return extensions
}

const editor = useEditor({
  content: props.modelValue,
  editable: !props.disabled,
  extensions: buildExtensions(),
  editorProps: {
    // Sanitize Word/Office HTML on paste; other sources pass through untouched.
    transformPastedHTML: (html: string) => transformPastedHtml(html),
  },
  onUpdate: ({ editor: currentEditor }) => {
    if (isApplyingExternalContent.value) return
    const json = currentEditor.getJSON()
    lastEmittedValue = json
    emit('update:modelValue', json)
  },
})

const applyClass = (className: string) => {
  if (!editor.value) return
  editor.value.chain().focus().toggleMark('textClass', { class: className }).run()
}

const activeListType = computed<'bullet' | 'ordered' | null>(() => {
  if (editor.value?.isActive('orderedList')) return 'ordered'
  if (editor.value?.isActive('bulletList')) return 'bullet'
  return null
})

// Only offer styles that match the list the cursor is in (or that target both).
const listStyleOptions = computed<ListStyleConfig[]>(() => {
  const active = activeListType.value
  if (!active) return props.listStyles
  return props.listStyles.filter((s) => !s.type || s.type === 'both' || s.type === active)
})

const applyListStyle = (className: string | null) => {
  if (!editor.value) return
  ;(editor.value.chain().focus() as any).setListStyle(className).run()
}

const insertPlaceholder = (placeholder: Placeholder) => {
  if (!editor.value) return
  ;(editor.value.chain().focus() as any).insertPlaceholderToken(placeholder).run()
}

const canLinkUrl = computed(() => isEnabled('link'))
const canLinkInternal = computed(() => isEnabled('internalLink') && !!props.spaceId)
const canLink = computed(() => canLinkUrl.value || canLinkInternal.value)
const isLinkActive = computed(
  () => editor.value?.isActive('link') || editor.value?.isActive('internalLink')
)

// Open the unified link editor, prefilled from whichever link the cursor sits on.
const openLinkDialog = () => {
  if (!editor.value) return
  linkHasSelection.value = !editor.value.state.selection.empty

  if (editor.value.isActive('internalLink')) {
    const attrs = editor.value.getAttributes('internalLink')
    linkDialogInitial.value = {
      kind: 'internal',
      content: attrs.content,
      anchor: attrs.anchor,
      target: attrs.target,
      rel: attrs.rel,
    }
  } else if (editor.value.isActive('link')) {
    const attrs = editor.value.getAttributes('link')
    linkDialogInitial.value = { kind: 'url', url: attrs.href, target: attrs.target, rel: attrs.rel }
  } else {
    linkDialogInitial.value = null
  }

  linkDialogOpen.value = true
}

const onLinkApply = (payload: LinkApplyPayload) => {
  if (!editor.value) return
  const chain = editor.value.chain().focus()
  const initial = linkDialogInitial.value

  if (initial) {
    // Editing an existing link — expand the selection to cover the whole mark.
    chain.extendMarkRange(initial.kind === 'url' ? 'link' : 'internalLink')
  } else if (!linkHasSelection.value && payload.text) {
    // New link over a collapsed cursor — insert the text, then select it.
    const from = editor.value.state.selection.from
    chain.insertContent(payload.text).setTextSelection({ from, to: from + payload.text.length })
  }

  if (payload.kind === 'url') {
    // Only touch a mark whose extension is actually registered (feature enabled).
    if (isEnabled('internalLink')) chain.unsetMark('internalLink')
    chain.setLink({ href: payload.url as string, target: payload.target, rel: payload.rel })
  } else {
    if (isEnabled('link')) chain.unsetMark('link')
    ;(chain as any).setInternalLink({
      content: payload.content,
      anchor: payload.anchor,
      target: payload.target,
      rel: payload.rel,
    })
  }

  chain.run()
  linkDialogOpen.value = false
}

const onLinkRemove = () => {
  if (!editor.value) return
  const chain = editor.value.chain().focus()
  if (isEnabled('link')) chain.extendMarkRange('link').unsetMark('link')
  if (isEnabled('internalLink')) chain.extendMarkRange('internalLink').unsetMark('internalLink')
  chain.run()
  linkDialogOpen.value = false
}

watch(
  () => props.disabled,
  (disabled) => {
    editor.value?.setEditable(!disabled)
  },
  { immediate: true }
)

const resetDocument = () => {
  if (!editor.value) return
  isApplyingExternalContent.value = true
  editor.value.commands.setContent(emptyDoc)
  isBroken.value = false
  nextTick(() => {
    isApplyingExternalContent.value = false
    emit('update:modelValue', emptyDoc as Record<string, unknown>)
  })
}

watch(
  () => props.modelValue,
  (newValue) => {
    if (!editor.value) return

    // Our own edit round-tripping back through v-model — the editor already holds
    // this exact document, so there is nothing to apply and nothing to diff.
    if (newValue === lastEmittedValue) return

    if (!isValidDoc(newValue)) {
      isBroken.value = true
      return
    }

    const isSame = JSON.stringify(editor.value.getJSON()) === JSON.stringify(newValue)
    if (!isSame) {
      isApplyingExternalContent.value = true
      try {
        editor.value.commands.setContent(newValue)
        isBroken.value = false
      } catch {
        isBroken.value = true
      }
      nextTick(() => {
        isApplyingExternalContent.value = false
      })
    }
  }
)

onBeforeUnmount(() => {
  editor.value?.destroy()
})
</script>

<template>
  <div class="flex flex-col">
    <div
      v-if="!props.disabled"
      class="sticky top-0 z-10 flex flex-wrap gap-1 bg-background pb-2"
    >
      <Button
        v-if="isEnabled('bold')"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.bold')"
        :title="$t('labels.tiptap.toolbar.bold')"
        :class="editor?.isActive('bold') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleBold().run()"
      >
        <Icon name="lucide:bold" />
      </Button>
      <Button
        v-if="isEnabled('italic')"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.italic')"
        :title="$t('labels.tiptap.toolbar.italic')"
        :class="editor?.isActive('italic') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleItalic().run()"
      >
        <Icon name="lucide:italic" />
      </Button>
      <Button
        v-if="isEnabled('underline')"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.underline')"
        :title="$t('labels.tiptap.toolbar.underline')"
        :class="editor?.isActive('underline') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleUnderline().run()"
      >
        <Icon name="lucide:underline" />
      </Button>
      <Button
        v-if="isEnabled('strike')"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.strikethrough')"
        :title="$t('labels.tiptap.toolbar.strikethrough')"
        :class="editor?.isActive('strike') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleStrike().run()"
      >
        <Icon name="lucide:strikethrough" />
      </Button>
      <Button
        v-if="isEnabled('code')"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.inlineCode')"
        :title="$t('labels.tiptap.toolbar.inlineCode')"
        :class="editor?.isActive('code') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleCode().run()"
      >
        <Icon name="lucide:code-2" />
      </Button>
      <DropdownMenu v-if="isEnabled('heading') && headingLevels.length">
        <DropdownMenuTrigger as-child>
          <Button
            size="xs"
            class="gap-1"
            variant="input"
            :aria-label="$t('labels.tiptap.toolbar.format')"
            :title="`Current: ${headingDisplayLabel}`"
          >
            <Icon :name="getHeadingIcon(currentHeading || 'p')" />
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
                : editor?.isActive('heading', { level: headingLevelNumber(level) })) &&
              'bg-primary text-primary-foreground'
            "
            @click="
              level === 'p'
                ? editor?.chain().focus().setParagraph().run()
                : editor
                    ?.chain()
                    .focus()
                    .toggleHeading({ level: headingLevelNumber(level) })
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
        v-if="isEnabled('bulletList')"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.bulletList')"
        :title="$t('labels.tiptap.toolbar.bulletList')"
        :class="editor?.isActive('bulletList') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleBulletList().run()"
      >
        <Icon name="lucide:list" />
      </Button>
      <Button
        v-if="isEnabled('orderedList')"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.orderedList')"
        :title="$t('labels.tiptap.toolbar.orderedList')"
        :class="editor?.isActive('orderedList') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleOrderedList().run()"
      >
        <Icon name="lucide:list-ordered" />
      </Button>
      <DropdownMenu
        v-if="listStyles.length > 0 && (isEnabled('bulletList') || isEnabled('orderedList'))"
      >
        <DropdownMenuTrigger as-child>
          <Button
            type="button"
            size="xs"
            variant="outline"
            class="gap-1"
            :aria-label="$t('labels.tiptap.toolbar.listStyle')"
            :title="$t('labels.tiptap.toolbar.listStyle')"
            :disabled="!activeListType"
          >
            <Icon name="lucide:list-tree" />
            <Icon
              name="lucide:chevron-down"
              size="0.8rem"
            />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent class="max-h-96 overflow-y-auto">
          <DropdownMenuItem @click="applyListStyle(null)">
            <Icon
              name="lucide:list"
              class="mr-2"
            />
            {{ $t('labels.tiptap.listStyles.default') }}
          </DropdownMenuItem>
          <DropdownMenuSeparator v-if="listStyleOptions.length > 0" />
          <DropdownMenuItem
            v-for="style in listStyleOptions"
            :key="style.className"
            @click="applyListStyle(style.className)"
          >
            {{ style.name }}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <Button
        v-if="isEnabled('codeBlock')"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.codeBlock')"
        :title="$t('labels.tiptap.toolbar.codeBlock')"
        :class="editor?.isActive('codeBlock') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleCodeBlock().run()"
      >
        <Icon name="lucide:code" />
      </Button>
      <Button
        v-if="isEnabled('blockquote')"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.blockquote')"
        :title="$t('labels.tiptap.toolbar.blockquote')"
        :class="editor?.isActive('blockquote') && 'bg-primary text-primary-foreground'"
        @click="editor?.chain().focus().toggleBlockquote().run()"
      >
        <Icon name="lucide:quote" />
      </Button>
      <Button
        v-if="isEnabled('horizontalRule')"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.horizontalRule')"
        :title="$t('labels.tiptap.toolbar.horizontalRule')"
        @click="editor?.chain().focus().setHorizontalRule().run()"
      >
        <Icon name="lucide:minus" />
      </Button>
      <Button
        v-if="isEnabled('table')"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.insertTable')"
        :title="$t('labels.tiptap.toolbar.insertTable')"
        @click="
          editor?.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()
        "
      >
        <Icon name="lucide:table" />
      </Button>
      <DropdownMenu v-if="isEnabled('table')">
        <DropdownMenuTrigger as-child>
          <Button
            type="button"
            size="xs"
            variant="input"
            class="gap-1"
            :aria-label="$t('labels.tiptap.toolbar.tableTools')"
            :title="$t('labels.tiptap.toolbar.tableTools')"
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
            {{ $t('labels.tiptap.table.addColumnBefore') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().addColumnAfter()"
            @click="editor?.chain().focus().addColumnAfter().run()"
          >
            <Icon
              name="lucide:columns"
              class="mr-2"
            />
            {{ $t('labels.tiptap.table.addColumnAfter') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().deleteColumn()"
            @click="editor?.chain().focus().deleteColumn().run()"
          >
            <Icon
              name="lucide:trash-2"
              class="mr-2"
            />
            {{ $t('labels.tiptap.table.deleteColumn') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().addRowBefore()"
            @click="editor?.chain().focus().addRowBefore().run()"
          >
            <Icon
              name="lucide:rows"
              class="mr-2"
            />
            {{ $t('labels.tiptap.table.addRowBefore') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().addRowAfter()"
            @click="editor?.chain().focus().addRowAfter().run()"
          >
            <Icon
              name="lucide:rows"
              class="mr-2"
            />
            {{ $t('labels.tiptap.table.addRowAfter') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().deleteRow()"
            @click="editor?.chain().focus().deleteRow().run()"
          >
            <Icon
              name="lucide:trash-2"
              class="mr-2"
            />
            {{ $t('labels.tiptap.table.deleteRow') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().deleteTable()"
            @click="editor?.chain().focus().deleteTable().run()"
          >
            <Icon
              name="lucide:trash-2"
              class="mr-2"
            />
            {{ $t('labels.tiptap.table.deleteTable') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().mergeCells()"
            @click="editor?.chain().focus().mergeCells().run()"
          >
            <Icon
              name="lucide:merge"
              class="mr-2"
            />
            {{ $t('labels.tiptap.table.mergeCells') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().splitCell()"
            @click="editor?.chain().focus().splitCell().run()"
          >
            <Icon
              name="lucide:split-square-vertical"
              class="mr-2"
            />
            {{ $t('labels.tiptap.table.splitCell') }}
          </DropdownMenuItem>
          <DropdownMenuItem
            :disabled="!editor?.can().toggleHeaderCell()"
            @click="editor?.chain().focus().toggleHeaderCell().run()"
          >
            <Icon
              name="lucide:heading"
              class="mr-2"
            />
            {{ $t('labels.tiptap.table.toggleHeaderCell') }}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <Button
        v-if="canLink"
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.link')"
        :title="$t('labels.tiptap.toolbar.link')"
        :class="isLinkActive && 'bg-primary text-primary-foreground'"
        @click="openLinkDialog"
      >
        <Icon name="lucide:link" />
      </Button>

      <DropdownMenu v-if="placeholders.length > 0">
        <DropdownMenuTrigger as-child>
          <Button
            type="button"
            size="xs"
            variant="outline"
            :aria-label="$t('labels.tiptap.toolbar.insertPlaceholder')"
            :title="$t('labels.tiptap.toolbar.insertPlaceholder')"
          >
            <Icon name="lucide:braces" />
            <Icon name="lucide:chevron-down" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent class="max-h-96 overflow-y-auto">
          <DropdownMenuItem
            v-for="placeholder in placeholders"
            :key="placeholder.key"
            @click="insertPlaceholder(placeholder)"
          >
            <span class="flex items-center gap-2">
              <code class="text-xs text-muted-foreground"
                >&#123;&#123;{{ placeholder.key }}&#125;&#125;</code
              >
              {{ placeholder.label }}
            </span>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <DropdownMenu v-if="htmlClasses.length > 0">
        <DropdownMenuTrigger as-child>
          <Button
            type="button"
            size="xs"
            variant="outline"
            :aria-label="$t('labels.tiptap.toolbar.applyCssClass')"
            :title="$t('labels.tiptap.toolbar.applyCssClass')"
          >
            <Icon name="lucide:palette" />
            <Icon name="lucide:chevron-down" />
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
          <DropdownMenuSeparator />
          <DropdownMenuItem
            :disabled="!editor?.isActive('textClass')"
            class="text-destructive focus:text-destructive"
            @click="editor?.chain().focus().unsetTextClass().run()"
          >
            <Icon name="lucide:x" />
            Remove Class
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.undo')"
        :title="$t('labels.tiptap.toolbar.undo')"
        :disabled="!editor?.can().undo()"
        @click="editor?.chain().focus().undo().run()"
      >
        <Icon name="lucide:undo-2" />
      </Button>
      <Button
        type="button"
        size="toolbar"
        variant="ghost"
        :aria-label="$t('labels.tiptap.toolbar.redo')"
        :title="$t('labels.tiptap.toolbar.redo')"
        :disabled="!editor?.can().redo()"
        @click="editor?.chain().focus().redo().run()"
      >
        <Icon name="lucide:redo-2" />
      </Button>
    </div>

    <div
      v-if="isBroken"
      class="flex items-center gap-3 border-b border-destructive/30 bg-destructive/10 px-3 py-2 text-sm text-destructive"
    >
      <Icon
        name="lucide:alert-triangle"
        class="shrink-0"
      />
      <span class="flex-1">{{ $t('labels.tiptap.brokenDocument.title') }}</span>
      <Button
        type="button"
        size="xs"
        variant="destructive"
        @click="resetDocument"
      >
        {{ $t('labels.tiptap.brokenDocument.reset') }}
      </Button>
    </div>
    <EditorContent
      :editor="editor"
      :tabindex="props.disabled ? -1 : undefined"
      class="rounded border border-input-border bg-input shadow-sm"
    />

    <LinkDialog
      v-if="canLink && !props.disabled"
      v-model:open="linkDialogOpen"
      :space-id="spaceId"
      :allow-url="canLinkUrl"
      :allow-internal="canLinkInternal"
      :has-selection="linkHasSelection"
      :initial="linkDialogInitial"
      @apply="onLinkApply"
      @remove="onLinkRemove"
    />
  </div>
</template>
