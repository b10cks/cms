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

    this.notifyListeners(type as EventType, payload)
  }

  private postMessageToIframe(type: EventType, payload: EventPayloadMap[typeof type]): void {
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
    this.iframeElement = null
  }
}
