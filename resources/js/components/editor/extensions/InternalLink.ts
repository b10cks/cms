import { Mark, mergeAttributes } from '@tiptap/core'

interface InternalLinkAttrs {
  content: string
  anchor?: string
  target?: string | null
  rel?: string | null
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
        renderHTML: (attributes) =>
          attributes.anchor ? { 'data-anchor': attributes.anchor } : {},
      },
      target: {
        default: null,
        parseHTML: (element) => element.getAttribute('target'),
        renderHTML: (attributes) => (attributes.target ? { target: attributes.target } : {}),
      },
      rel: {
        default: null,
        parseHTML: (element) => element.getAttribute('rel'),
        renderHTML: (attributes) => (attributes.rel ? { rel: attributes.rel } : {}),
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

  renderHTML({ HTMLAttributes }: any) {
    return [
      'a',
      mergeAttributes(HTMLAttributes, {
        'data-type': 'internal',
        href: '#',
        class: 'text-primary underline cursor-pointer',
      }),
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
    } as any
  },
})

export { InternalLink, type InternalLinkAttrs }
