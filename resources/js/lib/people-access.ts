/**
 * How a person in a People list reaches the team or space, reduced to one
 * line for the "Access" column. Labels are i18n keys under
 * `labels.teamWorkspace.access`, rendered by the caller.
 */
export type AccessSource = 'team' | 'direct' | 'mixed' | 'pending'

export interface AccessSummary {
  source: AccessSource
  key: string
  params: Record<string, string | number>
}

export const ACCESS_SOURCE_ICONS: Record<AccessSource, string> = {
  team: 'lucide:corner-down-right',
  direct: 'lucide:box',
  mixed: 'lucide:arrow-left-right',
  pending: 'lucide:mail',
}

type AccessPerson = Pick<
  PersonResource,
  'kind' | 'role' | 'membership_origin' | 'inherited_from' | 'inherited_role' | 'space_memberships'
>

interface AccessContext {
  resourceType: 'space' | 'team'
  /** Team or space name, for invites ("Editor on Campaigns"). */
  resourceName: string
  /** Spaces a team role reaches (the team's subtree). */
  spaceCount: number
  roleName: (key: string | null) => string
}

/** "Admin on 1, Editor on 2", or "Editor on Campaigns" for a single space. */
function describeSpaceRoles(
  memberships: PersonSpaceMembership[],
  roleName: AccessContext['roleName']
): Omit<AccessSummary, 'source'> {
  if (memberships.length === 1) {
    return {
      key: 'direct.one',
      params: { role: roleName(memberships[0].role), name: memberships[0].space.name },
    }
  }

  const byRole = new Map<string, number>()
  for (const membership of memberships) {
    const role = roleName(membership.role)
    byRole.set(role, (byRole.get(role) ?? 0) + 1)
  }

  return {
    key: 'direct.many',
    params: {
      roles: [...byRole].map(([role, count]) => `${role} (${count})`).join(', '),
      count: memberships.length,
    },
  }
}

export function summarizeAccess(person: AccessPerson, context: AccessContext): AccessSummary {
  if (person.kind === 'invite') {
    return {
      source: 'pending',
      key: 'pending',
      params: { role: context.roleName(person.role), name: context.resourceName },
    }
  }

  if (context.resourceType === 'space') {
    if (person.membership_origin === 'inherited') {
      return {
        source: 'team',
        key: 'inherited',
        params: {
          role: context.roleName(person.inherited_role),
          team: person.inherited_from?.name ?? '',
        },
      }
    }

    return person.inherited_from
      ? {
          source: 'mixed',
          key: 'spaceMixed',
          params: {
            role: context.roleName(person.inherited_role),
            team: person.inherited_from.name,
          },
        }
      : { source: 'direct', key: 'spaceDirect', params: {} }
  }

  const memberships = person.space_memberships
  if (person.membership_origin === 'space') {
    const described = describeSpaceRoles(memberships, context.roleName)
    return { source: 'direct', ...described }
  }

  if (memberships.length > 0) {
    return {
      source: 'mixed',
      key: 'mixed',
      params: { count: context.spaceCount, direct: memberships.length },
    }
  }

  return { source: 'team', key: 'allSpaces', params: { count: context.spaceCount } }
}
