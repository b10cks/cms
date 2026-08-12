import type { MaybeRef } from 'vue'

import {
  entityKeys,
  listKeys,
  nestedEntityKeys,
  spaceEntityKeys,
  spaceListKeys,
} from '~/lib/query-keys'

/**
 * The app's query-key registry.
 *
 * Most namespaces are the same five levels (`all`/`lists`/`list`/`details`/`detail`)
 * hanging off `['spaces', spaceId, segment]`, so they are generated rather than
 * spelled out — see `~/lib/query-keys`. The arrays produced are unchanged; only
 * the namespaces with extra branches (or a non-standard root) stay hand-written,
 * and those spread a generated base so the shared levels can't drift.
 */
export const queryKeys = {
  spaces: entityKeys(() => ['spaces'] as const),
  automationActions: spaceEntityKeys('automation-actions'),
  automationExecutions: spaceEntityKeys('automation-executions'),
  automations: spaceEntityKeys('automations'),
  assetFolders: spaceEntityKeys('asset-folders'),
  blockFolders: spaceEntityKeys('block-folders'),
  blockTags: spaceEntityKeys('block-tags'),
  assetCollections: (spaceId: MaybeRef<string>) => {
    const keys = spaceEntityKeys('asset-collections')(spaceId)
    return {
      ...keys,
      assets: (id: MaybeRef<string>) => [...keys.all(), 'assets', id] as const,
      assetsList: (id: MaybeRef<string>, filters: unknown = {}) =>
        [...keys.all(), 'assets', id, filters] as const,
    }
  },
  assetPackages: spaceEntityKeys('asset-packages'),
  assetShares: spaceEntityKeys('asset-shares'),
  publicShare: (spaceId: MaybeRef<string>, token: MaybeRef<string>) => {
    const all = () => ['public-share', spaceId, token] as const
    return {
      all,
      meta: () => [...all(), 'meta'] as const,
      assets: () => [...all(), 'assets'] as const,
      assetsList: (filters: unknown = {}) => [...all(), 'assets', filters] as const,
    }
  },
  assetTags: spaceEntityKeys('asset-tags'),
  tokens: spaceListKeys('tokens'),
  teams: {
    ...entityKeys(() => ['teams'] as const),
    hierarchy: () => [...queryKeys.teams.all(), 'hierarchy'] as const,
    samlProvider: (teamId: MaybeRef<string>) =>
      [...queryKeys.teams.detail(teamId), 'saml-provider'] as const,
    roles: (teamId: MaybeRef<string>) => ({
      all: () => [...queryKeys.teams.detail(teamId), 'roles'] as const,
      space: () => [...queryKeys.teams.detail(teamId), 'roles', 'space'] as const,
    }),
  },
  provider: {
    all: () => ['provider'] as const,
    stats: (params: unknown = {}) => ['provider', 'stats', params] as const,
    notes: () => ['provider', 'notes'] as const,
    notesList: (params: unknown = {}) => ['provider', 'notes', 'list', params] as const,
  },
  redirects: spaceEntityKeys('redirects'),
  assets: (spaceId: MaybeRef<string>) => {
    const keys = spaceEntityKeys('assets')(spaceId)
    return {
      ...keys,
      linkedContents: (id: MaybeRef<string>) => [...keys.detail(id), 'linked-contents'] as const,
      linkedContentsPage: (id: MaybeRef<string>, page: MaybeRef<number>) =>
        [...keys.detail(id), 'linked-contents', page] as const,
    }
  },
  // Nested under the asset's own detail key, like `linkedContents` above, so
  // invalidating an asset cascades to its versions.
  assetVersions: (spaceId: MaybeRef<string>, assetId: MaybeRef<string>) =>
    listKeys(() => [...queryKeys.assets(spaceId).detail(assetId), 'versions'] as const),
  icons: (spaceId: MaybeRef<string>) => {
    const keys = spaceEntityKeys('icons')(spaceId)
    return {
      ...keys,
      tags: () => [...keys.all(), 'tags'] as const,
    }
  },
  blocks: spaceEntityKeys('blocks'),
  blockTemplates: nestedEntityKeys('blocks', 'templates'),
  blockVersions: nestedEntityKeys('blocks', 'versions'),
  contents: spaceEntityKeys('contents'),
  contentVersions: nestedEntityKeys('contents', 'history'),
  comments: nestedEntityKeys('contents', 'comments'),
  contentMenu: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'content-menu'] as const,
  }),
  fieldPlugins: spaceEntityKeys('field-plugins'),
  dataSources: spaceEntityKeys('data-sources'),
  dataEntries: nestedEntityKeys('data-sources', 'entries'),
  spaceMembers: spaceListKeys('members'),
  spacePeople: spaceListKeys('people'),
  teamPeople: (teamId: MaybeRef<string>) => listKeys(() => ['teams', teamId, 'people'] as const),
  invites: {
    all: () => ['invites'] as const,
    public: (inviteId: MaybeRef<string | undefined>) =>
      ['invites', 'public', inviteId] as const,
    my: () => ['invites', 'my'] as const,
    myLists: () => ['invites', 'my', 'list'] as const,
    myList: (filters: unknown = {}) => ['invites', 'my', 'list', filters] as const,
    myDetails: () => ['invites', 'my', 'detail'] as const,
    myDetail: (id: MaybeRef<string>) => ['invites', 'my', 'detail', id] as const,
  },
  releases: spaceEntityKeys('releases'),
  users: {
    all: () => ['users'] as const,
    me: () => ['users', 'me'] as const,
    socialLinks: () => ['users', 'me', 'social-links'] as const,
  },
  notifications: {
    ...listKeys(() => ['notifications'] as const),
    unreadCount: () => ['notifications', 'unread-count'] as const,
  },
  authorization: {
    all: () => ['authorization'] as const,
    context: (params: unknown = {}) => ['authorization', params] as const,
  },
  personalAccessTokens: listKeys(() => ['users', 'me', 'tokens'] as const),
  twoFactor: {
    all: () => ['two-factor'] as const,
    status: () => ['two-factor', 'status'] as const,
  },
  ai: (spaceId: MaybeRef<string>) => {
    const all = () => ['spaces', spaceId, 'ai'] as const
    return {
      all,
      models: () => [...all(), 'models'] as const,
      settings: () => [...all(), 'settings'] as const,
      usage: () => [...all(), 'usage'] as const,
      configs: () => [...all(), 'configs'] as const,
      config: (configId: MaybeRef<string>) => [...all(), 'configs', configId] as const,
    }
  },
  migrations: spaceEntityKeys('migrations'),
  backups: spaceEntityKeys('backups'),
  auditLogs: spaceListKeys('audit-logs'),
  plans: {
    all: () => ['plans'] as const,
    lists: () => ['plans', 'list'] as const,
    forSpace: (spaceId: MaybeRef<string>) => ['plans', 'space', spaceId] as const,
  },
  // `all` + a few siblings, but deliberately no `list(filters)` — do not add one,
  // the shape is asserted by tests and consumed by prefix-based invalidation.
  subscriptions: (spaceId: MaybeRef<string>) => {
    const all = () => ['spaces', spaceId, 'subscriptions'] as const
    return {
      all,
      lists: () => [...all(), 'list'] as const,
      current: () => [...all(), 'current'] as const,
      proposal: () => [...all(), 'proposal'] as const,
    }
  },
  spaceUsage: (spaceId: MaybeRef<string | null>) => ({
    all: () => ['spaces', spaceId, 'usage'] as const,
  }),
  usageHistory: (spaceId: MaybeRef<string>) => {
    const all = () => ['spaces', spaceId, 'usage-history'] as const
    return {
      all,
      lists: () => [...all(), 'list'] as const,
      timeseries: (periodId: MaybeRef<string>) => [...all(), 'timeseries', periodId] as const,
    }
  },
  invoices: (spaceId: MaybeRef<string>) => {
    const all = () => ['spaces', spaceId, 'invoices'] as const
    return { all, lists: () => [...all(), 'list'] as const }
  },
}
