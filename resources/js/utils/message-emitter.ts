export type EventCallback<T> = (payload: T) => void

/**
 * Typed listener registry shared by the iframe bridges (PluginBridge,
 * PreviewBridge). Subclasses dispatch inbound messages via notifyListeners.
 */
export class MessageEmitter<M> {
  protected eventListeners: Partial<{ [K in keyof M]: Array<EventCallback<M[K]>> }> = {}

  protected notifyListeners<T extends keyof M>(type: T, payload: M[T]): void {
    this.eventListeners[type]?.forEach((listener) => listener(payload))
  }

  public on<T extends keyof M>(eventType: T, callback: EventCallback<M[T]>): () => void {
    const listeners = this.eventListeners[eventType] ?? (this.eventListeners[eventType] = [])
    listeners.push(callback)

    return () => {
      this.eventListeners[eventType] = this.eventListeners[eventType]?.filter(
        (listener) => listener !== callback
      )
    }
  }

  protected clearListeners(): void {
    this.eventListeners = {}
  }
}
