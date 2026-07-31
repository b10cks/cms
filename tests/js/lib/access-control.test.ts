import { describe, expect, it } from 'vitest'

import type { AuthorizationPayload } from '~/types/authorization'

import {
  canAccessNavigationItem,
  canAccessRequirement,
  canAccessRouteByName,
  filterNavigationItems,
  firstAllowedRouteForSpace,
  firstAllowedRouteForTeam,
  getAbilitySet,
  getActionAccessRequirement,
  getRouteAccessRequirement,
  hasAbilityRequirement,
  spaceNavigationItems,
} from '~/lib/access-control'

const authorization = (
  overrides: Partial<AuthorizationPayload> & {
    spaceAbilities?: string[]
    teamAbilities?: string[]
  } = {}
): AuthorizationPayload => {
  const { spaceAbilities = [], teamAbilities = [], ...rest } = overrides

  return {
    user_id: 'user-1',
    is_root: false,
    teams: [],
    spaces: [],
    team: { id: 'team-1', role_keys: [], abilities: teamAbilities },
    space: { id: 'space-1', team_role_keys: [], abilities: spaceAbilities },
    roles: { team: [], space: [] },
    ...rest,
  }
}

describe('getAbilitySet', () => {
  it('merges team and space abilities', () => {
    const set = getAbilitySet(
      authorization({ teamAbilities: ['team.update'], spaceAbilities: ['space.view'] })
    )

    expect([...set].sort()).toEqual(['space.view', 'team.update'])
  })

  it('returns an empty set for a missing payload', () => {
    expect(getAbilitySet(null).size).toBe(0)
    expect(getAbilitySet(undefined).size).toBe(0)
  })
})

describe('hasAbilityRequirement', () => {
  it('passes when no requirement is given', () => {
    expect(hasAbilityRequirement(null, undefined)).toBe(true)
  })

  it('short-circuits for root users', () => {
    expect(hasAbilityRequirement(authorization({ is_root: true }), 'anything.at.all')).toBe(true)
  })

  it('matches a single ability string', () => {
    const auth = authorization({ spaceAbilities: ['content.view'] })

    expect(hasAbilityRequirement(auth, 'content.view')).toBe(true)
    expect(hasAbilityRequirement(auth, 'content.manage')).toBe(false)
  })

  it('requires one of anyOf', () => {
    const requirement = { anyOf: ['automations.view', 'automation_actions.view'] }

    expect(
      hasAbilityRequirement(authorization({ spaceAbilities: ['automations.view'] }), requirement)
    ).toBe(true)
    expect(hasAbilityRequirement(authorization({ spaceAbilities: ['blocks.view'] }), requirement)).toBe(
      false
    )
  })

  it('requires all of allOf', () => {
    const requirement = { allOf: ['content.view', 'blocks.view'] }

    expect(
      hasAbilityRequirement(
        authorization({ spaceAbilities: ['content.view', 'blocks.view'] }),
        requirement
      )
    ).toBe(true)
    expect(
      hasAbilityRequirement(authorization({ spaceAbilities: ['content.view'] }), requirement)
    ).toBe(false)
  })

  it('denies an empty requirement object — it names no ability to satisfy', () => {
    expect(hasAbilityRequirement(authorization({ spaceAbilities: ['content.view'] }), {})).toBe(
      false
    )
  })
})

describe('canAccessRequirement', () => {
  it('allows a null requirement', () => {
    expect(canAccessRequirement(null, { authorization: authorization() })).toBe(true)
  })

  it('allows root regardless of the requirement', () => {
    expect(
      canAccessRequirement(
        { check: () => false, abilities: 'nope' },
        { authorization: authorization({ is_root: true }) }
      )
    ).toBe(true)
  })

  it('resolves anyRouteOf against the nested route requirements', () => {
    const requirement = { anyRouteOf: ['space-settings-index', 'space-settings-backups'] }

    expect(
      canAccessRequirement(requirement, {
        authorization: authorization({ spaceAbilities: ['backups.view'] }),
      })
    ).toBe(true)
    expect(
      canAccessRequirement(requirement, {
        authorization: authorization({ spaceAbilities: ['content.view'] }),
      })
    ).toBe(false)
  })

  it('runs check() only after the ability gate passes', () => {
    let checked = false
    const requirement = {
      abilities: 'space.update',
      check: () => {
        checked = true
        return true
      },
    }

    expect(
      canAccessRequirement(requirement, { authorization: authorization({ spaceAbilities: [] }) })
    ).toBe(false)
    expect(checked).toBe(false)

    expect(
      canAccessRequirement(requirement, {
        authorization: authorization({ spaceAbilities: ['space.update'] }),
      })
    ).toBe(true)
    expect(checked).toBe(true)
  })
})

describe('canAccessRouteByName', () => {
  it('allows unknown routes — no requirement means no gate', () => {
    expect(canAccessRouteByName('some-public-page', { authorization: authorization() })).toBe(true)
  })

  it('gates the canvas on both content and blocks', () => {
    expect(
      canAccessRouteByName('space-canvas', {
        authorization: authorization({ spaceAbilities: ['content.view'] }),
      })
    ).toBe(false)
    expect(
      canAccessRouteByName('space-canvas', {
        authorization: authorization({ spaceAbilities: ['content.view', 'blocks.view'] }),
      })
    ).toBe(true)
  })

  it('gates provider routes on root', () => {
    expect(canAccessRouteByName('provider-dashboard', { authorization: authorization() })).toBe(
      false
    )
    expect(
      canAccessRouteByName('provider-dashboard', {
        authorization: authorization({ is_root: true }),
      })
    ).toBe(true)
  })
})

describe('canAccessNavigationItem', () => {
  it('uses visibilityRouteNames when present', () => {
    const settingsItem = spaceNavigationItems.find((item) => item.routeName === 'space-settings-index')!

    expect(settingsItem.visibilityRouteNames?.length).toBeGreaterThan(0)

    // Not space.update, but a settings sub-page the user can reach.
    expect(
      canAccessNavigationItem(settingsItem, {
        authorization: authorization({ spaceAbilities: ['migrations.view'] }),
      })
    ).toBe(true)
    expect(
      canAccessNavigationItem(settingsItem, {
        authorization: authorization({ spaceAbilities: ['content.view'] }),
      })
    ).toBe(false)
  })
})

describe('filterNavigationItems', () => {
  it('keeps only the items the abilities allow', () => {
    const filtered = filterNavigationItems(spaceNavigationItems, {
      authorization: authorization({ spaceAbilities: ['assets.view', 'icons.view'] }),
    })

    expect(filtered.map((item) => item.routeName)).toEqual([
      'space-assets-index',
      'space-icons-index',
    ])
  })

  it('keeps everything for root', () => {
    const filtered = filterNavigationItems(spaceNavigationItems, {
      authorization: authorization({ is_root: true }),
    })

    expect(filtered).toHaveLength(spaceNavigationItems.length)
  })
})

describe('firstAllowedRouteForSpace', () => {
  it('follows the declared navigation order, not the ability order', () => {
    expect(
      firstAllowedRouteForSpace('space-1', {
        authorization: authorization({ spaceAbilities: ['audit_logs.view', 'assets.view'] }),
      })
    ).toEqual({ name: 'space-assets-index', params: { space: 'space-1' } })
  })

  it('returns null when nothing is reachable', () => {
    expect(firstAllowedRouteForSpace('space-1', { authorization: authorization() })).toBeNull()
  })
})

describe('firstAllowedRouteForTeam', () => {
  it('falls through to the first reachable team tab', () => {
    expect(
      firstAllowedRouteForTeam('team-1', {
        authorization: authorization({ teamAbilities: ['team.saml.manage'] }),
      })
    ).toEqual({ name: 'team-saml', params: { team: 'team-1' } })
  })

  it('returns null when nothing is reachable', () => {
    expect(firstAllowedRouteForTeam('team-1', { authorization: authorization() })).toBeNull()
  })
})

describe('requirement lookups', () => {
  it('returns null for a missing or empty route name', () => {
    expect(getRouteAccessRequirement(null)).toBeNull()
    expect(getRouteAccessRequirement('')).toBeNull()
    expect(getRouteAccessRequirement('does-not-exist')).toBeNull()
  })

  it('exposes action requirements by key', () => {
    expect(getActionAccessRequirement('space.archive')).toEqual({ abilities: 'space.archive' })
    expect(getActionAccessRequirement('unknown.action')).toBeNull()
  })

  it('allows space creation via the selected team flag alone', () => {
    const requirement = getActionAccessRequirement('team.spaces.create')!

    expect(
      canAccessRequirement(requirement, {
        authorization: authorization(),
        selectedTeamCanCreateSpace: true,
      })
    ).toBe(true)
    expect(
      canAccessRequirement(requirement, {
        authorization: authorization(),
        selectedTeamCanCreateSpace: false,
      })
    ).toBe(false)
    expect(
      canAccessRequirement(requirement, {
        authorization: authorization({ teamAbilities: ['team.spaces.create'] }),
      })
    ).toBe(true)
  })
})
