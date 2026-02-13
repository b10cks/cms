import { Mark } from '@tiptap/core'

interface InternalLinkAttrs {
  content: string
  anchor?: string
}

const InternalLink = Mark.create({
  name: 'internalLink',
  priority: 1000,
  keepOnSplit: false,

  addAttributes() {
    return {
      content: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-content'),
        renderHTML: (attributes) => ({
          'data-content': attributes.content,
        }),
      },
      anchor: {
        default: null,
        parseHTML: (element) => element.getAttribute('data-anchor'),
        renderHTML: (attributes) => ({
          'data-anchor': attributes.anchor,
        }),
      },
    }
  },

  parseHTML() {
    return [
      {
        tag: 'a[data-type="internal"]',
      },
    ]
  },

  renderHTML({ attributes }: any) {
    return [
      'a',
      {
        ...attributes,
        'data-type': 'internal',
        href: '#',
        class: 'text-primary underline cursor-pointer',
      },
      0,
    ]
  },

  addCommands() {
    return {
      setInternalLink:
        (attributes: InternalLinkAttrs) =>
        ({ commands }: any) =>
          commands.setMark(this.name, attributes),
      unsetInternalLink:
        () =>
        ({ commands }: any) =>
          commands.unsetMark(this.name),
    }
  },
})

export { InternalLink, type InternalLinkAttrs }
