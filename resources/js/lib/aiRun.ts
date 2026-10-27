/**
 * Types and wire helpers for AI runs. One discriminated event union covers the
 * inline SSE stream and the stored events a background run is followed by.
 */

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

export interface AiGlossaryFinding {
  type: 'missing_term' | 'translated_do_not_translate' | 'forbidden_variant'
  term: string
  expected?: string
  found?: string
}

export interface AiRunResult<TData = unknown> {
  text: string
  data: TData | null
  glossary_findings: AiGlossaryFinding[]
}

export interface AiRunUsage {
  steps: number
  input_tokens: number
  output_tokens: number
  cost_usd: number
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
  state: AiRunState
  mode: 'inline' | 'background'
  partial: boolean
  questionnaire: AiQuestionnaire | null
  result: AiRunResult | null
  error: string | null
  event_seq: number
  usage: AiRunUsage
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
  conversation_id?: string | null
  idempotency_key?: string
  background?: boolean
  /** A confirmed preflight selection; the run may touch only these entries. */
  targets?: { type: string; ids: string[] }
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
  | (EventBase & { type: 'glossary_findings'; findings: AiGlossaryFinding[] })
  | (EventBase & { type: 'usage' } & AiRunUsage)
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
  'glossary_findings',
  'usage',
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
