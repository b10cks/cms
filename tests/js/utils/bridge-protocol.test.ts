import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  BRIDGE_PROTOCOL,
  PreviewBridge,
  type InboundType,
  type OutboundPayloadMap,
} from '~/utils/preview-bridge'

/**
 * Drift alarm for the preview bridge protocol. The fixture is shared
 * byte-identical with the SDK (packages/client/src/__fixtures__), whose suite
 * asserts the preview side against the same messages.
 */
type ProtocolMessage = {
  type: string
  direction: 'editor-to-preview' | 'preview-to-editor'
  since: number
  examples: Record<string, unknown>[]
  invalid?: Record<string, unknown>[]
}

const fixture: {
  protocol: number
  ready: { type: string; payload: { protocol: number } }
  messages: ProtocolMessage[]
} = JSON.parse(readFileSync(resolve(__dirname, '../../fixtures/bridge-protocol.json'), 'utf8'))

const outbound = fixture.messages.filter(({ direction }) => direction === 'editor-to-preview')
const inbound = fixture.messages.filter(({ direction }) => direction === 'preview-to-editor')

/** COMMENTS_UPDATE and the COMMENT_* events are CMS-only, outside the SDK protocol. */
type ProtocolOutboundType = Exclude<keyof OutboundPayloadMap, 'COMMENTS_UPDATE'>

/** How the bridge's public API sends each editor-to-preview message. */
const senders: {
  [K in ProtocolOutboundType]: (bridge: PreviewBridge, payload: OutboundPayloadMap[K]) => void
} = {
  CONTENT_UPDATE: (bridge, { content }) => bridge.updateContent(content),
  CONTENT_PATCH: (bridge, patch) => bridge.pushChange([], [patch]),
  SELECT_UPDATE: (bridge, { selectedItem }) => bridge.updateSelectedItem(selectedItem),
  HOVER_UPDATE: (bridge, { selectedItem }) => bridge.updateHover(selectedItem),
  BLOCK_LABELS: (bridge, { labels }) => bridge.updateBlockLabels(labels),
  FIELD_CONFIG: (bridge, event) => bridge.sendFieldConfig(event),
  HIDDEN_BLOCKS: (bridge, { ids }) => bridge.updateHiddenBlocks(ids),
}

const postToIframe = vi.fn()
const contentWindow = { postMessage: postToIframe } as unknown as Window
let bridge: PreviewBridge

const deliver = (data: unknown) =>
  window.dispatchEvent(
    new MessageEvent('message', { data, origin: 'https://site.test', source: contentWindow })
  )

const send = (type: string, payload: Record<string, unknown>) =>
  (senders[type as ProtocolOutboundType] as (bridge: PreviewBridge, payload: unknown) => void)(
    bridge,
    payload
  )

beforeEach(() => {
  postToIframe.mockClear()
  bridge = new PreviewBridge({ contentWindow } as unknown as HTMLIFrameElement)
})

afterEach(() => bridge.destroy())

describe('bridge protocol fixture', () => {
  it('speaks the fixture protocol version', () => {
    deliver(fixture.ready)

    expect(BRIDGE_PROTOCOL).toBe(fixture.protocol)
    expect(bridge.protocol).toBe(fixture.protocol)
  })

  it('covers exactly the protocol messages the editor sends', () => {
    expect(Object.keys(senders).sort()).toEqual(
      [...new Set(outbound.map(({ type }) => type))].sort()
    )
  })

  it.each(outbound)('sends $type as the fixture describes', ({ type, examples }) => {
    deliver(fixture.ready)
    postToIframe.mockClear()

    examples.forEach((payload) => send(type, payload))

    expect(postToIframe.mock.calls).toEqual(examples.map((payload) => [{ type, payload }, '*']))
  })

  it.each(outbound.filter(({ since }) => since > 0))(
    'does not send $type to a preview on an older protocol',
    ({ type, since, examples }) => {
      deliver({ type: fixture.ready.type, payload: { protocol: since - 1 } })
      postToIframe.mockClear()

      examples.forEach((payload) => send(type, payload))

      expect(postToIframe).not.toHaveBeenCalled()
    }
  )

  it('drops editor-to-preview messages coming from the preview', () => {
    const inboundTypes = new Set(inbound.map(({ type }) => type))
    const listener = vi.fn()

    for (const { type, examples } of outbound.filter(({ type }) => !inboundTypes.has(type))) {
      bridge.on(type as InboundType, listener)
      examples.forEach((payload) => deliver({ type, payload }))
    }

    expect(listener).not.toHaveBeenCalled()
  })

  it.each(inbound)('accepts valid $type payloads and drops invalid ones', (message) => {
    const listener = vi.fn()
    bridge.on(message.type as InboundType, listener)

    for (const payload of [...message.examples, ...(message.invalid ?? [])]) {
      deliver({ type: message.type, payload })
    }

    expect(listener.mock.calls).toEqual(message.examples.map((payload) => [payload]))
  })
})
