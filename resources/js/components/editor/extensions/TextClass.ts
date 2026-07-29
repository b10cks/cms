import { Mark, mergeAttributes } from '@tiptap/core'

declare module '@tiptap/core' {
  interface Commands<ReturnType> {
    textClass: {
      setTextClass: (attrs: { class: string }) => ReturnType
      unsetTextClass: () => ReturnType
    }
  }
}

const TextClass = Mark.create({
  name: 'textClass',
  priority: 1000,
  keepOnSplit: true,

  addAttributes() {
    return {
      class: {
        default: '',
        parseHTML: (element) => element.getAttribute('class') || '',
        renderHTML: (attributes) => {
          if (!attributes.class) {
            return {}
          }
          return {
            class: attributes.class,
          }
        },
      },
    }
  },

  parseHTML() {
    return [
      {
        tag: 'span[class]',
        getAttrs: (element: any) => {
          const className = element.getAttribute('class')
          return className ? { class: className } : false
        },
      },
    ]
  },

  renderHTML({ HTMLAttributes }: any) {
    return ['span', mergeAttributes(HTMLAttributes), 0]
  },

  addCommands() {
    return {
      setTextClass:
        (attrs: { class: string }) =>
        ({ commands }: any) => {
          if (!attrs?.class) {
            return false
          }
          return commands.setMark(this.name, { class: attrs.class })
        },
      unsetTextClass:
        () =>
        ({ commands }: any) =>
          commands.unsetMark(this.name),
    }
  },
})

export { TextClass }
