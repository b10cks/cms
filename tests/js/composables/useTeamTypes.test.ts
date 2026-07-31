import { describe, expect, it } from 'vitest'

import { TEAM_TYPE_KEYS, useTeamTypes } from '~/composables/useTeamTypes'

const { teamTypeOptions, getTeamTypeLabel } = useTeamTypes()

describe('TEAM_TYPE_KEYS', () => {
  it('mirrors the backend enum exactly', () => {
    expect([...TEAM_TYPE_KEYS]).toEqual(['partner', 'reseller', 'affiliate'])
  })
})

describe('teamTypeOptions', () => {
  it('pairs every key with its translated label', () => {
    expect(teamTypeOptions.value).toEqual([
      { value: 'partner', label: 'Partner' },
      { value: 'reseller', label: 'Reseller' },
      { value: 'affiliate', label: 'Affiliate' },
    ])
  })

  it('covers every key, so a new enum member cannot be forgotten', () => {
    expect(teamTypeOptions.value.map((option) => option.value)).toEqual([...TEAM_TYPE_KEYS])
  })
})

describe('getTeamTypeLabel', () => {
  it.each([
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
