import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import type { AcceptInvitePayload, CreateInvitePayload } from '~/types/invites'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const getPublicInvite = vi.fn()
const listMyInvites = vi.fn()
const getMyInvite = vi.fn()
const createSpaceInvite = vi.fn()
const deleteSpaceInvite = vi.fn()
const resendSpaceInvite = vi.fn()
const createTeamInvite = vi.fn()
const deleteTeamInvite = vi.fn()
const resendTeamInvite = vi.fn()
const acceptInvite = vi.fn()
const declineInvite = vi.fn()

vi.mock('~/api', () => ({
  api: {
    invites: {
      getPublicInvite,
      listMyInvites,
      getMyInvite,
      createSpaceInvite,
      deleteSpaceInvite,
      resendSpaceInvite,
      createTeamInvite,
      deleteTeamInvite,
      resendTeamInvite,
      acceptInvite,
      declineInvite,
    },
  },
}))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useInvites } = await import('~/composables/useInvites')

const SPACE = 'space-1'
const TEAM = 'team-1'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

type Composable = ReturnType<typeof useInvites>
type Mutations = {
  createSpace: ReturnType<Composable['useCreateSpaceInviteMutation']>
  deleteSpace: ReturnType<Composable['useDeleteSpaceInviteMutation']>
  resendSpace: ReturnType<Composable['useResendSpaceInviteMutation']>
  createTeam: ReturnType<Composable['useCreateTeamInviteMutation']>
  deleteTeam: ReturnType<Composable['useDeleteTeamInviteMutation']>
  resendTeam: ReturnType<Composable['useResendTeamInviteMutation']>
  accept: ReturnType<Composable['useAcceptInviteMutation']>
  decline: ReturnType<Composable['useDeclineInviteMutation']>
}

let harness: Harness<Mutations> | undefined
let queryHarness: Harness<unknown> | undefined

const setup = () => {
  harness = withSetup<Mutations>(() => {
    const invites = useInvites()
    return {
      createSpace: invites.useCreateSpaceInviteMutation(),
      deleteSpace: invites.useDeleteSpaceInviteMutation(),
      resendSpace: invites.useResendSpaceInviteMutation(),
      createTeam: invites.useCreateTeamInviteMutation(),
      deleteTeam: invites.useDeleteTeamInviteMutation(),
      resendTeam: invites.useResendTeamInviteMutation(),
      accept: invites.useAcceptInviteMutation(),
      decline: invites.useDeclineInviteMutation(),
    }
  })
  return harness.result
}

beforeEach(() => {
  for (const fn of [
    getPublicInvite,
    listMyInvites,
    getMyInvite,
    createSpaceInvite,
    deleteSpaceInvite,
    resendSpaceInvite,
    createTeamInvite,
    deleteTeamInvite,
    resendTeamInvite,
    acceptInvite,
    declineInvite,
    success,
    error,
  ]) {
    fn.mockReset()
  }
})

afterEach(() => {
  harness?.unmount()
  queryHarness?.unmount()
  harness = undefined
  queryHarness = undefined
})

describe('usePublicInviteQuery', () => {
  it('sends both the id and the token', async () => {
    getPublicInvite.mockResolvedValue({ data: { id: 'i1', email: 'a@b.test' } })

    queryHarness = withSetup(() => useInvites().usePublicInviteQuery('i1', 'tok'))
    await flush()

    expect(getPublicInvite).toHaveBeenCalledWith('i1', 'tok')
    expect(
      queryHarness.queryClient.getQueryData([...queryKeys.invites.public('i1'), 'tok'])
    ).toEqual({
      id: 'i1',
      email: 'a@b.test',
    })
  })

  it('stays idle without a token — the invite is token-gated', async () => {
    const local = withSetup(() => useInvites().usePublicInviteQuery('i1', undefined))
    await flush()

    expect(local.result.fetchStatus.value).toBe('idle')
    expect(getPublicInvite).not.toHaveBeenCalled()
    local.unmount()
  })

  it('stays idle without an invite id', async () => {
    const local = withSetup(() => useInvites().usePublicInviteQuery(undefined, 'tok'))
    await flush()

    expect(local.result.fetchStatus.value).toBe('idle')
    local.unmount()
  })

  it('keys on the token too, so a second token is fetched rather than served from cache', async () => {
    getPublicInvite.mockResolvedValue({ data: { id: 'i1' } })
    const token = ref('tok-a')

    const local = withSetup(() => useInvites().usePublicInviteQuery('i1', token))
    await flush()
    token.value = 'tok-b'
    await nextTick()
    await flush()

    expect(getPublicInvite).toHaveBeenCalledTimes(2)
    expect(getPublicInvite).toHaveBeenLastCalledWith('i1', 'tok-b')
    local.unmount()
  })
})

describe('my invites queries', () => {
  it('returns the whole envelope for the list', async () => {
    listMyInvites.mockResolvedValue({ data: [{ id: 'i1' }], meta: { total: 1 } })

    queryHarness = withSetup(() => useInvites().useMyInvitesQuery({ page: 2 }))
    await flush()

    expect(queryHarness.queryClient.getQueryData(queryKeys.invites.myList({ page: 2 }))).toEqual({
      data: [{ id: 'i1' }],
      meta: { total: 1 },
    })
    expect(listMyInvites).toHaveBeenCalledWith({ page: 2 })
  })

  it('unwraps the envelope for a single invite', async () => {
    getMyInvite.mockResolvedValue({ data: { id: 'i1' } })

    const local = withSetup(() => useInvites().useMyInviteQuery('i1'))
    await flush()

    expect(local.result.data.value).toEqual({ id: 'i1' })
    local.unmount()
  })

  it('fetches my invites without an auth guard, unlike the team and space lists', async () => {
    listMyInvites.mockResolvedValue({ data: [] })

    queryHarness = withSetup(() => useInvites().useMyInvitesQuery())
    await flush()

    expect(listMyInvites).toHaveBeenCalledTimes(1)
  })
})

describe('space invites', () => {
  it('creates an invite and refreshes the space people list', async () => {
    createSpaceInvite.mockResolvedValue({ data: { id: 'i1', email: 'ada@acme.test' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.createSpace.mutateAsync({
      spaceId: SPACE,
      payload: { email: 'ada@acme.test', role: 'editor' } as CreateInvitePayload,
    })

    expect(createSpaceInvite).toHaveBeenCalledWith(SPACE, {
      email: 'ada@acme.test',
      role: 'editor',
    })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.spacePeople(SPACE).lists() })
    expect(success).toHaveBeenCalledWith('Invite sent to ada@acme.test')
  })

  it('does not refresh the members list, which is keyed separately', async () => {
    createSpaceInvite.mockResolvedValue({ data: { email: 'ada@acme.test' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.createSpace.mutateAsync({
      spaceId: SPACE,
      payload: {} as CreateInvitePayload,
    })

    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(invalidate).not.toHaveBeenCalledWith({
      queryKey: queryKeys.spaceMembers(SPACE).lists(),
    })
  })

  it('reports a rejected invite, e.g. a seat ceiling', async () => {
    createSpaceInvite.mockRejectedValue(new Error('member limit reached'))
    const mutations = setup()

    await mutations.createSpace
      .mutateAsync({ spaceId: SPACE, payload: {} as CreateInvitePayload })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to send invite: member limit reached')
    expect(success).not.toHaveBeenCalled()
  })

  it('revokes an invite and refreshes the people list', async () => {
    deleteSpaceInvite.mockResolvedValue(undefined)
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.deleteSpace.mutateAsync({ spaceId: SPACE, inviteId: 'i1' })

    expect(deleteSpaceInvite).toHaveBeenCalledWith(SPACE, 'i1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.spacePeople(SPACE).lists() })
    expect(success).toHaveBeenCalledWith('Invite revoked')
  })

  it('reports a failed revoke', async () => {
    deleteSpaceInvite.mockRejectedValue(new Error('already accepted'))
    const mutations = setup()

    await mutations.deleteSpace
      .mutateAsync({ spaceId: SPACE, inviteId: 'i1' })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to revoke invite: already accepted')
  })

  it('resends an invite and names the recipient', async () => {
    resendSpaceInvite.mockResolvedValue({ data: { email: 'ada@acme.test' } })
    const mutations = setup()

    await mutations.resendSpace.mutateAsync({ spaceId: SPACE, inviteId: 'i1' })

    expect(resendSpaceInvite).toHaveBeenCalledWith(SPACE, 'i1')
    expect(success).toHaveBeenCalledWith('Invite resent to ada@acme.test')
  })

  it('reports a throttled resend', async () => {
    resendSpaceInvite.mockRejectedValue(new Error('too many requests'))
    const mutations = setup()

    await mutations.resendSpace
      .mutateAsync({ spaceId: SPACE, inviteId: 'i1' })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to resend invite: too many requests')
  })
})

describe('team invites', () => {
  it('creates a team invite and refreshes the team people list', async () => {
    createTeamInvite.mockResolvedValue({ data: { email: 'ada@acme.test' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.createTeam.mutateAsync({
      teamId: TEAM,
      payload: { email: 'ada@acme.test', role: 'member' } as CreateInvitePayload,
    })

    expect(createTeamInvite).toHaveBeenCalledWith(TEAM, {
      email: 'ada@acme.test',
      role: 'member',
    })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teamPeople(TEAM).lists() })
    expect(success).toHaveBeenCalledWith('Invite sent to ada@acme.test')
  })

  it('refreshes the team detail too, so a cached member count stays right', async () => {
    createTeamInvite.mockResolvedValue({ data: { email: 'a@b.test' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.createTeam.mutateAsync({ teamId: TEAM, payload: {} as CreateInvitePayload })

    expect(invalidate).toHaveBeenCalledTimes(2)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.detail(TEAM) })
  })

  it('revokes a team invite', async () => {
    deleteTeamInvite.mockResolvedValue(undefined)
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.deleteTeam.mutateAsync({ teamId: TEAM, inviteId: 'i1' })

    expect(deleteTeamInvite).toHaveBeenCalledWith(TEAM, 'i1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teamPeople(TEAM).lists() })
    expect(success).toHaveBeenCalledWith('Invite revoked')
  })

  it('resends a team invite', async () => {
    resendTeamInvite.mockResolvedValue({ data: { email: 'ada@acme.test' } })
    const mutations = setup()

    await mutations.resendTeam.mutateAsync({ teamId: TEAM, inviteId: 'i1' })

    expect(resendTeamInvite).toHaveBeenCalledWith(TEAM, 'i1')
    expect(success).toHaveBeenCalledWith('Invite resent to ada@acme.test')
  })

  it('shares the space-invite error copy', async () => {
    deleteTeamInvite.mockRejectedValue(new Error('gone'))
    const mutations = setup()

    await mutations.deleteTeam.mutateAsync({ teamId: TEAM, inviteId: 'i1' }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to revoke invite: gone')
  })
})

describe('useAcceptInviteMutation', () => {
  it('names the space it joined and clears every affected cache', async () => {
    acceptInvite.mockResolvedValue({ data: { id: 'i1', space: { name: 'Acme Web' } } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.accept.mutateAsync({
      inviteId: 'i1',
      payload: { token: 'tok' } as AcceptInvitePayload,
    })

    expect(acceptInvite).toHaveBeenCalledWith('i1', { token: 'tok' })
    for (const key of [
      queryKeys.invites.my(),
      queryKeys.spaces.lists(),
      queryKeys.teams.all(),
      queryKeys.authorization.all(),
    ]) {
      expect(invalidate).toHaveBeenCalledWith({ queryKey: key })
    }
    // spaces.all() would be a prefix of every space-scoped key, i.e. the whole cache.
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: queryKeys.spaces.all() })
    expect(success).toHaveBeenCalledWith('Successfully joined Acme Web')
  })

  it('names the team when the invite is a team invite', async () => {
    acceptInvite.mockResolvedValue({ data: { team: { name: 'Platform' } } })

    await setup().accept.mutateAsync({ inviteId: 'i1', payload: {} as AcceptInvitePayload })

    expect(success).toHaveBeenCalledWith('Successfully joined Platform')
  })

  it('prefers the space name when the invite carries both', async () => {
    acceptInvite.mockResolvedValue({
      data: { space: { name: 'Acme Web' }, team: { name: 'Platform' } },
    })

    await setup().accept.mutateAsync({ inviteId: 'i1', payload: {} as AcceptInvitePayload })

    expect(success).toHaveBeenCalledWith('Successfully joined Acme Web')
  })

  it('falls back to a translated target when neither name is present', async () => {
    acceptInvite.mockResolvedValue({ data: { id: 'i1' } })

    await setup().accept.mutateAsync({ inviteId: 'i1', payload: {} as AcceptInvitePayload })

    expect(success).toHaveBeenCalledWith('Successfully joined the workspace')
  })

  it('purges the my-invites list, so the accepted invite disappears', async () => {
    acceptInvite.mockResolvedValue({ data: { space: { name: 'Acme' } } })
    const mutations = setup()
    harness!.queryClient.setQueryData(queryKeys.invites.myList({}), { data: [{ id: 'i1' }] })

    await mutations.accept.mutateAsync({ inviteId: 'i1', payload: {} as AcceptInvitePayload })

    const state = harness!.queryClient
      .getQueryCache()
      .find({ queryKey: queryKeys.invites.myList({}) })

    expect(state?.state.isInvalidated).toBe(true)
  })

  it('leaves the public invite entry alone', async () => {
    acceptInvite.mockResolvedValue({ data: { space: { name: 'Acme' } } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.accept.mutateAsync({ inviteId: 'i1', payload: {} as AcceptInvitePayload })

    // invites.my() does not cover invites.public(id) — both hang off invites.all().
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: queryKeys.invites.all() })
  })

  it('reports a rejected acceptance', async () => {
    acceptInvite.mockRejectedValue(new Error('invite expired'))
    const mutations = setup()

    await mutations.accept
      .mutateAsync({ inviteId: 'i1', payload: {} as AcceptInvitePayload })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to accept invite: invite expired')
  })
})

describe('useDeclineInviteMutation', () => {
  it('refreshes only my invites — nothing was joined', async () => {
    declineInvite.mockResolvedValue({ data: { id: 'i1' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.decline.mutateAsync({ inviteId: 'i1' })

    expect(declineInvite).toHaveBeenCalledWith('i1')
    expect(invalidate).toHaveBeenCalledTimes(1)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.invites.my() })
    expect(success).toHaveBeenCalledWith('Invitation declined')
  })

  it('reports a failed decline', async () => {
    declineInvite.mockRejectedValue(new Error('already accepted'))
    const mutations = setup()

    await mutations.decline.mutateAsync({ inviteId: 'i1' }).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to decline invitation: already accepted')
  })

  it('does not remove the declined invite detail from the cache', async () => {
    declineInvite.mockResolvedValue({ data: { id: 'i1' } })
    const mutations = setup()
    harness!.queryClient.setQueryData(queryKeys.invites.myDetail('i1'), { id: 'i1' })

    await mutations.decline.mutateAsync({ inviteId: 'i1' })

    // myDetail sits under invites.my(), so it is invalidated rather than dropped.
    expect(harness!.queryClient.getQueryData(queryKeys.invites.myDetail('i1'))).toEqual({
      id: 'i1',
    })
  })
})

describe('query keys', () => {
  it('my() prefixes both myList and myDetail, so one invalidation covers both', () => {
    const my = queryKeys.invites.my()

    for (const key of [queryKeys.invites.myList({ page: 1 }), queryKeys.invites.myDetail('i1')]) {
      expect(key.slice(0, my.length)).toEqual([...my])
    }
  })

  it('my() does not cover the public invite entry', () => {
    const pub = queryKeys.invites.public('i1')
    const my = queryKeys.invites.my()

    expect(pub.slice(0, my.length)).not.toEqual([...my])
  })
})
