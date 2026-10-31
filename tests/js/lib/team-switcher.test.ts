import { describe, expect, it } from 'vitest'

import { flattenTeamHierarchy } from '~/lib/team-hierarchy'
import { canMoveTeam, filterTeamTree, nestTeams, pushRecentTeam } from '~/lib/team-switcher'

const teams = flattenTeamHierarchy([
  { id: 'n', name: 'Northwind Studio', parent_id: null },
  { id: 'h', name: 'Helio Energy', parent_id: 'n' },
  { id: 'r', name: 'Helio Retail', parent_id: 'h' },
  { id: 'f', name: 'Fjord Bank', parent_id: 'n' },
])

describe('filterTeamTree', () => {
  it('keeps matches with their ancestor chain in tree order and starts at the best match', () => {
    const { rows, best } = filterTeamTree('hr', teams)

    expect(rows.map((row) => [row.team.id, row.isMatch])).toEqual([
      ['n', false],
      ['h', true],
      ['r', true],
    ])
    expect(rows[best].team.id).toBe('r')
    expect(rows[best].indices).toEqual([0, 6])
    expect(rows.map((row) => row.team.id)).not.toContain('f')
  })

  it('returns nothing for a blank query', () => {
    expect(filterTeamTree('  ', teams)).toEqual({ rows: [], best: -1 })
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
