import { describe, expect, it } from 'vitest'

import { flattenTeamHierarchy } from '~/lib/team-hierarchy'
import { canMoveTeam, nestTeams, pushRecentTeam, searchTeams } from '~/lib/team-switcher'

const teams = flattenTeamHierarchy([
  { id: 'n', name: 'Northwind Studio', parent_id: null },
  { id: 'h', name: 'Helio Energy', parent_id: 'n' },
  { id: 'r', name: 'Helio Retail', parent_id: 'h' },
  { id: 'f', name: 'Fjord Bank', parent_id: 'n' },
])

describe('searchTeams', () => {
  it('ranks word-start matches above scattered ones and reports the matched characters', () => {
    const matches = searchTeams('hr', teams)

    expect(matches[0].team.id).toBe('r')
    expect(matches.map((match) => match.team.id)).not.toContain('f')
    expect(matches[0].indices).toEqual([0, 6])
    expect(matches[0].depth).toBe(2)
  })

  it('keeps tree order between equal scores and returns nothing for a blank query', () => {
    expect(searchTeams('helio', teams).map((match) => match.team.id)).toEqual(['h', 'r'])
    expect(searchTeams('  ', teams)).toEqual([])
  })
})

describe('pushRecentTeam', () => {
  it('moves the team to the front without duplicates and caps the list', () => {
    expect(pushRecentTeam(['a', 'b', 'c'], 'b')).toEqual(['b', 'a', 'c'])
    expect(pushRecentTeam(['a', 'b', 'c'], 'd', 3)).toEqual(['d', 'a', 'b'])
  })
})

describe('team moves', () => {
  const flat = [
    { id: 'n', parent_id: null },
    { id: 'h', parent_id: 'n' },
    { id: 'r', parent_id: 'h' },
    { id: 'f', parent_id: 'n' },
  ]

  it('nests a flat list', () => {
    const [root] = nestTeams(flat)
    expect(root.children.map((child) => child.team.id)).toEqual(['h', 'f'])
    expect(root.children[0].children[0].team.id).toBe('r')
  })

  it('refuses itself, its current parent and its own descendants as targets', () => {
    expect(canMoveTeam(flat, 'h', 'f')).toBe(true)
    expect(canMoveTeam(flat, 'h', 'h')).toBe(false)
    expect(canMoveTeam(flat, 'h', 'n')).toBe(false)
    expect(canMoveTeam(flat, 'h', 'r')).toBe(false)
  })
})
