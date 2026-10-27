import { beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import {
  aiDock,
  beginLiveTurn,
  closeDock,
  DOCK_MAX_WIDTH,
  DOCK_MIN_WIDTH,
  resetDock,
  selectThread,
  startThread,
  takeDraft,
  threadCreated,
} from '~/lib/aiDock'

beforeEach(() => {
  resetDock()
  closeDock()
})

describe('aiDock', () => {
  it('opens on a new thread whose draft the composer takes once', () => {
    startThread({ prompt: 'Shorten the intro', skill: 'rewrite', send: true })

    expect(aiDock.state).toMatchObject({ open: true, threadId: null, composing: true })
    expect(takeDraft()).toEqual({ prompt: 'Shorten the intro', skill: 'rewrite', context: undefined, send: true })
    expect(takeDraft()).toBeNull()
  })

  it('follows a new thread to the ID the server gave it', () => {
    startThread()
    beginLiveTurn({ threadId: null, prompt: 'Translate', attachments: [], skill: null })
    threadCreated('thread-1')

    expect(aiDock.state).toMatchObject({ threadId: 'thread-1', composing: false })
    expect(aiDock.state.live?.threadId).toBe('thread-1')
  })

  it('stays on the thread the user moved to while a new one started', () => {
    startThread()
    beginLiveTurn({ threadId: null, prompt: 'Translate', attachments: [], skill: null })
    selectThread('thread-other')
    threadCreated('thread-1')

    expect(aiDock.state.threadId).toBe('thread-other')
    expect(aiDock.state.live?.threadId).toBe('thread-1')
  })

  it('keeps the width within bounds and remembers it', async () => {
    aiDock.width.value = 10_000
    expect(aiDock.width.value).toBe(DOCK_MAX_WIDTH)

    aiDock.width.value = 0
    expect(aiDock.width.value).toBe(DOCK_MIN_WIDTH)

    aiDock.width.value = 512
    await nextTick()
    expect(window.localStorage.getItem('b10cks:ai-dock-width')).toBe('512')
  })
})
