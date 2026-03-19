import { Node, mergeAttributes } from '@tiptap/core'

interface PlaceholderTokenAttrs {
  key: string
  label: string
}

const PlaceholderToken = Node.create({
  name: 'placeholderToken',
  group: 'inline',
  inline: true,
  atom: true,

  addAttributes() {
    return {
      key: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-key'),
        renderHTML: (attributes) => ({ 'data-key': attributes.key }),
      },
      label: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-label'),
        renderHTML: (attributes) => ({ 'data-label': attributes.label }),
      },
    }
  },

  parseHTML() {
    return [{ tag: 'span[data-type="placeholder-token"]' }]
  },

  renderHTML({ HTMLAttributes }) {
    return [
      'span',
      mergeAttributes(HTMLAttributes, {
        'data-type': 'placeholder-token',
        class:
          'inline-flex items-center rounded bg-primary/10 px-1.5 py-0.5 text-xs font-mono text-primary border border-primary/20 select-none mx-0.5',
        contenteditable: 'false',
      }),
      `{${HTMLAttributes['data-key']}}`,
    ]
  },

  addCommands() {
    return {
      insertPlaceholderToken:
        (attrs: PlaceholderTokenAttrs) =>
        ({ commands }: any) =>
          commands.insertContent({
            type: this.name,
            attrs,
          }),
    }
  },
})

export { PlaceholderToken, type PlaceholderTokenAttrs }
