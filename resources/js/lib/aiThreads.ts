/**
 * Types and helpers for AI threads: every conversation and run lives in one,
 * listed in the dock and on the threads page. Status is derived on the server.
 */
import type { AiGlossaryResource } from '~/api/resources/ai-knowledge'
import type { AiAttachment } from '~/lib/aiAttachments'
import type { AiChangeset, AiChangesetItem, AiGlossarySuggestion } from '~/lib/aiChangesets'
import type { AiRunRecord, AiSource } from '~/lib/aiRun'

export type AiThreadKind = 'chat' | 'schedule' | 'suggestions'

export type AiThreadStatus = 'needs_input' | 'needs_review' | 'running' | 'failed' | 'done'

/** Something a thread works on: the screen it started from or a mention. */
export interface AiThreadChip {
  type: 'content' | 'field' | 'data_source' | 'asset' | 'asset_folder' | 'block' | 'document'
  /** The entry's id for `field` chips */
  id: string
  label: string
  /** Path of the focused field inside the entry, `field` chips only */
  field?: string
}

/** Chip types that name a page or a thing on it, as opposed to attached knowledge. */
export const SCREEN_CHIP_TYPES: ReadonlySet<AiThreadChip['type']> = new Set([
  'content',
  'field',
  'data_source',
  'asset',
  'asset_folder',
  'block',
])

export interface AiThread {
  id: string
  kind: AiThreadKind
  title: string
  status: AiThreadStatus
  shared: boolean
  quiet: boolean
  archived: boolean
  last_activity_at: string | null
  owner: { id: string; name: string } | null
  is_owner: boolean
  /** Entries with pending items */
  entry_count: number
  /** The chips the thread started with, the row's context line */
  context?: AiThreadChip[] | null
  /** The skill of the first run */
  skill?: string | null
}

/** A tool call that got its result, read back from the run's transcript. */
export interface AiRunStep {
  id: string
  name: string
  ok: boolean
}

/** A tool call of the live run, shown while it streams. */
export interface AiThreadStep extends AiRunStep {
  done: boolean
}

export interface AiThreadTurn {
  run: AiRunRecord
  prompt: string | null
  answer: string | null
  changeset: AiChangeset | null
  attachments: AiAttachment[]
  steps: AiRunStep[]
  answers: AiThreadAnswer[]
  sources: AiThreadSource[]
}

/** An answer to a question the run asked; null when skipped. */
export interface AiThreadAnswer {
  question: string
  answer: string | null
}

/** A knowledge source of a stored turn; scope is null once the document is gone. */
export interface AiThreadSource extends AiSource {
  scope: 'space' | 'team' | 'system' | null
  team_id: string | null
}

export interface AiThreadDetail extends AiThread {
  context: AiThreadChip[] | null
  turns: AiThreadTurn[]
  /** Suggestions thread only */
  suggestions?: AiGlossarySuggestion[]
  /** Glossaries the user may accept suggestions into; suggestions thread only */
  glossaries?: AiGlossaryResource[]
}

export interface AiThreadList {
  data: AiThread[]
  /** `settled` counts the user's visible settled threads */
  meta: { attention: number; settled: number }
}

export interface AiThreadListParams {
  archived: boolean
  search: string
}

export interface AiThreadPatch {
  title?: string
  shared?: boolean
  archived?: boolean
}

/** States that put a thread on top of the list and raise a toast. */
export const ATTENTION_STATUSES: ReadonlySet<AiThreadStatus> = new Set(['needs_input', 'needs_review'])

const TOAST_STATUSES: ReadonlySet<AiThreadStatus> = new Set(['needs_input', 'needs_review', 'failed'])

/** A turn touching more entries than this renders one summary card. */
export const SUMMARY_ENTRY_LIMIT = 5

/** Icon and text color of a status, as the list row and thread header show it. */
export const THREAD_STATUS_STYLES = {
  needs_input: { icon: 'lucide:message-circle-question', class: 'text-ai' },
  needs_review: { icon: 'lucide:shield-question', class: 'text-warning' },
  running: { icon: 'lucide:circle-dashed', class: 'text-info' },
  failed: { icon: 'lucide:circle-alert', class: 'text-destructive' },
  done: { icon: 'lucide:circle-check', class: 'text-success' },
} as const satisfies Record<AiThreadStatus, { icon: string; class: string }>

/** A running thread's elapsed time, short: `45s`, `12m`, `2h`. */
export function formatElapsed(milliseconds: number): string {
  const seconds = Math.max(0, Math.floor(milliseconds / 1000))
  if (seconds < 60) return `${seconds}s`
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m`
  return `${Math.floor(minutes / 60)}h`
}

/** The user's own and unowned threads, and the ones others shared. */
/**
 * Splits the list for the dock: threads about the current screen first, then
 * the user's own (and the space's non-chat threads), then others' shared chats.
 * A thread is about the screen when one of its chips names the same thing; a
 * field chip counts as its entry.
 */
export function groupThreads(threads: readonly AiThread[], screen: readonly AiThreadChip[] = []) {
  const screenIds = new Set(screen.map((chip) => chip.id))
  const onScreen = (thread: AiThread) =>
    thread.context?.some((chip) => SCREEN_CHIP_TYPES.has(chip.type) && screenIds.has(chip.id)) ?? false

  const page = threads.filter(onScreen)
  const rest = threads.filter((thread) => !onScreen(thread))
  return {
    page,
    mine: rest.filter((thread) => thread.is_owner || thread.kind !== 'chat'),
    shared: rest.filter((thread) => !thread.is_owner && thread.kind === 'chat'),
  }
}

/**
 * Threads that moved into a toast-worthy status since `previous` was taken.
 * Threads first seen after the baseline count; quiet threads and the open one don't.
 */
export function attentionTransitions(
  previous: ReadonlyMap<string, AiThreadStatus>,
  threads: readonly AiThread[],
  openThreadId: string | null
): AiThread[] {
  return threads.filter(
    (thread) =>
      !thread.quiet &&
      !thread.archived &&
      thread.id !== openThreadId &&
      TOAST_STATUSES.has(thread.status) &&
      previous.get(thread.id) !== thread.status
  )
}

/** The run context for the chips a thread works on. */
export function chipsToRunContext(chips: readonly AiThreadChip[]): Record<string, unknown> {
  const content = chips.find((chip) => chip.type === 'content')
  const dataSource = chips.find((chip) => chip.type === 'data_source')

  return {
    ...(content ? { content_id: content.id } : {}),
    ...(dataSource ? { data_source_id: dataSource.id } : {}),
    chips,
  }
}

export const sameChip = (a: AiThreadChip, b: AiThreadChip) =>
  a.type === b.type && a.id === b.id && a.field === b.field

export interface AiItemLocales {
  source: string
  target: string
}

/**
 * The locales of a cross-locale item. Items that name their locales win;
 * otherwise the path tells: asset fields are `fields.<locale>.<field>`, data
 * entry translations `dimensions.<locale>`. Null when the item stays in the
 * source locale.
 */
export function itemLocales(item: AiChangesetItem, defaultLocale: string): AiItemLocales | null {
  const segments = (item.path ?? '').split('.')
  const source = item.source_locale ?? defaultLocale
  const target =
    item.target_locale ??
    (item.target_type === 'asset' && segments[0] === 'fields'
      ? segments[1]
      : item.target_type === 'data_entry' && segments[0] === 'dimensions'
        ? segments[1]
        : undefined)

  if (!target || target.toLowerCase() === source.toLowerCase()) return null
  return { source, target }
}
