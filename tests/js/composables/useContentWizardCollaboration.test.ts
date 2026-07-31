import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref, unref, type Ref } from 'vue'

import type { ContentWizardSyncOperation } from '~/types/content-wizard'

import { getPresenceColor } from '~/components/ui/presence-colors'

import { createPresenceController, presenceUser, type PresenceController } from '../support/presence'
import { withSetup, type Harness } from '../support/harness'

let presence: PresenceController
let channelArgs: unknown[]

vi.mock('~/composables/usePresence', async () => {
  const actual = await vi.importActual<typeof import('~/composables/usePresence')>(
    '~/composables/usePresence'
  )

  return {
    ...actual,
    usePresence: (...args: unknown[]) => {
      channelArgs = args
      return presence
    },
  }
})

const { useContentWizardCollaboration } = await import('~/composables/useContentWizardCollaboration')

const FOCUS_EVENT = 'content-canvas-focus'
const CURSOR_EVENT = 'content-canvas-cursor'
const OPERATION_EVENT = 'content-canvas-operation'

type Collaboration = ReturnType<typeof useContentWizardCollaboration>

// Explicit, not ReturnType<typeof setup> — that is circular and TS would widen
// the composable surface to `any`.
let harness: Harness<Collaboration> | undefined

const setup = (spaceId: string | Ref<string | null> = 'space-1') => {
  harness = withSetup(() => useContentWizardCollaboration(spaceId))

  return harness.result
}

const channelName = () => unref(channelArgs[0] as Ref<string | null>)

const sentOf = (event: string) => presence.sent.filter((entry) => entry.event === event)

const addOperation = (nodeId: string): ContentWizardSyncOperation => ({
  type: 'add',
  nodeId,
  parentId: null,
  blockId: 'block-page',
  title: nodeId,
  slug: nodeId,
  slugMode: 'auto',
})

beforeEach(() => {
  presence = createPresenceController('me')
  channelArgs = []
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
  vi.useRealTimers()
})

describe('channel', () => {
  it('joins the space canvas presence channel', () => {
    setup('space-1')

    expect(channelName()).toBe('presence-spaces.space-1.content-canvas')
  })

  it('joins nothing without a space', () => {
    setup(ref(null))

    expect(channelName()).toBeNull()
  })

  it('follows a changing space id', async () => {
    const spaceId = ref<string | null>('space-1')
    setup(spaceId)

    spaceId.value = 'space-2'
    await nextTick()

    expect(channelName()).toBe('presence-spaces.space-2.content-canvas')
  })
})

describe('collaborators', () => {
  it('gives every peer a stable presence colour derived from the user id', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const result = setup()

    expect(result.collaborators.value.map((user) => user.id)).toEqual(['me', 'peer'])
    expect(result.collaborators.value[1].color).toBe(getPresenceColor('peer').value)
    expect(result.collaborators.value[1].colorLabel).toBe(getPresenceColor('peer').label)
  })

  it('exposes the current user and connection state from presence', () => {
    const result = setup()

    expect(result.currentUser.value?.id).toBe('me')
    expect(result.isConnected.value).toBe(true)
  })
})

describe('broadcasting', () => {
  it('whispers the focused node with the sender id', () => {
    setup().broadcastFocus('node-1')

    expect(sentOf(FOCUS_EVENT)).toEqual([
      { event: FOCUS_EVENT, payload: { nodeId: 'node-1', userId: 'me' } },
    ])
  })

  it('whispers a null node to give up focus', () => {
    setup().broadcastFocus(null)

    expect(sentOf(FOCUS_EVENT)[0].payload).toEqual({ nodeId: null, userId: 'me' })
  })

  it('whispers cursor coordinates as visible', () => {
    setup().broadcastCursor({ x: 12, y: 34 })

    expect(sentOf(CURSOR_EVENT)[0].payload).toEqual({
      userId: 'me',
      x: 12,
      y: 34,
      visible: true,
    })
  })

  it('whispers the origin and visible:false to hide the cursor', () => {
    setup().broadcastCursor(null)

    expect(sentOf(CURSOR_EVENT)[0].payload).toEqual({
      userId: 'me',
      x: 0,
      y: 0,
      visible: false,
    })
  })

  it('whispers a sync operation verbatim', () => {
    const operation = addOperation('node-1')

    setup().broadcastOperation(operation)

    expect(sentOf(OPERATION_EVENT)[0].payload).toEqual({ operation, userId: 'me' })
  })

  it('stays silent while there is no authenticated user', () => {
    const result = setup()
    ;(presence.currentUser as Ref<unknown>).value = null

    result.broadcastFocus('node-1')
    result.broadcastCursor({ x: 1, y: 1 })
    result.broadcastOperation(addOperation('node-1'))

    expect(presence.sent).toEqual([])
  })
})

describe('remote focus', () => {
  const withPeer = () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    return setup()
  }

  it('groups a peer under the node it focused', () => {
    const result = withPeer()

    presence.fire(FOCUS_EVENT, { nodeId: 'node-1', userId: 'peer' })

    expect(result.focusedUsersByNodeId.value['node-1'].map((user) => user.id)).toEqual(['peer'])
  })

  it('ignores the echo of the local user own focus', () => {
    const result = withPeer()

    presence.fire(FOCUS_EVENT, { nodeId: 'node-1', userId: 'me' })

    expect(result.focusedUsersByNodeId.value).toEqual({})
  })

  it('ignores a focus from a user not in the presence list', () => {
    const result = withPeer()

    presence.fire(FOCUS_EVENT, { nodeId: 'node-1', userId: 'stranger' })

    expect(result.focusedUsersByNodeId.value).toEqual({})
  })

  it('ignores an empty payload', () => {
    const result = withPeer()

    presence.fire(FOCUS_EVENT, null)

    expect(result.focusedUsersByNodeId.value).toEqual({})
  })

  it('moves a peer from one node to another rather than duplicating it', () => {
    const result = withPeer()

    presence.fire(FOCUS_EVENT, { nodeId: 'node-1', userId: 'peer' })
    presence.fire(FOCUS_EVENT, { nodeId: 'node-2', userId: 'peer' })

    expect(result.focusedUsersByNodeId.value['node-1']).toBeUndefined()
    expect(result.focusedUsersByNodeId.value['node-2']).toHaveLength(1)
  })

  it('drops a peer from the grouping when it blurs', () => {
    const result = withPeer()

    presence.fire(FOCUS_EVENT, { nodeId: 'node-1', userId: 'peer' })
    presence.fire(FOCUS_EVENT, { nodeId: null, userId: 'peer' })

    expect(result.focusedUsersByNodeId.value).toEqual({})
  })

  it('lists several peers focused on the same node', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer'), presenceUser('other')])
    const result = setup()

    presence.fire(FOCUS_EVENT, { nodeId: 'node-1', userId: 'peer' })
    presence.fire(FOCUS_EVENT, { nodeId: 'node-1', userId: 'other' })

    expect(result.focusedUsersByNodeId.value['node-1'].map((user) => user.id)).toEqual([
      'peer',
      'other',
    ])
  })
})

describe('remote cursors', () => {
  const withPeer = () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    return setup()
  }

  it('exposes a peer cursor stamped with the receive time', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-29T12:00:00.000Z'))
    const result = withPeer()

    presence.fire(CURSOR_EVENT, { userId: 'peer', x: 10, y: 20, visible: true })

    expect(result.visibleRemoteCursors.value).toHaveLength(1)
    expect(result.visibleRemoteCursors.value[0]).toMatchObject({
      userId: 'peer',
      x: 10,
      y: 20,
      updatedAt: Date.parse('2026-07-29T12:00:00.000Z'),
    })
    expect(result.visibleRemoteCursors.value[0].user.id).toBe('peer')
  })

  it('hides a cursor whispered as not visible', () => {
    const result = withPeer()

    presence.fire(CURSOR_EVENT, { userId: 'peer', x: 10, y: 20, visible: true })
    presence.fire(CURSOR_EVENT, { userId: 'peer', x: 10, y: 20, visible: false })

    expect(result.visibleRemoteCursors.value).toEqual([])
  })

  it('ignores the local user own cursor', () => {
    const result = withPeer()

    presence.fire(CURSOR_EVENT, { userId: 'me', x: 1, y: 1, visible: true })

    expect(result.visibleRemoteCursors.value).toEqual([])
  })

  it('drops a cursor from an unknown user', () => {
    const result = withPeer()

    presence.fire(CURSOR_EVENT, { userId: 'stranger', x: 1, y: 1, visible: true })

    expect(result.visibleRemoteCursors.value).toEqual([])
  })

  it('keeps only the latest position per peer', () => {
    const result = withPeer()

    presence.fire(CURSOR_EVENT, { userId: 'peer', x: 1, y: 1, visible: true })
    presence.fire(CURSOR_EVENT, { userId: 'peer', x: 9, y: 9, visible: true })

    expect(result.visibleRemoteCursors.value).toHaveLength(1)
    expect(result.visibleRemoteCursors.value[0].x).toBe(9)
  })
})

describe('leaving users', () => {
  it('forgets the focus and cursor of a peer that left', async () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])
    const result = setup()

    presence.fire(FOCUS_EVENT, { nodeId: 'node-1', userId: 'peer' })
    presence.fire(CURSOR_EVENT, { userId: 'peer', x: 1, y: 1, visible: true })

    presence.setUsers([presenceUser('me')])
    await nextTick()

    expect(result.focusedUsersByNodeId.value).toEqual({})
    expect(result.visibleRemoteCursors.value).toEqual([])
  })

  it('keeps the state of peers that stayed', async () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer'), presenceUser('other')])
    const result = setup()

    presence.fire(FOCUS_EVENT, { nodeId: 'node-1', userId: 'peer' })
    presence.fire(FOCUS_EVENT, { nodeId: 'node-2', userId: 'other' })

    presence.setUsers([presenceUser('me'), presenceUser('peer')])
    await nextTick()

    expect(Object.keys(result.focusedUsersByNodeId.value)).toEqual(['node-1'])
  })
})

describe('onOperation', () => {
  it('delivers a peer operation without its envelope', () => {
    const received: ContentWizardSyncOperation[] = []
    const operation = addOperation('node-1')

    setup().onOperation((incoming) => received.push(incoming))
    presence.fire(OPERATION_EVENT, { operation, userId: 'peer' })

    expect(received).toEqual([operation])
  })

  it('suppresses the local user own operations', () => {
    const received: ContentWizardSyncOperation[] = []

    setup().onOperation((incoming) => received.push(incoming))
    presence.fire(OPERATION_EVENT, { operation: addOperation('node-1'), userId: 'me' })

    expect(received).toEqual([])
  })

  it('ignores an empty payload', () => {
    const received: ContentWizardSyncOperation[] = []

    setup().onOperation((incoming) => received.push(incoming))
    presence.fire(OPERATION_EVENT, null)

    expect(received).toEqual([])
  })

  it('stops delivering once the returned unsubscribe is called', () => {
    const received: ContentWizardSyncOperation[] = []

    const stop = setup().onOperation((incoming) => received.push(incoming))
    stop()
    presence.fire(OPERATION_EVENT, { operation: addOperation('node-1'), userId: 'peer' })

    expect(received).toEqual([])
  })

  it('fans out to several subscribers', () => {
    const first: ContentWizardSyncOperation[] = []
    const second: ContentWizardSyncOperation[] = []
    const result = setup()

    result.onOperation((incoming) => first.push(incoming))
    result.onOperation((incoming) => second.push(incoming))
    presence.fire(OPERATION_EVENT, { operation: addOperation('node-1'), userId: 'peer' })

    expect(first).toHaveLength(1)
    expect(second).toHaveLength(1)
  })
})

describe('unmount', () => {
  it('gives up focus and hides the cursor for the peers', () => {
    setup()

    harness?.unmount()
    harness = undefined

    expect(sentOf(FOCUS_EVENT)[0].payload).toEqual({ nodeId: null, userId: 'me' })
    expect(sentOf(CURSOR_EVENT)[0].payload).toMatchObject({ visible: false })
  })

  it('stops listening for focus and cursor whispers', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])
    const result = setup()

    harness?.unmount()
    harness = undefined

    presence.fire(FOCUS_EVENT, { nodeId: 'node-1', userId: 'peer' })
    presence.fire(CURSOR_EVENT, { userId: 'peer', x: 1, y: 1, visible: true })

    expect(result.focusedUsersByNodeId.value).toEqual({})
    expect(result.visibleRemoteCursors.value).toEqual([])
  })

  // Torn down like the focus and cursor listeners, whether or not the caller
  // kept the unsubscribe it was handed.
  it('stops an onOperation subscription on unmount', () => {
    const received: ContentWizardSyncOperation[] = []

    setup().onOperation((incoming) => received.push(incoming))
    harness?.unmount()
    harness = undefined

    presence.fire(OPERATION_EVENT, { operation: addOperation('node-1'), userId: 'peer' })

    expect(received).toEqual([])
  })

  it('still lets the caller unsubscribe on its own', () => {
    const received: ContentWizardSyncOperation[] = []
    const stop = setup().onOperation((incoming) => received.push(incoming))

    stop()
    presence.fire(OPERATION_EVENT, { operation: addOperation('node-1'), userId: 'peer' })

    expect(received).toEqual([])
  })
})
