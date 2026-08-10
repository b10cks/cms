import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { CommentResource } from '~/types/comments'

import { PreviewBridge, type PreviewBridgeOptions } from '~/utils/preview-bridge'

const postToIframe = vi.fn()

/**
 * A stand-in for the preview iframe. jsdom gives an <iframe> a contentWindow
 * only once it is in the document with a loaded src, and messages between real
 * windows are async — a fake window keeps the source/origin checks under test
 * without any of that.
 */
const fakeContentWindow = { postMessage: postToIframe } as unknown as Window

const iframe = (contentWindow: Window | null = fakeContentWindow) =>
  ({ contentWindow }) as unknown as HTMLIFrameElement

/** Deliver a message as the browser would, so the bridge's own listener runs. */
const deliver = (data: unknown, { origin = 'https://site.test', source = fakeContentWindow } = {}) =>
  window.dispatchEvent(new MessageEvent('message', { data, origin, source }))

let bridges: PreviewBridge[] = []

/**
 * Bridges start out buffering until the preview announces readiness; most
 * tests exercise the steady state, so mark them ready up front.
 */
const createBridge = (options: PreviewBridgeOptions = {}, element = iframe(), ready = true) => {
  const bridge = new PreviewBridge(element, options)
  if (ready) bridge.markReady()
  bridges.push(bridge)
  return bridge
}

beforeEach(() => {
  postToIframe.mockClear()
})

afterEach(() => {
  // Every bridge adds a window listener in its constructor; leaking one across
  // tests would let a later dispatch hit an earlier test's listeners.
  bridges.forEach((bridge) => bridge.destroy())
  bridges = []
})

describe('outbound messages', () => {
  it('posts content updates', () => {
    createBridge().updateContent({ title: 'Home' })

    expect(postToIframe).toHaveBeenCalledWith(
      { type: 'CONTENT_UPDATE', payload: { content: { title: 'Home' } } },
      '*'
    )
  })

  it('posts selection and hover updates, including a cleared selection', () => {
    const bridge = createBridge()

    bridge.updateSelectedItem('block-1')
    bridge.updateHover(null)

    expect(postToIframe).toHaveBeenNthCalledWith(
      1,
      { type: 'SELECT_UPDATE', payload: { selectedItem: 'block-1' } },
      '*'
    )
    expect(postToIframe).toHaveBeenNthCalledWith(
      2,
      { type: 'HOVER_UPDATE', payload: { selectedItem: null } },
      '*'
    )
  })

  it('defaults the target origin to the first allowed origin', () => {
    createBridge({ allowedOrigins: ['https://site.test', 'https://staging.test'] }).updateHover(null)

    expect(postToIframe).toHaveBeenCalledWith(expect.anything(), 'https://site.test')
  })

  it('prefers an explicit target origin', () => {
    createBridge({
      allowedOrigins: ['https://site.test'],
      targetOrigin: 'https://staging.test',
    }).updateHover(null)

    expect(postToIframe).toHaveBeenCalledWith(expect.anything(), 'https://staging.test')
  })

  it('falls back to the wildcard origin when none is configured', () => {
    createBridge({ allowedOrigins: [] }).updateHover(null)

    expect(postToIframe).toHaveBeenCalledWith(expect.anything(), '*')
  })

  it('stays silent when the iframe has no content window', () => {
    createBridge({}, iframe(null)).updateContent({})

    expect(postToIframe).not.toHaveBeenCalled()
  })

  it('stays silent after destroy', () => {
    const bridge = createBridge()

    bridge.destroy()
    bridge.updateContent({})

    expect(postToIframe).not.toHaveBeenCalled()
  })
})

describe('updateComments', () => {
  const comment = (id: string, position: unknown) => ({ id, position }) as unknown as CommentResource

  it('forwards only comments anchored to a position', () => {
    createBridge().updateComments([
      comment('c1', { x: 10, y: 20 }),
      comment('c2', null),
      comment('c3', { x: 1 }),
      comment('c4', { y: 2 }),
      comment('c5', undefined),
    ])

    const [message] = postToIframe.mock.calls[0]

    expect(message.type).toBe('COMMENTS_UPDATE')
    expect(message.payload.comments.map((entry: CommentResource) => entry.id)).toEqual(['c1'])
  })

  it('keeps a comment pinned at the origin', () => {
    createBridge().updateComments([comment('c1', { x: 0, y: 0 })])

    expect(postToIframe.mock.calls[0][0].payload.comments).toHaveLength(1)
  })

  it('posts an empty list rather than nothing', () => {
    createBridge().updateComments([])

    expect(postToIframe).toHaveBeenCalledWith(
      { type: 'COMMENTS_UPDATE', payload: { comments: [] } },
      '*'
    )
  })
})

describe('inbound messages', () => {
  it('dispatches a message from the iframe to its listener', () => {
    const listener = vi.fn()

    createBridge().on('COMMENT_CLICK', listener)
    deliver({ type: 'COMMENT_CLICK', payload: { commentId: 'c1' } })

    expect(listener).toHaveBeenCalledWith({ commentId: 'c1' })
  })

  it.each([
    ['COMMENT_CREATE', { x: 1, y: 2, body: 'Hi' }],
    ['COMMENT_UPDATE', { commentId: 'c1', x: 1, y: 2, isResolved: true }],
    ['FIELD_UPDATE', { itemId: 'b1', path: ['title'], value: 'New' }],
  ] as const)('dispatches %s', (type, payload) => {
    const listener = vi.fn()

    createBridge().on(type, listener)
    deliver({ type, payload })

    expect(listener).toHaveBeenCalledWith(payload)
  })

  it('does not dispatch to listeners of other event types', () => {
    const listener = vi.fn()

    createBridge().on('COMMENT_CLICK', listener)
    deliver({ type: 'COMMENT_CREATE', payload: {} })

    expect(listener).not.toHaveBeenCalled()
  })

  it('stops dispatching after destroy', () => {
    const listener = vi.fn()
    const bridge = createBridge()

    bridge.on('COMMENT_CLICK', listener)
    bridge.destroy()
    deliver({ type: 'COMMENT_CLICK', payload: { commentId: 'c1' } })

    expect(listener).not.toHaveBeenCalled()
  })
})

describe('inbound message trust', () => {
  const listener = vi.fn()

  beforeEach(() => {
    listener.mockClear()
  })

  it('drops a message from a window that is not the preview iframe', () => {
    createBridge().on('COMMENT_CLICK', listener)

    deliver(
      { type: 'COMMENT_CLICK', payload: { commentId: 'c1' } },
      { source: { postMessage: vi.fn() } as unknown as Window }
    )

    expect(listener).not.toHaveBeenCalled()
  })

  it('drops a message with no source at all', () => {
    createBridge().on('COMMENT_CLICK', listener)

    deliver({ type: 'COMMENT_CLICK', payload: {} }, { source: null as unknown as Window })

    expect(listener).not.toHaveBeenCalled()
  })

  it('drops every message when the iframe has no content window', () => {
    createBridge({}, iframe(null)).on('COMMENT_CLICK', listener)

    deliver({ type: 'COMMENT_CLICK', payload: {} }, { source: null as unknown as Window })

    expect(listener).not.toHaveBeenCalled()
  })

  it('accepts a message from an allowed origin', () => {
    createBridge({ allowedOrigins: ['https://site.test'] }).on('COMMENT_CLICK', listener)

    deliver({ type: 'COMMENT_CLICK', payload: { commentId: 'c1' } }, { origin: 'https://site.test' })

    expect(listener).toHaveBeenCalledTimes(1)
  })

  it('drops a message from an origin that is not configured', () => {
    createBridge({ allowedOrigins: ['https://site.test'] }).on('COMMENT_CLICK', listener)

    deliver({ type: 'COMMENT_CLICK', payload: {} }, { origin: 'https://evil.test' })

    expect(listener).not.toHaveBeenCalled()
  })

  it('matches origins exactly, not by prefix', () => {
    createBridge({ allowedOrigins: ['https://site.test'] }).on('COMMENT_CLICK', listener)

    deliver({ type: 'COMMENT_CLICK', payload: {} }, { origin: 'https://site.test.evil.test' })

    expect(listener).not.toHaveBeenCalled()
  })

  it('accepts any origin when none are configured', () => {
    createBridge().on('COMMENT_CLICK', listener)

    deliver({ type: 'COMMENT_CLICK', payload: {} }, { origin: 'https://anywhere.test' })

    expect(listener).toHaveBeenCalledTimes(1)
  })

  it.each([
    ['null data', null],
    ['a string', 'COMMENT_CLICK'],
    ['a number', 42],
  ])('ignores %s', (_label, data) => {
    createBridge().on('COMMENT_CLICK', listener)

    deliver(data)

    expect(listener).not.toHaveBeenCalled()
  })

  it('ignores a message with no recognisable type', () => {
    createBridge().on('COMMENT_CLICK', listener)

    deliver({ payload: { commentId: 'c1' } })

    expect(listener).not.toHaveBeenCalled()
  })
})

describe('readiness handshake', () => {
  const ready = (overrides = {}) => deliver({ type: 'B10CKS_BRIDGE_READY' }, overrides)

  it('buffers state events until the preview announces readiness', () => {
    const bridge = createBridge({}, iframe(), false)

    bridge.updateContent({ title: 'Draft' })
    bridge.updateSelectedItem('block-1')
    expect(postToIframe).not.toHaveBeenCalled()

    ready()

    expect(postToIframe).toHaveBeenCalledWith(
      { type: 'CONTENT_UPDATE', payload: { content: { title: 'Draft' } } },
      '*'
    )
    expect(postToIframe).toHaveBeenCalledWith(
      { type: 'SELECT_UPDATE', payload: { selectedItem: 'block-1' } },
      '*'
    )
  })

  it('replays only the latest payload per state event', () => {
    const bridge = createBridge({}, iframe(), false)

    bridge.updateContent({ title: 'First' })
    bridge.updateContent({ title: 'Second' })
    ready()

    expect(postToIframe).toHaveBeenCalledTimes(1)
    expect(postToIframe).toHaveBeenCalledWith(
      { type: 'CONTENT_UPDATE', payload: { content: { title: 'Second' } } },
      '*'
    )
  })

  it('replays state on every announcement, so a navigated document catches up', () => {
    const bridge = createBridge({}, iframe(), false)

    bridge.updateContent({ title: 'Draft' })
    ready()
    ready()

    expect(postToIframe).toHaveBeenCalledTimes(2)
  })

  it('does not buffer or replay transient hover events', () => {
    const bridge = createBridge({}, iframe(), false)

    bridge.updateHover('block-1')
    ready()

    expect(postToIframe).not.toHaveBeenCalled()
  })

  it('markReady flushes the buffer for previews that never announce', () => {
    const bridge = createBridge({}, iframe(), false)

    bridge.updateContent({ title: 'Draft' })
    bridge.markReady()

    expect(postToIframe).toHaveBeenCalledTimes(1)

    bridge.markReady()

    expect(postToIframe).toHaveBeenCalledTimes(1)
  })

  it('notifies onReady listeners on each announcement until unsubscribed', () => {
    const listener = vi.fn()
    const bridge = createBridge({}, iframe(), false)
    const off = bridge.onReady(listener)

    ready()
    ready()
    off()
    ready()

    expect(listener).toHaveBeenCalledTimes(2)
  })

  it('ignores an announcement from a window that is not the preview iframe', () => {
    const bridge = createBridge({}, iframe(), false)

    bridge.updateContent({ title: 'Draft' })
    ready({ source: { postMessage: vi.fn() } as unknown as Window })

    expect(postToIframe).not.toHaveBeenCalled()
  })

  it('ignores an announcement from an origin that is not configured', () => {
    const bridge = createBridge({ allowedOrigins: ['https://site.test'] }, iframe(), false)

    bridge.updateContent({ title: 'Draft' })
    ready({ origin: 'https://evil.test' })

    expect(postToIframe).not.toHaveBeenCalled()
  })
})

describe('bridge isolation', () => {
  it('routes a message only to the bridge whose iframe sent it', () => {
    const otherWindow = { postMessage: vi.fn() } as unknown as Window
    const first = vi.fn()
    const second = vi.fn()

    createBridge().on('COMMENT_CLICK', first)
    createBridge({}, iframe(otherWindow)).on('COMMENT_CLICK', second)

    deliver({ type: 'COMMENT_CLICK', payload: {} }, { source: otherWindow })

    expect(first).not.toHaveBeenCalled()
    expect(second).toHaveBeenCalledTimes(1)
  })

  it('leaves a sibling bridge working after one is destroyed', () => {
    const listener = vi.fn()
    const doomed = createBridge()

    createBridge().on('COMMENT_CLICK', listener)
    doomed.destroy()

    deliver({ type: 'COMMENT_CLICK', payload: {} })

    expect(listener).toHaveBeenCalledTimes(1)
  })
})
