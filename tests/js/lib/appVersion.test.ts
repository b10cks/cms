import { beforeEach, describe, expect, it, vi } from 'vitest'

// The newer release is module state, so every case starts from a fresh one.
const load = async () => {
  vi.resetModules()

  return import('~/lib/appVersion')
}

describe('noteServerRelease', () => {
  let appVersion: Awaited<ReturnType<typeof load>>

  beforeEach(async () => {
    appVersion = await load()
  })

  it('stays quiet while the server runs the release this tab loaded', () => {
    appVersion.noteServerRelease('test')
    appVersion.noteServerRelease(null)

    expect(appVersion.outdatedBy.value).toBeNull()
  })

  it('ignores the header when the tab does not know its own release', () => {
    appVersion.noteServerRelease('v2', '')

    expect(appVersion.outdatedBy.value).toBeNull()
  })

  it('reports a release that differs from the loaded one', () => {
    appVersion.noteServerRelease('v2')

    expect(appVersion.outdatedBy.value).toBe('v2')
  })
})
