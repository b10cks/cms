import { config } from '@vue/test-utils'
import { beforeEach } from 'vitest'

import { i18n } from '~/plugins/i18n'

// `~/lib/runtime-config` reads window.__APP_CONFIG__ once at module load, and
// several modules read it at *their* module load (access-control builds its
// navigation arrays from the billing flag). Setting it here — before any test
// file imports anything — keeps that evaluation deterministic instead of
// depending on which test ran first.
window.__APP_CONFIG__ = {
  version: 'test',
  apiBaseUrl: 'https://api.b10cks.test',
  docsUrl: 'https://docs.b10cks.test',
  features: {
    billing: true,
    ai: true,
    realtime: false,
    registration: true,
  },
  ilum: { baseURL: '/ilum' },
}

// jsdom ships no ResizeObserver; reka-ui primitives instantiate one on mount.
globalThis.ResizeObserver ??= class {
  observe() {}
  unobserve() {}
  disconnect() {}
} as unknown as typeof ResizeObserver

// reka-ui's Select/Combobox trigger drives pointer capture and scrolls the
// active option into view. jsdom implements none of it, and without these the
// portalled listbox never opens, so its content cannot be asserted at all.
Element.prototype.hasPointerCapture ??= () => false
Element.prototype.setPointerCapture ??= () => {}
Element.prototype.releasePointerCapture ??= () => {}
Element.prototype.scrollIntoView ??= () => {}

// Node installs its own `localStorage` global, which is `undefined` unless
// --localstorage-file is passed — and because jsdom's `window === globalThis`,
// that undefined value shadows jsdom's real implementation. Every
// `useStorage`-backed composable would silently degrade to a detached ref, so
// tests would pass while exercising nothing. Give them a real backend.
if (!window.localStorage) {
  const store = new Map<string, string>()

  const memoryStorage: Storage = {
    get length() {
      return store.size
    },
    key: (index) => [...store.keys()][index] ?? null,
    getItem: (key) => store.get(key) ?? null,
    setItem: (key, value) => void store.set(key, String(value)),
    removeItem: (key) => void store.delete(key),
    clear: () => store.clear(),
  }

  Object.defineProperty(window, 'localStorage', { value: memoryStorage, configurable: true })
}

// Persisted state must not travel between test files.
beforeEach(() => {
  window.localStorage.clear()
})

// The real i18n instance rather than a `$t` stub: `~/plugins/i18n` exports a
// standalone Composer that works without an app, so composables already
// translate for real in tests. Installing it here makes template `$t` agree
// with them, and asserting on real copy catches a missing key — a stub would
// happily echo one back.
config.global.plugins = [i18n]
