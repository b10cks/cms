/** A block item inside a content, linkable by its id. */
export interface BlockItemEntry {
  id: string
  item: Record<string, unknown>
  /** Nesting level, 0 for items placed directly on the content. */
  depth: number
}

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

const isBlockItem = (value: unknown): value is Record<string, unknown> & { id: string } =>
  isRecord(value) && typeof value.id === 'string' && value.id !== '' && typeof value.block === 'string'

/**
 * Lists the block items of a content in document order.
 *
 * Hidden items and everything inside them are skipped, since delivery drops them.
 */
export const collectBlockItems = (content: unknown): BlockItemEntry[] => {
  const entries: BlockItemEntry[] = []

  const walk = (value: unknown, depth: number): void => {
    if (Array.isArray(value)) {
      value.forEach((element) => walk(element, depth))
      return
    }
    if (!isRecord(value)) return

    if (isBlockItem(value)) {
      if (value.hidden === true) return
      entries.push({ id: value.id, item: value, depth })
      Object.values(value).forEach((field) => walk(field, depth + 1))
      return
    }

    Object.values(value).forEach((field) => walk(field, depth))
  }

  // The content root is a block item itself, but it is the page, not an anchor.
  if (isRecord(content)) Object.values(content).forEach((field) => walk(field, 0))

  return entries
}
