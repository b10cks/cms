/**
 * Types and wire helpers for AI runs. One discriminated event union covers the
 * inline SSE stream and the stored events a background run is followed by.
 */

import type { AiReasoningEffort } from '~/api/resources/ai'

export type AiRunState = 'running' | 'awaiting_input' | 'completed' | 'failed' | 'cancelled'

export interface AiQuestionOption {
  id: string
  label: string
  description: string | null
}

export interface AiQuestion {
  id: string
  title: string
  description: string | null
  required: boolean
  options: AiQuestionOption[]
}

export interface AiQuestionnaire {
  questions: AiQuestion[]
}

export type AiQuestionnaireAnswers = Record<string, string | null>

export interface AiRunResult<TData = unknown> {
  text: string
  data: TData | null
}

export interface AiRunUsage {
  steps: number
  input_tokens: number
  output_tokens: number
  cost_usd: number
  /** Set once known generations are reconciled; interrupted calls may still be unaccounted for */
  cost_confirmed_at?: string | null
}

export interface AiProviderFailure {
  kind: 'rate_limited' | 'unavailable' | 'connection' | 'interrupted' | 'auth' | 'quota' | 'rejected' | 'unknown'
  status: number | null
  code: string | null
}

/** The run's stored retry state. `not_before` is set while a retry is scheduled. */
export interface AiProviderRetryState {
  attempts: number
  total: number
  unconfirmed_calls: number
  waited_seconds: number
  not_before: string | null
  failure: AiProviderFailure | null
}

export interface AiSource {
  document_id: string
  revision_id: string
  title: string | null
  heading: string | null
}

export interface AiRunRecord {
  id: string
  surface: string
  skill: string | null
  model: string | null
  /** The level the run was started with; null when the config default applied */
  reasoning_effort?: AiReasoningEffort | null
  state: AiRunState
  mode: 'inline' | 'background'
  partial: boolean
  questionnaire: AiQuestionnaire | null
  result: AiRunResult | null
  error: string | null
  event_seq: number
  usage: AiRunUsage & { cached_tokens: number }
  provider_retry?: AiProviderRetryState | null
  operation?: { id: string; phase: 'plan' | 'batch' | 'review'; batch?: number } | null
  /** The thread the run belongs to */
  conversation_id?: string | null
  started_at?: string | null
  finished_at?: string | null
}

/** A changeset item a run added; review loads the changeset. */
export interface AiProposal {
  changeset_id: string
  item_id: string
  target_type: string
  target_id: string | null
  path: string | null
}

export interface StartAiRunRequest {
  surface: string
  skill?: string | null
  prompt?: string
  context?: Record<string, unknown>
  config_id?: string | null
  /** Overrides the config's reasoning level for this run */
  reasoning_effort?: AiReasoningEffort | null
  conversation_id?: string | null
  idempotency_key?: string
  background?: boolean
  /** A confirmed preflight selection; the run may touch only these entries. */
  targets?: { type: string; ids: string[] }
  /** Uploaded, unsent attachment IDs for the user message */
  attachments?: string[]
}

interface EventBase {
  run_id: string
  seq: number
}

export type AiRunEvent =
  | (EventBase & { type: 'run_started'; skill: string | null; surface: string; conversation_id?: string | null })
  | (EventBase & { type: 'text_delta'; text: string })
  | (EventBase & { type: 'tool_started'; id: string; name: string })
  | (EventBase & { type: 'tool_finished'; id: string; name: string; ok: boolean })
  | (EventBase & { type: 'source'; source: AiSource })
  | (EventBase & { type: 'proposal'; proposal: AiProposal })
  | (EventBase & { type: 'questionnaire'; questionnaire: AiQuestionnaire })
  | (EventBase & { type: 'usage' } & AiRunUsage)
  | (EventBase & {
      type: 'provider_retry_scheduled'
      attempt: number
      max_attempts: number
      retry_at: string
      failure: AiProviderFailure
      possibly_billed: boolean
      unconfirmed_calls: number
    })
  | (EventBase & { type: 'moved_to_background' })
  | (EventBase & { type: 'completed'; result: AiRunResult; partial: boolean; usage: AiRunUsage })
  | (EventBase & { type: 'error'; reason?: string; message: string })

export type AiRunEventType = AiRunEvent['type']

const EVENT_TYPES: ReadonlySet<string> = new Set<AiRunEventType>([
  'run_started',
  'text_delta',
  'tool_started',
  'tool_finished',
  'source',
  'proposal',
  'questionnaire',
  'usage',
  'provider_retry_scheduled',
  'moved_to_background',
  'completed',
  'error',
])

/** Events after which the inline stream closes. */
export const STREAM_ENDING_EVENTS: ReadonlySet<AiRunEventType> = new Set([
  'questionnaire',
  'moved_to_background',
  'completed',
  'error',
])

/** A provider wait and its exposure, from a stored run or a live event, as the editor reads it. */
export interface AiProviderRetryNotice {
  /** When the next call may go out; null when no retry is scheduled */
  retry_at: string | null
  attempt: number
  /** Only the live event carries the limit */
  max_attempts: number | null
  failure: AiProviderFailure['kind'] | null
  /** Interrupted calls whose cost may not be in the recorded spend */
  unconfirmed_calls: number
  /** Retries over the whole run or operation; zero when not known */
  retries: number
  cost_status: AiRunCostStatus
}

/**
 * `estimated` until reconciled. A confirmed time covers known generations only:
 * a call that failed before its first chunk has no ID, so with unconfirmed calls
 * the run never reads as fully confirmed.
 */
export type AiRunCostStatus = 'estimated' | 'confirmed' | 'confirmed_known_calls'

export function runCostStatus(run: Pick<AiRunRecord, 'usage' | 'provider_retry'>): AiRunCostStatus {
  if (!run.usage.cost_confirmed_at) return 'estimated'
  return (run.provider_retry?.unconfirmed_calls ?? 0) > 0 ? 'confirmed_known_calls' : 'confirmed'
}

/** Null when the run has neither a scheduled retry nor possibly unrecorded cost. */
export function retryNoticeOfRun(run: Pick<AiRunRecord, 'usage' | 'provider_retry' | 'state'>): AiProviderRetryNotice | null {
  const retry = run.provider_retry
  if (!retry) return null

  const retry_at = run.state === 'running' ? retry.not_before : null
  if (!retry_at && retry.unconfirmed_calls === 0) return null

  return {
    retry_at,
    attempt: retry.attempts,
    max_attempts: null,
    failure: retry.failure?.kind ?? null,
    unconfirmed_calls: retry.unconfirmed_calls,
    retries: retry.total,
    cost_status: runCostStatus(run),
  }
}

export function retryNoticeOfEvent(event: Extract<AiRunEvent, { type: 'provider_retry_scheduled' }>): AiProviderRetryNotice {
  return {
    retry_at: event.retry_at,
    attempt: event.attempt,
    max_attempts: event.max_attempts,
    failure: event.failure.kind,
    unconfirmed_calls: event.unconfirmed_calls,
    retries: 0,
    cost_status: 'estimated',
  }
}

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

/**
 * Narrow an untrusted payload to a run event. An `error` written by the
 * transport before the run existed carries no `run_id`/`seq`; it gets seq 0.
 */
export function toAiRunEvent(value: unknown): AiRunEvent | null {
  if (!isRecord(value) || typeof value.type !== 'string' || !EVENT_TYPES.has(value.type)) {
    return null
  }

  if (value.type === 'error') {
    return {
      ...value,
      type: 'error',
      run_id: typeof value.run_id === 'string' ? value.run_id : '',
      seq: typeof value.seq === 'number' ? value.seq : 0,
      message: typeof value.message === 'string' ? value.message : 'Unknown error',
    } as AiRunEvent
  }

  if (typeof value.run_id !== 'string' || typeof value.seq !== 'number') {
    return null
  }

  return value as unknown as AiRunEvent
}

export function parseAiRunEventLine(line: string): AiRunEvent | null {
  if (!line.startsWith('data: ')) return null

  try {
    return toAiRunEvent(JSON.parse(line.slice(6)))
  } catch {
    return null
  }
}

/** Read an SSE body and hand each run event over in order. */
export async function readAiRunEvents(
  reader: ReadableStreamDefaultReader<Uint8Array>,
  onEvent: (event: AiRunEvent) => void
): Promise<void> {
  const decoder = new TextDecoder()
  let buffer = ''

  try {
    while (true) {
      const { done, value } = await reader.read()
      buffer += done ? '' : decoder.decode(value, { stream: true })
      const lines = buffer.split('\n')
      buffer = done ? '' : (lines.pop() ?? '')

      for (const line of lines) {
        const event = parseAiRunEventLine(line)
        if (event) onEvent(event)
      }

      if (done) return
    }
  } finally {
    void reader.cancel().catch(() => {})
    reader.releaseLock()
  }
}

export const aiRunUrl = (spaceId: string, path = '') =>
  `/mgmt/v1/spaces/${spaceId}/ai/runs${path}`
