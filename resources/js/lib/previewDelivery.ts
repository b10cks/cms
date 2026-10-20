/**
 * Shapes the editor's content the way the Data API delivers it, so the live
 * preview renders what the site shows once the entry is saved. Every content
 * push to the preview runs through here. Mirrors `ContentResource::removeHiddenBlocks`
 * and `AssetHandler::replaceContentAssets` on the server; keep them in sync.
 */

export type DeliveryOptions = {
  /** The space strips hidden blocks from Data API responses (`filter_hidden_blocks`). */
  filterHiddenBlocks: boolean
  /** Library assets by id. An asset value without one stays as stored. */
  assets: ReadonlyMap<string, AssetResource>
  /**
   * Language of a translated entry, whose asset fields win over `_default`.
   * Null for the canonical entry, which only gets `_default`.
   */
  translationLanguage: string | null
}

/** The library metadata delivery exposes on an asset value. */
const DELIVERED_METADATA = [
  'width',
  'height',
  'thumbnails',
  'duration',
  'dominant_color',
  'a11y',
] as const

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

const isHiddenBlock = (value: unknown): boolean =>
  isRecord(value) && typeof value.block === 'string' && value.hidden === true

const isAssetValue = (value: Record<string, unknown>): value is { type: 'asset'; id: string } =>
  value.type === 'asset' && typeof value.id === 'string'

const recordAt = (record: Record<string, unknown>, key: string | null): Record<string, unknown> => {
  const value = key === null ? undefined : record[key]
  return isRecord(value) ? value : {}
}

/**
 * An asset value as delivered: library url, file and metadata, the library's
 * metadata fields (alt, description, …) over the usage's own data, and the
 * usage focus over the library focus.
 */
function deliverAsset(
  value: Record<string, unknown>,
  asset: AssetResource,
  translationLanguage: string | null
): Record<string, unknown> {
  const usage = recordAt(value, 'data')
  const fields = recordAt(asset.data, 'fields')
  const metadata = isRecord(asset.metadata) ? asset.metadata : {}

  return {
    ...value,
    url: asset.url,
    data: {
      ...usage,
      ...recordAt(fields, '_default'),
      ...recordAt(fields, translationLanguage),
      // A usage that dropped its focus key falls back to the library focus.
      focus: 'focus' in usage ? usage.focus : (asset.data.focus ?? null),
    },
    full_path: asset.full_path,
    filename: asset.filename,
    extension: asset.extension,
    mime_type: asset.mime_type,
    size: asset.size,
    metadata: Object.fromEntries(
      DELIVERED_METADATA.filter((key) => key in metadata).map((key) => [key, metadata[key]])
    ),
  }
}

function deliver(value: unknown, options: DeliveryOptions): unknown {
  if (Array.isArray(value)) {
    const items = options.filterHiddenBlocks ? value.filter((item) => !isHiddenBlock(item)) : value
    return items.map((item) => deliver(item, options))
  }
  if (!isRecord(value)) return value

  const copy = Object.fromEntries(
    Object.entries(value).map(([key, item]) => [key, deliver(item, options)])
  )
  const asset = isAssetValue(value) ? options.assets.get(value.id) : undefined

  return asset ? deliverAsset(copy, asset, options.translationLanguage) : copy
}

/**
 * A plain copy of `value` in delivered shape: hidden blocks dropped at any
 * depth when the space filters them, asset values resolved from the library.
 * Never mutates `value`.
 */
export function toDeliveredContent<T>(value: T, options: DeliveryOptions): T {
  // Arrays stay arrays and records stay records, so the shape of T holds.
  return deliver(value, options) as T
}

/** Ids of the assets `value` references, at any depth, added to `ids`. */
export function collectAssetIds(value: unknown, ids = new Set<string>()): Set<string> {
  if (Array.isArray(value)) {
    value.forEach((item) => collectAssetIds(item, ids))
  } else if (isRecord(value)) {
    if (isAssetValue(value)) {
      ids.add(value.id)
    } else {
      Object.values(value).forEach((item) => collectAssetIds(item, ids))
    }
  }
  return ids
}
