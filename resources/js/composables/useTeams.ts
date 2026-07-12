import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { TeamsQueryParams } from '~/api/resources/teams'
import type { CreateTeamSpaceRolePayload, UpdateTeamSpaceRolePayload } from '~/types/authorization'
import type {
  AddTeamUserPayload,
  CreateTeamPayload,
  TeamSamlProviderPayload,
  TeamHierarchyItem,
  TeamUserQueryParams,
  UpdateTeamPayload,
  UpdateTeamUserPayload,
} from '~/types/teams'

import { queryKeys } from './useQueryClient'

export function useTeams() {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const { isAuthenticated } = useAuth()

  // Teams Queries
  const useTeamsQuery = (params: MaybeRef<TeamsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.teams.list(params)),
      queryFn: async () => {
        return await api.teams.index({
          sort: '+name',
          ...toValue(params),
        })
      },
      enabled: computed(() => !!toValue(isAuthenticated)),
      placeholderData: keepPreviousData,
    })
  }

  const useTeamQuery = (id: MaybeRef<string>) => {
    return useQuery({
      queryKey: computed(() => queryKeys.teams.detail(id)),
      queryFn: async () => {
        const response = await api.teams.get(toValue(id))
        return response.data
      },
      enabled: computed(() => !!toValue(isAuthenticated)),
    })
  }

  const useTeamHierarchyQuery = () => {
    return useQuery({
      queryKey: queryKeys.teams.hierarchy(),
      queryFn: async () => {
        const response = await api.teams.getHierarchy()
        return response.data
      },
      enabled: computed(() => !!toValue(isAuthenticated)),
    })
  }

  // Team Users Queries
  const useTeamUsersQuery = (
    teamId: MaybeRef<string>,
    params: MaybeRef<TeamUserQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.teams.users(teamId).list(params)),
      queryFn: async () => {
        const response = await api.teams.getUsers(toValue(teamId), {
          sort: '+user.firstname',
          ...toValue(params),
        })
        return response
      },
      enabled: computed(
        () => !!toValue(isAuthenticated) && !!toValue(teamId) && !!toValue(enabled)
      ),
      placeholderData: keepPreviousData,
    })
  }

  const useTeamSpaceRolesQuery = (teamId: MaybeRef<string>, enabled: MaybeRef<boolean> = true) => {
    return useQuery({
      queryKey: computed(() => queryKeys.teams.roles(teamId).space()),
      queryFn: async () => {
        const response = await api.teams.getSpaceRoles(toValue(teamId))
        return response.data
      },
      enabled: computed(
        () => !!toValue(isAuthenticated) && !!toValue(teamId) && !!toValue(enabled)
      ),
    })
  }

  const useTeamSamlProviderQuery = (
    teamId: MaybeRef<string>,
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.teams.samlProvider(teamId)),
      queryFn: async () => {
        return await api.teams.getSamlProvider(toValue(teamId))
      },
      enabled: computed(
        () => !!toValue(isAuthenticated) && !!toValue(teamId) && !!toValue(enabled)
      ),
    })
  }

  // Team Mutations
  const useCreateTeamMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateTeamPayload) => {
        const response = await api.teams.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.hierarchy() })
        toast.success(t('composables.teams.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.teams.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateTeamMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateTeamPayload }) => {
        const response = await api.teams.update(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.detail(data.id) })
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.hierarchy() })
        toast.success(t('composables.teams.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.teams.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteTeamMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await api.teams.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.lists() })
        queryClient.removeQueries({ queryKey: queryKeys.teams.detail(id) })
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.hierarchy() })
        // Also invalidate team users queries
        queryClient.removeQueries({ queryKey: queryKeys.teams.users(id).all() })
        toast.success(t('composables.teams.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.teams.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  // Team User Mutations
  const useAddTeamUserMutation = () => {
    return useMutation({
      mutationFn: async ({ teamId, payload }: { teamId: string; payload: AddTeamUserPayload }) => {
        const response = await api.teams.addUser(teamId, payload)
        return { teamId, user: response.data }
      },
      onSuccess: ({ teamId, user }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.users(teamId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.detail(teamId) })
        toast.success(
          t('composables.teams.addUserSuccess', {
            firstname: user.user.firstname,
            lastname: user.user.lastname,
          }) as string
        )
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.teams.addUserError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateTeamUserMutation = () => {
    return useMutation({
      mutationFn: async ({
        teamId,
        userId,
        payload,
      }: {
        teamId: string
        userId: string
        payload: UpdateTeamUserPayload
      }) => {
        const response = await api.teams.updateUser(teamId, userId, payload)
        return { teamId, userId, user: response.data }
      },
      onSuccess: ({ teamId, user }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.users(teamId).lists() })
        toast.success(t('composables.teams.updateUserSuccess', { role: user.role }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.teams.updateUserError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useRemoveTeamUserMutation = () => {
    return useMutation({
      mutationFn: async ({ teamId, userId }: { teamId: string; userId: string }) => {
        await api.teams.removeUser(teamId, userId)
        return { teamId, userId }
      },
      onSuccess: ({ teamId }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.users(teamId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.detail(teamId) })
        toast.success(t('composables.teams.removeUserSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.teams.removeUserError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useCreateTeamSpaceRoleMutation = () => {
    return useMutation({
      mutationFn: async ({
        teamId,
        payload,
      }: {
        teamId: string
        payload: CreateTeamSpaceRolePayload
      }) => {
        const response = await api.teams.createSpaceRole(teamId, payload)
        return { teamId, role: response.data }
      },
      onSuccess: ({ teamId, role }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.roles(teamId).all() })
        queryClient.invalidateQueries({ queryKey: queryKeys.authorization.all() })
        toast.success(t('composables.teams.createRoleSuccess', { name: role.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.teams.createRoleError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateTeamSpaceRoleMutation = () => {
    return useMutation({
      mutationFn: async ({
        teamId,
        roleId,
        payload,
      }: {
        teamId: string
        roleId: string
        payload: UpdateTeamSpaceRolePayload
      }) => {
        const response = await api.teams.updateSpaceRole(teamId, roleId, payload)
        return { teamId, role: response.data }
      },
      onSuccess: ({ teamId, role }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.roles(teamId).all() })
        queryClient.invalidateQueries({ queryKey: queryKeys.authorization.all() })
        toast.success(t('composables.teams.updateRoleSuccess', { name: role.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.teams.updateRoleError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteTeamSpaceRoleMutation = () => {
    return useMutation({
      mutationFn: async ({ teamId, roleId }: { teamId: string; roleId: string }) => {
        await api.teams.deleteSpaceRole(teamId, roleId)
        return { teamId, roleId }
      },
      onSuccess: ({ teamId }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.roles(teamId).all() })
        queryClient.invalidateQueries({ queryKey: queryKeys.authorization.all() })
        toast.success(t('composables.teams.deleteRoleSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.teams.deleteRoleError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpsertTeamSamlProviderMutation = () => {
    return useMutation({
      mutationFn: async ({
        teamId,
        payload,
      }: {
        teamId: string
        payload: TeamSamlProviderPayload
      }) => {
        const response = await api.teams.upsertSamlProvider(teamId, payload)
        return { teamId, provider: response.data }
      },
      onSuccess: ({ teamId }) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.samlProvider(teamId) })
        queryClient.invalidateQueries({ queryKey: queryKeys.authorization.all() })
        toast.success(t('composables.teams.samlSaveSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.teams.samlSaveError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteTeamSamlProviderMutation = () => {
    return useMutation({
      mutationFn: async (teamId: string) => {
        await api.teams.deleteSamlProvider(teamId)
        return teamId
      },
      onSuccess: (teamId) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.teams.samlProvider(teamId) })
        toast.success(t('composables.teams.samlDeleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.teams.samlDeleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  // Utility functions
  const findTeamInHierarchy = (
    hierarchy: MaybeRef<TeamHierarchyItem[] | undefined>,
    teamId: MaybeRef<string>
  ): ComputedRef<TeamHierarchyItem | undefined> => {
    return computed(() => {
      const hierarchyValue = toValue(hierarchy)
      const teamIdValue = toValue(teamId)
      if (!hierarchyValue || !teamIdValue) return undefined

      const findInTree = (items: TeamHierarchyItem[]): TeamHierarchyItem | undefined => {
        for (const item of items) {
          if (item.id === teamIdValue) return item
          if (item.children?.length) {
            const found = findInTree(item.children)
            if (found) return found
          }
        }
        return undefined
      }

      return findInTree(toValue(hierarchyValue))
    })
  }

  const getTeamAncestors = (
    hierarchy: MaybeRef<TeamHierarchyItem[] | undefined>,
    teamId: MaybeRef<string>
  ): ComputedRef<TeamHierarchyItem[]> => {
    return computed(() => {
      const hierarchyValue = toValue(hierarchy)
      const teamIdValue = toValue(teamId)
      if (!hierarchyValue || !teamIdValue) return []

      const findAncestors = (
        items: TeamHierarchyItem[],
        targetId: string,
        ancestors: TeamHierarchyItem[] = []
      ): TeamHierarchyItem[] => {
        for (const item of items) {
          const currentPath = [...ancestors, item]

          if (item.id === targetId) {
            return currentPath.slice(0, -1) // Exclude the target team itself
          }

          if (item.children?.length) {
            const found = findAncestors(item.children, targetId, currentPath)
            if (found.length > 0) return found
          }
        }
        return []
      }

      return findAncestors(hierarchyValue, teamIdValue)
    })
  }

  const getTeamDescendants = (
    hierarchy: MaybeRef<TeamHierarchyItem[] | undefined>,
    teamId: MaybeRef<string>
  ): ComputedRef<TeamHierarchyItem[]> => {
    return computed(() => {
      const hierarchyValue = toValue(hierarchy)
      const teamIdValue = toValue(teamId)
      if (!hierarchyValue || !teamIdValue) return []

      const team = findTeamInHierarchy(hierarchy, teamId).value
      if (!team) return []

      const getAllDescendants = (item: TeamHierarchyItem): TeamHierarchyItem[] => {
        const descendants: TeamHierarchyItem[] = []

        if (item.children?.length) {
          for (const child of item.children) {
            descendants.push(child)
            descendants.push(...getAllDescendants(child))
          }
        }

        return descendants
      }

      return getAllDescendants(team)
    })
  }

  return {
    // Team Queries
    useTeamsQuery,
    useTeamQuery,
    useTeamHierarchyQuery,

    // Team User Queries
    useTeamUsersQuery,
    useTeamSpaceRolesQuery,
    useTeamSamlProviderQuery,

    // Team Mutations
    useCreateTeamMutation,
    useUpdateTeamMutation,
    useDeleteTeamMutation,

    // Team User Mutations
    useAddTeamUserMutation,
    useUpdateTeamUserMutation,
    useRemoveTeamUserMutation,
    useCreateTeamSpaceRoleMutation,
    useUpdateTeamSpaceRoleMutation,
    useDeleteTeamSpaceRoleMutation,
    useUpsertTeamSamlProviderMutation,
    useDeleteTeamSamlProviderMutation,

    // Utility Functions
    findTeamInHierarchy,
    getTeamAncestors,
    getTeamDescendants,
  }
}
