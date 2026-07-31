import { describe, expect, it, vi } from 'vitest'

import { MessageEmitter } from '~/utils/message-emitter'

type Events = {
  PING: { n: number }
  PONG: { n: number }
}

// notifyListeners/clearListeners are protected — the bridges are the real
// subclasses, so the test uses one too rather than casting the protection away.
class TestEmitter extends MessageEmitter<Events> {
  emit<T extends keyof Events>(type: T, payload: Events[T]) {
    this.notifyListeners(type, payload)
  }

  clear() {
    this.clearListeners()
  }
}

describe('on', () => {
  it('delivers the payload to a listener', () => {
    const emitter = new TestEmitter()
    const listener = vi.fn()

    emitter.on('PING', listener)
    emitter.emit('PING', { n: 1 })

    expect(listener).toHaveBeenCalledWith({ n: 1 })
  })

  it('delivers to every listener in registration order', () => {
    const emitter = new TestEmitter()
    const calls: string[] = []

    emitter.on('PING', () => calls.push('first'))
    emitter.on('PING', () => calls.push('second'))
    emitter.emit('PING', { n: 1 })

    expect(calls).toEqual(['first', 'second'])
  })

  it('keeps event types separate', () => {
    const emitter = new TestEmitter()
    const ping = vi.fn()
    const pong = vi.fn()

    emitter.on('PING', ping)
    emitter.on('PONG', pong)
    emitter.emit('PING', { n: 1 })

    expect(ping).toHaveBeenCalledTimes(1)
    expect(pong).not.toHaveBeenCalled()
  })

  it('registers the same callback twice, so it fires twice', () => {
    const emitter = new TestEmitter()
    const listener = vi.fn()

    emitter.on('PING', listener)
    emitter.on('PING', listener)
    emitter.emit('PING', { n: 1 })

    expect(listener).toHaveBeenCalledTimes(2)
  })

  it('does nothing when nobody is listening', () => {
    expect(() => new TestEmitter().emit('PING', { n: 1 })).not.toThrow()
  })
})

describe('unsubscribe', () => {
  it('stops delivery to the removed listener only', () => {
    const emitter = new TestEmitter()
    const kept = vi.fn()
    const removed = vi.fn()

    emitter.on('PING', kept)
    const off = emitter.on('PING', removed)
    off()
    emitter.emit('PING', { n: 1 })

    expect(kept).toHaveBeenCalledTimes(1)
    expect(removed).not.toHaveBeenCalled()
  })

  it('is idempotent', () => {
    const emitter = new TestEmitter()
    const listener = vi.fn()
    const off = emitter.on('PING', listener)

    off()
    off()
    emitter.emit('PING', { n: 1 })

    expect(listener).not.toHaveBeenCalled()
  })

  it('removes every registration of a duplicated callback', () => {
    const emitter = new TestEmitter()
    const listener = vi.fn()

    emitter.on('PING', listener)
    const off = emitter.on('PING', listener)
    off()
    emitter.emit('PING', { n: 1 })

    // The unsubscribe filters by identity, so both copies go.
    expect(listener).not.toHaveBeenCalled()
  })
})

describe('clearListeners', () => {
  it('drops every listener across every event', () => {
    const emitter = new TestEmitter()
    const ping = vi.fn()
    const pong = vi.fn()

    emitter.on('PING', ping)
    emitter.on('PONG', pong)
    emitter.clear()
    emitter.emit('PING', { n: 1 })
    emitter.emit('PONG', { n: 1 })

    expect(ping).not.toHaveBeenCalled()
    expect(pong).not.toHaveBeenCalled()
  })

  it('leaves the emitter usable afterwards', () => {
    const emitter = new TestEmitter()
    const listener = vi.fn()

    emitter.clear()
    emitter.on('PING', listener)
    emitter.emit('PING', { n: 1 })

    expect(listener).toHaveBeenCalledTimes(1)
  })

  it('lets an unsubscribe returned before the clear run harmlessly', () => {
    const emitter = new TestEmitter()
    const off = emitter.on('PING', vi.fn())

    emitter.clear()

    expect(off).not.toThrow()
  })
})

describe('emitters are independent', () => {
  it('does not share listeners between instances', () => {
    const first = new TestEmitter()
    const second = new TestEmitter()
    const listener = vi.fn()

    first.on('PING', listener)
    second.emit('PING', { n: 1 })

    expect(listener).not.toHaveBeenCalled()
  })
})
