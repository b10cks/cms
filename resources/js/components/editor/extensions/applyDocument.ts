import type { Editor } from '@tiptap/core'
import type { Fragment, Node as ProseMirrorNode } from '@tiptap/pm/model'

/**
 * Replace the editor's document with `next` by rewriting only the range that
 * differs, so the selection of a focused editor maps through the change
 * instead of jumping. Outside changes stay out of the undo history.
 */
export function applyDocument(editor: Editor, next: ProseMirrorNode): void {
  const { state } = editor
  const start = state.doc.content.findDiffStart(next.content)
  const end = state.doc.content.findDiffEnd(next.content)
  if (start == null || !end) return

  // Where the changed ranges overlap (e.g. a repeated character), widen both.
  let { a: endA, b: endB } = end
  const overlap = start - Math.min(endA, endB)
  if (overlap > 0) {
    endA += overlap
    endB += overlap
  }
  const tr = state.tr.replace(start, endA, next.slice(start, endB))
  editor.view.dispatch(tr.setMeta('addToHistory', false))
}

/**
 * Whether two documents hold the same content, not counting an empty paragraph
 * at the end. Tiptap's trailing node adds one on its own after a closing list,
 * heading, or table; that alone is not an edit and must not mark content dirty.
 */
export function isSameContent(a: ProseMirrorNode, b: ProseMirrorNode): boolean {
  return withoutTrailingParagraph(a).eq(withoutTrailingParagraph(b))
}

function withoutTrailingParagraph(doc: ProseMirrorNode): Fragment {
  const last = doc.lastChild
  if (doc.childCount < 2 || last?.type.name !== 'paragraph' || last.content.size > 0) {
    return doc.content
  }
  return doc.content.cut(0, doc.content.size - last.nodeSize)
}
