/**
 * The inbox as one list: proposed changesets, glossary suggestions and the
 * actor's background runs, newest first, each with the filter it belongs to.
 */
import type { AiChangeset, AiGlossarySuggestion, AiInbox } from '~/lib/aiChangesets'
import type { AiRunRecord } from '~/lib/aiRun'

export const AI_INBOX_FILTERS = ['all', 'changes', 'suggestions', 'questions', 'failed'] as const

export type AiInboxFilter = (typeof AI_INBOX_FILTERS)[number]

export type AiInboxItem =
  | { kind: 'changeset'; id: string; at: string | null; filter: 'changes'; changeset: AiChangeset }
  | { kind: 'suggestion'; id: string; at: string | null; filter: 'suggestions'; suggestion: AiGlossarySuggestion }
  | { kind: 'run'; id: string; at: string | null; filter: 'questions' | 'failed' | null; run: AiRunRecord }

const runFilter = (run: AiRunRecord) =>
  run.state === 'awaiting_input' ? ('questions' as const) : run.state === 'failed' ? ('failed' as const) : null

export function aiInboxItems(inbox: AiInbox | undefined): AiInboxItem[] {
  if (!inbox) return []

  const items: AiInboxItem[] = [
    ...inbox.changesets.map((changeset) => ({
      kind: 'changeset' as const,
      id: changeset.id,
      at: changeset.created_at,
      filter: 'changes' as const,
      changeset,
    })),
    ...inbox.suggestions.map((suggestion) => ({
      kind: 'suggestion' as const,
      id: suggestion.id,
      at: suggestion.created_at,
      filter: 'suggestions' as const,
      suggestion,
    })),
    ...inbox.runs.map((run) => ({
      kind: 'run' as const,
      id: run.id,
      at: run.finished_at ?? run.started_at ?? null,
      filter: runFilter(run),
      run,
    })),
  ]

  return items.sort((a, b) => (b.at ?? '').localeCompare(a.at ?? ''))
}

export const filterAiInboxItems = (items: readonly AiInboxItem[], filter: AiInboxFilter) =>
  filter === 'all' ? [...items] : items.filter((item) => item.filter === filter)
