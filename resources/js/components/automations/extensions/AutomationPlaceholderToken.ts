import { Node, mergeAttributes } from '@tiptap/core'
import { VueNodeViewRenderer } from '@tiptap/vue-3'

import AutomationPlaceholderTokenNode from '~/components/automations/AutomationPlaceholderTokenNode.vue'

export const AutomationPlaceholderToken = Node.create({
  name: 'automationPlaceholderToken',
  group: 'inline',
  inline: true,
  selectable: true,
  atom: true,

  addAttributes() {
    return {
      value: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-value'),
        renderHTML: (attributes) => ({
          'data-value': attributes.value,
        }),
      },
      label: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-label'),
        renderHTML: (attributes) => ({
          'data-label': attributes.label,
        }),
      },
      group: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-group'),
        renderHTML: (attributes) => ({
          'data-group': attributes.group,
        }),
      },
    }
  },

  parseHTML() {
    return [
      {
        tag: 'span[data-automation-placeholder]',
      },
    ]
  },

  renderHTML({ HTMLAttributes }) {
    const value = String(HTMLAttributes['data-value'] || '')
    const label = String(HTMLAttributes['data-label'] || value)

    return [
      'span',
      mergeAttributes(HTMLAttributes, {
        'data-automation-placeholder': 'true',
      }),
      `{{ ${label} }}`,
    ]
  },

  addNodeView() {
    return VueNodeViewRenderer(AutomationPlaceholderTokenNode)
  },
})
