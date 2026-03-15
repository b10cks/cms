<script lang="ts">
export default {
  name: 'AutomationPlaceholderEditor',
}
</script>

<script setup lang="ts">
import type { JSONContent } from '@tiptap/core'
import Placeholder from '@tiptap/extension-placeholder'
import type { Node as ProseMirrorNode } from '@tiptap/pm/model'
import { StarterKit } from '@tiptap/starter-kit'
import { EditorContent, useEditor } from '@tiptap/vue-3'
import type { AcceptableValue } from 'reka-ui'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import type { AutomationPlaceholderOption } from '~/utils/automations'
import { formatAutomationPlaceholderToken } from '~/utils/automations'

import { AutomationPlaceholderToken } from './extensions/AutomationPlaceholderToken'

const modelValue = defineModel<string>({ default: '' })

const props = withDefaults(
  defineProps<{
    placeholder?: string
    disabled?: boolean
    options?: AutomationPlaceholderOption[]
    singleLine?: boolean
    minHeightClass?: string
  }>(),
  {
    placeholder: '',
    disabled: false,
    options: () => [],
    singleLine: false,
    minHeightClass: 'min-h-28',
  }
)

const { t } = useI18n()

const selectedPlaceholder = ref<string>()

const tokenEditorHint = computed(() =>
  [
    t('labels.automationActions.fields.tokenEditorHintPrefix'),
    formatAutomationPlaceholderToken('...'),
    t('labels.automationActions.fields.tokenEditorHintSuffix'),
  ]
    .filter(Boolean)
    .join(' ')
)

const groupedOptions = computed(() => {
  const groups: Array<{
    key: AutomationPlaceholderOption['group']
    label: string
    items: AutomationPlaceholderOption[]
  }> = []

  const order: AutomationPlaceholderOption['group'][] = ['record', 'changes', 'workflow', 'secrets']

  for (const key of order) {
    const items = props.options.filter((option) => option.group === key)

    if (items.length === 0) {
      continue
    }

    groups.push({
      key,
      label: t(`labels.automationActions.placeholderGroups.${key}`),
      items,
    })
  }

  return groups
})

const optionMap = computed(() => {
  return new Map(props.options.map((option) => [option.value, option]))
})

const editor = useEditor({
  content: deserializeAutomationPlaceholderText(
    modelValue.value,
    optionMap.value,
    props.singleLine
  ),
  extensions: [
    StarterKit.configure({
      blockquote: false,
      bulletList: false,
      code: false,
      codeBlock: false,
      dropcursor: false,
      gapcursor: false,
      heading: false,
      horizontalRule: false,
      orderedList: false,
    }),
    Placeholder.configure({
      placeholder: props.placeholder,
      showOnlyWhenEditable: true,
      showOnlyCurrent: true,
    }),
    AutomationPlaceholderToken,
  ],
  onUpdate: ({ editor: currentEditor }) => {
    modelValue.value = serializeAutomationPlaceholderDocument(currentEditor.state.doc)
  },
  editorProps: {
    attributes: {
      class: `automation-placeholder-editor prose prose-sm max-w-none px-3 py-2 focus:outline-none ${props.minHeightClass}`,
      'aria-placeholder': props.placeholder,
      role: 'textbox',
      'aria-multiline': props.singleLine ? 'false' : 'true',
    },
    handleKeyDown: (_view, event) => {
      if (props.singleLine && event.key === 'Enter') {
        return true
      }

      return false
    },
  },
})

watch(
  () => modelValue.value,
  (newValue) => {
    if (!editor.value) {
      return
    }

    const currentValue = serializeAutomationPlaceholderDocument(editor.value.state.doc)

    if (currentValue !== newValue) {
      editor.value.commands.setContent(
        deserializeAutomationPlaceholderText(newValue, optionMap.value, props.singleLine),
        { emitUpdate: false }
      )
    }
  }
)

watch(
  () => props.options,
  () => {
    if (!editor.value) {
      return
    }

    editor.value.commands.setContent(
      deserializeAutomationPlaceholderText(modelValue.value, optionMap.value, props.singleLine),
      { emitUpdate: false }
    )
  },
  { deep: true }
)

watch(
  () => props.placeholder,
  (newPlaceholder) => {
    if (!editor.value) {
      return
    }

    const placeholderExtension = editor.value.extensionManager.extensions.find(
      (extension) => extension.name === 'placeholder'
    )

    if (placeholderExtension) {
      placeholderExtension.options.placeholder = newPlaceholder
    }
  }
)

watch(
  () => props.disabled,
  (disabled) => {
    editor.value?.setEditable(!disabled)
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  editor.value?.destroy()
})

const insertPlaceholder = (value: string) => {
  const option = optionMap.value.get(value)

  if (!editor.value || !option) {
    return
  }

  editor.value
    .chain()
    .focus()
    .insertContent({
      type: 'automationPlaceholderToken',
      attrs: {
        value: option.value,
        label: option.label,
        group: option.group,
      },
    })
    .run()
}

const handlePlaceholderSelect = (value: AcceptableValue) => {
  if (typeof value !== 'string' || !value) {
    return
  }

  insertPlaceholder(value)
  selectedPlaceholder.value = undefined
}

const clearValue = () => {
  editor.value?.commands.clearContent(true)
  modelValue.value = ''
}

function deserializeAutomationPlaceholderText(
  value: string,
  options: Map<string, AutomationPlaceholderOption>,
  singleLine: boolean
): JSONContent {
  const normalizedValue = singleLine ? value.replace(/\r\n|\n|\r/g, ' ') : value
  const lines = normalizedValue.split(/\r\n|\n|\r/)

  return {
    type: 'doc',
    content: (lines.length > 0 ? lines : ['']).map((line) => ({
      type: 'paragraph',
      content: buildLineContent(line, options),
    })),
  }
}

function buildLineContent(
  line: string,
  options: Map<string, AutomationPlaceholderOption>
): JSONContent[] {
  const content: JSONContent[] = []
  const pattern = /\{\{\s*([^{}]+?)\s*\}\}/g
  let lastIndex = 0
  let match = pattern.exec(line)

  while (match !== null) {
    if (match.index > lastIndex) {
      content.push({
        type: 'text',
        text: line.slice(lastIndex, match.index),
      })
    }

    const value = match[1].trim()
    const option = options.get(value)

    content.push({
      type: 'automationPlaceholderToken',
      attrs: {
        value,
        label: option?.label ?? value,
        group: option?.group ?? 'record',
      },
    })

    lastIndex = match.index + match[0].length
    match = pattern.exec(line)
  }

  if (lastIndex < line.length) {
    content.push({
      type: 'text',
      text: line.slice(lastIndex),
    })
  }

  return content
}

function serializeAutomationPlaceholderDocument(doc: ProseMirrorNode): string {
  const lines: string[] = []

  doc.forEach((node) => {
    lines.push(serializeAutomationPlaceholderNode(node))
  })

  return lines.join('\n')
}

function serializeAutomationPlaceholderNode(node: ProseMirrorNode): string {
  if (node.type.name === 'text') {
    return node.text ?? ''
  }

  if (node.type.name === 'automationPlaceholderToken') {
    return `{{ ${String(node.attrs.value || '')} }}`
  }

  if (node.type.name === 'hardBreak') {
    return '\n'
  }

  let content = ''

  node.forEach((child) => {
    content += serializeAutomationPlaceholderNode(child)
  })

  return content
}
</script>

<template>
  <div class="space-y-3">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
      <div class="text-muted-foreground text-xs">
        {{ tokenEditorHint }}
      </div>

      <div class="flex items-center gap-2">
        <Select
          :model-value="selectedPlaceholder"
          :disabled="disabled || groupedOptions.length === 0"
          @update:model-value="handlePlaceholderSelect"
        >
          <SelectTrigger class="min-w-56">
            <SelectValue
              :placeholder="$t('labels.automationActions.fields.insertPlaceholderPlaceholder')"
            />
          </SelectTrigger>
          <SelectContent>
            <SelectGroup
              v-for="group in groupedOptions"
              :key="group.key"
            >
              <SelectLabel>{{ group.label }}</SelectLabel>
              <SelectItem
                v-for="option in group.items"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </SelectItem>
            </SelectGroup>
          </SelectContent>
        </Select>

        <Button
          type="button"
          variant="outline"
          size="sm"
          :disabled="disabled || !modelValue"
          @click="clearValue"
        >
          <Icon name="lucide:eraser" />
          {{ $t('actions.clear') }}
        </Button>
      </div>
    </div>

    <div class="rounded-md border border-input-border bg-input">
      <EditorContent
        :editor="editor"
        class="w-full"
      />
    </div>
  </div>
</template>
