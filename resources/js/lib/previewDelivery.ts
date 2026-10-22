/**
 * Shapes the editor's content the way the Data API delivers it, so the live
 * preview renders what the site shows once the entry is saved. Every content
 * push to the preview runs through here. Mirrors `ContentResource::removeHiddenBlocks`,
 * `LinkHandler::replaceContentLinks` (draft scope) and `AssetHandler::replaceContentAssets`
 * on the server; keep them in sync.
 */

import type { ContentResource } from '~/types/contents'

/** A link target as the management API returns it, with its i18n family. */
export type LinkTarget = Pick<
  ContentResource,
  | 'id'
  | 'name'
  | 'full_slug'
  | 'language_iso'
  | 'i18n_parent'
  | 'i18n_translations'
  | 'i18n_siblings'
>

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
  /** Link targets by content id. A link without one stays as stored. */
  links: ReadonlyMap<string, LinkTarget>
  /** Language the preview requests; links resolve to the target's row in it. */
  language: string
  /** The space's default language, the fallback when the target lacks `language`. */
  defaultLanguage: string
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

const isInternalLink = (
  value: Record<string, unknown>
): value is { type: 'internal'; content: string } =>
  value.type === 'internal' && typeof value.content === 'string'

const isInternalLinkMark = (
  value: Record<string, unknown>
): value is { type: 'internalLink'; attrs: { content: string } } =>
  value.type === 'internalLink' && isRecord(value.attrs) && typeof value.attrs.content === 'string'

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

type DeliveredLink = { url: string; title: string }

/**
 * Url and title of the target's row in `language`, else in the default
 * language, else of the canonical row.
 */
function resolveLink(target: LinkTarget, language: string, defaultLanguage: string): DeliveredLink {
  const family = [
    target.i18n_parent,
    target,
    ...(target.i18n_translations ?? []),
    ...(target.i18n_siblings ?? []),
  ].filter((row) => row != null)
  const row =
    family.find((member) => member.language_iso === language) ??
    family.find((member) => member.language_iso === defaultLanguage) ??
    target.i18n_parent ??
    target

  return { url: row.full_slug, title: row.name }
}

/** Fills in `url` and `title` on internal links; keys the value already has win. */
function deliverLink(
  value: Record<string, unknown>,
  options: DeliveryOptions
): Record<string, unknown> {
  const contentId = isInternalLink(value)
    ? value.content
    : isInternalLinkMark(value)
      ? value.attrs.content
      : null
  const target = contentId === null ? undefined : options.links.get(contentId)
  if (!target) return value

  const link = resolveLink(target, options.language, options.defaultLanguage)

  return isInternalLink(value)
    ? { ...link, ...value }
    : { ...value, attrs: { ...link, ...recordAt(value, 'attrs') } }
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
  if (isInternalLink(value) || isInternalLinkMark(value)) return deliverLink(copy, options)
  const asset = isAssetValue(value) ? options.assets.get(value.id) : undefined

  return asset ? deliverAsset(copy, asset, options.translationLanguage) : copy
}

/**
 * A plain copy of `value` in delivered shape: hidden blocks dropped at any
 * depth when the space filters them, internal links resolved to their
 * target's url and title, asset values resolved from the library.
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

/** Ids of the contents `value` links to internally, at any depth, added to `ids`. */
export function collectLinkIds(value: unknown, ids = new Set<string>()): Set<string> {
  if (Array.isArray(value)) {
    value.forEach((item) => collectLinkIds(item, ids))
  } else if (isRecord(value)) {
    if (isInternalLink(value)) ids.add(value.content)
    else if (isInternalLinkMark(value)) ids.add(value.attrs.content)
    else Object.values(value).forEach((item) => collectLinkIds(item, ids))
  }
  return ids
}
