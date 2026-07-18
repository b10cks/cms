type TranslateFn = (key: string, params?: Record<string, unknown>) => string

type RoleAbilityGroup =
  | 'space'
  | 'people'
  | 'billing'
  | 'assets'
  | 'blocks'
  | 'content'
  | 'comments'
  | 'data'
  | 'delivery'
  | 'operations'
  | 'ai'

type RoleAbilityAction =
  | 'view'
  | 'manage'
  | 'create'
  | 'update'
  | 'delete'
  | 'archive'
  | 'publish'
  | 'updateOwn'
  | 'deleteOwn'
  | 'resolveOwn'
  | 'react'

type RoleAbilityResource =
  | 'space'
  | 'members'
  | 'invites'
  | 'billing'
  | 'tokens'
  | 'assets'
  | 'assetFolders'
  | 'assetTags'
  | 'icons'
  | 'blocks'
  | 'blockTemplates'
  | 'blockVersions'
  | 'content'
  | 'contentHistory'
  | 'comments'
  | 'dataSources'
  | 'fieldPlugins'
  | 'dataEntries'
  | 'redirects'
  | 'releases'
  | 'backups'
  | 'migrations'
  | 'automationActions'
  | 'automations'
  | 'ai'
  | 'auditLogs'

interface RoleAbilityMeta {
  group: RoleAbilityGroup
  action: RoleAbilityAction
  resource: RoleAbilityResource
}

interface GroupedRoleAbilities {
  id: RoleAbilityGroup
  label: string
  abilities: Array<{
    key: string
    label: string
  }>
}

interface GroupedRoleAbilityResource {
  id: RoleAbilityResource
  label: string
  abilities: Array<{
    key: string
    label: string
  }>
}

interface GroupedRoleAbilitySection {
  id: RoleAbilityGroup
  label: string
  resources: GroupedRoleAbilityResource[]
}

const ROLE_ABILITY_ORDER: RoleAbilityGroup[] = [
  'space',
  'people',
  'billing',
  'assets',
  'blocks',
  'content',
  'comments',
  'data',
  'delivery',
  'operations',
  'ai',
]

const ROLE_ABILITY_META: Record<string, RoleAbilityMeta> = {
  'space.view': { group: 'space', action: 'view', resource: 'space' },
  'space.update': { group: 'space', action: 'update', resource: 'space' },
  'space.archive': { group: 'space', action: 'archive', resource: 'space' },
  'space.delete': { group: 'space', action: 'delete', resource: 'space' },
  'space.members.view': { group: 'people', action: 'view', resource: 'members' },
  'space.members.manage': { group: 'people', action: 'manage', resource: 'members' },
  'space.invites.view': { group: 'people', action: 'view', resource: 'invites' },
  'space.invites.manage': { group: 'people', action: 'manage', resource: 'invites' },
  'space.billing.view': { group: 'billing', action: 'view', resource: 'billing' },
  'space.billing.manage': { group: 'billing', action: 'manage', resource: 'billing' },
  'space.tokens.view': { group: 'billing', action: 'view', resource: 'tokens' },
  'space.tokens.manage': { group: 'billing', action: 'manage', resource: 'tokens' },
  'assets.view': { group: 'assets', action: 'view', resource: 'assets' },
  'assets.manage': { group: 'assets', action: 'manage', resource: 'assets' },
  'asset_folders.view': { group: 'assets', action: 'view', resource: 'assetFolders' },
  'asset_folders.manage': { group: 'assets', action: 'manage', resource: 'assetFolders' },
  'asset_tags.view': { group: 'assets', action: 'view', resource: 'assetTags' },
  'asset_tags.manage': { group: 'assets', action: 'manage', resource: 'assetTags' },
  'icons.view': { group: 'assets', action: 'view', resource: 'icons' },
  'icons.manage': { group: 'assets', action: 'manage', resource: 'icons' },
  'blocks.view': { group: 'blocks', action: 'view', resource: 'blocks' },
  'blocks.manage': { group: 'blocks', action: 'manage', resource: 'blocks' },
  'block_templates.view': { group: 'blocks', action: 'view', resource: 'blockTemplates' },
  'block_templates.manage': { group: 'blocks', action: 'manage', resource: 'blockTemplates' },
  'block_versions.view': { group: 'blocks', action: 'view', resource: 'blockVersions' },
  'block_versions.manage': { group: 'blocks', action: 'manage', resource: 'blockVersions' },
  'content.view': { group: 'content', action: 'view', resource: 'content' },
  'content.manage': { group: 'content', action: 'manage', resource: 'content' },
  'content.publish': { group: 'content', action: 'publish', resource: 'content' },
  'content.history.view': { group: 'content', action: 'view', resource: 'contentHistory' },
  'comments.view': { group: 'comments', action: 'view', resource: 'comments' },
  'comments.create': { group: 'comments', action: 'create', resource: 'comments' },
  'comments.update_own': { group: 'comments', action: 'updateOwn', resource: 'comments' },
  'comments.delete_own': { group: 'comments', action: 'deleteOwn', resource: 'comments' },
  'comments.resolve_own': { group: 'comments', action: 'resolveOwn', resource: 'comments' },
  'comments.react': { group: 'comments', action: 'react', resource: 'comments' },
  'data_sources.view': { group: 'data', action: 'view', resource: 'dataSources' },
  'data_sources.manage': { group: 'data', action: 'manage', resource: 'dataSources' },
  'field_plugins.view': { group: 'data', action: 'view', resource: 'fieldPlugins' },
  'field_plugins.manage': { group: 'data', action: 'manage', resource: 'fieldPlugins' },
  'data_entries.view': { group: 'data', action: 'view', resource: 'dataEntries' },
  'data_entries.manage': { group: 'data', action: 'manage', resource: 'dataEntries' },
  'redirects.view': { group: 'delivery', action: 'view', resource: 'redirects' },
  'redirects.manage': { group: 'delivery', action: 'manage', resource: 'redirects' },
  'releases.view': { group: 'delivery', action: 'view', resource: 'releases' },
  'releases.manage': { group: 'delivery', action: 'manage', resource: 'releases' },
  'releases.publish': { group: 'delivery', action: 'publish', resource: 'releases' },
  'backups.view': { group: 'operations', action: 'view', resource: 'backups' },
  'backups.manage': { group: 'operations', action: 'manage', resource: 'backups' },
  'migrations.view': { group: 'operations', action: 'view', resource: 'migrations' },
  'migrations.manage': { group: 'operations', action: 'manage', resource: 'migrations' },
  'automation_actions.view': { group: 'operations', action: 'view', resource: 'automationActions' },
  'automation_actions.manage': {
    group: 'operations',
    action: 'manage',
    resource: 'automationActions',
  },
  'automations.view': { group: 'operations', action: 'view', resource: 'automations' },
  'automations.manage': { group: 'operations', action: 'manage', resource: 'automations' },
  'ai.view': { group: 'ai', action: 'view', resource: 'ai' },
  'ai.manage': { group: 'ai', action: 'manage', resource: 'ai' },
  'audit_logs.view': { group: 'operations', action: 'view', resource: 'auditLogs' },
}

export function formatRoleAbilityLabel(ability: string, t: TranslateFn): string {
  const meta = ROLE_ABILITY_META[ability]

  if (!meta) {
    return ability
  }

  return t(`labels.teamRoles.abilityTemplates.${meta.action}`, {
    resource: t(`labels.teamRoles.resources.${meta.resource}`),
  })
}

export function formatRoleAbilityResourceLabel(ability: string, t: TranslateFn): string {
  const meta = ROLE_ABILITY_META[ability]

  if (!meta) {
    return ability
  }

  return t(`labels.teamRoles.resources.${meta.resource}`)
}

export function groupRoleAbilities(abilities: string[], t: TranslateFn): GroupedRoleAbilities[] {
  const grouped = new Map<RoleAbilityGroup, GroupedRoleAbilities>()

  for (const ability of abilities) {
    const meta = ROLE_ABILITY_META[ability]

    if (!meta) {
      continue
    }

    const group = grouped.get(meta.group) ?? {
      id: meta.group,
      label: t(`labels.teamRoles.groups.${meta.group}`),
      abilities: [],
    }

    group.abilities.push({
      key: ability,
      label: formatRoleAbilityLabel(ability, t),
    })

    grouped.set(meta.group, group)
  }

  return ROLE_ABILITY_ORDER.map((groupId) => grouped.get(groupId))
    .filter((group): group is GroupedRoleAbilities => !!group)
    .map((group) => ({
      ...group,
      abilities: [...group.abilities].sort((left, right) => left.label.localeCompare(right.label)),
    }))
}

export function groupRoleAbilitySections(
  abilities: string[],
  t: TranslateFn
): GroupedRoleAbilitySection[] {
  const grouped = new Map<RoleAbilityGroup, GroupedRoleAbilitySection>()

  for (const ability of abilities) {
    const meta = ROLE_ABILITY_META[ability]

    if (!meta) {
      continue
    }

    const section = grouped.get(meta.group) ?? {
      id: meta.group,
      label: t(`labels.teamRoles.groups.${meta.group}`),
      resources: [],
    }

    let resource = section.resources.find((entry) => entry.id === meta.resource)

    if (!resource) {
      resource = {
        id: meta.resource,
        label: t(`labels.teamRoles.resources.${meta.resource}`),
        abilities: [],
      }
      section.resources.push(resource)
    }

    resource.abilities.push({
      key: ability,
      label: formatRoleAbilityLabel(ability, t),
    })

    grouped.set(meta.group, section)
  }

  return ROLE_ABILITY_ORDER.map((groupId) => grouped.get(groupId))
    .filter((group): group is GroupedRoleAbilitySection => !!group)
    .map((group) => ({
      ...group,
      resources: [...group.resources]
        .sort((left, right) => left.label.localeCompare(right.label))
        .map((resource) => ({
          ...resource,
          abilities: [...resource.abilities].sort((left, right) =>
            left.label.localeCompare(right.label)
          ),
        })),
    }))
}
