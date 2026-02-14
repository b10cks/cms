import { Node, mergeAttributes } from '@tiptap/core'
import { PluginKey } from '@tiptap/pm/state'
import Suggestion, { type SuggestionOptions } from '@tiptap/suggestion'
import { VueNodeViewRenderer } from '@tiptap/vue-3'

import AiMentionNode from '~/components/editor/AiMentionNode.vue'

export interface AiMentionOptions {
  HTMLAttributes: Record<string, unknown>
  suggestion: Omit<SuggestionOptions, 'editor'>
}

export interface AiMentionItem {
  id: string
  label: string
  type: 'content' | 'block'
  color?: string | null
  icon?: string | null
}

export const AiMentionPluginKey = new PluginKey('aiMention')

export const AiMention = Node.create<AiMentionOptions>({
  name: 'aiMention',
  group: 'inline',
  inline: true,
  selectable: true,
  atom: true,

  addOptions() {
    return {
      HTMLAttributes: {},
      suggestion: {
        char: '@',
        pluginKey: AiMentionPluginKey,
        command: ({ editor, range, props }) => {
          const item = props as AiMentionItem
          editor
            .chain()
            .focus()
            .insertContentAt(range, [
              {
                type: this.name,
                attrs: {
                  id: item.id,
                  label: item.label,
                  mentionType: item.type,
                  color: item.color ?? null,
                  icon: item.icon ?? null,
                },
              },
              {
                type: 'text',
                text: ' ',
              },
            ])
            .run()
        },
        allow: ({ editor, range }) => {
          const textBefore = editor.state.doc.textBetween(
            Math.max(0, range.from - 1),
            range.from,
            undefined,
            '\0'
          )
          return textBefore.length === 0 || /[\s\n\r]/.test(textBefore)
        },
      },
    }
  },

  addAttributes() {
    return {
      id: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-id'),
        renderHTML: (attributes) => ({
          'data-id': attributes.id,
        }),
      },
      label: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-label'),
        renderHTML: (attributes) => ({
          'data-label': attributes.label,
        }),
      },
      mentionType: {
        default: 'content',
        parseHTML: (element) => element.getAttribute('data-mention-type'),
        renderHTML: (attributes) => ({
          'data-mention-type': attributes.mentionType,
        }),
      },
      icon: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-icon'),
        renderHTML: (attributes) => ({
          'data-icon': attributes.icon,
        }),
      },
      color: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-color'),
        renderHTML: (attributes) => ({
          'data-color': attributes.color,
        }),
      },
    }
  },

  parseHTML() {
    return [
      {
        tag: 'span[data-mention-type]',
      },
    ]
  },

  renderHTML({ HTMLAttributes }) {
    return [
      'span',
      mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
        'data-type': 'ai-mention',
      }),
      `@${HTMLAttributes['data-label']}`,
    ]
  },

  addNodeView() {
    return VueNodeViewRenderer(AiMentionNode)
  },

  addProseMirrorPlugins() {
    return [
      Suggestion({
        editor: this.editor,
        ...this.options.suggestion,
      }),
    ]
  },
})

export function extractMentionsFromText(text: string): Array<{ type: string; id: string }> {
  const mentions: Array<{ type: string; id: string }> = []
  const regex = /@(content|block):([A-Z0-9]{26}|[a-z0-9-]+)/gi
  let match: RegExpExecArray | null = regex.exec(text)
  while (match !== null) {
    mentions.push({
      type: match[1].toLowerCase(),
      id: match[2],
    })
    match = regex.exec(text)
  }
  return mentions
}
