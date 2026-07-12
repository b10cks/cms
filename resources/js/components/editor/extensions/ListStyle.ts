import { Extension } from '@tiptap/core'

/**
 * Adds a configurable `className` attribute to `bulletList` / `orderedList`
 * nodes so a space can define its own list variants. The class round-trips
 * through the ProseMirror JSON and is rendered verbatim by the b10cks SDK,
 * keeping list styling framework- and CSS-agnostic.
 */
const ListStyle = Extension.create({
  name: 'listStyle',

  addGlobalAttributes() {
    return [
      {
        types: ['bulletList', 'orderedList'],
        attributes: {
          className: {
            default: null,
            parseHTML: (element: HTMLElement) => element.getAttribute('class') || null,
            renderHTML: (attributes: Record<string, unknown>) =>
              attributes.className ? { class: attributes.className } : {},
          },
        },
      },
    ]
  },

  addCommands() {
    return {
      setListStyle:
        (className: string | null) =>
        ({ editor, commands }: any) => {
          const type = editor.isActive('orderedList') ? 'orderedList' : 'bulletList'
          if (!editor.isActive(type)) return false
          return commands.updateAttributes(type, { className })
        },
    } as any
  },
})

export { ListStyle }
