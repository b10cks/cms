/** A stored richtext value: a tiptap JSON document. */
export type RichTextDoc = Record<string, unknown> & { type: 'doc' }

export const createEmptyRichTextDoc = (): RichTextDoc => ({
  type: 'doc',
  content: [{ type: 'paragraph', content: [] }],
})

export const isRichTextDoc = (value: unknown): value is RichTextDoc =>
  typeof value === 'object' &&
  value !== null &&
  !Array.isArray(value) &&
  (value as Record<string, unknown>).type === 'doc'

/**
 * Richtext that holds no document yet. PHP decodes an empty `{}` into `[]`,
 * so schema defaults and saved values can carry either shape.
 */
export const isEmptyRichText = (value: unknown): boolean =>
  value === null ||
  value === undefined ||
  (Array.isArray(value) && value.length === 0) ||
  (typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length === 0)

/** The document to load into the editor, or null when the value is not a document. */
export const toRichTextDoc = (value: unknown): RichTextDoc | null => {
  if (isEmptyRichText(value)) return createEmptyRichTextDoc()
  return isRichTextDoc(value) ? value : null
}
