import { describe, expect, it } from 'vitest'
import { ref } from 'vue'

import { queryKeys } from '~/composables/useQueryClient'

const SPACE = 'space-1'

type KeyBuilder = (...args: unknown[]) => readonly unknown[]
type Namespace = Record<string, KeyBuilder>
type ScopedFactory = (scope: unknown, ...rest: unknown[]) => Namespace

const scoped = (name: string): ScopedFactory =>
  (queryKeys as unknown as Record<string, ScopedFactory>)[name]

/**
 * `invalidateQueries` matches a key by prefix, so the coarse builders must
 * literally lead the fine ones — otherwise a mutation invalidates nothing.
 */
const isPrefixOf = (prefix: readonly unknown[], key: readonly unknown[]) =>
  prefix.length <= key.length && prefix.every((part, index) => Object.is(part, key[index]))

const expectPrefix = (prefix: readonly unknown[], key: readonly unknown[]) => {
  expect(
    isPrefixOf(prefix, key),
    `${JSON.stringify(prefix)} is not a prefix of ${JSON.stringify(key)}`
  ).toBe(true)
}

// Every space-scoped namespace with the full all/lists/list/details/detail
// shape, paired with the URL-ish segment it claims under ['spaces', id].
const fullSpaceNamespaces = [
  ['automationActions', 'automation-actions'],
  ['automationExecutions', 'automation-executions'],
  ['automations', 'automations'],
  ['assetFolders', 'asset-folders'],
  ['blockFolders', 'block-folders'],
  ['blockTags', 'block-tags'],
  ['assetCollections', 'asset-collections'],
  ['assetPackages', 'asset-packages'],
  ['assetShares', 'asset-shares'],
  ['assetTags', 'asset-tags'],
  ['redirects', 'redirects'],
  ['assets', 'assets'],
  ['icons', 'icons'],
  ['blocks', 'blocks'],
  ['contents', 'contents'],
  ['fieldPlugins', 'field-plugins'],
  ['dataSources', 'data-sources'],
  ['releases', 'releases'],
  ['migrations', 'migrations'],
  ['backups', 'backups'],
] as const

// Namespaces that only ever list — no detail branch exists.
const listOnlySpaceNamespaces = [
  ['tokens', 'tokens'],
  ['spaceMembers', 'members'],
  ['spacePeople', 'people'],
  ['auditLogs', 'audit-logs'],
] as const

describe('space-scoped namespaces', () => {
  it.each(fullSpaceNamespaces)('%s builds the full key ladder', (name, segment) => {
    const keys = scoped(name)(SPACE)

    expect(keys.all()).toEqual(['spaces', SPACE, segment])
    expect(keys.lists()).toEqual(['spaces', SPACE, segment, 'list'])
    expect(keys.list({ page: 2 })).toEqual(['spaces', SPACE, segment, 'list', { page: 2 }])
    expect(keys.details()).toEqual(['spaces', SPACE, segment, 'detail'])
    expect(keys.detail('id-1')).toEqual(['spaces', SPACE, segment, 'detail', 'id-1'])
  })

  it.each(fullSpaceNamespaces)('%s nests every builder under all()', (name) => {
    const keys = scoped(name)(SPACE)

    expectPrefix(keys.all(), keys.lists())
    expectPrefix(keys.all(), keys.details())
    expectPrefix(keys.lists(), keys.list({ q: 'x' }))
    expectPrefix(keys.details(), keys.detail('id-1'))
  })

  it.each(fullSpaceNamespaces)('%s defaults list() to an empty filter object', (name, segment) => {
    expect(scoped(name)(SPACE).list()).toEqual(['spaces', SPACE, segment, 'list', {}])
  })

  it.each(listOnlySpaceNamespaces)('%s builds a list-only ladder', (name, segment) => {
    const keys = scoped(name)(SPACE)

    expect(keys.all()).toEqual(['spaces', SPACE, segment])
    expect(keys.lists()).toEqual(['spaces', SPACE, segment, 'list'])
    expect(keys.list()).toEqual(['spaces', SPACE, segment, 'list', {}])
    expect(keys.details).toBeUndefined()
  })

  it('keeps lists() and details() disjoint, so one never invalidates the other', () => {
    const keys = queryKeys.contents(SPACE)

    expect(isPrefixOf(keys.lists(), keys.detail('id-1'))).toBe(false)
    expect(isPrefixOf(keys.details(), keys.list({}))).toBe(false)
  })

  it('separates spaces, so invalidating one space leaves the other alone', () => {
    expect(isPrefixOf(queryKeys.assets('space-1').all(), queryKeys.assets('space-2').list({}))).toBe(
      false
    )
  })

  it('keeps a ref unwrapped in the key — vue-query hashes it, the array does not', () => {
    const spaceRef = ref(SPACE)

    // Pinned deliberately: the raw array holds the Ref object, so a test that
    // seeds with a literal id will not match a key built from a ref.
    expect(queryKeys.assets(spaceRef).all()[1]).toBe(spaceRef)
    expect(queryKeys.assets(spaceRef).all()).not.toEqual(['spaces', SPACE, 'assets'])
  })

  it('passes a null filter straight through — the default only covers undefined', () => {
    expect(queryKeys.contents(SPACE).list(null)).toEqual(['spaces', SPACE, 'contents', 'list', null])
  })

  it('distinguishes filter objects by value, since vue-query hashes them structurally', () => {
    expect(queryKeys.contents(SPACE).list({ a: 1 })).toEqual(
      queryKeys.contents(SPACE).list({ a: 1 })
    )
    expect(queryKeys.contents(SPACE).list({ a: 1 })).not.toEqual(
      queryKeys.contents(SPACE).list({ a: 2 })
    )
  })
})

describe('spaces', () => {
  it('builds the management-level ladder', () => {
    expect(queryKeys.spaces.all()).toEqual(['spaces'])
    expect(queryKeys.spaces.lists()).toEqual(['spaces', 'list'])
    expect(queryKeys.spaces.list()).toEqual(['spaces', 'list', {}])
    expect(queryKeys.spaces.details()).toEqual(['spaces', 'detail'])
    expect(queryKeys.spaces.detail('space-1')).toEqual(['spaces', 'detail', 'space-1'])
  })

  it('is a prefix of every space-scoped key', () => {
    // Consequence: invalidating spaces.all() (useInvites does) invalidates the
    // entire per-space cache for every space at once.
    expectPrefix(queryKeys.spaces.all(), queryKeys.contents(SPACE).list({}))
    expectPrefix(queryKeys.spaces.all(), queryKeys.assets(SPACE).detail('a1'))
  })

  it('keeps its own list branch clear of a space-scoped key', () => {
    expect(isPrefixOf(queryKeys.spaces.lists(), queryKeys.assets(SPACE).all())).toBe(false)
  })
})

describe('nested space resources', () => {
  it('nests asset versions under the asset detail, so invalidating the asset reaches them', () => {
    const keys = queryKeys.assetVersions(SPACE, 'asset-1')
    const detail = queryKeys.assets(SPACE).detail('asset-1')

    expect(keys.all()).toEqual([...detail, 'versions'])
    expect(keys.all().slice(0, detail.length)).toEqual([...detail])
    expect(keys.lists()).toEqual([...detail, 'versions', 'list'])
    expect(keys.list({ page: 1 })).toEqual([...detail, 'versions', 'list', { page: 1 }])

    expectPrefix(queryKeys.assets(SPACE).all(), keys.all())
    // The point of the nesting: an asset-detail invalidation now cascades to versions.
    expect(isPrefixOf(detail, keys.all())).toBe(true)
  })

  it('scopes linked contents under the asset detail key', () => {
    const keys = queryKeys.assets(SPACE)

    expect(keys.linkedContents('a1')).toEqual(['spaces', SPACE, 'assets', 'detail', 'a1', 'linked-contents'])
    expect(keys.linkedContentsPage('a1', 3)).toEqual([
      'spaces',
      SPACE,
      'assets',
      'detail',
      'a1',
      'linked-contents',
      3,
    ])

    // So removing the detail key also drops every linked-contents page.
    expectPrefix(keys.detail('a1'), keys.linkedContents('a1'))
    expectPrefix(keys.linkedContents('a1'), keys.linkedContentsPage('a1', 3))
  })

  it('scopes collection assets under the collection namespace', () => {
    const keys = queryKeys.assetCollections(SPACE)

    expect(keys.assets('c1')).toEqual(['spaces', SPACE, 'asset-collections', 'assets', 'c1'])
    expect(keys.assetsList('c1', { page: 2 })).toEqual([
      'spaces',
      SPACE,
      'asset-collections',
      'assets',
      'c1',
      { page: 2 },
    ])
    expect(keys.assetsList('c1')).toEqual([...keys.assets('c1'), {}])

    // The assets branch sits beside lists(), not inside it.
    expectPrefix(keys.all(), keys.assets('c1'))
    expect(isPrefixOf(keys.lists(), keys.assets('c1'))).toBe(false)
  })

  it('scopes icon tags beside the icon lists', () => {
    expect(queryKeys.icons(SPACE).tags()).toEqual(['spaces', SPACE, 'icons', 'tags'])
    expect(isPrefixOf(queryKeys.icons(SPACE).lists(), queryKeys.icons(SPACE).tags())).toBe(false)
  })

  it('scopes block templates under the block id', () => {
    const keys = queryKeys.blockTemplates(SPACE, 'block-1')

    expect(keys.all()).toEqual(['spaces', SPACE, 'blocks', 'block-1', 'templates'])
    expect(keys.lists()).toEqual([...keys.all(), 'list'])
    expect(keys.list()).toEqual([...keys.all(), 'list', {}])
    expect(keys.details()).toEqual([...keys.all(), 'detail'])
    expect(keys.detail('t1')).toEqual([...keys.all(), 'detail', 't1'])

    expectPrefix(queryKeys.blocks(SPACE).all(), keys.all())
    expect(isPrefixOf(queryKeys.blocks(SPACE).lists(), keys.all())).toBe(false)
  })

  it('scopes block versions under the block id', () => {
    const keys = queryKeys.blockVersions(SPACE, 'block-1')

    expect(keys.all()).toEqual(['spaces', SPACE, 'blocks', 'block-1', 'versions'])
    expect(keys.list({ page: 1 })).toEqual([...keys.all(), 'list', { page: 1 }])
    expect(keys.detail('v1')).toEqual([...keys.all(), 'detail', 'v1'])
  })

  it('names the content version namespace "history", not "versions"', () => {
    const keys = queryKeys.contentVersions(SPACE, 'content-1')

    expect(keys.all()).toEqual(['spaces', SPACE, 'contents', 'content-1', 'history'])
    expect(keys.lists()).toEqual([...keys.all(), 'list'])
    expect(keys.list({ page: 1 })).toEqual([...keys.all(), 'list', { page: 1 }])
    expect(keys.detail('v1')).toEqual([...keys.all(), 'detail', 'v1'])

    // Invalidating contents.lists() misses history entirely — which is why
    // useContent has to walk the family and invalidate history explicitly.
    expect(isPrefixOf(queryKeys.contents(SPACE).lists(), keys.all())).toBe(false)
    expectPrefix(queryKeys.contents(SPACE).all(), keys.all())
  })

  it('scopes comments under the content id', () => {
    const keys = queryKeys.comments(SPACE, 'content-1')

    expect(keys.all()).toEqual(['spaces', SPACE, 'contents', 'content-1', 'comments'])
    expect(keys.lists()).toEqual([...keys.all(), 'list'])
    expect(keys.list({ resolved: false })).toEqual([...keys.all(), 'list', { resolved: false }])
    expect(keys.detail('c1')).toEqual([...keys.all(), 'detail', 'c1'])

    expectPrefix(queryKeys.contents(SPACE).all(), keys.all())
  })

  it('keeps comments and history apart for the same content', () => {
    expect(
      isPrefixOf(
        queryKeys.contentVersions(SPACE, 'content-1').all(),
        queryKeys.comments(SPACE, 'content-1').all()
      )
    ).toBe(false)
  })

  it('scopes data entries under the data source id', () => {
    const keys = queryKeys.dataEntries(SPACE, 'ds-1')

    expect(keys.all()).toEqual(['spaces', SPACE, 'data-sources', 'ds-1', 'entries'])
    expect(keys.list({ page: 1 })).toEqual([...keys.all(), 'list', { page: 1 }])
    expect(keys.detail('e1')).toEqual([...keys.all(), 'detail', 'e1'])

    expectPrefix(queryKeys.dataSources(SPACE).all(), keys.all())
    expect(isPrefixOf(queryKeys.dataSources(SPACE).lists(), keys.all())).toBe(false)
  })

  it('gives the content menu a single flat key', () => {
    expect(queryKeys.contentMenu(SPACE).all()).toEqual(['spaces', SPACE, 'content-menu'])
    expect(isPrefixOf(queryKeys.contents(SPACE).all(), queryKeys.contentMenu(SPACE).all())).toBe(
      false
    )
  })
})

describe('teams', () => {
  it('builds the team ladder', () => {
    expect(queryKeys.teams.all()).toEqual(['teams'])
    expect(queryKeys.teams.lists()).toEqual(['teams', 'list'])
    expect(queryKeys.teams.list()).toEqual(['teams', 'list', {}])
    expect(queryKeys.teams.details()).toEqual(['teams', 'detail'])
    expect(queryKeys.teams.detail('t1')).toEqual(['teams', 'detail', 't1'])
    expect(queryKeys.teams.hierarchy()).toEqual(['teams', 'hierarchy'])
  })

  it('nests the saml provider and roles under the team detail', () => {
    expect(queryKeys.teams.samlProvider('t1')).toEqual(['teams', 'detail', 't1', 'saml-provider'])
    expect(queryKeys.teams.roles('t1').all()).toEqual(['teams', 'detail', 't1', 'roles'])
    expect(queryKeys.teams.roles('t1').space()).toEqual(['teams', 'detail', 't1', 'roles', 'space'])

    expectPrefix(queryKeys.teams.detail('t1'), queryKeys.teams.samlProvider('t1'))
    expectPrefix(queryKeys.teams.roles('t1').all(), queryKeys.teams.roles('t1').space())
  })

  it('scopes team people by team id instead of under the team detail', () => {
    const keys = queryKeys.teamPeople('t1')

    expect(keys.all()).toEqual(['teams', 't1', 'people'])
    expect(keys.list()).toEqual(['teams', 't1', 'people', 'list', {}])

    expectPrefix(queryKeys.teams.all(), keys.all())
    expect(isPrefixOf(queryKeys.teams.detail('t1'), keys.all())).toBe(false)
  })
})

describe('user-scoped keys', () => {
  it('builds the user ladder', () => {
    expect(queryKeys.users.all()).toEqual(['users'])
    expect(queryKeys.users.me()).toEqual(['users', 'me'])
    expect(queryKeys.users.socialLinks()).toEqual(['users', 'me', 'social-links'])
  })

  it('nests personal access tokens under users.me()', () => {
    expect(queryKeys.personalAccessTokens.all()).toEqual(['users', 'me', 'tokens'])
    expect(queryKeys.personalAccessTokens.lists()).toEqual(['users', 'me', 'tokens', 'list'])
    expect(queryKeys.personalAccessTokens.list()).toEqual(['users', 'me', 'tokens', 'list', {}])

    // Consequence: invalidating users.me() also invalidates the token list and
    // the social links, even though neither changed.
    expectPrefix(queryKeys.users.me(), queryKeys.personalAccessTokens.all())
    expectPrefix(queryKeys.users.me(), queryKeys.users.socialLinks())
  })

  it('builds the two factor ladder', () => {
    expect(queryKeys.twoFactor.all()).toEqual(['two-factor'])
    expect(queryKeys.twoFactor.status()).toEqual(['two-factor', 'status'])
  })

  it('builds the notification ladder', () => {
    expect(queryKeys.notifications.all()).toEqual(['notifications'])
    expect(queryKeys.notifications.lists()).toEqual(['notifications', 'list'])
    expect(queryKeys.notifications.list({ unread: true })).toEqual([
      'notifications',
      'list',
      { unread: true },
    ])
    expect(queryKeys.notifications.unreadCount()).toEqual(['notifications', 'unread-count'])

    // Both branches hang off all(), so one invalidateQueries refreshes each.
    expectPrefix(queryKeys.notifications.all(), queryKeys.notifications.unreadCount())
    expect(
      isPrefixOf(queryKeys.notifications.lists(), queryKeys.notifications.unreadCount())
    ).toBe(false)
  })

  it('builds the invites ladder', () => {
    expect(queryKeys.invites.all()).toEqual(['invites'])
    expect(queryKeys.invites.public('i1')).toEqual(['invites', 'public', 'i1'])
    expect(queryKeys.invites.public(undefined)).toEqual(['invites', 'public', undefined])
    expect(queryKeys.invites.my()).toEqual(['invites', 'my'])
    expect(queryKeys.invites.myLists()).toEqual(['invites', 'my', 'list'])
    expect(queryKeys.invites.myList()).toEqual(['invites', 'my', 'list', {}])
    expect(queryKeys.invites.myDetails()).toEqual(['invites', 'my', 'detail'])
    expect(queryKeys.invites.myDetail('i1')).toEqual(['invites', 'my', 'detail', 'i1'])

    expectPrefix(queryKeys.invites.my(), queryKeys.invites.myList({}))
    expect(isPrefixOf(queryKeys.invites.my(), queryKeys.invites.public('i1'))).toBe(false)
  })

  it('builds the authorization ladder', () => {
    expect(queryKeys.authorization.all()).toEqual(['authorization'])
    expect(queryKeys.authorization.context()).toEqual(['authorization', {}])
    expect(queryKeys.authorization.context({ space: SPACE })).toEqual([
      'authorization',
      { space: SPACE },
    ])
  })
})

describe('billing keys', () => {
  it('builds the plan ladder', () => {
    expect(queryKeys.plans.all()).toEqual(['plans'])
    expect(queryKeys.plans.lists()).toEqual(['plans', 'list'])
    expect(queryKeys.plans.forSpace(SPACE)).toEqual(['plans', 'space', SPACE])

    // forSpace is a sibling of lists(), so invalidating the global plan list
    // leaves a space's plan offer stale.
    expect(isPrefixOf(queryKeys.plans.lists(), queryKeys.plans.forSpace(SPACE))).toBe(false)
  })

  it('builds the subscription ladder', () => {
    const keys = queryKeys.subscriptions(SPACE)

    expect(keys.all()).toEqual(['spaces', SPACE, 'subscriptions'])
    expect(keys.lists()).toEqual(['spaces', SPACE, 'subscriptions', 'list'])
    expect(keys.current()).toEqual(['spaces', SPACE, 'subscriptions', 'current'])
    expect(keys.proposal()).toEqual(['spaces', SPACE, 'subscriptions', 'proposal'])

    expectPrefix(keys.all(), keys.current())
    expectPrefix(keys.all(), keys.proposal())
  })

  it('builds the usage history ladder', () => {
    const keys = queryKeys.usageHistory(SPACE)

    expect(keys.all()).toEqual(['spaces', SPACE, 'usage-history'])
    expect(keys.lists()).toEqual(['spaces', SPACE, 'usage-history', 'list'])
    expect(keys.timeseries('p1')).toEqual(['spaces', SPACE, 'usage-history', 'timeseries', 'p1'])

    expect(isPrefixOf(keys.lists(), keys.timeseries('p1'))).toBe(false)
  })

  it('builds the invoice ladder', () => {
    expect(queryKeys.invoices(SPACE).all()).toEqual(['spaces', SPACE, 'invoices'])
    expect(queryKeys.invoices(SPACE).lists()).toEqual(['spaces', SPACE, 'invoices', 'list'])
  })
})

describe('keys outside the space tree', () => {
  it('keeps the ai keys inside the space tree, under one prefix', () => {
    const keys = queryKeys.ai(SPACE)

    expect(keys.all()).toEqual(['spaces', SPACE, 'ai'])
    expect(keys.models()).toEqual(['spaces', SPACE, 'ai', 'models'])
    expect(keys.settings()).toEqual(['spaces', SPACE, 'ai', 'settings'])
    expect(keys.usage()).toEqual(['spaces', SPACE, 'ai', 'usage'])
    expect(keys.configs()).toEqual(['spaces', SPACE, 'ai', 'configs'])
    expect(keys.config('cfg-1')).toEqual(['spaces', SPACE, 'ai', 'configs', 'cfg-1'])

    // So a space-wide invalidation reaches ai state, and ai.all() clears all
    // of it at once — a config detail included.
    for (const key of [
      keys.models(),
      keys.settings(),
      keys.usage(),
      keys.configs(),
      keys.config('cfg-1'),
    ]) {
      expect(isPrefixOf(['spaces', SPACE], key)).toBe(true)
      expect(isPrefixOf(keys.all(), key)).toBe(true)
    }
  })

  it('puts a public share on its own root, keyed by space and token', () => {
    const keys = queryKeys.publicShare(SPACE, 'tok')

    expect(keys.all()).toEqual(['public-share', SPACE, 'tok'])
    expect(keys.meta()).toEqual(['public-share', SPACE, 'tok', 'meta'])
    expect(keys.assets()).toEqual(['public-share', SPACE, 'tok', 'assets'])
    expect(keys.assetsList({ page: 1 })).toEqual([
      'public-share',
      SPACE,
      'tok',
      'assets',
      { page: 1 },
    ])
    expect(keys.assetsList()).toEqual([...keys.assets(), {}])

    expectPrefix(keys.all(), keys.meta())
    expectPrefix(keys.assets(), keys.assetsList({}))
    expect(isPrefixOf(queryKeys.assets(SPACE).all(), keys.assets())).toBe(false)
  })

  it('builds the provider ladder', () => {
    expect(queryKeys.provider.all()).toEqual(['provider'])
    expect(queryKeys.provider.stats()).toEqual(['provider', 'stats', {}])
    expect(queryKeys.provider.stats({ range: '7d' })).toEqual([
      'provider',
      'stats',
      { range: '7d' },
    ])
    expect(queryKeys.provider.notes()).toEqual(['provider', 'notes'])
    expect(queryKeys.provider.notesList()).toEqual(['provider', 'notes', 'list', {}])

    expectPrefix(queryKeys.provider.notes(), queryKeys.provider.notesList({ page: 1 }))
  })
})

describe('builder stability', () => {
  it('returns a fresh array each call, so a caller cannot mutate the factory', () => {
    const first = queryKeys.contents(SPACE).all() as unknown as string[]
    first.push('tampered')

    expect(queryKeys.contents(SPACE).all()).toEqual(['spaces', SPACE, 'contents'])
  })

  it('returns equal keys for equal inputs across calls', () => {
    expect(queryKeys.blocks(SPACE).detail('b1')).toEqual(queryKeys.blocks(SPACE).detail('b1'))
    expect(queryKeys.comments(SPACE, 'c1').lists()).toEqual(queryKeys.comments(SPACE, 'c1').lists())
  })

  it('names blockTags detail by tag name, so two spaces cannot collide', () => {
    expect(queryKeys.blockTags(SPACE).detail('hero')).toEqual([
      'spaces',
      SPACE,
      'block-tags',
      'detail',
      'hero',
    ])
  })
})
