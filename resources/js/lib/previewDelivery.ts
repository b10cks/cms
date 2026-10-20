/**
 * Shapes the editor's content the way the Data API delivers it, so the live
 * preview renders what the site shows once the entry is saved. Every content
 * push to the preview runs through here. Mirrors `ContentResource::removeHiddenBlocks`
 * on the server; keep the two in sync.
 */

export type DeliveryOptions = {
  /** The space strips hidden blocks from Data API responses (`filter_hidden_blocks`). */
  filterHiddenBlocks: boolean
}

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

const isHiddenBlock = (value: unknown): boolean =>
  isRecord(value) && typeof value.block === 'string' && value.hidden === true

function deliver(value: unknown, options: DeliveryOptions): unknown {
  if (Array.isArray(value)) {
    const items = options.filterHiddenBlocks ? value.filter((item) => !isHiddenBlock(item)) : value
    return items.map((item) => deliver(item, options))
  }
  if (!isRecord(value)) return value

  return Object.fromEntries(
    Object.entries(value).map(([key, item]) => [key, deliver(item, options)])
  )
}

/**
 * A plain copy of `value` in delivered shape: hidden blocks dropped at any
 * depth when the space filters them. Never mutates `value`.
 */
export function toDeliveredContent<T>(value: T, options: DeliveryOptions): T {
  // Arrays stay arrays and records stay records, so the shape of T holds.
  return deliver(value, options) as T
}
