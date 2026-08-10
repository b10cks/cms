import type { CommentResource } from '~/types/comments'

import { MessageEmitter } from './message-emitter'

export type ContentUpdateEvent = {
  content: Record<string, unknown>
}

export type SelectUpdateEvent = {
  selectedItem: string | null
}

export type FieldUpdateEvent = {
  itemId: string
  /** Path to the field within the block. Preferred by newer site SDKs. */
  path?: Array<string | number>
  /** @deprecated Flat field key kept for older site SDKs. */
  field?: string
  value: unknown
}

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

export type EventType =
  | 'CONTENT_UPDATE'
  | 'SELECT_UPDATE'
  | 'HOVER_UPDATE'
  | 'FIELD_UPDATE'
  | 'COMMENTS_UPDATE'
  | 'COMMENT_CLICK'
  | 'COMMENT_CREATE'
  | 'COMMENT_UPDATE'

export type EventPayloadMap = {
  CONTENT_UPDATE: ContentUpdateEvent
  FIELD_UPDATE: FieldUpdateEvent
  SELECT_UPDATE: SelectUpdateEvent
  HOVER_UPDATE: SelectUpdateEvent
  COMMENTS_UPDATE: CommentsUpdateEvent
  COMMENT_CLICK: CommentClickEvent
  COMMENT_CREATE: CommentCreateEvent
  COMMENT_UPDATE: CommentUpdateEvent
}

export type BridgeEvent = {
  type: EventType
  payload: ContentUpdateEvent | SelectUpdateEvent
}

/** Sent by the site SDK once its message listener is attached. */
const BRIDGE_READY = 'B10CKS_BRIDGE_READY'

/**
 * State (not transient) events: the latest payload of each is replayed
 * whenever the preview announces readiness, so a document that loads (or
 * navigates) after the editor sent them still catches up.
 */
const STATE_EVENTS: ReadonlySet<EventType> = new Set([
  'CONTENT_UPDATE',
  'SELECT_UPDATE',
  'COMMENTS_UPDATE',
])

export type PreviewBridgeOptions = {
  /**
   * Origins of the space's configured preview environments. Messages from any
   * other origin are dropped, so an iframe that navigated away from the
   * configured site can no longer drive the editor.
   */
  allowedOrigins?: string[]
  /** Origin outgoing messages are addressed to; defaults to the first allowed origin. */
  targetOrigin?: string
}

export class PreviewBridge extends MessageEmitter<EventPayloadMap> {
  private iframeElement: HTMLIFrameElement | null = null
  private allowedOrigins: Set<string>
  private targetOrigin: string
  private ready = false
  private lastState = new Map<EventType, EventPayloadMap[EventType]>()
  private readyListeners = new Set<() => void>()

  constructor(iframeElement: HTMLIFrameElement, options: PreviewBridgeOptions = {}) {
    super()
    this.iframeElement = iframeElement
    this.allowedOrigins = new Set(options.allowedOrigins ?? [])
    this.targetOrigin = options.targetOrigin ?? options.allowedOrigins?.[0] ?? '*'
    window.addEventListener('message', this.handleMessage)
  }

  private handleMessage = (event: MessageEvent): void => {
    if (!event.data || typeof event.data !== 'object') return
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
      this.handleReady()
      return
    }

    this.notifyListeners(type as EventType, payload)
  }

  /**
   * The preview document (re)announced readiness — its listener is attached,
   * so replay the current state. Runs again on every in-iframe navigation,
   * which is what keeps the new document in sync.
   */
  private handleReady(): void {
    this.ready = true
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
   * Fallback for site SDKs that predate the readiness announcement: start
   * posting directly. A later announcement still triggers a full replay, so
   * calling this early only risks a harmless duplicate send.
   */
  public markReady(): void {
    if (this.ready) return
    this.handleReady()
  }

  private postMessageToIframe(type: EventType, payload: EventPayloadMap[typeof type]): void {
    if (STATE_EVENTS.has(type)) {
      this.lastState.set(type, payload)
    }
    // Until the preview's listener is attached the message would be lost;
    // the stored state is replayed by handleReady instead.
    if (!this.ready) {
      return
    }

    this.post(type, payload)
  }

  private post(type: EventType, payload: EventPayloadMap[EventType]): void {
    if (!this.iframeElement || !this.iframeElement.contentWindow) {
      return
    }

    this.iframeElement.contentWindow.postMessage(
      {
        type,
        payload,
      },
      this.targetOrigin
    )
  }

  public updateContent(content: Record<string, unknown>): void {
    this.postMessageToIframe('CONTENT_UPDATE', { content })
  }

  public updateSelectedItem(selectedItem: string | null): void {
    this.postMessageToIframe('SELECT_UPDATE', { selectedItem })
  }

  public updateHover(selectedItem: string | null): void {
    this.postMessageToIframe('HOVER_UPDATE', { selectedItem })
  }

  public updateComments(comments: CommentResource[]): void {
    const positionComments = comments.filter(
      (c) => c.position && c.position.x !== undefined && c.position.y !== undefined
    )
    this.postMessageToIframe('COMMENTS_UPDATE', { comments: positionComments })
  }

  public destroy(): void {
    window.removeEventListener('message', this.handleMessage)
    this.clearListeners()
    this.readyListeners.clear()
    this.lastState.clear()
    this.iframeElement = null
  }
}
