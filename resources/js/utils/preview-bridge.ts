import type { CommentResource } from '~/types/comments'

import { MessageEmitter } from './message-emitter'

/**
 * Editor side of the preview bridge. The site SDK (`@b10cks/client`,
 * `preview-bridge.ts`) is the other side; tests/fixtures/bridge-protocol.json
 * is shared byte-identical with the SDK so the two cannot drift silently.
 */

/** Bridge protocol this editor speaks. */
export const BRIDGE_PROTOCOL = 3

/** Addresses a field within a block, through nested objects and arrays. */
export type FieldPath = Array<string | number>

export type ContentUpdateEvent = {
  content: Record<string, unknown>
}

/**
 * Replace the value at `path`, relative to block `itemId`, or to the root when
 * `itemId` is omitted. Protocol 1.
 */
export type ContentPatchEvent = {
  itemId?: string
  path: FieldPath
  value: unknown
}

/** `selectedItem` is null when the selection or hover is cleared. */
export type SelectUpdateEvent = {
  selectedItem: string | null
}

/** Display names of the space's blocks, keyed by block slug. Protocol 1. */
export type BlockLabelsEvent = {
  labels: Record<string, string>
}

export type FieldUpdateEvent = {
  itemId: string
  /** Path to the field within the block. Preferred by newer site SDKs. */
  path?: FieldPath
  /** @deprecated Flat field key kept for older site SDKs. */
  field?: string
  value: unknown
}

/** Open the editor's own field editor for the field at `path` in block `itemId`. */
export type FieldSelectEvent = {
  itemId: string
  path: FieldPath
}

/** Settings of a rich text field that the preview's in-place editor honours. */
export type RichTextFieldConfig = {
  features?: Partial<Record<RichTextFeature, boolean>>
  headingLevels?: HeadingLevel[]
}

/**
 * Answer to FIELD_SELECT for a rich text field the user may edit: the preview
 * may edit it in place, with these settings. Protocol 3.
 */
export type FieldConfigEvent = {
  itemId: string
  path: FieldPath
  richtext: RichTextFieldConfig
}

/**
 * Ids of every hidden block in the edited content, at any depth, in document
 * order. Protocol 2.
 */
export type HiddenBlocksEvent = {
  ids: string[]
}

/** `hide` and `show` (protocol 2) set the target state, so they are idempotent. */
export const BLOCK_ACTIONS = [
  'move-up',
  'move-down',
  'duplicate',
  'delete',
  'insert-before',
  'insert-after',
  'hide',
  'show',
] as const

export type BlockAction = (typeof BLOCK_ACTIONS)[number]

export type BlockActionEvent = {
  itemId: string
  action: BlockAction
}

/** Drag and drop: move block `itemId` before or after block `targetId`. */
export type BlockMoveEvent = {
  itemId: string
  targetId: string
  position: 'before' | 'after'
}

// CMS-only events (comment pins). Not part of the SDK protocol.
export type CommentsUpdateEvent = {
  comments: CommentResource[]
}

export type CommentClickEvent = {
  commentId: string
}

export type CommentCreateEvent = {
  x: number
  y: number
  body: string
}

export type CommentUpdateEvent = {
  commentId: string
  x: number
  y: number
  body?: string
  isResolved?: boolean
}

/** Messages the editor sends to the preview. */
export type OutboundPayloadMap = {
  CONTENT_UPDATE: ContentUpdateEvent
  CONTENT_PATCH: ContentPatchEvent
  SELECT_UPDATE: SelectUpdateEvent
  HOVER_UPDATE: SelectUpdateEvent
  BLOCK_LABELS: BlockLabelsEvent
  FIELD_CONFIG: FieldConfigEvent
  HIDDEN_BLOCKS: HiddenBlocksEvent
  COMMENTS_UPDATE: CommentsUpdateEvent
}

/** Messages the preview sends to the editor. */
export type InboundPayloadMap = {
  SELECT_UPDATE: SelectUpdateEvent
  FIELD_UPDATE: FieldUpdateEvent
  FIELD_SELECT: FieldSelectEvent
  BLOCK_ACTION: BlockActionEvent
  BLOCK_MOVE: BlockMoveEvent
  COMMENT_CLICK: CommentClickEvent
  COMMENT_CREATE: CommentCreateEvent
  COMMENT_UPDATE: CommentUpdateEvent
}

type OutboundType = keyof OutboundPayloadMap
export type InboundType = keyof InboundPayloadMap

/** Sent by the site SDK once its message listener is attached. */
const BRIDGE_READY = 'B10CKS_BRIDGE_READY'

/** The protocol that introduced an outbound event; older previews don't get it. */
const SINCE_PROTOCOL: Partial<Record<OutboundType, number>> = {
  CONTENT_PATCH: 1,
  BLOCK_LABELS: 1,
  HIDDEN_BLOCKS: 2,
  FIELD_CONFIG: 3,
}

/**
 * State (not transient) events: the latest payload of each is replayed
 * whenever the preview announces readiness, so a document that loads (or
 * navigates) after the editor sent them still catches up.
 */
const STATE_EVENTS: ReadonlySet<OutboundType> = new Set([
  'CONTENT_UPDATE',
  'SELECT_UPDATE',
  'BLOCK_LABELS',
  'HIDDEN_BLOCKS',
  'COMMENTS_UPDATE',
])

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
const isId = (value: unknown): value is string => typeof value === 'string' && value !== ''
const isNumber = (value: unknown): value is number =>
  typeof value === 'number' && Number.isFinite(value)
const isFieldPath = (value: unknown): value is FieldPath =>
  Array.isArray(value) &&
  value.every(
    (segment) =>
      (typeof segment === 'string' && segment !== '') || (Number.isInteger(segment) && segment >= 0)
  )

/** Shape checks for inbound payloads; anything that fails is dropped. */
const INBOUND_VALIDATORS: {
  [K in InboundType]: (payload: Record<string, unknown>) => boolean
} = {
  SELECT_UPDATE: (p) => p.selectedItem === null || isId(p.selectedItem),
  FIELD_UPDATE: (p) =>
    isId(p.itemId) && 'value' in p && (p.path === undefined ? isId(p.field) : isFieldPath(p.path)),
  FIELD_SELECT: (p) => isId(p.itemId) && isFieldPath(p.path),
  BLOCK_ACTION: (p) => isId(p.itemId) && BLOCK_ACTIONS.some((action) => action === p.action),
  BLOCK_MOVE: (p) =>
    isId(p.itemId) &&
    isId(p.targetId) &&
    p.itemId !== p.targetId &&
    (p.position === 'before' || p.position === 'after'),
  COMMENT_CLICK: (p) => isId(p.commentId),
  COMMENT_CREATE: (p) => isNumber(p.x) && isNumber(p.y) && typeof p.body === 'string',
  COMMENT_UPDATE: (p) =>
    isId(p.commentId) &&
    isNumber(p.x) &&
    isNumber(p.y) &&
    (p.body === undefined || typeof p.body === 'string') &&
    (p.isResolved === undefined || typeof p.isResolved === 'boolean'),
}

const isInboundType = (type: unknown): type is InboundType =>
  typeof type === 'string' && Object.hasOwn(INBOUND_VALIDATORS, type)

export type PreviewBridgeOptions = {
  /**
   * Origins of the space's configured preview environments. Messages from any
   * other origin are dropped, so an iframe that navigated away from the
   * configured site can no longer drive the editor.
   */
  allowedOrigins?: string[]
  /** Origin outgoing messages are addressed to; defaults to the first allowed origin. */
  targetOrigin?: string
  /**
   * Id of the content the preview renders, read on every push so it can change
   * with the edited content. Only a CONTENT_UPDATE carrying it describes the
   * whole tree and is kept as the replay snapshot.
   */
  rootId?: () => string | null | undefined
}

export class PreviewBridge extends MessageEmitter<InboundPayloadMap> {
  private iframeElement: HTMLIFrameElement | null = null
  private allowedOrigins: Set<string>
  private targetOrigin: string
  private rootId: () => string | null | undefined
  private ready = false
  /** Protocol of the latest readiness announcement; 0 for SDKs that send none. */
  private previewProtocol = 0
  /** Whether a readiness announcement arrived since the last load fallback. */
  private announced = false
  private hiddenBlocks: string[] | null = null
  private lastState = new Map<OutboundType, OutboundPayloadMap[OutboundType]>()
  private readyListeners = new Set<() => void>()

  constructor(iframeElement: HTMLIFrameElement, options: PreviewBridgeOptions = {}) {
    super()
    this.iframeElement = iframeElement
    this.allowedOrigins = new Set(options.allowedOrigins ?? [])
    this.targetOrigin = options.targetOrigin ?? options.allowedOrigins?.[0] ?? '*'
    this.rootId = options.rootId ?? (() => null)
    window.addEventListener('message', this.handleMessage)
  }

  get protocol(): number {
    return this.previewProtocol
  }

  private handleMessage = (event: MessageEvent): void => {
    if (!isRecord(event.data)) return
    // Only the preview iframe itself may talk to the editor — any other
    // window (a popup, a sibling iframe) holding a reference to the console
    // is ignored, as is a configured-origin mismatch.
    if (!this.iframeElement?.contentWindow || event.source !== this.iframeElement.contentWindow) {
      return
    }
    if (this.allowedOrigins.size > 0 && !this.allowedOrigins.has(event.origin)) {
      return
    }
    const { type, payload } = event.data

    if (type === BRIDGE_READY) {
      const protocol = isRecord(payload) ? payload.protocol : undefined
      this.announced = true
      this.handleReady(Number.isInteger(protocol) ? (protocol as number) : 0)
      return
    }

    if (!isInboundType(type) || !isRecord(payload) || !INBOUND_VALIDATORS[type](payload)) return

    // Validated against the shape of InboundPayloadMap[type] above.
    this.notifyListeners(type, payload as InboundPayloadMap[typeof type])
  }

  /**
   * The preview document (re)announced readiness — its listener is attached,
   * so replay the current state. Runs again on every in-iframe navigation,
   * which is what keeps the new document in sync.
   */
  private handleReady(protocol: number): void {
    this.ready = true
    this.previewProtocol = protocol
    for (const [type, payload] of this.lastState) {
      this.post(type, payload)
    }
    this.readyListeners.forEach((listener) => listener())
  }

  /** Runs on every readiness announcement, not just the first. */
  public onReady(listener: () => void): () => void {
    this.readyListeners.add(listener)
    return () => this.readyListeners.delete(listener)
  }

  /**
   * Fallback for site SDKs that predate the readiness announcement: replay the
   * current state and start posting directly. Runs after every document load,
   * not just the first — an in-iframe navigation leaves such an SDK with a
   * document that was never sent anything. Replays are idempotent, so a
   * duplicate after a real announcement is harmless. The protocol stays what
   * the document announced; a document that announced nothing since the
   * previous fallback (one that navigated to a site on such an SDK) gets
   * protocol 0.
   */
  public markReady(): void {
    const protocol = this.announced ? this.previewProtocol : 0
    this.announced = false
    this.handleReady(protocol)
  }

  /**
   * A CONTENT_UPDATE is only a usable snapshot for a document that holds
   * nothing yet when it carries the whole tree — block-scoped pushes are
   * patches against a tree the receiver already has. Replaying one as the
   * content state would hand a freshly loaded document a single block instead
   * of the page.
   */
  private isReplayableState<T extends OutboundType>(
    type: T,
    payload: OutboundPayloadMap[T]
  ): boolean {
    if (!STATE_EVENTS.has(type)) return false
    if (type !== 'CONTENT_UPDATE') return true

    const rootId = this.rootId()
    if (!rootId) return true

    return (payload as ContentUpdateEvent).content?.id === rootId
  }

  /**
   * Record state for replay and, once the preview listens, post it. With
   * `post` false only the replay state is kept.
   */
  private send<T extends OutboundType>(type: T, payload: OutboundPayloadMap[T], post = true): void {
    if (this.isReplayableState(type, payload)) {
      this.lastState.set(type, payload)
    }
    // Until the preview's listener is attached the message would be lost;
    // the stored state is replayed by handleReady instead.
    if (post && this.ready) {
      this.post(type, payload)
    }
  }

  private post<T extends OutboundType>(type: T, payload: OutboundPayloadMap[T]): void {
    if (!this.iframeElement?.contentWindow) return
    if ((SINCE_PROTOCOL[type] ?? 0) > this.previewProtocol) return

    this.iframeElement.contentWindow.postMessage({ type, payload }, this.targetOrigin)
  }

  public updateContent(content: Record<string, unknown>): void {
    this.pushChange([content])
  }

  /**
   * Push the content changes of one editor tick. `patches` describe the same
   * change field by field; previews on protocol 1 and newer get those
   * instead of the CONTENT_UPDATE payloads, which are still kept as replay
   * state.
   */
  public pushChange(contents: Record<string, unknown>[], patches: ContentPatchEvent[] = []): void {
    const asPatches = patches.length > 0 && this.previewProtocol >= 1
    for (const content of contents) {
      this.send('CONTENT_UPDATE', { content }, !asPatches)
    }
    if (asPatches) {
      patches.forEach((patch) => this.send('CONTENT_PATCH', patch))
    }
  }

  public updateSelectedItem(selectedItem: string | null): void {
    this.send('SELECT_UPDATE', { selectedItem })
  }

  public updateHover(selectedItem: string | null): void {
    this.send('HOVER_UPDATE', { selectedItem })
  }

  public updateBlockLabels(labels: Record<string, string>): void {
    this.send('BLOCK_LABELS', { labels })
  }

  /** Let the preview edit a rich text field in place, in answer to its FIELD_SELECT. */
  public sendFieldConfig(event: FieldConfigEvent): void {
    this.send('FIELD_CONFIG', event)
  }

  /** Send the hidden blocks when they changed; the first call always sends. */
  public updateHiddenBlocks(ids: string[]): void {
    const last = this.hiddenBlocks
    if (last && last.length === ids.length && last.every((id, i) => id === ids[i])) return

    this.hiddenBlocks = ids
    this.send('HIDDEN_BLOCKS', { ids })
  }

  public updateComments(comments: CommentResource[]): void {
    const positionComments = comments.filter(
      (c) => c.position && c.position.x !== undefined && c.position.y !== undefined
    )
    this.send('COMMENTS_UPDATE', { comments: positionComments })
  }

  public destroy(): void {
    window.removeEventListener('message', this.handleMessage)
    this.clearListeners()
    this.readyListeners.clear()
    this.lastState.clear()
    this.iframeElement = null
  }
}
