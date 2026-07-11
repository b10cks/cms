// Collaboration support for the flattened localization editor. Translation
// drafts are sparse overlays, so peers can hold the same block item at
// different array indices (or not at all). Field updates are therefore
// whispered with the sender's path plus block stamps (id/block per stamped
// array index); receivers resolve stamped indices by id and materialize
// missing block items so concurrent edits converge.

export interface BlockStamp {
  pathIndex: number
  id: string
  block: string
}

export interface LocalizedFieldMeta {
  path: Array<string | number>
  blockStamps?: BlockStamp[]
}

export interface LocalizedFieldUpdatePayload extends LocalizedFieldMeta {
  key: string
  previousValue: unknown
  value: unknown
  debounceMs?: number
}

export interface LocalizedFieldFocusPayload {
  key: string
  focused: boolean
}

const isObjectRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

// Stable field identity across peers: stamped array indices are replaced by
// the block item id, so the same logical field maps to the same key even when
// local array positions differ.
export const getCollaborationFieldKey = (
  path: Array<string | number>,
  blockStamps?: BlockStamp[]
): string =>
  path
    .map(
      (segment, index) =>
        blockStamps?.find((stamp) => stamp.pathIndex === index)?.id ?? String(segment)
    )
    .join('.')

const findStampedIndex = (items: unknown[], stamp: BlockStamp): number =>
  items.findIndex((item) => isObjectRecord(item) && item.id === stamp.id)

export const getLocalizedFieldValue = (
  content: Record<string, unknown>,
  path: Array<string | number>,
  blockStamps?: BlockStamp[]
): unknown => {
  let current: unknown = content

  for (let i = 0; i < path.length; i++) {
    if (current == null || typeof current !== 'object') {
      return undefined
    }

    let segment = path[i]
    const stamp = blockStamps?.find((entry) => entry.pathIndex === i)

    if (stamp && Array.isArray(current)) {
      const index = findStampedIndex(current, stamp)
      if (index < 0) return undefined
      segment = index
    }

    current = (current as Record<string | number, unknown>)[segment]
  }

  return current
}

export const applyLocalizedFieldValue = (
  content: Record<string, unknown>,
  path: Array<string | number>,
  value: unknown,
  blockStamps?: BlockStamp[]
): void => {
  if (path.length === 0) return

  let current: Record<string | number, unknown> = content

  for (let i = 0; i < path.length - 1; i++) {
    let segment = path[i]
    const stamp = blockStamps?.find((entry) => entry.pathIndex === i)

    if (stamp && Array.isArray(current)) {
      const items = current as unknown[]
      let index = findStampedIndex(items, stamp)

      if (index < 0) {
        items.push({ id: stamp.id, block: stamp.block })
        index = items.length - 1
      }

      segment = index
    }

    const nextSegment = path[i + 1]
    let child = current[segment]

    if (child == null || typeof child !== 'object') {
      child = typeof nextSegment === 'number' ? [] : {}
      current[segment] = child
    }

    // Pad plain positional indices; stamped indices resolve by id instead.
    if (Array.isArray(child) && typeof nextSegment === 'number') {
      const nextStamp = blockStamps?.find((entry) => entry.pathIndex === i + 1)
      if (!nextStamp) {
        while (child.length <= nextSegment) child.push({})
      }
    }

    current = child as Record<string | number, unknown>
  }

  current[path[path.length - 1]] = value
}
