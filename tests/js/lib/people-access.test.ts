import { describe, expect, it } from 'vitest'

import { summarizeAccess } from '~/lib/people-access'

const roleName = (key: string | null) => (key ? key[0].toUpperCase() + key.slice(1) : '')
const team = { resourceType: 'team' as const, resourceName: 'Helio', spaceCount: 12, roleName }
const space = { ...team, resourceType: 'space' as const }

const membership = (role: string, name: string) => ({
  space: { id: name, name },
  role,
  joined_at: '2026-10-01T00:00:00Z',
  expires_at: null,
})

const person = (overrides: Partial<PersonResource> = {}) =>
  ({
    kind: 'member',
    role: 'member',
    membership_origin: 'team',
    inherited_from: null,
    inherited_role: null,
    space_memberships: [],
    ...overrides,
  }) as PersonResource

describe('summarizeAccess', () => {
  it('describes a team role as reaching every space of the team', () => {
    expect(summarizeAccess(person(), team)).toEqual({
      source: 'team',
      key: 'allSpaces',
      params: { count: 12 },
    })
  })

  it('counts direct space roles per role, or names the one space', () => {
    const many = person({
      membership_origin: 'space',
      space_memberships: [
        membership('admin', 'A'),
        membership('editor', 'B'),
        membership('editor', 'C'),
      ],
    })
    expect(summarizeAccess(many, team).params.roles).toBe('Admin (1), Editor (2)')

    const one = person({ membership_origin: 'space', space_memberships: [membership('editor', 'Campaigns')] })
    expect(summarizeAccess(one, team)).toMatchObject({
      source: 'direct',
      key: 'direct.one',
      params: { role: 'Editor', name: 'Campaigns' },
    })
  })

  it('marks a team role plus direct space roles as mixed, and invites as pending', () => {
    expect(summarizeAccess(person({ space_memberships: [membership('admin', 'A')] }), team).source).toBe('mixed')
    expect(summarizeAccess(person({ kind: 'invite', role: 'editor' }), team)).toMatchObject({
      source: 'pending',
      params: { role: 'Editor', name: 'Helio' },
    })
  })

  it('shows on a space where an inherited team role comes from', () => {
    const inherited = person({
      membership_origin: 'inherited',
      inherited_role: 'owner',
      inherited_from: { id: 't', name: 'Agency' },
    })
    expect(summarizeAccess(inherited, space)).toEqual({
      source: 'team',
      key: 'inherited',
      params: { role: 'Owner', team: 'Agency' },
    })
    expect(summarizeAccess(person({ membership_origin: 'space' }), space).source).toBe('direct')
  })
})
