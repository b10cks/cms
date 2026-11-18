import type { ContentResource } from '~/types/contents'

/**
 * Translations of nested blocks, taken along when a block is copied, cut or
 * duplicated. Only an overlay entry has them: its translations store
 * overrides matched to the canonical entry by block id, so a copy with fresh
 * ids would otherwise show the source language everywhere.
 *
 * A copy's translations wait here, by the ids the copy got, until the entry
 * it sits in is saved. The save sends them along and the server writes them
 * to the entry's translations. They never become part of the content.
 */

/** The source text one translated value was written against; `unit` is its ID below the block. */
export interface BlockTranslationLineage {
  unit: string
  source_hash: string
  source_text: string | null
  origin: string
}

/** What one translation stores for a block. */
export interface BlockTranslation {
  item: Record<string, unknown>
  lineage?: BlockTranslationLineage[]
}

/** Block id => language => translation. */
export type BlockTranslations = Record<string, Record<string, BlockTranslation>>

/** What a save did with the block translations it carried. */
export interface BlockTranslationReport {
  applied: string[]
  skipped: { language: string; reason: 'missing' | 'invalid' }[]
}

type Entry = Pick<ContentResource, 'id' | 'i18n_parent_id' | 'effective_i18n_mode'>

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

const isBlock = (value: unknown): value is Record<string, unknown> & { id: string } =>
  isRecord(value) && typeof value.id === 'string' && typeof value.block === 'string'

const pending = new Map<string, Record<string, BlockTranslation>>()
/** Translations still being fetched for a copy that is already in the content. */
const loading = new Set<Promise<void>>()

/** The ids of every block in `value`, at any depth. */
export function collectBlockIds(value: unknown, ids = new Set<string>()): Set<string> {
  if (Array.isArray(value)) {
    for (const entry of value) collectBlockIds(entry, ids)
  } else if (isRecord(value)) {
    if (isBlock(value)) ids.add(value.id)
    for (const entry of Object.values(value)) collectBlockIds(entry, ids)
  }

  return ids
}

/** Original block id => id of its copy, for a copy that differs from the original in ids only. */
export function pairBlockIds(
  original: unknown,
  copy: unknown,
  ids = new Map<string, string>()
): Map<string, string> {
  if (Array.isArray(original) && Array.isArray(copy)) {
    original.forEach((entry, index) => pairBlockIds(entry, copy[index], ids))
  } else if (isRecord(original) && isRecord(copy)) {
    if (isBlock(original) && isBlock(copy)) ids.set(original.id, copy.id)
    for (const [key, entry] of Object.entries(original)) pairBlockIds(entry, copy[key], ids)
  }

  return ids
}

const withMappedIds = <T>(value: T, ids: Map<string, string>): T => {
  if (Array.isArray(value)) return value.map((entry) => withMappedIds(entry, ids)) as T
  if (!isRecord(value)) return value

  const copy = Object.fromEntries(
    Object.entries(value).map(([key, entry]) => [key, withMappedIds(entry, ids)])
  )
  if (typeof value.id === 'string' && ids.has(value.id)) copy.id = ids.get(value.id)

  return copy as T
}

/** The translations of copied blocks, under the ids of their copies. Blocks without a copy are left out. */
export function remapBlockTranslations(
  translations: BlockTranslations,
  ids: Map<string, string>
): BlockTranslations {
  const remapped: BlockTranslations = {}

  for (const [blockId, byLanguage] of Object.entries(translations)) {
    const copyId = ids.get(blockId)
    if (!copyId) continue

    remapped[copyId] = Object.fromEntries(
      Object.entries(byLanguage).map(([language, { item, lineage }]) => [
        language,
        {
          item: withMappedIds(item, ids),
          lineage: lineage?.map((entry) => ({
            ...entry,
            // Unit IDs name nested blocks by id: `cards.<block id>.label`.
            unit: entry.unit
              .split('.')
              .map((segment) => ids.get(segment) ?? segment)
              .join('.'),
          })),
        },
      ])
    )
  }

  return remapped
}

/**
 * Keep the translations of `originals` for their `copies` until the entry is
 * saved. `translations` may still be on its way; a save waits for it.
 */
export function carryBlockTranslations(
  translations: BlockTranslations | Promise<BlockTranslations>,
  originals: unknown,
  copies: unknown
): void {
  const carried: Promise<void> = Promise.resolve(translations)
    .then((resolved) => {
      const remapped = remapBlockTranslations(resolved, pairBlockIds(originals, copies))
      for (const [blockId, byLanguage] of Object.entries(remapped)) pending.set(blockId, byLanguage)
    })
    // The copy is in the content either way; it then starts untranslated, as before.
    .catch(() => {})
    .finally(() => loading.delete(carried))

  loading.add(carried)
}

/** The translations waiting for blocks of `content`, once every fetch for a copy has settled. */
export async function pendingBlockTranslations(content: unknown): Promise<BlockTranslations> {
  await Promise.all(loading)

  const waiting: BlockTranslations = {}
  for (const id of collectBlockIds(content)) {
    const byLanguage = pending.get(id)
    if (byLanguage) waiting[id] = byLanguage
  }

  return waiting
}

/** Forget translations a save has delivered. */
export function forgetBlockTranslations(translations: BlockTranslations): void {
  for (const blockId of Object.keys(translations)) pending.delete(blockId)
}

/**
 * The translations of `items` in the entry being edited: what its translations
 * have saved, and what still waits for blocks that were pasted but not saved.
 * Empty for anything but the canonical entry of an overlay family.
 */
export async function loadBlockTranslations(
  entry: Entry | null | undefined,
  items: Record<string, unknown>[],
  fetchSaved: (contentId: string, blockIds: string[]) => Promise<BlockTranslations>
): Promise<BlockTranslations> {
  const waiting = await pendingBlockTranslations(items)
  const saved = items.flatMap((item) => (isBlock(item) && !waiting[item.id] ? [item.id] : []))

  if (!entry?.id || entry.i18n_parent_id || entry.effective_i18n_mode !== 'overlay') return waiting
  if (saved.length === 0) return waiting

  return { ...(await fetchSaved(entry.id, saved)), ...waiting }
}
