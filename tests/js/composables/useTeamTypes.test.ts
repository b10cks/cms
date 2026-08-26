import { describe, expect, it } from 'vitest'

import {
  ASSIGNABLE_TEAM_TYPE_KEYS,
  TEAM_TYPE_KEYS,
  useTeamTypes,
} from '~/composables/useTeamTypes'

const { teamTypeOptions, assignableTeamTypeOptions, getTeamTypeLabel } = useTeamTypes()

describe('TEAM_TYPE_KEYS', () => {
  it('mirrors the backend enum exactly', () => {
    expect([...TEAM_TYPE_KEYS]).toEqual(['personal', 'partner', 'reseller', 'affiliate'])
  })

  // `personal` is stamped on the team a new user gets, so it has to validate
  // and translate — but offering it in a picker would let root mark any team as
  // somebody's personal team.
  it('keeps personal out of the assignable set', () => {
    expect([...ASSIGNABLE_TEAM_TYPE_KEYS]).toEqual(['partner', 'reseller', 'affiliate'])
  })
})

describe('teamTypeOptions', () => {
  it('pairs every key with its translated label', () => {
    expect(teamTypeOptions.value).toEqual([
      { value: 'personal', label: 'Personal' },
      { value: 'partner', label: 'Partner' },
      { value: 'reseller', label: 'Reseller' },
      { value: 'affiliate', label: 'Affiliate' },
    ])
  })

  it('covers every key, so a new enum member cannot be forgotten', () => {
    expect(teamTypeOptions.value.map((option) => option.value)).toEqual([...TEAM_TYPE_KEYS])
  })

  it('offers only the assignable keys for picking', () => {
    expect(assignableTeamTypeOptions.value.map((option) => option.value)).toEqual([
      ...ASSIGNABLE_TEAM_TYPE_KEYS,
    ])
  })
})

describe('getTeamTypeLabel', () => {
  it.each([
    ['personal', 'Personal'],
    ['partner', 'Partner'],
    ['reseller', 'Reseller'],
    ['affiliate', 'Affiliate'],
  ])('translates %s', (type, expected) => {
    expect(getTeamTypeLabel(type)).toBe(expected)
  })

  it.each([undefined, null, ''])('returns an empty string for %s', (type) => {
    expect(getTeamTypeLabel(type)).toBe('')
  })

  it('falls back to the raw value for an unknown type', () => {
    // vue-i18n echoes a missing key back, so an unexpected value from the API
    // would otherwise render "labels.teams.types.ghost" in the UI.
    expect(getTeamTypeLabel('ghost')).toBe('ghost')
  })
})
