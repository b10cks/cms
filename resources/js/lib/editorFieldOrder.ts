/**
 * A block's field keys in the order the editor shows them: page by page, then
 * any field no page lists. The stored schema's own key order is not usable,
 * MySQL JSON columns sort keys by length.
 */
export const editorOrderedKeys = (
  schema: Record<string, unknown>,
  editor: EditorPage[] | null | undefined
): string[] => {
  const listed = (editor ?? []).flatMap((page) => page?.items ?? []).filter((key) => key in schema)

  return [...new Set([...listed, ...Object.keys(schema)])]
}
