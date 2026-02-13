import type { MaybeRef } from 'vue'

export const queryKeys = {
  spaces: {
    all: () => ['spaces'] as const,
    lists: () => [...queryKeys.spaces.all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.spaces.lists(), filters] as const,
    details: () => [...queryKeys.spaces.all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) => [...queryKeys.spaces.details(), id] as const,
  },
  assetFolders: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'asset-folders'] as const,
    lists: () => [...queryKeys.assetFolders(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.assetFolders(spaceId).lists(), filters] as const,
    details: () => [...queryKeys.assetFolders(spaceId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) => [...queryKeys.assetFolders(spaceId).details(), id] as const,
  }),
  blockFolders: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'block-folders'] as const,
    lists: () => [...queryKeys.blockFolders(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.blockFolders(spaceId).lists(), filters] as const,
    details: () => [...queryKeys.blockFolders(spaceId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) => [...queryKeys.blockFolders(spaceId).details(), id] as const,
  }),
  blockTags: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'block-tags'] as const,
    lists: () => [...queryKeys.blockTags(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.blockTags(spaceId).lists(), filters] as const,
    details: () => [...queryKeys.blockTags(spaceId).all(), 'detail'] as const,
    detail: (tagName: MaybeRef<string>) =>
      [...queryKeys.blockTags(spaceId).details(), tagName] as const,
  }),
  assetTags: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'asset-tags'] as const,
    lists: () => [...queryKeys.assetTags(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.assetTags(spaceId).lists(), filters] as const,
    details: () => [...queryKeys.assetTags(spaceId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) => [...queryKeys.assetTags(spaceId).details(), id] as const,
  }),
  tokens: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'tokens'] as const,
    lists: () => [...queryKeys.assetTags(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.assetTags(spaceId).lists(), filters] as const,
  }),
  teams: {
    all: () => ['teams'] as const,
    lists: () => [...queryKeys.teams.all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.teams.lists(), filters] as const,
    details: () => [...queryKeys.teams.all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) => [...queryKeys.teams.details(), id] as const,
    hierarchy: () => [...queryKeys.teams.all(), 'hierarchy'] as const,
    users: (teamId: MaybeRef<string>) => ({
      all: () => [...queryKeys.teams.detail(teamId), 'users'] as const,
      lists: () => [...queryKeys.teams.users(teamId).all(), 'list'] as const,
      list: (filters: any = {}) => [...queryKeys.teams.users(teamId).lists(), filters] as const,
    }),
  },
  redirects: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'redirects'] as const,
    lists: () => [...queryKeys.redirects(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.redirects(spaceId).lists(), filters] as const,
    details: () => [...queryKeys.redirects(spaceId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) => [...queryKeys.redirects(spaceId).details(), id] as const,
  }),
  assets: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'assets'] as const,
    lists: () => [...queryKeys.assets(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.assets(spaceId).lists(), filters] as const,
    details: () => [...queryKeys.assets(spaceId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) => [...queryKeys.assets(spaceId).details(), id] as const,
  }),
  blocks: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'blocks'] as const,
    lists: () => [...queryKeys.blocks(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.blocks(spaceId).lists(), filters] as const,
    details: () => [...queryKeys.blocks(spaceId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) => [...queryKeys.blocks(spaceId).details(), id] as const,
  }),
  blockTemplates: (spaceId: MaybeRef<string>, blockId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'blocks', blockId, 'templates'] as const,
    lists: () => [...queryKeys.blockTemplates(spaceId, blockId).all(), 'list'] as const,
    list: (filters: any = {}) =>
      [...queryKeys.blockTemplates(spaceId, blockId).lists(), filters] as const,
    details: () => [...queryKeys.blockTemplates(spaceId, blockId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) =>
      [...queryKeys.blockTemplates(spaceId, blockId).details(), id] as const,
  }),
  blockVersions: (spaceId: MaybeRef<string>, blockId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'blocks', blockId, 'versions'] as const,
    lists: () => [...queryKeys.blockVersions(spaceId, blockId).all(), 'list'] as const,
    list: (filters: any = {}) =>
      [...queryKeys.blockVersions(spaceId, blockId).lists(), filters] as const,
    details: () => [...queryKeys.blockVersions(spaceId, blockId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) =>
      [...queryKeys.blockVersions(spaceId, blockId).details(), id] as const,
  }),
  contents: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'contents'] as const,
    lists: () => [...queryKeys.contents(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.contents(spaceId).lists(), filters] as const,
    details: () => [...queryKeys.contents(spaceId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) => [...queryKeys.contents(spaceId).details(), id] as const,
  }),
  contentVersions: (spaceId: MaybeRef<string>, contentId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'contents', contentId, 'history'] as const,
    lists: () => [...queryKeys.contentVersions(spaceId, contentId).all(), 'list'] as const,
    list: (filters: any = {}) =>
      [...queryKeys.contentVersions(spaceId, contentId).lists(), filters] as const,
    details: () => [...queryKeys.contentVersions(spaceId, contentId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) =>
      [...queryKeys.contentVersions(spaceId, contentId).details(), id] as const,
  }),
  comments: (spaceId: MaybeRef<string>, contentId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'contents', contentId, 'comments'] as const,
    lists: () => [...queryKeys.comments(spaceId, contentId).all(), 'list'] as const,
    list: (filters: any = {}) =>
      [...queryKeys.comments(spaceId, contentId).lists(), filters] as const,
    details: () => [...queryKeys.comments(spaceId, contentId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) =>
      [...queryKeys.comments(spaceId, contentId).details(), id] as const,
  }),
  contentMenu: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'content-menu'] as const,
  }),
  // Add data sources and entries query keys
  dataSources: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'data-sources'] as const,
    lists: () => [...queryKeys.dataSources(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.dataSources(spaceId).lists(), filters] as const,
    details: () => [...queryKeys.dataSources(spaceId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) => [...queryKeys.dataSources(spaceId).details(), id] as const,
  }),
  dataEntries: (spaceId: MaybeRef<string>, dataSourceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'data-sources', dataSourceId, 'entries'] as const,
    lists: () => [...queryKeys.dataEntries(spaceId, dataSourceId).all(), 'list'] as const,
    list: (filters: any = {}) =>
      [...queryKeys.dataEntries(spaceId, dataSourceId).lists(), filters] as const,
    details: () => [...queryKeys.dataEntries(spaceId, dataSourceId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) =>
      [...queryKeys.dataEntries(spaceId, dataSourceId).details(), id] as const,
  }),
  invites: {
    all: () => ['invites'] as const,
    public: (token: MaybeRef<string>) => [...queryKeys.invites.all(), 'public', token] as const,
    my: () => [...queryKeys.invites.all(), 'my'] as const,
    myLists: () => [...queryKeys.invites.my(), 'list'] as const,
    myList: (filters: any = {}) => [...queryKeys.invites.myLists(), filters] as const,
    myDetails: () => [...queryKeys.invites.my(), 'detail'] as const,
    myDetail: (id: MaybeRef<string>) => [...queryKeys.invites.myDetails(), id] as const,
    spaces: (spaceId: MaybeRef<string>) => ({
      all: () => ['spaces', spaceId, 'invites'] as const,
      lists: () => [...queryKeys.invites.spaces(spaceId).all(), 'list'] as const,
      list: (filters: any = {}) => [...queryKeys.invites.spaces(spaceId).lists(), filters] as const,
    }),
    teams: (teamId: MaybeRef<string>) => ({
      all: () => ['teams', teamId, 'invites'] as const,
      lists: () => [...queryKeys.invites.teams(teamId).all(), 'list'] as const,
      list: (filters: any = {}) => [...queryKeys.invites.teams(teamId).lists(), filters] as const,
    }),
    spaceList: (spaceId: MaybeRef<string>, filters: any = {}) =>
      [...queryKeys.invites.spaces(spaceId).list(filters)] as const,
    teamList: (teamId: MaybeRef<string>, filters: any = {}) =>
      [...queryKeys.invites.teams(teamId).list(filters)] as const,
  },
  releases: (spaceId: MaybeRef<string>) => ({
    all: () => ['spaces', spaceId, 'releases'] as const,
    lists: () => [...queryKeys.releases(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.releases(spaceId).lists(), filters] as const,
    details: () => [...queryKeys.releases(spaceId).all(), 'detail'] as const,
    detail: (id: MaybeRef<string>) => [...queryKeys.releases(spaceId).details(), id] as const,
  }),
  users: {
    all: () => ['users'] as const,
    me: () => [...queryKeys.users.all(), 'me'] as const,
  },
  personalAccessTokens: {
    all: () => ['users', 'me', 'tokens'] as const,
    lists: () => [...queryKeys.personalAccessTokens.all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.personalAccessTokens.lists(), filters] as const,
  },
  twoFactor: {
    all: () => ['two-factor'] as const,
    status: () => [...queryKeys.twoFactor.all(), 'status'] as const,
  },
  backups: (spaceId: string) => ({
    all: () => ['spaces', spaceId, 'backups'] as const,
    lists: () => [...queryKeys.backups(spaceId).all(), 'list'] as const,
    list: (filters: any = {}) => [...queryKeys.backups(spaceId).lists(), filters] as const,
    details: () => [...queryKeys.backups(spaceId).all(), 'detail'] as const,
    detail: (id: string) => [...queryKeys.backups(spaceId).details(), id] as const,
  }),
}
