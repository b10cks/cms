import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import type { TeamResource } from '~/types/teams'

const teamsIndex = vi.fn()

vi.mock('~/api', () => ({
  api: { teams: { index: teamsIndex } },
}))

// `useTeams` reaches `useAuth`, which imports the real router and with it every
// page chunk. Nothing here navigates.
vi.mock('~/router', () => ({
  router: { currentRoute: { value: { query: {}, meta: {}, fullPath: '/' } }, push: vi.fn() },
}))

vi.mock('~/plugins/posthog', () => ({
  getPosthog: () => ({ identify: vi.fn(), reset: vi.fn() }),
}))

vi.mock('vue-sonner', () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}))

const { useAuth } = await import('~/composables/useAuth')
const { useGlobalTeam } = await import('~/composables/useGlobalTeam')
const { queryKeys } = await import('~/composables/useQueryClient')

const { withSetup } = await import('../support/harness')
type Harness<T> = import('../support/harness').Harness<T>

const STORAGE_KEY = 'global-team'

// This jsdom build exposes no localStorage; `useGlobalTeam` persists the
// selected team in it. One shared instance so nothing vueuse captured goes stale.
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

const team = (id: string, overrides: Partial<TeamResource> = {}) =>
  ({
    id,
    name: `Team ${id}`,
    icon: 'lucide:users',
    color: 'blue',
    description: `${id} description`,
    type: 'company',
    user_count: 3,
    spaces_count: 2,
    can_create_space: false,
    ...overrides,
  }) as unknown as TeamResource

/** The exact params object `useGlobalTeam` hands `useTeamsQuery`. */
const TEAMS_PARAMS = { include_space_context: true, per_page: 1000 }

const stored = () => JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null')

let harness: Harness<unknown> | undefined

const mountGlobalTeam = (teams: TeamResource[] | null = []) => {
  const created = withSetup(() => useGlobalTeam(), {
    seed: teams ? [[queryKeys.teams.list(TEAMS_PARAMS), { data: teams }]] : [],
  })

  harness = created

  return created.result
}

beforeEach(() => {
  teamsIndex.mockReset()
  // No seed means a real fetch; keep it pending unless a test says otherwise.
  teamsIndex.mockReturnValue(new Promise(() => {}))
  window.localStorage.clear()
  useAuth().setUser({ id: 'user-1' } as never)
})

afterEach(() => {
  harness?.unmount()
  harness = undefined
  useAuth().setUser(null)
  vi.useRealTimers()
})

describe('initial selection', () => {
  it('auto-selects the first team when nothing is stored', () => {
    const globalTeam = mountGlobalTeam([team('team-1'), team('team-2')])

    expect(globalTeam.selectedTeamId.value).toBe('team-1')
    expect(globalTeam.selectedTeam.value?.id).toBe('team-1')
    expect(globalTeam.hasSelectedTeam.value).toBe(true)
  })

  it('restores a stored selection', () => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ selectedTeamId: 'team-2' }))

    const globalTeam = mountGlobalTeam([team('team-1'), team('team-2')])

    expect(globalTeam.selectedTeamId.value).toBe('team-2')
  })

  it('corrects a stored team the user no longer belongs to', () => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ selectedTeamId: 'gone' }))

    const globalTeam = mountGlobalTeam([team('team-1')])

    expect(globalTeam.selectedTeamId.value).toBe('team-1')
  })

  it('drops a stored selection when the user has no teams at all', () => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ selectedTeamId: 'gone' }))

    const globalTeam = mountGlobalTeam([])

    expect(globalTeam.selectedTeamId.value).toBeNull()
    expect(globalTeam.hasTeams.value).toBe(false)
  })

  it('leaves the stored selection alone while the team list is still loading', () => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ selectedTeamId: 'gone' }))

    const globalTeam = mountGlobalTeam(null)

    expect(globalTeam.isLoading.value).toBe(true)
    expect(globalTeam.selectedTeamId.value).toBe('gone')
    expect(globalTeam.selectedTeam.value).toBeNull()
  })

  it('validates the selection once the team list arrives', async () => {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ selectedTeamId: 'gone' }))
    teamsIndex.mockResolvedValue({ data: [team('team-1')] })

    const globalTeam = mountGlobalTeam(null)
    await vi.waitUntil(() => !globalTeam.isLoading.value)

    expect(globalTeam.selectedTeamId.value).toBe('team-1')
  })

  it.each([
    ['unparseable JSON', '{'],
    ['a non-string team id', JSON.stringify({ selectedTeamId: 42 })],
    ['an empty object', '{}'],
  ])('ignores %s in storage', (_label, value) => {
    window.localStorage.setItem(STORAGE_KEY, value)

    expect(mountGlobalTeam([team('team-1')]).selectedTeamId.value).toBe('team-1')
  })
})

describe('selectTeam', () => {
  it('accepts a team resource', async () => {
    const globalTeam = mountGlobalTeam([team('team-1'), team('team-2')])
    // The auto-select write suspends vueuse's persist watcher until the next
    // tick; a change made before then never reaches storage.
    await nextTick()

    globalTeam.selectTeam(team('team-2'))
    await nextTick()

    expect(globalTeam.selectedTeamId.value).toBe('team-2')
    expect(stored().selectedTeamId).toBe('team-2')
  })

  it('accepts a team id', () => {
    const globalTeam = mountGlobalTeam([team('team-1'), team('team-2')])

    globalTeam.selectTeam('team-2')

    expect(globalTeam.selectedTeam.value?.id).toBe('team-2')
  })

  it('records when the selection changed', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-29T10:00:00.000Z'))

    const globalTeam = mountGlobalTeam([team('team-1'), team('team-2')])

    globalTeam.selectTeam('team-2')

    expect(globalTeam.lastSelectedAt.value).toBe('2026-07-29T10:00:00.000Z')
  })

  it('ignores a re-selection of the same team', () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-07-29T10:00:00.000Z'))

    const globalTeam = mountGlobalTeam([team('team-1'), team('team-2')])
    globalTeam.selectTeam('team-2')

    vi.setSystemTime(new Date('2026-07-29T11:00:00.000Z'))
    globalTeam.selectTeam('team-2')

    expect(globalTeam.lastSelectedAt.value).toBe('2026-07-29T10:00:00.000Z')
  })

  it('accepts a team id that is not in the list, leaving the selection unresolvable', () => {
    // Pinned, not endorsed: nothing validates the id at selection time, and the
    // correcting watcher only runs when the team list itself changes.
    const globalTeam = mountGlobalTeam([team('team-1')])

    globalTeam.selectTeam('ghost')

    expect(globalTeam.selectedTeamId.value).toBe('ghost')
    expect(globalTeam.selectedTeam.value).toBeNull()
    expect(globalTeam.isValidSelection.value).toBe(false)
  })
})

describe('clearSelection and autoSelectFirstTeam', () => {
  it('clears the selection and the timestamp', async () => {
    const globalTeam = mountGlobalTeam([team('team-1')])
    await nextTick()

    globalTeam.clearSelection()
    await nextTick()

    expect(globalTeam.selectedTeamId.value).toBeNull()
    expect(globalTeam.lastSelectedAt.value).toBeNull()
    expect(stored()).toEqual({ selectedTeamId: null, lastSelectedAt: null })
  })

  it('leaves the cleared selection empty — the correcting watcher does not re-run', () => {
    const globalTeam = mountGlobalTeam([team('team-1')])

    globalTeam.clearSelection()

    // A user with teams and no selection: `isValidSelection` reports false,
    // which is what the UI has to recover from.
    expect(globalTeam.hasSelectedTeam.value).toBe(false)
    expect(globalTeam.isValidSelection.value).toBe(false)
  })

  it('restores the first team on demand', () => {
    const globalTeam = mountGlobalTeam([team('team-1'), team('team-2')])
    globalTeam.clearSelection()

    globalTeam.autoSelectFirstTeam()

    expect(globalTeam.selectedTeamId.value).toBe('team-1')
  })

  it('never overrides an existing selection', () => {
    const globalTeam = mountGlobalTeam([team('team-1'), team('team-2')])
    globalTeam.selectTeam('team-2')

    globalTeam.autoSelectFirstTeam()

    expect(globalTeam.selectedTeamId.value).toBe('team-2')
  })

  it('does nothing without teams', () => {
    const globalTeam = mountGlobalTeam([])

    globalTeam.autoSelectFirstTeam()

    expect(globalTeam.selectedTeamId.value).toBeNull()
  })
})

describe('derived state', () => {
  it('reports a null selection with no teams as valid', () => {
    expect(mountGlobalTeam([]).isValidSelection.value).toBe(true)
  })

  it('maps teams to select options', () => {
    const globalTeam = mountGlobalTeam([team('team-1', { name: 'Acme', user_count: 7 })])

    expect(globalTeam.teamOptions.value).toEqual([
      {
        label: 'Acme',
        value: 'team-1',
        icon: 'lucide:users',
        color: 'blue',
        description: 'team-1 description',
        type: 'company',
        userCount: 7,
        spacesCount: 2,
      },
    ])
  })

  it('finds a team by id and nothing for a nullish id', () => {
    const globalTeam = mountGlobalTeam([team('team-1')])

    expect(globalTeam.findTeamById('team-1')?.id).toBe('team-1')
    expect(globalTeam.findTeamById('ghost')).toBeNull()
    expect(globalTeam.findTeamById(null)).toBeNull()
    expect(globalTeam.findTeamById(undefined)).toBeNull()
  })

  it('reports the loading flags off the team list only', () => {
    const globalTeam = mountGlobalTeam([team('team-1')])

    expect(globalTeam.isLoading.value).toBe(false)
    expect(globalTeam.isLoadingTeams.value).toBe(false)
    // Constant placeholders: there is no separate selected-team request.
    expect(globalTeam.isLoadingSelectedTeam.value).toBe(false)
    expect(globalTeam.selectedTeamError.value).toBeNull()
  })

  it('surfaces the team list error', async () => {
    teamsIndex.mockRejectedValue(new Error('boom'))

    const globalTeam = mountGlobalTeam(null)
    await vi.waitUntil(() => globalTeam.teamsError.value !== null)

    expect((globalTeam.teamsError.value as Error).message).toBe('boom')
  })
})

describe('sharing the selection between callers', () => {
  it('propagates a selection to another instance in the same document', async () => {
    const created = withSetup(
      () => ({ first: useGlobalTeam(), second: useGlobalTeam() }),
      { seed: [[queryKeys.teams.list(TEAMS_PARAMS), { data: [team('team-1'), team('team-2')] }]] }
    )
    harness = created
    await nextTick()

    created.result.first.selectTeam('team-2')
    await nextTick()

    // Each caller builds its own useStorage ref; they stay in step only because
    // vueuse re-reads on its own storage event.
    expect(created.result.second.selectedTeamId.value).toBe('team-2')
  })
})
