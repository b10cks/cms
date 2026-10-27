/**
 * The AI dock's state, shared by the header button, Cmd+K, feature buttons and
 * the dock itself: open or not, its width, the active thread and a draft that
 * starts a new thread. The dock is mounted once in the space layout.
 */
import { useLocalStorage } from '@vueuse/core'
import { computed, reactive, readonly, type InjectionKey } from 'vue'

import type { useAiRun } from '~/composables/useAiRun'
import type { AiAttachment } from '~/lib/aiAttachments'
import type { AiThreadChip, AiThreadStep } from '~/lib/aiThreads'

export const DOCK_MIN_WIDTH = 360
export const DOCK_MAX_WIDTH = 760
export const DOCK_DEFAULT_WIDTH = 440

/** What a new thread starts with. Without `context` the dock attaches the current screen. */
export interface AiDockDraft {
  prompt: string
  skill: string | null
  context?: AiThreadChip[]
  /** Send right away instead of waiting in the composer */
  send: boolean
}

/** The turn the shared run is streaming; its thread is null until the server names it. */
export interface AiLiveTurn {
  threadId: string | null
  prompt: string
  attachments: AiAttachment[]
  skill: string | null
  steps: AiThreadStep[]
}

interface AiDockState {
  open: boolean
  /** Null shows the thread list, or a new thread while a draft waits */
  threadId: string | null
  /** A new thread is open in the composer */
  composing: boolean
  draft: AiDockDraft | null
  live: AiLiveTurn | null
}

const state = reactive<AiDockState>({ open: false, threadId: null, composing: false, draft: null, live: null })

const storedWidth = useLocalStorage('b10cks:ai-dock-width', DOCK_DEFAULT_WIDTH)

export const clampDockWidth = (width: number) =>
  Math.round(Math.min(DOCK_MAX_WIDTH, Math.max(DOCK_MIN_WIDTH, width)))

export const aiDock = {
  state: readonly(state),
  width: computed({
    get: () => clampDockWidth(storedWidth.value),
    set: (width: number) => {
      storedWidth.value = clampDockWidth(width)
    },
  }),
}

export function openDock(threadId?: string) {
  state.open = true
  if (threadId === undefined) return
  state.threadId = threadId
  state.composing = false
}

export function closeDock() {
  state.open = false
}

export function toggleDock() {
  state.open = !state.open
}

/** Show a thread, or the list with null. */
export function selectThread(threadId: string | null) {
  state.threadId = threadId
  state.composing = false
  state.draft = null
}

/** Open the dock on a new thread; `send` starts the run at once. */
export function startThread(draft: Partial<AiDockDraft> = {}) {
  state.threadId = null
  state.composing = true
  state.draft = { prompt: draft.prompt ?? '', skill: draft.skill ?? null, context: draft.context, send: draft.send ?? false }
  state.open = true
}

/** Hand the draft to the composer once. */
export function takeDraft(): AiDockDraft | null {
  const draft = state.draft
  state.draft = null
  return draft
}

/** A new thread got its ID from the server; the dock follows it if it still shows the new thread. */
export function threadCreated(threadId: string) {
  if (state.live?.threadId === null) state.live.threadId = threadId
  if (!state.composing) return
  state.threadId = threadId
  state.composing = false
}

export function beginLiveTurn(turn: Omit<AiLiveTurn, 'steps'>) {
  state.live = { ...turn, steps: [] }
}

export function trackLiveStep(step: { id: string; name: string }, finished?: { ok: boolean }) {
  const steps = state.live?.steps
  if (!steps) return
  const existing = steps.find((candidate) => candidate.id === step.id)
  if (!existing) steps.push({ ...step, done: !!finished, ok: finished?.ok ?? true })
  else if (finished) Object.assign(existing, { done: true, ok: finished.ok })
}

export function endLiveTurn() {
  state.live = null
}

/** A space switch starts from the thread list. */
export function resetDock() {
  state.threadId = null
  state.composing = false
  state.draft = null
  state.live = null
}

/** The run the dock and the threads page share, provided by the space layout so it outlives both. */
export const aiThreadRunKey: InjectionKey<ReturnType<typeof useAiRun>> = Symbol('aiThreadRun')
