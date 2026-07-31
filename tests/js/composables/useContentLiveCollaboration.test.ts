import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref, type Ref } from 'vue'

import type { ContentResource } from '~/types/contents'

import { createPresenceController, presenceUser, type PresenceController } from '../support/presence'
import { withSetup, type Harness } from '../support/harness'

let presence: PresenceController

vi.mock('~/composables/usePresence', async () => {
  const actual = await vi.importActual<typeof import('~/composables/usePresence')>(
    '~/composables/usePresence'
  )

  return { ...actual, useContentPresence: () => presence }
})

const { useContentLiveCollaboration } = await import('~/composables/useContentLiveCollaboration')

const BLOCK_OPERATION_EVENT = 'content-block-operation'
const FIELD_UPDATE_EVENT = 'content-field-update'
const FIELD_FOCUS_EVENT = 'content-field-focus'
const SYNC_REQUEST_EVENT = 'content-sync-request'
const SYNC_STATE_EVENT = 'content-sync-state'

const block = (id: string, extra: Record<string, unknown> = {}) => ({ id, block: 'card', ...extra })

/**
 * Whispers of one kind. A freshly mounted client also whispers a sync request
 * (it might be a late joiner), so assertions have to name the event they mean.
 */
const sentOf = (event: string) => presence.sent.filter((entry) => entry.event === event)

/** The content ref's inner `content` map — what the whisper handlers write into. */
const inner = (content: Ref<ContentResource | null>) =>
  (content.value?.content ?? {}) as Record<string, unknown>

const contentResource = (inner: Record<string, unknown> = {}): ContentResource =>
  ({ id: 'content-1', content: { title: 'Home', ...inner } }) as unknown as ContentResource

type CollabOptions = Parameters<typeof useContentLiveCollaboration>[2]
type CollabHarness = Harness<ReturnType<typeof useContentLiveCollaboration>>

// Explicit, not ReturnType<typeof setup>: that would be circular, and TS would
// silently widen the composable's whole surface to `any`.
let harness: CollabHarness | undefined

const setup = (
  content: Ref<ContentResource | null> = ref(contentResource()),
  options: Partial<CollabOptions> = {}
) => {
  harness = withSetup(() =>
    useContentLiveCollaboration('space-1', 'content-1', { content, ...options })
  )

  return { ...harness, content }
}

beforeEach(() => {
  presence = createPresenceController('me')
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
  vi.useRealTimers()
})

describe('collaborators', () => {
  it('gives every collaborator a stable presence colour', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const { result } = setup()

    expect(result.collaborators.value).toHaveLength(2)
    expect(result.collaborators.value[0].color).toEqual(expect.any(String))
    expect(result.collaborators.value[0].colorLabel).toEqual(expect.any(String))
  })

  it('keeps the same colour for the same user id', () => {
    presence.setUsers([presenceUser('peer')])
    const first = setup().result.collaborators.value[0].color

    harness?.unmount()
    presence = createPresenceController('me')
    presence.setUsers([presenceUser('peer')])

    expect(setup().result.collaborators.value[0].color).toBe(first)
  })
})

describe('broadcastBlockOperation', () => {
  it('whispers the operation with the sender id', () => {
    setup().result.broadcastBlockOperation({
      type: 'remove',
      itemIds: ['b1'],
      parentId: 'content-1',
      field: 'body',
    })

    expect(sentOf(BLOCK_OPERATION_EVENT)).toEqual([
      {
        event: BLOCK_OPERATION_EVENT,
        payload: expect.objectContaining({ type: 'remove', itemIds: ['b1'], userId: 'me' }),
      },
    ])
  })

  it('drops an operation that names no parent or field', () => {
    // BlocksBlock emits bare ops; FieldEditor is what fills these in.
    const { result } = setup()

    result.broadcastBlockOperation({ type: 'remove', itemIds: ['b1'] })
    result.broadcastBlockOperation({ type: 'remove', itemIds: ['b1'], parentId: 'content-1' })
    result.broadcastBlockOperation({ type: 'remove', itemIds: ['b1'], field: 'body' })

    expect(sentOf(BLOCK_OPERATION_EVENT)).toEqual([])
  })

  // Guarded like flushFieldUpdate: nothing goes on the wire without a channel.
  it('does not whisper while disconnected', () => {
    presence.isConnected.value = false

    setup().result.broadcastBlockOperation({
      type: 'remove',
      itemIds: ['b1'],
      parentId: 'content-1',
      field: 'body',
    })

    expect(sentOf(BLOCK_OPERATION_EVENT)).toEqual([])
  })

  // The local bookkeeping is not part of the guard — the trail index depends on
  // it whether or not there is anyone to broadcast to.
  it('still records the local draft while disconnected', () => {
    presence.isConnected.value = false

    const { result } = setup()
    result.broadcastBlockOperation({
      type: 'remove',
      itemIds: ['b1'],
      parentId: 'content-1',
      field: 'body',
      previousValue: [{ id: 'b1' }],
    })

    expect(result.hasLocalDraft('content-1', 'body')).toBe(true)
  })

  it('marks the parent field as locally dirty', () => {
    const { result } = setup()

    result.broadcastBlockOperation({
      type: 'remove',
      itemIds: ['b1'],
      parentId: 'content-1',
      field: 'body',
      previousValue: [{ id: 'b1' }],
    })

    expect(result.hasLocalDraft('content-1', 'body')).toBe(true)
  })
})

describe('remote block operations', () => {
  const remote = (payload: Record<string, unknown>) =>
    presence.fire(BLOCK_OPERATION_EVENT, { userId: 'peer', ...payload })

  const bodyOf = (content: Ref<ContentResource | null>) =>
    inner(content).body as Record<string, unknown>[]

  it('adds items at the requested index', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1'), block('b3')] })))

    remote({ type: 'add', index: 1, items: [block('b2')], parentId: 'content-1', field: 'body' })

    expect(bodyOf(content).map((item) => item.id)).toEqual(['b1', 'b2', 'b3'])
  })

  it('clamps an out-of-range add index', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1')] })))

    remote({ type: 'add', index: 99, items: [block('b2')], parentId: 'content-1', field: 'body' })
    remote({ type: 'add', index: -5, items: [block('b0')], parentId: 'content-1', field: 'body' })

    expect(bodyOf(content).map((item) => item.id)).toEqual(['b0', 'b1', 'b2'])
  })

  it('ignores an add of an item that is already present', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1')] })))

    remote({ type: 'add', index: 0, items: [block('b1')], parentId: 'content-1', field: 'body' })

    expect(bodyOf(content)).toHaveLength(1)
  })

  it('creates the array when the field holds nothing yet', () => {
    const { content } = setup(ref(contentResource()))

    remote({ type: 'add', index: 0, items: [block('b1')], parentId: 'content-1', field: 'body' })

    expect(bodyOf(content).map((item) => item.id)).toEqual(['b1'])
  })

  it('removes the named items and leaves the rest', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1'), block('b2'), block('b3')] })))

    remote({ type: 'remove', itemIds: ['b1', 'b3'], parentId: 'content-1', field: 'body' })

    expect(bodyOf(content).map((item) => item.id)).toEqual(['b2'])
  })

  it('ignores a remove of an item that is already gone', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1')] })))

    remote({ type: 'remove', itemIds: ['ghost'], parentId: 'content-1', field: 'body' })

    expect(bodyOf(content).map((item) => item.id)).toEqual(['b1'])
  })

  it('reorders to the requested order', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1'), block('b2'), block('b3')] })))

    remote({ type: 'reorder', order: ['b3', 'b1', 'b2'], parentId: 'content-1', field: 'body' })

    expect(bodyOf(content).map((item) => item.id)).toEqual(['b3', 'b1', 'b2'])
  })

  it('appends items the reorder does not mention rather than dropping them', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1'), block('b2'), block('b3')] })))

    remote({ type: 'reorder', order: ['b3', 'b1'], parentId: 'content-1', field: 'body' })

    expect(bodyOf(content).map((item) => item.id)).toEqual(['b3', 'b1', 'b2'])
  })

  it('ignores unknown ids in the order', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1'), block('b2')] })))

    remote({ type: 'reorder', order: ['ghost', 'b2', 'b1'], parentId: 'content-1', field: 'body' })

    expect(bodyOf(content).map((item) => item.id)).toEqual(['b2', 'b1'])
  })

  it('toggles item visibility', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1')] })))

    remote({ type: 'visibility', itemId: 'b1', hidden: true, parentId: 'content-1', field: 'body' })

    expect(bodyOf(content)[0].hidden).toBe(true)
  })

  it('replaces the whole list', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1')] })))

    remote({ type: 'replace', items: [block('b9')], parentId: 'content-1', field: 'body' })

    expect(bodyOf(content).map((item) => item.id)).toEqual(['b9'])
  })

  it('applies an operation to a nested parent', () => {
    const { content } = setup(
      ref(contentResource({ body: [block('section', { items: [block('c1')] })] }))
    )

    remote({ type: 'add', index: 1, items: [block('c2')], parentId: 'section', field: 'items' })

    const items = (bodyOf(content)[0].items as Record<string, unknown>[]).map((item) => item.id)

    expect(items).toEqual(['c1', 'c2'])
  })

  it('ignores an operation this client sent itself', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1')] })))

    presence.fire(BLOCK_OPERATION_EVENT, {
      userId: 'me',
      type: 'remove',
      itemIds: ['b1'],
      parentId: 'content-1',
      field: 'body',
    })

    expect(bodyOf(content)).toHaveLength(1)
  })

  it('ignores an empty payload', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1')] })))

    presence.fire(BLOCK_OPERATION_EVENT, null)

    expect(bodyOf(content)).toHaveLength(1)
  })
})

describe('out-of-order block operations', () => {
  const remote = (payload: Record<string, unknown>) =>
    presence.fire(BLOCK_OPERATION_EVENT, { userId: 'peer', ...payload })

  it('queues an operation whose parent has not arrived, then applies it', () => {
    const { content } = setup(ref(contentResource({ body: [] })))

    // Arrives first, but `section` does not exist locally yet.
    remote({ type: 'add', index: 0, items: [block('c1')], parentId: 'section', field: 'items' })

    expect((inner(content).body as unknown[]).length).toBe(0)

    // The parent shows up; the queued child op drains behind it.
    remote({
      type: 'add',
      index: 0,
      items: [block('section', { items: [] })],
      parentId: 'content-1',
      field: 'body',
    })

    const body = inner(content).body as Record<string, unknown>[]

    expect((body[0].items as Record<string, unknown>[]).map((item) => item.id)).toEqual(['c1'])
  })

  it('drains a chain of queued operations in dependency order', () => {
    const { content } = setup(ref(contentResource({ body: [] })))

    remote({ type: 'add', index: 0, items: [block('leaf')], parentId: 'card', field: 'items' })
    remote({
      type: 'add',
      index: 0,
      items: [block('card', { items: [] })],
      parentId: 'section',
      field: 'items',
    })
    remote({
      type: 'add',
      index: 0,
      items: [block('section', { items: [] })],
      parentId: 'content-1',
      field: 'body',
    })

    const body = inner(content).body as Record<string, unknown>[]
    const card = (body[0].items as Record<string, unknown>[])[0]

    expect((card.items as Record<string, unknown>[]).map((item) => item.id)).toEqual(['leaf'])
  })

  it('does not lose the queue to an unrelated operation', () => {
    const { content } = setup(ref(contentResource({ body: [] })))

    remote({ type: 'add', index: 0, items: [block('c1')], parentId: 'section', field: 'items' })
    remote({ type: 'add', index: 0, items: [block('other')], parentId: 'content-1', field: 'body' })
    remote({
      type: 'add',
      index: 1,
      items: [block('section', { items: [] })],
      parentId: 'content-1',
      field: 'body',
    })

    const body = inner(content).body as Record<string, unknown>[]
    const section = body.find((item) => item.id === 'section')
    const items = (section?.items ?? []) as Record<string, unknown>[]

    expect(items.map((item) => item.id)).toEqual(['c1'])
  })
})

describe('field updates', () => {
  it('debounces the outgoing whisper', () => {
    vi.useFakeTimers()

    const { result } = setup()

    result.queueFieldUpdate({ itemId: 'content-1', field: 'title', value: 'A', debounceMs: 300 })
    result.queueFieldUpdate({ itemId: 'content-1', field: 'title', value: 'AB', debounceMs: 300 })

    expect(sentOf(FIELD_UPDATE_EVENT)).toHaveLength(0)

    vi.advanceTimersByTime(300)

    // Only the latest value is sent, once.
    expect(sentOf(FIELD_UPDATE_EVENT)).toHaveLength(1)
    expect((sentOf(FIELD_UPDATE_EVENT)[0].payload as { value: string }).value).toBe('AB')
  })

  it('applies a remote field update to the content', () => {
    const { content } = setup()

    presence.fire(FIELD_UPDATE_EVENT, {
      userId: 'peer',
      itemId: 'content-1',
      field: 'title',
      value: 'Startseite',
    })

    expect(inner(content).title).toBe('Startseite')
  })

  it('applies a remote update to a nested block', () => {
    const { content } = setup(ref(contentResource({ body: [block('b1', { headline: 'Hi' })] })))

    presence.fire(FIELD_UPDATE_EVENT, {
      userId: 'peer',
      itemId: 'b1',
      field: 'headline',
      value: 'Hallo',
    })

    const body = inner(content).body as Record<string, unknown>[]

    expect(body[0].headline).toBe('Hallo')
  })

  it('ignores its own field update echoed back', () => {
    const { content } = setup()

    presence.fire(FIELD_UPDATE_EVENT, {
      userId: 'me',
      itemId: 'content-1',
      field: 'title',
      value: 'Mine',
    })

    expect(inner(content).title).toBe('Home')
  })

  it('ignores an update for a block it does not have', () => {
    const { content } = setup()

    expect(() =>
      presence.fire(FIELD_UPDATE_EVENT, {
        userId: 'peer',
        itemId: 'ghost',
        field: 'headline',
        value: 'x',
      })
    ).not.toThrow()
    expect(inner(content).title).toBe('Home')
  })

  it('routes through a field adapter when one is supplied', () => {
    const apply = vi.fn()
    const content = ref(contentResource())

    harness = withSetup(() =>
      useContentLiveCollaboration('space-1', 'content-1', {
        content,
        fieldValueAdapter: { get: () => undefined, apply },
      })
    )

    presence.fire(FIELD_UPDATE_EVENT, {
      userId: 'peer',
      itemId: 'b1',
      field: 'headline',
      value: 'Hallo',
      meta: { path: ['body', 0, 'headline'] },
    })

    expect(apply).toHaveBeenCalledWith(
      { itemId: 'b1', field: 'headline', meta: { path: ['body', 0, 'headline'] } },
      'Hallo'
    )
  })
})

describe('remote draft indicators', () => {
  it('reports the peer holding an unsaved change on a field', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const { result } = setup()

    presence.fire(FIELD_UPDATE_EVENT, {
      userId: 'peer',
      itemId: 'content-1',
      field: 'title',
      previousValue: 'Home',
      value: 'Startseite',
    })

    expect(
      result.getRemoteDraftCollaborators('content-1', 'title').map((user) => user.id)
    ).toEqual(['peer'])
  })

  it('clears the indicator once the field is edited back to its clean value', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const { result } = setup()
    const update = (value: string) =>
      presence.fire(FIELD_UPDATE_EVENT, {
        userId: 'peer',
        itemId: 'content-1',
        field: 'title',
        previousValue: 'Home',
        value,
      })

    update('Startseite')
    expect(result.getRemoteDraftCollaborators('content-1', 'title')).toHaveLength(1)

    update('Home')
    expect(result.getRemoteDraftCollaborators('content-1', 'title')).toHaveLength(0)
  })

  it('keeps naming a draft owner who has left the channel', async () => {
    const { result } = setup()

    // The peer has to be seen joining for its identity to be remembered.
    presence.setUsers([presenceUser('me'), presenceUser('peer')])
    await nextTick()

    presence.fire(FIELD_UPDATE_EVENT, {
      userId: 'peer',
      itemId: 'content-1',
      field: 'title',
      previousValue: 'Home',
      value: 'Startseite',
    })

    presence.setUsers([presenceUser('me')])
    await nextTick()

    expect(result.getRemoteDraftCollaborators('content-1', 'title').map((user) => user.id)).toEqual([
      'peer',
    ])
  })
})

describe('field focus presence', () => {
  it('whispers focus and blur', () => {
    const { result } = setup()

    result.updateFieldFocus({ itemId: 'content-1', field: 'title', focused: true })

    expect(sentOf(FIELD_FOCUS_EVENT)).toEqual([
      {
        event: FIELD_FOCUS_EVENT,
        payload: expect.objectContaining({ field: 'title', focused: true, userId: 'me' }),
      },
    ])
  })

  it('lists a peer focused on a field', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const { result } = setup()

    presence.fire(FIELD_FOCUS_EVENT, {
      userId: 'peer',
      itemId: 'content-1',
      field: 'title',
      focused: true,
    })

    expect(result.getCollaboratorsForField('content-1', 'title').map((user) => user.id)).toEqual([
      'peer',
    ])
  })

  it('drops the peer again on blur', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const { result } = setup()
    const focus = (focused: boolean) =>
      presence.fire(FIELD_FOCUS_EVENT, {
        userId: 'peer',
        itemId: 'content-1',
        field: 'title',
        focused,
      })

    focus(true)
    focus(false)

    expect(result.getCollaboratorsForField('content-1', 'title')).toEqual([])
  })

  it('ignores its own focus echoed back', () => {
    const { result } = setup()

    presence.fire(FIELD_FOCUS_EVENT, {
      userId: 'me',
      itemId: 'content-1',
      field: 'title',
      focused: true,
    })

    expect(result.getCollaboratorsForField('content-1', 'title')).toEqual([])
  })
})

describe('local drafts', () => {
  it('tracks a field the user has edited but not saved', () => {
    vi.useFakeTimers()

    const { result } = setup()

    expect(result.hasLocalDraft('content-1', 'title')).toBe(false)

    result.queueFieldUpdate({
      itemId: 'content-1',
      field: 'title',
      previousValue: 'Home',
      value: 'Changed',
    })

    expect(result.hasLocalDraft('content-1', 'title')).toBe(true)
  })

  it('forgets the draft when the value returns to its clean state', () => {
    vi.useFakeTimers()

    const { result } = setup()

    result.queueFieldUpdate({
      itemId: 'content-1',
      field: 'title',
      previousValue: 'Home',
      value: 'Changed',
    })
    result.queueFieldUpdate({
      itemId: 'content-1',
      field: 'title',
      previousValue: 'Home',
      value: 'Home',
    })

    expect(result.hasLocalDraft('content-1', 'title')).toBe(false)
  })

  it('reports any draft on an item when no field is named', () => {
    vi.useFakeTimers()

    const { result } = setup()

    result.queueFieldUpdate({
      itemId: 'content-1',
      field: 'title',
      previousValue: 'Home',
      value: 'Changed',
    })

    expect(result.hasLocalDraft('content-1')).toBe(true)
    expect(result.hasLocalDraft('other-item')).toBe(false)
  })

  it('discards its own drafts and tells peers', () => {
    vi.useFakeTimers()

    const { result } = setup()

    result.queueFieldUpdate({
      itemId: 'content-1',
      field: 'title',
      previousValue: 'Home',
      value: 'Changed',
    })
    vi.advanceTimersByTime(1000)
    presence.sent.length = 0

    result.discardOwnDrafts()

    expect(result.hasLocalDraft('content-1', 'title')).toBe(false)
    expect(presence.sent.map((entry) => entry.event)).toContain('content-discard')
  })
})

describe('late-joiner sync', () => {
  it('answers a peer sync request with its own dirty fields', async () => {
    vi.useFakeTimers()

    const { result } = setup()

    result.queueFieldUpdate({
      itemId: 'content-1',
      field: 'title',
      previousValue: 'Home',
      value: 'Changed',
    })
    vi.advanceTimersByTime(1000)
    presence.sent.length = 0

    presence.fire(SYNC_REQUEST_EVENT, { userId: 'joiner' })
    vi.advanceTimersByTime(400)
    await nextTick()

    const reply = presence.sent.find((entry) => entry.event === SYNC_STATE_EVENT)

    expect(reply).toBeDefined()
    expect(reply?.payload).toMatchObject({ requesterId: 'joiner', userId: 'me' })
    expect(((reply?.payload ?? {}) as { fields?: unknown[] }).fields).toHaveLength(1)
  })

  it('ignores a sync request it sent itself', () => {
    vi.useFakeTimers()

    setup()
    presence.sent.length = 0

    presence.fire(SYNC_REQUEST_EVENT, { userId: 'me' })
    vi.advanceTimersByTime(1000)

    expect(presence.sent.find((entry) => entry.event === SYNC_STATE_EVENT)).toBeUndefined()
  })

  it('adopts the dirty fields a peer reports for this client', () => {
    presence.setUsers([presenceUser('me'), presenceUser('peer')])

    const { result, content } = setup()

    presence.fire(SYNC_STATE_EVENT, {
      userId: 'peer',
      requesterId: 'me',
      fields: [
        { itemId: 'content-1', field: 'title', previousValue: 'Home', value: 'Startseite' },
      ],
      focusedFields: [{ itemId: 'content-1', field: 'title' }],
    })

    expect(inner(content).title).toBe('Startseite')
    expect(result.getRemoteDraftCollaborators('content-1', 'title').map((user) => user.id)).toEqual([
      'peer',
    ])
  })

  it('ignores sync state addressed to a different joiner', () => {
    const { content } = setup()

    presence.fire(SYNC_STATE_EVENT, {
      userId: 'peer',
      requesterId: 'someone-else',
      fields: [{ itemId: 'content-1', field: 'title', value: 'Startseite' }],
      focusedFields: [],
    })

    expect(inner(content).title).toBe('Home')
  })
})

describe('without content', () => {
  it('ignores remote operations rather than throwing', () => {
    setup(ref(null))

    expect(() =>
      presence.fire(BLOCK_OPERATION_EVENT, {
        userId: 'peer',
        type: 'remove',
        itemIds: ['b1'],
        parentId: 'content-1',
        field: 'body',
      })
    ).not.toThrow()
  })
})
