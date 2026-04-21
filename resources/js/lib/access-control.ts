import type { RouteLocationRaw } from 'vue-router'

import type { AuthorizationPayload } from '~/types/authorization'

export type AppRouteName = string

export interface AccessEvaluationContext {
  authorization?: AuthorizationPayload | null
  routeName?: AppRouteName | null
  spaceId?: string | null
  teamId?: string | null
  selectedTeamId?: string | null
  selectedTeamCanCreateSpace?: boolean | null
}

export interface AbilityRequirement {
  anyOf?: string[]
  allOf?: string[]
}

export interface RouteAccessRequirement {
  abilities?: string | AbilityRequirement
  anyRouteOf?: AppRouteName[]
  check?: (context: AccessEvaluationContext) => boolean
}

export interface NavigationAccessItem {
  label: string
  icon: string
  routeName: AppRouteName
  visibilityRouteNames?: AppRouteName[]
}

export interface AccessDeniedMetadata {
  title: string
  description: string
}

export const accessDeniedMetadataByScope: Record<
  'space' | 'team' | 'global',
  AccessDeniedMetadata
> = {
  space: {
    title: 'Access to this space is restricted',
    description: 'Your current role does not allow you to open this space area.',
  },
  team: {
    title: 'Access to this team is restricted',
    description: 'Your current role does not allow you to open this team area.',
  },
  global: {
    title: 'Access is restricted',
    description: 'Your current role does not allow you to open this page.',
  },
}

export const spaceSettingsNavigationItems: NavigationAccessItem[] = [
  {
    label: 'labels.settings.general.title',
    icon: 'lucide:settings',
    routeName: 'space-settings-index',
  },
  {
    label: 'labels.settings.subscription.title',
    icon: 'lucide:credit-card',
    routeName: 'space-settings-subscription',
  },
  {
    label: 'labels.settings.configuration.title',
    icon: 'lucide:sliders',
    routeName: 'space-settings-configuration',
  },
  {
    label: 'labels.settings.ai.title',
    icon: 'lucide:sparkles',
    routeName: 'space-settings-ai',
  },
  {
    label: 'labels.settings.people.title',
    icon: 'lucide:users',
    routeName: 'space-settings-people',
  },
  {
    label: 'labels.settings.backups.title',
    icon: 'lucide:database-backup',
    routeName: 'space-settings-backups',
  },
  {
    label: 'labels.settings.migrations.title',
    icon: 'lucide:arrow-right-left',
    routeName: 'space-settings-migrations',
  },
]

export const teamNavigationItems: NavigationAccessItem[] = [
  {
    label: 'labels.teams.tabs.people',
    icon: 'lucide:users',
    routeName: 'team',
  },
  {
    label: 'labels.teams.tabs.roles',
    icon: 'lucide:shield',
    routeName: 'team-roles',
  },
]

export const providerNavigationItems: NavigationAccessItem[] = [
  {
    label: 'labels.provider.dashboard.title',
    icon: 'lucide:layout-dashboard',
    routeName: 'provider-dashboard',
  },
  {
    label: 'labels.provider.notes.title',
    icon: 'lucide:notebook-pen',
    routeName: 'provider-notes',
  },
]

export const spaceNavigationItems: NavigationAccessItem[] = [
  {
    label: 'labels.navigation.home',
    icon: 'lucide:home',
    routeName: 'space',
  },
  {
    label: 'labels.navigation.canvas',
    icon: 'lucide:network',
    routeName: 'space-canvas',
  },
  {
    label: 'labels.navigation.content',
    icon: 'lucide:feather',
    routeName: 'space-content-index',
  },
  {
    label: 'labels.navigation.blocks',
    icon: 'lucide:blocks',
    routeName: 'space-blocks-index',
  },
  {
    label: 'labels.navigation.assets',
    icon: 'lucide:images',
    routeName: 'space-assets-index',
  },
  {
    label: 'labels.navigation.datasets',
    icon: 'lucide:database-zap',
    routeName: 'space-datasources',
  },
  {
    label: 'labels.navigation.redirects',
    icon: 'lucide:split',
    routeName: 'space-redirects',
  },
  {
    label: 'labels.navigation.releases',
    icon: 'lucide:rocket',
    routeName: 'space-releases',
  },
  {
    label: 'labels.navigation.auditLog',
    icon: 'lucide:scroll-text',
    routeName: 'space-audit-logs',
  },
  {
    label: 'labels.navigation.settings',
    icon: 'lucide:settings',
    routeName: 'space-settings-index',
    visibilityRouteNames: spaceSettingsNavigationItems.map((item) => item.routeName),
  },
]

export const actionAccessRequirements: Record<string, RouteAccessRequirement> = {
  'space.archive': { abilities: 'space.archive' },
  'space.settings': { anyRouteOf: spaceSettingsNavigationItems.map((item) => item.routeName) },
  'space.canvas': { abilities: { allOf: ['content.view', 'blocks.view'] } },
  'command.blocks': { abilities: 'blocks.view' },
  'command.content': { abilities: 'content.view' },
  'team.spaces.create': {
    check: ({ authorization, selectedTeamCanCreateSpace }) =>
      Boolean(
        selectedTeamCanCreateSpace || hasAbilityRequirement(authorization, 'team.spaces.create')
      ),
  },
}

export const routeAccessRequirements: Record<AppRouteName, RouteAccessRequirement> = {
  'provider-dashboard': {
    check: ({ authorization }) => Boolean(authorization?.is_root),
  },
  'provider-notes': {
    check: ({ authorization }) => Boolean(authorization?.is_root),
  },
  space: { abilities: 'space.view' },
  'space-content-index': { abilities: 'content.view' },
  'space-content-contentId': { abilities: 'content.view' },
  'space-content-contentId-localization': { abilities: 'content.view' },
  'space-content-contentId-versions': { abilities: 'content.history.view' },
  'space-canvas': { abilities: { allOf: ['content.view', 'blocks.view'] } },
  'space-assets-index': { abilities: 'assets.view' },
  'space-blocks-index': { abilities: 'blocks.view' },
  'space-block': { abilities: 'blocks.view' },
  'space-datasources': { abilities: 'data_sources.view' },
  'space-datasources-dataSourceId': { abilities: 'data_sources.view' },
  'space-releases': { abilities: 'releases.view' },
  'space-redirects': { abilities: 'redirects.view' },
  'space-audit-logs': { abilities: 'audit_logs.view' },
  'space-settings': { anyRouteOf: spaceSettingsNavigationItems.map((item) => item.routeName) },
  'space-settings-index': { abilities: 'space.update' },
  'space-settings-subscription': { abilities: 'space.billing.view' },
  'space-settings-configuration': { abilities: 'space.update' },
  'space-settings-ai': { abilities: 'ai.view' },
  'space-settings-people': {
    abilities: {
      anyOf: [
        'space.members.view',
        'space.members.manage',
        'space.invites.view',
        'space.invites.manage',
      ],
    },
  },
  'space-settings-backups': { abilities: 'backups.view' },
  'space-settings-migrations': { abilities: 'migrations.view' },
  team: {
    abilities: { anyOf: ['team.members.view', 'team.invites.view'] },
  },
  'team-roles': { abilities: 'team.members.manage' },
  'spaces-new': actionAccessRequirements['team.spaces.create'],
}

export function getRouteAccessRequirement(routeName?: AppRouteName | null) {
  if (!routeName) {
    return null
  }

  return routeAccessRequirements[routeName] ?? null
}

export function getActionAccessRequirement(actionKey: string) {
  return actionAccessRequirements[actionKey] ?? null
}

export function getAbilitySet(authorization?: AuthorizationPayload | null) {
  return new Set([
    ...(authorization?.team?.abilities ?? []),
    ...(authorization?.space?.abilities ?? []),
  ])
}

export function hasAbilityRequirement(
  authorization: AuthorizationPayload | null | undefined,
  requirement?: string | AbilityRequirement
): boolean {
  if (!requirement) {
    return true
  }

  if (authorization?.is_root) {
    return true
  }

  const abilities = getAbilitySet(authorization)

  if (typeof requirement === 'string') {
    return abilities.has(requirement)
  }

  const anyOf = requirement.anyOf ?? []
  const allOf = requirement.allOf ?? []

  if (anyOf.length > 0 && !anyOf.some((ability) => abilities.has(ability))) {
    return false
  }

  if (allOf.length > 0 && !allOf.every((ability) => abilities.has(ability))) {
    return false
  }

  return anyOf.length > 0 || allOf.length > 0
}

export function canAccessRequirement(
  requirement: RouteAccessRequirement | null | undefined,
  context: AccessEvaluationContext
): boolean {
  if (!requirement) {
    return true
  }

  if (context.authorization?.is_root) {
    return true
  }

  if (requirement.anyRouteOf?.length) {
    return requirement.anyRouteOf.some((routeName) => canAccessRouteByName(routeName, context))
  }

  if (
    requirement.abilities &&
    !hasAbilityRequirement(context.authorization, requirement.abilities)
  ) {
    return false
  }

  if (requirement.check) {
    return requirement.check(context)
  }

  return true
}

export function canAccessRouteByName(
  routeName: AppRouteName,
  context: AccessEvaluationContext
): boolean {
  return canAccessRequirement(getRouteAccessRequirement(routeName), {
    ...context,
    routeName,
  })
}

export function canAccessNavigationItem(
  item: NavigationAccessItem,
  context: AccessEvaluationContext
): boolean {
  if (item.visibilityRouteNames?.length) {
    return item.visibilityRouteNames.some((routeName) => canAccessRouteByName(routeName, context))
  }

  return canAccessRouteByName(item.routeName, context)
}

export function filterNavigationItems<T extends NavigationAccessItem>(
  items: T[],
  context: AccessEvaluationContext
) {
  return items.filter((item) => canAccessNavigationItem(item, context))
}

export function firstAllowedRouteForSpace(
  spaceId: string,
  context: AccessEvaluationContext
): RouteLocationRaw | null {
  const firstItem = spaceNavigationItems.find((item) => canAccessNavigationItem(item, context))

  if (!firstItem) {
    return null
  }

  return {
    name: firstItem.routeName,
    params: { space: spaceId },
  }
}

export function firstAllowedRouteForTeam(
  teamId: string,
  context: AccessEvaluationContext
): RouteLocationRaw | null {
  const firstItem = teamNavigationItems.find((item) => canAccessNavigationItem(item, context))

  if (!firstItem) {
    return null
  }

  return {
    name: firstItem.routeName,
    params: { team: teamId },
  }
}
