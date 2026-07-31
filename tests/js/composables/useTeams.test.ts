import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'

import type { CreateTeamSpaceRolePayload, UpdateTeamSpaceRolePayload } from '~/types/authorization'
import type {
  CreateTeamPayload,
  TeamHierarchyItem,
  TeamSamlProviderPayload,
  UpdateTeamPayload,
  UpdateTeamUserPayload,
} from '~/types/teams'

import { queryKeys } from '~/composables/useQueryClient'

import { withSetup, type Harness } from '../support/harness'

const index = vi.fn()
const get = vi.fn()
const getHierarchy = vi.fn()
const getSpaceRoles = vi.fn()
const getSamlProvider = vi.fn()
const create = vi.fn()
const update = vi.fn()
const destroy = vi.fn()
const deleteAvatar = vi.fn()
const updateUser = vi.fn()
const removeUser = vi.fn()
const createSpaceRole = vi.fn()
const updateSpaceRole = vi.fn()
const deleteSpaceRole = vi.fn()
const upsertSamlProvider = vi.fn()
const deleteSamlProvider = vi.fn()

vi.mock('~/api', () => ({
  api: {
    teams: {
      index,
      get,
      getHierarchy,
      getSpaceRoles,
      getSamlProvider,
      create,
      update,
      delete: destroy,
      deleteAvatar,
      updateUser,
      removeUser,
      createSpaceRole,
      updateSpaceRole,
      deleteSpaceRole,
      upsertSamlProvider,
      deleteSamlProvider,
    },
  },
}))

const isAuthenticated = ref(true)
vi.mock('~/composables/useAuth', () => ({ useAuth: () => ({ isAuthenticated }) }))

const success = vi.fn()
const error = vi.fn()
vi.mock('vue-sonner', () => ({ toast: { success, error } }))

const { useTeams } = await import('~/composables/useTeams')

const TEAM = 'team-1'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const node = (id: string, children: TeamHierarchyItem[] = []): TeamHierarchyItem =>
  ({ id, name: id, children }) as unknown as TeamHierarchyItem

type Composable = ReturnType<typeof useTeams>
type Mutations = {
  createTeam: ReturnType<Composable['useCreateTeamMutation']>
  updateTeam: ReturnType<Composable['useUpdateTeamMutation']>
  deleteTeam: ReturnType<Composable['useDeleteTeamMutation']>
  deleteAvatar: ReturnType<Composable['useDeleteTeamAvatarMutation']>
  updateUser: ReturnType<Composable['useUpdateTeamUserMutation']>
  removeUser: ReturnType<Composable['useRemoveTeamUserMutation']>
  createRole: ReturnType<Composable['useCreateTeamSpaceRoleMutation']>
  updateRole: ReturnType<Composable['useUpdateTeamSpaceRoleMutation']>
  deleteRole: ReturnType<Composable['useDeleteTeamSpaceRoleMutation']>
  upsertSaml: ReturnType<Composable['useUpsertTeamSamlProviderMutation']>
  deleteSaml: ReturnType<Composable['useDeleteTeamSamlProviderMutation']>
  invalidateTeam: Composable['invalidateTeam']
}

let harness: Harness<Mutations> | undefined
let queryHarness: Harness<unknown> | undefined

const setup = () => {
  harness = withSetup<Mutations>(() => {
    const teams = useTeams()
    return {
      createTeam: teams.useCreateTeamMutation(),
      updateTeam: teams.useUpdateTeamMutation(),
      deleteTeam: teams.useDeleteTeamMutation(),
      deleteAvatar: teams.useDeleteTeamAvatarMutation(),
      updateUser: teams.useUpdateTeamUserMutation(),
      removeUser: teams.useRemoveTeamUserMutation(),
      createRole: teams.useCreateTeamSpaceRoleMutation(),
      updateRole: teams.useUpdateTeamSpaceRoleMutation(),
      deleteRole: teams.useDeleteTeamSpaceRoleMutation(),
      upsertSaml: teams.useUpsertTeamSamlProviderMutation(),
      deleteSaml: teams.useDeleteTeamSamlProviderMutation(),
      invalidateTeam: teams.invalidateTeam,
    }
  })
  return harness.result
}

/** The hierarchy helpers are pure, but useTeams() itself needs a query client. */
const utils = () => {
  queryHarness = withSetup(() => {
    const teams = useTeams()
    return {
      findTeamInHierarchy: teams.findTeamInHierarchy,
      getTeamAncestors: teams.getTeamAncestors,
      getTeamDescendants: teams.getTeamDescendants,
    }
  })
  return queryHarness.result as {
    findTeamInHierarchy: Composable['findTeamInHierarchy']
    getTeamAncestors: Composable['getTeamAncestors']
    getTeamDescendants: Composable['getTeamDescendants']
  }
}

beforeEach(() => {
  for (const fn of [
    index,
    get,
    getHierarchy,
    getSpaceRoles,
    getSamlProvider,
    create,
    update,
    destroy,
    deleteAvatar,
    updateUser,
    removeUser,
    createSpaceRole,
    updateSpaceRole,
    deleteSpaceRole,
    upsertSamlProvider,
    deleteSamlProvider,
    success,
    error,
  ]) {
    fn.mockReset()
  }
  isAuthenticated.value = true
})

afterEach(() => {
  harness?.unmount()
  queryHarness?.unmount()
  harness = undefined
  queryHarness = undefined
})

describe('useTeamsQuery', () => {
  it('sorts by name by default', async () => {
    index.mockResolvedValue({ data: [] })

    queryHarness = withSetup(() => useTeams().useTeamsQuery())
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '+name' })
  })

  it('lets the caller override the sort', async () => {
    index.mockResolvedValue({ data: [] })

    queryHarness = withSetup(() => useTeams().useTeamsQuery({ sort: '-created_at' }))
    await flush()

    expect(index).toHaveBeenCalledWith({ sort: '-created_at' })
  })

  it('returns the whole envelope and caches it under the filtered list key', async () => {
    index.mockResolvedValue({ data: [{ id: TEAM }], meta: { total: 1 } })

    queryHarness = withSetup(() => useTeams().useTeamsQuery({ page: 2 }))
    await flush()

    expect(queryHarness.queryClient.getQueryData(queryKeys.teams.list({ page: 2 }))).toEqual({
      data: [{ id: TEAM }],
      meta: { total: 1 },
    })
  })

  it('stays idle while logged out', async () => {
    isAuthenticated.value = false

    queryHarness = withSetup(() => useTeams().useTeamsQuery())
    await flush()

    expect(index).not.toHaveBeenCalled()
  })
})

describe('useTeamQuery', () => {
  it('unwraps the data envelope', async () => {
    get.mockResolvedValue({ data: { id: TEAM, name: 'Platform' } })

    const local = withSetup(() => useTeams().useTeamQuery(TEAM))
    await flush()

    expect(local.result.data.value).toEqual({ id: TEAM, name: 'Platform' })
    expect(get).toHaveBeenCalledWith(TEAM)
    local.unmount()
  })

  it('unwraps a ref id into the cache key, so a plain-string invalidation matches', async () => {
    get.mockResolvedValue({ data: { id: TEAM } })

    queryHarness = withSetup(() => useTeams().useTeamQuery(ref(TEAM)))
    await flush()

    expect(queryHarness.queryClient.getQueryData(queryKeys.teams.detail(TEAM))).toEqual({
      id: TEAM,
    })
  })

  it('refetches under a new key when the id ref changes', async () => {
    get.mockResolvedValue({ data: {} })
    const id = ref(TEAM)

    queryHarness = withSetup(() => useTeams().useTeamQuery(id))
    await flush()
    id.value = 'team-2'
    await nextTick()
    await flush()

    expect(get).toHaveBeenLastCalledWith('team-2')
  })
})

describe('useTeamHierarchyQuery', () => {
  it('caches under the hierarchy key', async () => {
    getHierarchy.mockResolvedValue({ data: [node('a')] })

    queryHarness = withSetup(() => useTeams().useTeamHierarchyQuery())
    await flush()

    expect(queryHarness.queryClient.getQueryData(queryKeys.teams.hierarchy())).toHaveLength(1)
  })

  it('stays idle when disabled', async () => {
    queryHarness = withSetup(() => useTeams().useTeamHierarchyQuery(false))
    await flush()

    expect(getHierarchy).not.toHaveBeenCalled()
  })
})

describe('useTeamSpaceRolesQuery and useTeamSamlProviderQuery', () => {
  it('keys the space roles under the team detail, so a detail invalidation sweeps them too', async () => {
    getSpaceRoles.mockResolvedValue({ data: [] })

    queryHarness = withSetup(() => useTeams().useTeamSpaceRolesQuery(TEAM))
    await flush()

    const rolesKey = queryKeys.teams.roles(TEAM).space()
    const detail = queryKeys.teams.detail(TEAM)

    expect(queryHarness.queryClient.getQueryData(rolesKey)).toEqual([])
    expect(rolesKey.slice(0, detail.length)).toEqual([...detail])
  })

  it('returns the SAML provider response without unwrapping it', async () => {
    getSamlProvider.mockResolvedValue({ data: { entity_id: 'x' }, configured: true })

    const local = withSetup(() => useTeams().useTeamSamlProviderQuery(TEAM))
    await flush()

    expect(local.result.data.value).toEqual({ data: { entity_id: 'x' }, configured: true })
    local.unmount()
  })

  it.each([
    ['no team id', ''],
    ['a logged-out user', TEAM],
  ])('stays idle with %s', async (label, teamId) => {
    if (label === 'a logged-out user') isAuthenticated.value = false

    queryHarness = withSetup(() => useTeams().useTeamSpaceRolesQuery(teamId))
    await flush()

    expect(getSpaceRoles).not.toHaveBeenCalled()
  })
})

describe('useCreateTeamMutation', () => {
  it('invalidates the lists and the hierarchy, and names the team', async () => {
    create.mockResolvedValue({ data: { id: TEAM, name: 'Platform' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.createTeam.mutateAsync({ name: 'Platform' } as CreateTeamPayload)

    expect(create).toHaveBeenCalledWith({ name: 'Platform' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.hierarchy() })
    expect(success).toHaveBeenCalledWith('Team "Platform" created successfully')
  })

  it('refreshes the authorization context, so the new team is grantable right away', async () => {
    create.mockResolvedValue({ data: { id: TEAM, name: 'Platform' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.createTeam.mutateAsync({ name: 'Platform' } as CreateTeamPayload)

    expect(invalidate).toHaveBeenCalledTimes(3)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.authorization.all() })
  })

  it('reports the failure reason', async () => {
    create.mockRejectedValue(new Error('name taken'))
    const mutations = setup()

    await mutations.createTeam.mutateAsync({} as CreateTeamPayload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create team: name taken')
  })

  it('falls back to "Unknown error"', async () => {
    create.mockRejectedValue(new Error(''))
    const mutations = setup()

    await mutations.createTeam.mutateAsync({} as CreateTeamPayload).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to create team: Unknown error')
  })
})

describe('useUpdateTeamMutation', () => {
  it('keys the detail invalidation off the response id, not the argument', async () => {
    update.mockResolvedValue({ data: { id: 'server-id', name: 'Renamed' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.updateTeam.mutateAsync({
      id: TEAM,
      payload: { name: 'Renamed' } as UpdateTeamPayload,
    })

    expect(update).toHaveBeenCalledWith(TEAM, { name: 'Renamed' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.detail('server-id') })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: queryKeys.teams.detail(TEAM) })
    expect(success).toHaveBeenCalledWith('Team "Renamed" updated successfully')
  })

  it('refreshes the hierarchy, since a reparent changes the tree', async () => {
    update.mockResolvedValue({ data: { id: TEAM, name: 'Platform' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.updateTeam.mutateAsync({
      id: TEAM,
      payload: { parent_id: 'team-0' } as UpdateTeamPayload,
    })

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.hierarchy() })
  })

  it('reports the failure reason', async () => {
    update.mockRejectedValue(new Error('cycle'))
    const mutations = setup()

    await mutations.updateTeam
      .mutateAsync({ id: TEAM, payload: {} as UpdateTeamPayload })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update team: cycle')
  })
})

describe('useDeleteTeamMutation', () => {
  it('drops the team detail from the cache instead of refetching it', async () => {
    destroy.mockResolvedValue(undefined)
    const mutations = setup()
    harness!.queryClient.setQueryData(queryKeys.teams.detail(TEAM), { id: TEAM })

    await mutations.deleteTeam.mutateAsync(TEAM)

    expect(destroy).toHaveBeenCalledWith(TEAM)
    expect(harness!.queryClient.getQueryData(queryKeys.teams.detail(TEAM))).toBeUndefined()
    expect(success).toHaveBeenCalledWith('Team deleted successfully')
  })

  it('also drops the roles and SAML entries, which hang off the team detail', async () => {
    destroy.mockResolvedValue(undefined)
    const mutations = setup()
    harness!.queryClient.setQueryData(queryKeys.teams.roles(TEAM).space(), [])
    harness!.queryClient.setQueryData(queryKeys.teams.samlProvider(TEAM), { configured: true })

    await mutations.deleteTeam.mutateAsync(TEAM)

    expect(harness!.queryClient.getQueryData(queryKeys.teams.roles(TEAM).space())).toBeUndefined()
    expect(harness!.queryClient.getQueryData(queryKeys.teams.samlProvider(TEAM))).toBeUndefined()
  })

  it('drops the team people list and refreshes the authorization context', async () => {
    destroy.mockResolvedValue(undefined)
    const mutations = setup()
    harness!.queryClient.setQueryData(queryKeys.teamPeople(TEAM).list({}), { data: [] })
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.deleteTeam.mutateAsync(TEAM)

    // Team-scoped rights must not survive the team.
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.authorization.all() })
    expect(harness!.queryClient.getQueryData(queryKeys.teamPeople(TEAM).list({}))).toBeUndefined()
  })

  it('reports the failure reason', async () => {
    destroy.mockRejectedValue(new Error('has child teams'))
    const mutations = setup()

    await mutations.deleteTeam.mutateAsync(TEAM).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to delete team: has child teams')
  })
})

describe('invalidateTeam and useDeleteTeamAvatarMutation', () => {
  it('invalidates the lists, the detail and the hierarchy', () => {
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    mutations.invalidateTeam(TEAM)

    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.detail(TEAM) })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.hierarchy() })
  })

  it('deleting the avatar shows no success toast, only refreshes the caches', async () => {
    deleteAvatar.mockResolvedValue({ data: { id: TEAM } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.deleteAvatar.mutateAsync(TEAM)

    expect(deleteAvatar).toHaveBeenCalledWith(TEAM)
    expect(invalidate).toHaveBeenCalledTimes(3)
    expect(success).not.toHaveBeenCalled()
  })

  it('reuses the update-team error copy when avatar deletion fails', async () => {
    deleteAvatar.mockRejectedValue(new Error('no avatar'))
    const mutations = setup()

    await mutations.deleteAvatar.mutateAsync(TEAM).catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update team: no avatar')
  })

  it('sweeps the roles and SAML caches as collateral of the detail invalidation', async () => {
    deleteAvatar.mockResolvedValue({ data: {} })
    const mutations = setup()
    harness!.queryClient.setQueryData(queryKeys.teams.samlProvider(TEAM), { configured: true })

    await mutations.deleteAvatar.mutateAsync(TEAM)

    const entry = harness!.queryClient
      .getQueryCache()
      .find({ queryKey: queryKeys.teams.samlProvider(TEAM) })

    expect(entry?.state.isInvalidated).toBe(true)
  })
})

describe('team member mutations', () => {
  it('sends the role change and names the resulting role', async () => {
    updateUser.mockResolvedValue({ data: { id: 'u1', role: 'owner' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.updateUser.mutateAsync({
      teamId: TEAM,
      userId: 'u1',
      payload: { role: 'owner' } as UpdateTeamUserPayload,
    })

    expect(updateUser).toHaveBeenCalledWith(TEAM, 'u1', { role: 'owner' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teamPeople(TEAM).lists() })
    expect(success).toHaveBeenCalledWith('User role updated to owner')
  })

  it('reads the role from the response, not the payload', async () => {
    updateUser.mockResolvedValue({ data: { role: 'admin' } })
    const mutations = setup()

    await mutations.updateUser.mutateAsync({
      teamId: TEAM,
      userId: 'u1',
      payload: { role: 'owner' } as UpdateTeamUserPayload,
    })

    expect(success).toHaveBeenCalledWith('User role updated to admin')
  })

  it('a role change refreshes the authorization context — you can demote yourself', async () => {
    updateUser.mockResolvedValue({ data: { role: 'owner' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.updateUser.mutateAsync({
      teamId: TEAM,
      userId: 'u1',
      payload: {} as UpdateTeamUserPayload,
    })

    expect(invalidate).toHaveBeenCalledTimes(2)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.authorization.all() })
  })

  it('surfaces a rejected role change, e.g. the last-owner rule', async () => {
    updateUser.mockRejectedValue(new Error('cannot demote the last owner'))
    const mutations = setup()

    await mutations.updateUser
      .mutateAsync({ teamId: TEAM, userId: 'u1', payload: {} as UpdateTeamUserPayload })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith('Failed to update user role: cannot demote the last owner')
    expect(success).not.toHaveBeenCalled()
  })

  it('removing a member refreshes the people list and the team detail', async () => {
    removeUser.mockResolvedValue(undefined)
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.removeUser.mutateAsync({ teamId: TEAM, userId: 'u1' })

    expect(removeUser).toHaveBeenCalledWith(TEAM, 'u1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teamPeople(TEAM).lists() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.detail(TEAM) })
    expect(success).toHaveBeenCalledWith('User removed from team')
  })

  it('removing a member refreshes the authorization context but not the hierarchy', async () => {
    removeUser.mockResolvedValue(undefined)
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.removeUser.mutateAsync({ teamId: TEAM, userId: 'u1' })

    // Removing yourself must drop your cached team-scoped permissions.
    expect(invalidate).toHaveBeenCalledTimes(3)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.authorization.all() })
    expect(invalidate).not.toHaveBeenCalledWith({ queryKey: queryKeys.teams.hierarchy() })
  })

  it('surfaces a rejected removal', async () => {
    removeUser.mockRejectedValue(new Error('last owner'))
    const mutations = setup()

    await expect(
      mutations.removeUser.mutateAsync({ teamId: TEAM, userId: 'u1' })
    ).rejects.toThrow('last owner')
    expect(error).toHaveBeenCalledWith('Failed to remove user from team: last owner')
  })
})

describe('team space role mutations', () => {
  it('creating a role invalidates every role key and the authorization context', async () => {
    createSpaceRole.mockResolvedValue({ data: { id: 'r1', name: 'Editors' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.createRole.mutateAsync({
      teamId: TEAM,
      payload: { name: 'Editors' } as CreateTeamSpaceRolePayload,
    })

    expect(createSpaceRole).toHaveBeenCalledWith(TEAM, { name: 'Editors' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.roles(TEAM).all() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.authorization.all() })
    expect(success).toHaveBeenCalledWith('Role "Editors" created successfully')
  })

  it('roles.all() prefixes roles.space(), so the space list is really refreshed', async () => {
    createSpaceRole.mockResolvedValue({ data: { name: 'Editors' } })
    const mutations = setup()
    harness!.queryClient.setQueryData(queryKeys.teams.roles(TEAM).space(), [])

    await mutations.createRole.mutateAsync({
      teamId: TEAM,
      payload: {} as CreateTeamSpaceRolePayload,
    })

    const entry = harness!.queryClient
      .getQueryCache()
      .find({ queryKey: queryKeys.teams.roles(TEAM).space() })

    expect(entry?.state.isInvalidated).toBe(true)
  })

  it('updating a role names it and refreshes the authorization context', async () => {
    updateSpaceRole.mockResolvedValue({ data: { id: 'r1', name: 'Reviewers' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.updateRole.mutateAsync({
      teamId: TEAM,
      roleId: 'r1',
      payload: { name: 'Reviewers' } as UpdateTeamSpaceRolePayload,
    })

    expect(updateSpaceRole).toHaveBeenCalledWith(TEAM, 'r1', { name: 'Reviewers' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.authorization.all() })
    expect(success).toHaveBeenCalledWith('Role "Reviewers" updated successfully')
  })

  it('deleting a role refreshes the roles and the authorization context', async () => {
    deleteSpaceRole.mockResolvedValue(undefined)
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.deleteRole.mutateAsync({ teamId: TEAM, roleId: 'r1' })

    expect(deleteSpaceRole).toHaveBeenCalledWith(TEAM, 'r1')
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.roles(TEAM).all() })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.authorization.all() })
    expect(success).toHaveBeenCalledWith('Role deleted successfully')
  })

  it.each([
    ['createRole', 'Failed to create role: denied'],
    ['updateRole', 'Failed to update role: denied'],
    ['deleteRole', 'Failed to delete role: denied'],
  ])('%s reports the failure reason', async (which, copy) => {
    const target = { createRole: createSpaceRole, updateRole: updateSpaceRole, deleteRole: deleteSpaceRole }[
      which
    ]!
    target.mockRejectedValue(new Error('denied'))
    const mutations = setup()

    await (mutations[which as 'createRole' | 'updateRole' | 'deleteRole'] as {
      mutateAsync: (v: unknown) => Promise<unknown>
    })
      .mutateAsync({ teamId: TEAM, roleId: 'r1', payload: {} })
      .catch(() => {})

    expect(error).toHaveBeenCalledWith(copy)
  })
})

describe('SAML provider mutations', () => {
  it('saving refreshes the provider and the authorization context', async () => {
    upsertSamlProvider.mockResolvedValue({ data: { entity_id: 'x' } })
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.upsertSaml.mutateAsync({
      teamId: TEAM,
      payload: { idp_entity_id: 'x' } as unknown as TeamSamlProviderPayload,
    })

    expect(upsertSamlProvider).toHaveBeenCalledWith(TEAM, { idp_entity_id: 'x' })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.samlProvider(TEAM) })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.authorization.all() })
    expect(success).toHaveBeenCalledWith('SAML provider saved successfully')
  })

  it('deleting refreshes the provider and the authorization context, like saving does', async () => {
    deleteSamlProvider.mockResolvedValue(undefined)
    const mutations = setup()
    const invalidate = vi.spyOn(harness!.queryClient, 'invalidateQueries')

    await mutations.deleteSaml.mutateAsync(TEAM)

    expect(deleteSamlProvider).toHaveBeenCalledWith(TEAM)
    expect(invalidate).toHaveBeenCalledTimes(2)
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.teams.samlProvider(TEAM) })
    expect(invalidate).toHaveBeenCalledWith({ queryKey: queryKeys.authorization.all() })
    expect(success).toHaveBeenCalledWith('SAML provider deleted successfully')
  })

  it.each([
    ['upsertSaml', 'Failed to save SAML provider: bad metadata'],
    ['deleteSaml', 'Failed to delete SAML provider: bad metadata'],
  ])('%s reports the failure reason', async (which, copy) => {
    const target = which === 'upsertSaml' ? upsertSamlProvider : deleteSamlProvider
    target.mockRejectedValue(new Error('bad metadata'))
    const mutations = setup()

    await (mutations[which as 'upsertSaml' | 'deleteSaml'] as {
      mutateAsync: (v: unknown) => Promise<unknown>
    })
      .mutateAsync(which === 'upsertSaml' ? { teamId: TEAM, payload: {} } : TEAM)
      .catch(() => {})

    expect(error).toHaveBeenCalledWith(copy)
  })
})

describe('findTeamInHierarchy', () => {
  const tree = [node('a', [node('a1', [node('a1x')])]), node('b')]

  it('finds a root team', () => {
    expect(utils().findTeamInHierarchy(tree, 'b').value?.id).toBe('b')
  })

  it('finds a deeply nested team', () => {
    expect(utils().findTeamInHierarchy(tree, 'a1x').value?.id).toBe('a1x')
  })

  it('returns undefined for an unknown id', () => {
    expect(utils().findTeamInHierarchy(tree, 'ghost').value).toBeUndefined()
  })

  it.each([
    ['an undefined hierarchy', undefined, 'a'],
    ['an empty team id', [node('a')], ''],
  ])('returns undefined for %s', (_label, hierarchy, teamId) => {
    expect(utils().findTeamInHierarchy(hierarchy, teamId).value).toBeUndefined()
  })

  it('tracks a changing team id ref', () => {
    const id = ref('a')
    const found = utils().findTeamInHierarchy(tree, id)

    expect(found.value?.id).toBe('a')
    id.value = 'a1x'
    expect(found.value?.id).toBe('a1x')
  })

  it('tolerates a node without a children array', () => {
    const leaf = { id: 'x', name: 'x' } as unknown as TeamHierarchyItem

    expect(utils().findTeamInHierarchy([leaf], 'x').value?.id).toBe('x')
  })
})

describe('getTeamAncestors', () => {
  const tree = [node('a', [node('a1', [node('a1x')])]), node('b')]

  it('returns the path from the root, excluding the team itself', () => {
    expect(utils().getTeamAncestors(tree, 'a1x').value?.map((item) => item.id)).toEqual(['a', 'a1'])
  })

  it('returns an empty array for a root team', () => {
    expect(utils().getTeamAncestors(tree, 'a').value).toEqual([])
  })

  it('returns null for an unknown team, so a breadcrumb can tell it from a root', () => {
    expect(utils().getTeamAncestors(tree, 'ghost').value).toBeNull()
  })

  it('does not confuse a sibling subtree for the path', () => {
    const forest = [node('a', [node('a1')]), node('b', [node('b1')])]

    expect(utils().getTeamAncestors(forest, 'b1').value?.map((item) => item.id)).toEqual(['b'])
  })

  it('returns null without a hierarchy', () => {
    expect(utils().getTeamAncestors(undefined, 'a').value).toBeNull()
  })

  it('accepts a ref hierarchy', () => {
    expect(utils().getTeamAncestors(ref(tree), 'a1').value?.map((item) => item.id)).toEqual(['a'])
  })
})

describe('getTeamDescendants', () => {
  const tree = [node('a', [node('a1', [node('a1x')]), node('a2')]), node('b')]

  it('flattens the whole subtree, depth first', () => {
    expect(utils().getTeamDescendants(tree, 'a').value.map((item) => item.id)).toEqual([
      'a1',
      'a1x',
      'a2',
    ])
  })

  it('returns an empty array for a leaf', () => {
    expect(utils().getTeamDescendants(tree, 'b').value).toEqual([])
  })

  it('returns an empty array for an unknown team', () => {
    expect(utils().getTeamDescendants(tree, 'ghost').value).toEqual([])
  })

  it('works from a nested starting point', () => {
    expect(utils().getTeamDescendants(tree, 'a1').value.map((item) => item.id)).toEqual(['a1x'])
  })

  it('returns an empty array without a hierarchy', () => {
    expect(utils().getTeamDescendants(undefined, 'a').value).toEqual([])
  })
})

describe('query keys', () => {
  it('lists() prefixes list(filters), so mutations really refresh cached pages', () => {
    const page = queryKeys.teams.list({ page: 2 })

    expect(page.slice(0, queryKeys.teams.lists().length)).toEqual([...queryKeys.teams.lists()])
  })

  it('the hierarchy key is not covered by lists(), so both must be invalidated', () => {
    const lists = queryKeys.teams.lists()

    expect(queryKeys.teams.hierarchy().slice(0, lists.length)).not.toEqual([...lists])
  })

  it('teamPeople is not covered by teams.detail, so a detail invalidation misses it', () => {
    const detail = queryKeys.teams.detail(TEAM)
    const people = queryKeys.teamPeople(TEAM).lists()

    expect(people.slice(0, detail.length)).not.toEqual([...detail])
  })
})
