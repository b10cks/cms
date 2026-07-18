import { MessageEmitter } from './message-emitter'

export const PLUGIN_PROTOCOL_VERSION = 1

export type PluginTheme = 'light' | 'dark'

export type PluginInitPayload = {
  value: unknown
  /** Manifest defaults merged with the field schema's options. */
  options: Record<string, string>
  context: {
    spaceId: string
    fieldKey: string
    language?: string
    readOnly: boolean
    isModal: boolean
  }
  theme: PluginTheme
}

export type PluginMessageType =
  // plugin -> host
  | 'PLUGIN_READY'
  | 'VALUE_CHANGE'
  | 'HEIGHT_CHANGE'
  | 'MODAL_TOGGLE'
  | 'ASSET_SELECT_REQUEST'
  // host -> plugin
  | 'INIT'
  | 'VALUE_UPDATE'
  | 'READ_ONLY_UPDATE'
  | 'ASSET_SELECT_RESULT'
  | 'THEME'

export type PluginMessagePayloadMap = {
  PLUGIN_READY: Record<string, never>
  VALUE_CHANGE: { value: unknown }
  HEIGHT_CHANGE: { height: number }
  MODAL_TOGGLE: { open: boolean }
  ASSET_SELECT_REQUEST: { requestId: string, fileTypes?: string[] }
  INIT: PluginInitPayload
  VALUE_UPDATE: { value: unknown }
  READ_ONLY_UPDATE: { readOnly: boolean }
  ASSET_SELECT_RESULT: { requestId: string, asset: null, error?: string }
  THEME: { theme: PluginTheme }
}

export type PluginMessage<T extends PluginMessageType = PluginMessageType> = {
  source: 'b10cks-plugin'
  version: typeof PLUGIN_PROTOCOL_VERSION
  token: string
  type: T
  payload: PluginMessagePayloadMap[T]
}

/**
 * Host side of the field-plugin postMessage protocol. Unlike PreviewBridge
 * (which talks to the trusted site preview) this bridge treats the iframe as
 * untrusted: inbound messages must originate from the plugin's contentWindow
 * and carry the handshake token the host minted for this mount. The token is
 * delivered via the URL fragment, so it never reaches the server or its logs.
 * targetOrigin stays '*' because sandboxed frames have an opaque origin.
 */
export class PluginBridge extends MessageEmitter<PluginMessagePayloadMap> {
  private iframeElement: HTMLIFrameElement | null = null

  public readonly token: string

  constructor(iframeElement: HTMLIFrameElement, token?: string) {
    super()
    this.iframeElement = iframeElement
    this.token = token ?? crypto.randomUUID()
    window.addEventListener('message', this.handleMessage)
  }

  private handleMessage = (event: MessageEvent): void => {
    if (!this.iframeElement || event.source !== this.iframeElement.contentWindow) return

    const data = event.data as Partial<PluginMessage> | null
    if (
      !data
      || typeof data !== 'object'
      || data.source !== 'b10cks-plugin'
      || data.token !== this.token
      || data.version !== PLUGIN_PROTOCOL_VERSION
      || typeof data.type !== 'string'
    ) return

    this.notifyListeners(data.type, data.payload as PluginMessagePayloadMap[PluginMessageType])
  }

  public post<T extends PluginMessageType>(type: T, payload: PluginMessagePayloadMap[T]): void {
    if (!this.iframeElement?.contentWindow) return

    const message: PluginMessage<T> = {
      source: 'b10cks-plugin',
      version: PLUGIN_PROTOCOL_VERSION,
      token: this.token,
      type,
      payload,
    }

    this.iframeElement.contentWindow.postMessage(message, '*')
  }

  public init(payload: PluginInitPayload): void {
    this.post('INIT', payload)
  }

  public updateValue(value: unknown): void {
    this.post('VALUE_UPDATE', { value })
  }

  public updateReadOnly(readOnly: boolean): void {
    this.post('READ_ONLY_UPDATE', { readOnly })
  }

  public updateTheme(theme: PluginTheme): void {
    this.post('THEME', { theme })
  }

  public destroy(): void {
    window.removeEventListener('message', this.handleMessage)
    this.clearListeners()
    this.iframeElement = null
  }
}
