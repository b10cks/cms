import { beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import { useTone } from '~/composables/useTone'

// Node installs its own `localStorage` global that stays undefined without
// --localstorage-file, and in jsdom (where window === globalThis) it shadows
// the real one — so useStorage gets no backend unless a test provides it.
const entries = new Map<string, string>()

const storage = {
  get length() {
    return entries.size
  },
  key: (index: number) => [...entries.keys()][index] ?? null,
  getItem: (key: string) => entries.get(key) ?? null,
  setItem: (key: string, value: string) => {
    entries.set(key, String(value))
  },
  removeItem: (key: string) => {
    entries.delete(key)
  },
  clear: () => entries.clear(),
} as unknown as Storage

Object.defineProperty(window, 'localStorage', { value: storage, configurable: true })

beforeEach(() => {
  storage.clear()
})

describe('tones', () => {
  it('exposes the six emoji skin tones, neutral first', () => {
    expect([...useTone().tones]).toEqual([
      'neutral',
      '1f3fb',
      '1f3fc',
      '1f3fd',
      '1f3fe',
      '1f3ff',
    ])
  })
})

describe('tone', () => {
  it('defaults to neutral when nothing is stored', () => {
    expect(useTone().tone.value).toBe('neutral')
  })

  it('persists a change under the "tone" key', async () => {
    const { tone } = useTone()

    tone.value = '1f3fd'
    await nextTick()

    expect(storage.getItem('tone')).toBe('1f3fd')
  })

  it('reads a previously stored tone', () => {
    storage.setItem('tone', '1f3ff')

    expect(useTone().tone.value).toBe('1f3ff')
  })

  it('writes the default back to storage on first use', async () => {
    useTone()
    await nextTick()

    expect(storage.getItem('tone')).toBe('neutral')
  })

  it('shares the stored value across independent callers', async () => {
    const first = useTone()

    first.tone.value = '1f3fc'
    await nextTick()

    expect(useTone().tone.value).toBe('1f3fc')
  })

  it('keeps an unknown stored value as-is', async () => {
    // useStorage does not validate against `tones`, so a stale or hand-edited
    // localStorage entry flows straight through to the emoji lookup, which
    // simply finds no variant and renders the neutral glyph.
    storage.setItem('tone', 'not-a-tone')

    expect(useTone().tone.value).toBe('not-a-tone')
  })
})
