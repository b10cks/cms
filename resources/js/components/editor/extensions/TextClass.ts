import { Mark } from '@tiptap/core'

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

  renderHTML({ attributes }: any) {
    if (!attributes || !attributes.class) {
      return ['span', {}, 0]
    }
    return [
      'span',
      {
        class: attributes.class,
      },
      0,
    ]
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
