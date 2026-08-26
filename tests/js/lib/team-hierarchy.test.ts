import { describe, expect, it } from 'vitest'

import {
  flattenNestedTeams,
  flattenTeamHierarchy,
  type HierarchicalTeam,
} from '~/lib/team-hierarchy'

const team = (id: string, name: string, parent_id: string | null = null): HierarchicalTeam => ({
  id,
  name,
  parent_id,
})

const shape = (teams: HierarchicalTeam[]) =>
  flattenTeamHierarchy(teams).map(({ team, depth }) => `${'-'.repeat(depth)}${team.name}`)

describe('flattenTeamHierarchy', () => {
  it('puts a parent before its children', () => {
    expect(shape([team('c', 'Child', 'p'), team('p', 'Parent')])).toEqual(['Parent', '-Child'])
  })

  it('sorts siblings by name at every level', () => {
    expect(
      shape([
        team('p', 'Parent'),
        team('b', 'Beta', 'p'),
        team('a', 'Alpha', 'p'),
        team('z', 'Zulu'),
      ])
    ).toEqual(['Parent', '-Alpha', '-Beta', 'Zulu'])
  })

  it('nests deeper levels', () => {
    expect(
      shape([team('a', 'A'), team('b', 'B', 'a'), team('c', 'C', 'b')])
    ).toEqual(['A', '-B', '--C'])
  })

  // Filtering the list (e.g. to the teams the user may create a blueprint in)
  // can drop a parent while keeping its child. The child must still show up.
  it('treats a team whose parent is missing as a root', () => {
    expect(shape([team('c', 'Orphan', 'gone')])).toEqual(['Orphan'])
  })

  it('does not lose a team that names itself as its parent', () => {
    expect(shape([team('a', 'Self', 'a')])).toEqual(['Self'])
  })

  it('returns an empty list for no teams', () => {
    expect(flattenTeamHierarchy([])).toEqual([])
  })

  it('keeps the original team object on each entry', () => {
    const parent = team('p', 'Parent')
    expect(flattenTeamHierarchy([parent])[0].team).toBe(parent)
  })
})

interface NestedTeam extends HierarchicalTeam {
  children?: NestedTeam[]
}

const nested: NestedTeam[] = [
  {
    id: 'a',
    name: 'A',
    parent_id: null,
    children: [
      { id: 'b', name: 'B', parent_id: 'a', children: [{ id: 'c', name: 'C', parent_id: 'b' }] },
      { id: 'd', name: 'D', parent_id: 'a' },
    ],
  },
  { id: 'e', name: 'E', parent_id: null },
]

describe('flattenNestedTeams', () => {
  it('collapses the tree depth-first', () => {
    expect(flattenNestedTeams(nested).map((t) => t.id)).toEqual(['a', 'b', 'c', 'd', 'e'])
  })

  // A team may not move under one of its own descendants, so the whole subtree
  // has to go, not just the team itself.
  it('drops the skipped team together with its descendants', () => {
    expect(flattenNestedTeams(nested, 'b').map((t) => t.id)).toEqual(['a', 'd', 'e'])
  })

  it('ignores a skip id that is not in the tree', () => {
    expect(flattenNestedTeams(nested, 'zzz')).toHaveLength(5)
  })

  it('round-trips back into a depth-tagged list', () => {
    expect(flattenTeamHierarchy(flattenNestedTeams(nested)).map((e) => `${e.depth}:${e.team.id}`))
      .toEqual(['0:a', '1:b', '2:c', '1:d', '0:e'])
  })
})
