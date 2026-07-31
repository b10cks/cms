import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { PLUGIN_PROTOCOL_VERSION, PluginBridge, type PluginInitPayload } from '~/utils/plugin-bridge'

const postToIframe = vi.fn()

/**
 * A stand-in for the plugin iframe. jsdom only gives a real <iframe> a
 * contentWindow once it is in the document with a loaded src, and cross-window
 * messages are async — a fake window keeps the source check under test without
 * any of that.
 */
const fakeContentWindow = { postMessage: postToIframe } as unknown as Window

/** Records every property write, so a test can prove the bridge never mutates the element. */
const writes: Array<string | symbol> = []

const iframe = (contentWindow: Window | null = fakeContentWindow) =>
  new Proxy(
    { contentWindow, src: 'https://plugins.b10cks.test/field', parentNode: null },
    {
      set(target, property, value) {
        writes.push(property)
        return Reflect.set(target, property, value)
      },
      deleteProperty(target, property) {
        writes.push(property)
        return Reflect.deleteProperty(target, property)
      },
    }
  ) as unknown as HTMLIFrameElement

/** A well-formed envelope, loose enough that a test can break any single field. */
const message = (overrides: Record<string, unknown> = {}, token: unknown = 'tok'): unknown => ({
  source: 'b10cks-plugin',
  version: PLUGIN_PROTOCOL_VERSION,
  token,
  type: 'PLUGIN_READY',
  payload: {},
  ...overrides,
})

/** Deliver a message as the browser would, so the bridge's own listener runs. */
const deliver = (
  data: unknown,
  { origin = 'null', source = fakeContentWindow }: { origin?: string; source?: Window | null } = {}
) => window.dispatchEvent(new MessageEvent('message', { data, origin, source }))

let bridges: PluginBridge[] = []

const track = <T extends PluginBridge>(bridge: T): T => {
  bridges.push(bridge)
  return bridge
}

const createBridge = (token: string = 'tok', element = iframe()) =>
  track(new PluginBridge(element, token))

/** A bridge that lets the constructor mint the handshake token. */
const mintedBridge = () => track(new PluginBridge(iframe()))

const initPayload = (): PluginInitPayload => ({
  value: { a: 1 },
  options: { mode: 'compact' },
  context: {
    spaceId: 'space-1',
    fieldKey: 'colour',
    language: 'en',
    readOnly: false,
    isModal: false,
  },
  theme: 'light',
})

beforeEach(() => {
  postToIframe.mockClear()
  writes.length = 0
})

afterEach(() => {
  // Every bridge adds a window listener in its constructor; leaking one across
  // tests would let a later dispatch hit an earlier test's listeners.
  bridges.forEach((bridge) => bridge.destroy())
  bridges = []
})

describe('token handshake', () => {
  it('mints a token when the host does not supply one', () => {
    expect(mintedBridge().token).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/
    )
  })

  it('mints a different token per mount', () => {
    expect(mintedBridge().token).not.toBe(mintedBridge().token)
  })

  it('keeps the host-supplied token', () => {
    expect(createBridge('handshake-1').token).toBe('handshake-1')
  })

  it('accepts a message carrying the handshake token', () => {
    const listener = vi.fn()

    createBridge('handshake-1').on('PLUGIN_READY', listener)
    deliver(message({}, 'handshake-1'))

    expect(listener).toHaveBeenCalledTimes(1)
  })

  it('drops a message carrying another mount’s token', () => {
    const listener = vi.fn()

    createBridge('handshake-1').on('PLUGIN_READY', listener)
    deliver(message({}, 'handshake-2'))

    expect(listener).not.toHaveBeenCalled()
  })

  it.each([
    ['no token', undefined],
    ['an empty token', ''],
    ['a null token', null],
    ['a numeric token', 1],
    ['a same-length near miss', 'handshake-2'],
  ])('drops a message with %s', (_label, token) => {
    const listener = vi.fn()

    createBridge('handshake-1').on('PLUGIN_READY', listener)
    deliver(message({}, token))

    expect(listener).not.toHaveBeenCalled()
  })

  it('does not treat a missing token as matching an empty configured token', () => {
    const listener = vi.fn()

    // An empty string is a degenerate token, but `undefined !== ''` still holds,
    // so a message that simply omits the field cannot slip through.
    createBridge('').on('PLUGIN_READY', listener)
    deliver(message({ token: undefined }))

    expect(listener).not.toHaveBeenCalled()
  })

  it('stamps every outbound message with the handshake token', () => {
    createBridge('handshake-1').updateValue(7)

    expect(postToIframe.mock.calls[0][0]).toMatchObject({ token: 'handshake-1' })
  })
})

describe('source-window trust', () => {
  const listener = vi.fn()

  beforeEach(() => {
    listener.mockClear()
  })

  it('drops a message from a window that is not the plugin iframe', () => {
    createBridge().on('PLUGIN_READY', listener)

    deliver(message(), { source: { postMessage: vi.fn() } as unknown as Window })

    expect(listener).not.toHaveBeenCalled()
  })

  it('drops a message with no source at all', () => {
    createBridge().on('PLUGIN_READY', listener)

    deliver(message(), { source: null })

    expect(listener).not.toHaveBeenCalled()
  })

  it('still drops a sourced message while the iframe has no content window', () => {
    createBridge('tok', iframe(null)).on('PLUGIN_READY', listener)

    deliver(message())

    expect(listener).not.toHaveBeenCalled()
  })

  it('drops a source-less message while the iframe has no content window', () => {
    createBridge('tok', iframe(null)).on('PLUGIN_READY', listener)

    deliver(message(), { source: null })

    // A missing contentWindow must not make `null === null` a match: an unsourced
    // message (worker/port relay, detached frame) would otherwise pass the source
    // check with only the handshake token in its way.
    expect(listener).not.toHaveBeenCalled()
  })

  it('routes a message only to the bridge whose iframe sent it', () => {
    const otherWindow = { postMessage: vi.fn() } as unknown as Window
    const first = vi.fn()
    const second = vi.fn()

    createBridge().on('PLUGIN_READY', first)
    createBridge('tok', iframe(otherWindow)).on('PLUGIN_READY', second)

    deliver(message(), { source: otherWindow })

    expect(first).not.toHaveBeenCalled()
    expect(second).toHaveBeenCalledTimes(1)
  })

  it('separates two mounts sharing one iframe by their tokens', () => {
    const shared = iframe()
    const first = vi.fn()
    const second = vi.fn()

    track(new PluginBridge(shared, 'first')).on('PLUGIN_READY', first)
    track(new PluginBridge(shared, 'second')).on('PLUGIN_READY', second)
    deliver(message({}, 'second'))

    expect(first).not.toHaveBeenCalled()
    expect(second).toHaveBeenCalledTimes(1)
  })
})

describe('origin handling', () => {
  const listener = vi.fn()

  beforeEach(() => {
    listener.mockClear()
  })

  it.each(['null', 'https://plugins.b10cks.test', 'https://evil.test'])(
    'accepts a token-bearing message from origin %s',
    (origin) => {
      // Sandboxed frames post from the opaque origin `null`, so the bridge
      // deliberately has no origin allowlist: identity rests on the source
      // window plus the handshake token.
      createBridge().on('PLUGIN_READY', listener)

      deliver(message(), { origin })

      expect(listener).toHaveBeenCalledTimes(1)
    }
  )

  it('posts to the wildcard target origin', () => {
    createBridge().updateTheme('dark')

    expect(postToIframe).toHaveBeenCalledWith(expect.anything(), '*')
  })
})

describe('envelope validation', () => {
  const listener = vi.fn()

  beforeEach(() => {
    listener.mockClear()
  })

  it.each([
    ['null data', null],
    ['undefined data', undefined],
    ['a string', 'PLUGIN_READY'],
    ['a number', 42],
    ['a boolean', true],
  ])('ignores %s', (_label, data) => {
    createBridge().on('PLUGIN_READY', listener)

    deliver(data)

    expect(listener).not.toHaveBeenCalled()
  })

  it.each([
    ['a foreign source marker', { source: 'b10cks-preview' }],
    ['a source marker with trailing space', { source: 'b10cks-plugin ' }],
    ['no source marker', { source: undefined }],
    ['a wrong protocol version', { version: PLUGIN_PROTOCOL_VERSION + 1 }],
    ['a stringified protocol version', { version: String(PLUGIN_PROTOCOL_VERSION) }],
    ['no protocol version', { version: undefined }],
    ['no type', { type: undefined }],
    ['a non-string type', { type: 3 }],
  ])('drops a message with %s', (_label, overrides) => {
    createBridge().on('PLUGIN_READY', listener)

    deliver(message(overrides as Record<string, unknown>))

    expect(listener).not.toHaveBeenCalled()
  })

  it('does not dispatch an array masquerading as an envelope', () => {
    createBridge().on('PLUGIN_READY', listener)

    deliver(['b10cks-plugin', PLUGIN_PROTOCOL_VERSION, 'tok', 'PLUGIN_READY'])

    expect(listener).not.toHaveBeenCalled()
  })

  it('forwards an unrecognised but well-formed type only to that type’s listeners', () => {
    const unknown = vi.fn()
    const bridge = createBridge()

    bridge.on('PLUGIN_READY', listener)
    // The type is not checked against the protocol, only that it is a string.
    bridge.on('SOMETHING_ELSE' as 'PLUGIN_READY', unknown)
    deliver(message({ type: 'SOMETHING_ELSE' }))

    expect(listener).not.toHaveBeenCalled()
    expect(unknown).toHaveBeenCalledTimes(1)
  })
})

describe('inbound dispatch', () => {
  it.each([
    ['VALUE_CHANGE', { value: '#fff' }],
    ['HEIGHT_CHANGE', { height: 240 }],
    ['MODAL_TOGGLE', { open: true }],
    ['ASSET_SELECT_REQUEST', { requestId: 'r1', fileTypes: ['image/png'] }],
  ] as const)('dispatches %s to its listener', (type, payload) => {
    const listener = vi.fn()

    createBridge().on(type, listener)
    deliver(message({ type, payload }))

    expect(listener).toHaveBeenCalledWith(payload)
  })

  it('does not dispatch to listeners of other event types', () => {
    const listener = vi.fn()

    createBridge().on('VALUE_CHANGE', listener)
    deliver(message({ type: 'HEIGHT_CHANGE', payload: { height: 1 } }))

    expect(listener).not.toHaveBeenCalled()
  })

  it('forwards a falsy value change rather than swallowing it', () => {
    const listener = vi.fn()

    createBridge().on('VALUE_CHANGE', listener)
    deliver(message({ type: 'VALUE_CHANGE', payload: { value: '' } }))

    expect(listener).toHaveBeenCalledWith({ value: '' })
  })

  it('forwards a missing payload as undefined', () => {
    const listener = vi.fn()

    createBridge().on('VALUE_CHANGE', listener)
    deliver(message({ type: 'VALUE_CHANGE', payload: undefined }))

    expect(listener).toHaveBeenCalledWith(undefined)
  })
})

describe('outbound messages', () => {
  it('wraps a payload in the protocol envelope', () => {
    createBridge('handshake-1').post('ASSET_SELECT_RESULT', { requestId: 'r1', asset: null })

    expect(postToIframe).toHaveBeenCalledWith(
      {
        source: 'b10cks-plugin',
        version: PLUGIN_PROTOCOL_VERSION,
        token: 'handshake-1',
        type: 'ASSET_SELECT_RESULT',
        payload: { requestId: 'r1', asset: null },
      },
      '*'
    )
  })

  it('sends the init payload verbatim', () => {
    const payload = initPayload()

    createBridge().init(payload)

    expect(postToIframe.mock.calls[0][0]).toMatchObject({ type: 'INIT', payload })
  })

  it.each([
    [
      'updateValue',
      (bridge: PluginBridge) => bridge.updateValue(null),
      'VALUE_UPDATE',
      { value: null },
    ],
    [
      'updateReadOnly',
      (bridge: PluginBridge) => bridge.updateReadOnly(true),
      'READ_ONLY_UPDATE',
      { readOnly: true },
    ],
    [
      'updateTheme',
      (bridge: PluginBridge) => bridge.updateTheme('dark'),
      'THEME',
      { theme: 'dark' },
    ],
  ] as const)('%s posts %s', (_label, act, type, payload) => {
    act(createBridge())

    expect(postToIframe.mock.calls[0][0]).toMatchObject({ type, payload })
  })

  it('stays silent when the iframe has no content window', () => {
    createBridge('tok', iframe(null)).updateValue(1)

    expect(postToIframe).not.toHaveBeenCalled()
  })
})

describe('the iframe handle', () => {
  it('is never mutated over a full lifecycle', () => {
    const element = iframe()
    const bridge = track(new PluginBridge(element, 'tok'))

    bridge.init(initPayload())
    deliver(message({ type: 'VALUE_CHANGE', payload: { value: 1 } }))
    bridge.destroy()

    // Re-parenting or re-pointing the frame would reload the plugin and void
    // the handshake, so the bridge must only ever read `contentWindow`.
    expect(writes).toEqual([])
    expect(element.src).toBe('https://plugins.b10cks.test/field')
  })

  it('is released on destroy, silencing outbound posts', () => {
    const bridge = createBridge()

    bridge.destroy()
    bridge.updateValue(1)
    bridge.init(initPayload())

    expect(postToIframe).not.toHaveBeenCalled()
  })

  it('is a plain own property, so `readonly`/`private` are compile-time only', () => {
    const bridge = createBridge('handshake-1')

    // Pins ACTUAL behaviour: nothing at runtime stops host code (or anything
    // that gets a reference to the bridge) from swapping the token or the frame.
    ;(bridge as unknown as { token: string }).token = 'forged'

    expect(bridge.token).toBe('forged')
    expect(Object.keys(bridge)).toContain('iframeElement')
  })
})

describe('destroy', () => {
  it('stops dispatching inbound messages', () => {
    const listener = vi.fn()
    const bridge = createBridge()

    bridge.on('PLUGIN_READY', listener)
    bridge.destroy()
    deliver(message())

    expect(listener).not.toHaveBeenCalled()
  })

  it('is safe to call twice', () => {
    const bridge = createBridge()

    bridge.destroy()

    expect(() => bridge.destroy()).not.toThrow()
  })

  it('leaves a sibling bridge on the same iframe working', () => {
    const shared = iframe()
    const listener = vi.fn()
    const doomed = new PluginBridge(shared, 'tok')

    track(new PluginBridge(shared, 'tok')).on('PLUGIN_READY', listener)
    doomed.destroy()
    deliver(message())

    expect(listener).toHaveBeenCalledTimes(1)
  })

  it('keeps the token readable after destroy', () => {
    const bridge = createBridge('handshake-1')

    bridge.destroy()

    expect(bridge.token).toBe('handshake-1')
  })

  it('cannot be revived by registering a new listener', () => {
    const listener = vi.fn()
    const bridge = createBridge()

    bridge.destroy()
    bridge.on('PLUGIN_READY', listener)
    deliver(message())

    // `on` still records the listener, but the window listener is gone, so no
    // message can reach it again — a destroyed bridge is not reusable.
    expect(listener).not.toHaveBeenCalled()
  })
})
