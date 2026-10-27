import { api } from '~/api'
import type {
  AiGlossaryFinding,
  AiQuestionnaire,
  AiQuestionnaireAnswers,
  AiRunEvent,
  AiRunRecord,
  AiProposal,
  AiRunResult,
  AiSource,
  StartAiRunRequest,
} from '~/lib/aiRun'
import { aiRunUrl, readAiRunEvents, toAiRunEvent } from '~/lib/aiRun'
import { ensureCsrfToken, getXsrfHeaders } from '~/lib/csrf'
import { parseStreamErrorResponse } from '~/lib/sse'

export type AiRunStatus =
  | 'idle'
  | 'running'
  | 'background'
  | 'awaiting_input'
  | 'completed'
  | 'failed'
  | 'cancelled'

export interface AiRunError {
  message: string
  reason?: string
}

export interface AiRunOutcome<TData = unknown> {
  status: 'completed' | 'failed' | 'cancelled'
  result: AiRunResult<TData> | null
  partial: boolean
  error: AiRunError | null
}

export interface AiRunOptions {
  /** Every event in order, inline or caught up from the background. */
  onEvent?: (event: AiRunEvent) => void
}

const FOLLOW_INTERVAL = 4000

/**
 * One AI run at a time: starts it over SSE, follows it on the queue when it
 * moves to the background (Reverb nudges plus catch-up by `seq`), pauses for
 * questionnaires and resumes with answers. `start()` resolves once the run
 * completes, fails or is cancelled.
 */
export function useAiRun(spaceId: MaybeRefOrGetter<string>) {
  const { t } = useI18n()
  const { user } = useAuth()

  const runId = ref<string | null>(null)
  const status = ref<AiRunStatus>('idle')
  const text = ref('')
  const activeTool = ref<string | null>(null)
  const questionnaire = shallowRef<AiQuestionnaire | null>(null)
  const result = shallowRef<AiRunResult | null>(null)
  const partial = ref(false)
  const findings = shallowRef<AiGlossaryFinding[]>([])
  const sources = shallowRef<AiSource[]>([])
  const proposals = shallowRef<AiProposal[]>([])
  const error = shallowRef<AiRunError | null>(null)

  let lastSeq = 0
  let options: AiRunOptions = {}
  let controller: AbortController | null = null
  let settle: ((outcome: AiRunOutcome) => void) | null = null
  let stopFollowing: (() => void) | null = null

  const isActive = computed(() => status.value === 'running' || status.value === 'background')

  const finish = (outcome: AiRunOutcome['status']) => {
    status.value = outcome
    activeTool.value = null
    stopFollowing?.()
    settle?.({ status: outcome, result: result.value, partial: partial.value, error: error.value })
    settle = null
  }

  const apply = (event: AiRunEvent) => {
    if (event.seq > 0) {
      if (event.seq <= lastSeq) return
      lastSeq = event.seq
    }
    if (event.run_id) runId.value = event.run_id

    options.onEvent?.(event)

    switch (event.type) {
      case 'run_started':
        status.value = 'running'
        break
      case 'text_delta':
        text.value += event.text
        break
      case 'tool_started':
        activeTool.value = event.name
        break
      case 'tool_finished':
        activeTool.value = null
        break
      case 'source':
        sources.value = [...sources.value, event.source]
        break
      case 'glossary_findings':
        findings.value = event.findings
        break
      case 'proposal':
        proposals.value = [...proposals.value, event.proposal]
        break
      case 'questionnaire':
        questionnaire.value = event.questionnaire
        status.value = 'awaiting_input'
        break
      case 'moved_to_background':
        status.value = 'background'
        follow()
        break
      case 'completed':
        result.value = event.result
        partial.value = event.partial
        findings.value = event.result.glossary_findings
        finish('completed')
        break
      case 'error':
        error.value = { message: event.message, reason: event.reason }
        finish(event.reason === 'cancelled' ? 'cancelled' : 'failed')
        break
    }
  }

  /** Settle from the stored run when its terminal event was not seen. */
  const reconcile = (run: AiRunRecord) => {
    if (!settle) return

    if (run.state === 'awaiting_input' && run.questionnaire) {
      questionnaire.value = run.questionnaire
      status.value = 'awaiting_input'
      stopFollowing?.()
    } else if (run.state === 'completed') {
      result.value = run.result
      partial.value = run.partial
      findings.value = run.result?.glossary_findings ?? []
      finish('completed')
    } else if (run.state === 'failed' || run.state === 'cancelled') {
      error.value = error.value ?? { message: t('composables.aiRun.failed'), reason: run.error ?? undefined }
      finish(run.state)
    }
  }

  const catchUp = async () => {
    const id = runId.value
    if (!id) return

    const response = await api.forSpace(toValue(spaceId)).ai.getRunEvents(id, lastSeq)
    for (const raw of response.data) {
      const event = toAiRunEvent(raw)
      if (event) apply(event)
    }
    reconcile(response.run)
  }

  function follow() {
    if (stopFollowing) return

    const channel = user.value?.id ? `App.Models.User.${user.value.id}` : null
    const echo = channel ? useEcho() : null
    const onBroadcast = (payload: { run_id?: string }) => {
      if (payload.run_id === runId.value) void catchUp().catch(() => {})
    }

    echo?.private(channel ?? '').listen('.ai_run:events', onBroadcast)
    const timer = setInterval(() => void catchUp().catch(() => {}), FOLLOW_INTERVAL)
    void catchUp().catch(() => {})

    stopFollowing = () => {
      clearInterval(timer)
      if (channel) echo?.private(channel).stopListening('.ai_run:events', onBroadcast)
      stopFollowing = null
    }
  }

  const send = async (url: string, body: unknown) => {
    await ensureCsrfToken()
    controller = new AbortController()

    try {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'text/event-stream',
          ...getXsrfHeaders(),
        },
        body: JSON.stringify(body),
        signal: controller.signal,
        credentials: 'include',
      })

      if (!response.ok) {
        const { message, reason } = await parseStreamErrorResponse(response)
        error.value = { message, reason }
        finish('failed')
        return
      }

      // A repeated idempotency key, a background start or a background resume
      // answers with the run as JSON instead of a stream.
      if (response.headers.get('Content-Type')?.includes('application/json')) {
        const { data } = (await response.json()) as { data: AiRunRecord }
        runId.value = data.id
        status.value = data.mode === 'background' ? 'background' : status.value
        follow()
        return
      }

      const reader = response.body?.getReader()
      if (!reader) throw new Error('No response body')
      await readAiRunEvents(reader, apply)

      // The stream ended without a terminal event: follow the stored run.
      if (settle && status.value === 'running') follow()
    } catch (caught: unknown) {
      if (caught instanceof DOMException && caught.name === 'AbortError') return
      error.value = { message: caught instanceof Error ? caught.message : String(caught) }
      finish('failed')
    } finally {
      controller = null
    }
  }

  const reset = () => {
    stopFollowing?.()
    controller?.abort()
    runId.value = null
    status.value = 'idle'
    text.value = ''
    activeTool.value = null
    questionnaire.value = null
    result.value = null
    partial.value = false
    findings.value = []
    sources.value = []
    proposals.value = []
    error.value = null
    lastSeq = 0
  }

  const start = <TData = unknown>(
    request: StartAiRunRequest,
    runOptions: AiRunOptions = {}
  ): Promise<AiRunOutcome<TData>> => {
    settle?.({ status: 'cancelled', result: null, partial: false, error: null })
    reset()
    options = runOptions
    status.value = 'running'

    const outcome = new Promise<AiRunOutcome>((resolve) => {
      settle = resolve
    })

    void send(aiRunUrl(toValue(spaceId)), {
      idempotency_key: crypto.randomUUID(),
      ...request,
    })

    return outcome as Promise<AiRunOutcome<TData>>
  }

  /** Answer the pending questionnaire; the run continues and `start()` resolves later. */
  const answer = async (answers: AiQuestionnaireAnswers) => {
    const id = runId.value
    if (!id || status.value !== 'awaiting_input') return

    questionnaire.value = null
    status.value = 'running'
    await send(aiRunUrl(toValue(spaceId), `/${id}/answers`), { answers })
  }

  const cancel = async () => {
    const id = runId.value
    const wasPending = settle !== null
    controller?.abort()
    stopFollowing?.()

    if (wasPending) finish('cancelled')
    if (id) await api.forSpace(toValue(spaceId)).ai.cancelRun(id).catch(() => {})
  }

  if (getCurrentScope()) {
    onScopeDispose(() => {
      // Leaving the screen ends an inline run (its stream closes) but never a
      // background one.
      controller?.abort()
      stopFollowing?.()
    })
  }

  return {
    runId: readonly(runId),
    status: readonly(status),
    isActive,
    text: readonly(text),
    activeTool: readonly(activeTool),
    questionnaire,
    result,
    partial: readonly(partial),
    findings,
    sources,
    proposals,
    error,
    start,
    answer,
    cancel,
  }
}
