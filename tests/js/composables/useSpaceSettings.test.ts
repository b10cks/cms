import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import useSpaceSettings from '~/composables/useSpaceSettings'

import { withSetup, type Harness } from '../support/harness'

// This jsdom build exposes no localStorage, and useSpaceSettings is nothing but
// a persisted store. One shared instance so nothing vueuse captured goes stale.
const memoryStorage = (() => {
  const store = new Map<string, string>()

  return {
    get length() {
      return store.size
    },
    key: (index: number) => [...store.keys()][index] ?? null,
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => void store.set(key, String(value)),
    removeItem: (key: string) => void store.delete(key),
    clear: () => store.clear(),
  } as Storage
})()

Object.defineProperty(window, 'localStorage', { value: memoryStorage, configurable: true })

const storageKey = (spaceId: string) => `space-${spaceId}-settings`

const stored = (spaceId: string) =>
  JSON.parse(window.localStorage.getItem(storageKey(spaceId)) ?? 'null')

let harness: Harness<ReturnType<typeof useSpaceSettings>> | undefined

const setup = (spaceId = 'space-1') => {
  harness = withSetup(() => useSpaceSettings(spaceId))
  return harness.result
}

beforeEach(() => {
  window.localStorage.clear()
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
})

describe('defaults', () => {
  it('starts every section from its defaults', () => {
    const { settings } = setup()

    expect(settings.value.content).toEqual({
      environment: null,
      siteLocale: null,
      treeWidth: 20,
      showPreview: true,
      history: { mode: 'changes', panelHeight: 60 },
      expanded: [],
    })
    expect(settings.value.blocks).toEqual({ pageSize: 25 })
    expect(settings.value.assets.gridSize).toBe('md')
    expect(settings.value.dataEntries).toEqual({ mode: 'single', autoSave: true })
  })

  it('writes the defaults out under a space-scoped key', () => {
    setup('space-9')

    expect(stored('space-9').blocks).toEqual({ pageSize: 25 })
  })
})

describe('persistence', () => {
  it('stores a change', async () => {
    const { settings } = setup()

    settings.value.assets.gridSize = 'lg'
    await nextTick()

    expect(stored('space-1').assets.gridSize).toBe('lg')
  })

  it('restores a stored change', () => {
    window.localStorage.setItem(
      storageKey('space-1'),
      JSON.stringify({ blocks: { pageSize: 50 } })
    )

    expect(setup().settings.value.blocks.pageSize).toBe(50)
  })

  it('keeps each space on its own key', async () => {
    const first = setup('space-1')
    first.settings.value.blocks.pageSize = 50
    await nextTick()
    harness?.unmount()

    expect(setup('space-2').settings.value.blocks.pageSize).toBe(25)
  })

  it('falls back to the defaults for an unparseable value', () => {
    window.localStorage.setItem(storageKey('space-1'), '{')

    expect(setup().settings.value.blocks.pageSize).toBe(25)
  })

  it('deep-merges the defaults, so a section stored before a key existed still gets it', () => {
    // The live migration hazard: a `content` section written before `history`
    // and `showPreview` existed must not blank them out.
    window.localStorage.setItem(
      storageKey('space-1'),
      JSON.stringify({ content: { treeWidth: 30 } })
    )

    const { settings } = setup()

    expect(settings.value.content.treeWidth).toBe(30)
    expect(settings.value.blocks.pageSize).toBe(25)
    expect(settings.value.content.showPreview).toBe(true)
    expect(settings.value.content.history).toEqual({ mode: 'changes', panelHeight: 60 })
  })

  it('lets a stored array replace the default wholesale rather than merging it', () => {
    window.localStorage.setItem(
      storageKey('space-1'),
      JSON.stringify({ content: { expanded: ['content-1'] } })
    )

    expect(setup().settings.value.content.expanded).toEqual(['content-1'])
  })

  it('keeps a stored key the defaults no longer declare', () => {
    window.localStorage.setItem(storageKey('space-1'), JSON.stringify({ legacy: { mode: 'x' } }))

    expect(
      (setup().settings.value as unknown as Record<string, unknown>).legacy
    ).toEqual({ mode: 'x' })
  })

  it('propagates a change to another caller for the same space', async () => {
    const created = withSetup(() => ({
      first: useSpaceSettings('space-1'),
      second: useSpaceSettings('space-1'),
    }))
    harness = created as unknown as Harness<ReturnType<typeof useSpaceSettings>>
    await nextTick()

    created.result.first.settings.value.blocks.pageSize = 50
    await nextTick()

    expect(created.result.second.settings.value.blocks.pageSize).toBe(50)
  })
})

describe('reset', () => {
  it('discards a stored override', async () => {
    window.localStorage.setItem(
      storageKey('space-1'),
      JSON.stringify({ blocks: { pageSize: 50 } })
    )
    const spaceSettings = setup()
    // vueuse suspends its persist watcher for a tick after the hydration write;
    // a change made before then never reaches storage.
    await nextTick()

    spaceSettings.reset()
    await nextTick()

    expect(spaceSettings.settings.value.blocks.pageSize).toBe(25)
    expect(stored('space-1').blocks.pageSize).toBe(25)
  })

  it('undoes a change made in this session', async () => {
    // Each caller gets its own defaults object, so a write cannot dirty the one
    // reset() restores from.
    const spaceSettings = setup()

    spaceSettings.settings.value.assets.gridSize = 'lg'
    await nextTick()

    spaceSettings.reset()
    await nextTick()

    expect(spaceSettings.settings.value.assets.gridSize).toBe('md')
    expect(stored('space-1').assets.gridSize).toBe('md')
  })

  it('undoes a nested change in a section that came from storage', async () => {
    window.localStorage.setItem(
      storageKey('space-1'),
      JSON.stringify({ blocks: { pageSize: 50 } })
    )
    const spaceSettings = setup()

    spaceSettings.settings.value.assets.gridSize = 'lg'
    await nextTick()

    spaceSettings.reset()
    await nextTick()

    expect(spaceSettings.settings.value.blocks.pageSize).toBe(25)
    expect(spaceSettings.settings.value.assets.gridSize).toBe('md')
  })
})
