import { describe, expect, it } from 'vitest'

import {
  formatRoleAbilityLabel,
  formatRoleAbilityResourceLabel,
  groupRoleAbilities,
  groupRoleAbilitySections,
} from '~/utils/role-abilities'

// The real translations are irrelevant to the grouping logic; returning the
// last key segment keeps the labels short and makes the sort order (which is
// label-based, hence locale-dependent) predictable.
const t = (key: string) => key.split('.').pop() as string

describe('formatRoleAbilityLabel', () => {
  it('renders the action template with the resource label', () => {
    const spy = (key: string, params?: Record<string, unknown>) =>
      params ? `${key}(${params.resource})` : key

    expect(formatRoleAbilityLabel('content.publish', spy)).toBe(
      'labels.teamRoles.abilityTemplates.publish(labels.teamRoles.resources.content)'
    )
  })

  it('falls back to the raw key for an unknown ability', () => {
    expect(formatRoleAbilityLabel('made.up.ability', t)).toBe('made.up.ability')
  })
})

describe('formatRoleAbilityResourceLabel', () => {
  it('maps an ability to its resource label', () => {
    expect(formatRoleAbilityResourceLabel('space.members.manage', (key) => key)).toBe(
      'labels.teamRoles.resources.members'
    )
  })

  it('falls back to the raw key for an unknown ability', () => {
    expect(formatRoleAbilityResourceLabel('made.up.ability', t)).toBe('made.up.ability')
  })
})

describe('groupRoleAbilities', () => {
  it('groups abilities under their declared group', () => {
    const groups = groupRoleAbilities(['space.view', 'assets.view'], t)

    expect(groups.map((group) => group.id)).toEqual(['space', 'assets'])
    expect(groups[0].abilities.map((ability) => ability.key)).toEqual(['space.view'])
  })

  it('emits groups in the canonical order, not the input order', () => {
    const groups = groupRoleAbilities(['ai.view', 'assets.view', 'space.view'], t)

    expect(groups.map((group) => group.id)).toEqual(['space', 'assets', 'ai'])
  })

  it('sorts abilities within a group by label', () => {
    const groups = groupRoleAbilities(['space.view', 'space.archive', 'space.delete'], t)

    expect(groups[0].abilities.map((ability) => ability.label)).toEqual([
      'archive',
      'delete',
      'view',
    ])
  })

  it('drops unknown abilities instead of grouping them', () => {
    expect(groupRoleAbilities(['made.up.ability'], t)).toEqual([])
  })

  it('returns an empty array for no abilities', () => {
    expect(groupRoleAbilities([], t)).toEqual([])
  })

  it('routes audit logs into operations, not its own group', () => {
    const groups = groupRoleAbilities(['audit_logs.view'], t)

    expect(groups.map((group) => group.id)).toEqual(['operations'])
  })
})

describe('groupRoleAbilitySections', () => {
  it('nests abilities under a resource inside each group', () => {
    const sections = groupRoleAbilitySections(
      ['space.members.view', 'space.members.manage', 'space.invites.view'],
      t
    )

    expect(sections).toHaveLength(1)
    expect(sections[0].id).toBe('people')
    expect(sections[0].resources.map((resource) => resource.id)).toEqual(['invites', 'members'])
    expect(sections[0].resources[1].abilities.map((ability) => ability.key)).toEqual([
      'space.members.manage',
      'space.members.view',
    ])
  })

  it('splits one group across several resources', () => {
    const sections = groupRoleAbilitySections(['assets.view', 'icons.view', 'asset_tags.view'], t)

    expect(sections[0].resources.map((resource) => resource.id)).toEqual([
      'assets',
      'assetTags',
      'icons',
    ])
  })

  it('keeps the canonical group order across sections', () => {
    const sections = groupRoleAbilitySections(['ai.view', 'space.view'], t)

    expect(sections.map((section) => section.id)).toEqual(['space', 'ai'])
  })

  it('drops unknown abilities', () => {
    expect(groupRoleAbilitySections(['made.up.ability'], t)).toEqual([])
  })
})
