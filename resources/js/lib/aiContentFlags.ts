/**
 * What AI left on entries for whoever publishes them, see `ContentFlags` on the
 * server: open conflicts, and flagged wording that is still in the draft. It
 * informs and never blocks a publish.
 */

export interface AiContentFlagCounts {
  conflicts: number
  flagged: number
}

/** Entry ID => its figures. Entries with nothing open are left out. */
export type AiContentFlagMap = Record<string, AiContentFlagCounts>

export interface AiContentFlag {
  kind: 'flagged' | 'conflict'
  path: string | null
  field: string | null
  wording?: string
  replacement?: string
  thread_id?: string | null
}

export interface AiContentFlagEntry {
  content_id: string
  name: string
  language_iso: string | null
  flags: AiContentFlag[]
}

export interface AiContentFlagTotals extends AiContentFlagCounts {
  /** How many of the entries have anything open */
  entries: number
}

export function flagTotals(flags: AiContentFlagMap | undefined, ids: readonly string[]): AiContentFlagTotals {
  const totals: AiContentFlagTotals = { conflicts: 0, flagged: 0, entries: 0 }
  if (!flags) return totals

  for (const id of ids) {
    const counts = flags[id]
    if (!counts) continue
    totals.conflicts += counts.conflicts
    totals.flagged += counts.flagged
    totals.entries += 1
  }

  return totals
}

/** "1 conflict, 2 flagged wordings": the tree mark's label and tooltip. */
export function flagCountsLine(
  t: (key: string, named: Record<string, unknown>, plural: number) => string,
  { conflicts, flagged }: AiContentFlagCounts
): string {
  return [
    ...(conflicts > 0 ? [t('components.contentFlags.conflicts', { count: conflicts }, conflicts)] : []),
    ...(flagged > 0 ? [t('components.contentFlags.flagged', { count: flagged }, flagged)] : []),
  ].join(', ')
}

/** The editor link to a flagged field; the editor opens the block and focuses the field. */
export function flagFieldLink(spaceId: string, contentId: string, path: string | null): string {
  const entry = `/${spaceId}/content/${contentId}`
  return path ? `${entry}?field=${encodeURIComponent(path)}` : entry
}

/**
 * Where a stored field path points in the editor. Stored paths name blocks by
 * ID (`body.<block id>.title`), so the last block ID is the block to open.
 */
export function flagFieldTarget(path: string, rootId: string): { itemId: string; path: string[] } {
  const segments = path.split('.')
  const field = segments[segments.length - 1]

  return segments.length < 3 ? { itemId: rootId, path: [segments[0]] } : { itemId: segments[segments.length - 2], path: [field] }
}
