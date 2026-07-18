import { keepPreviousData, useQuery } from '@tanstack/vue-query'

import { api } from '~/api'
import type { PeopleQueryParams } from '~/types/people'

import { queryKeys } from './useQueryClient'

export function usePeople() {
  const { isAuthenticated } = useAuth()

  const useSpacePeopleQuery = (
    spaceId: MaybeRef<string>,
    params: MaybeRef<PeopleQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.spacePeople(spaceId).list(params)),
      queryFn: async () => api.forSpace(toValue(spaceId)).people.list(toValue(params)),
      enabled: computed(
        () => !!toValue(isAuthenticated) && !!toValue(spaceId) && !!toValue(enabled)
      ),
      placeholderData: keepPreviousData,
    })
  }

  const useTeamPeopleQuery = (
    teamId: MaybeRef<string>,
    params: MaybeRef<PeopleQueryParams> = {},
    enabled: MaybeRef<boolean> = true
  ) => {
    return useQuery({
      queryKey: computed(() => queryKeys.teamPeople(teamId).list(params)),
      queryFn: async () => api.teams.getPeople(toValue(teamId), toValue(params)),
      enabled: computed(
        () => !!toValue(isAuthenticated) && !!toValue(teamId) && !!toValue(enabled)
      ),
      placeholderData: keepPreviousData,
    })
  }

  return {
    useSpacePeopleQuery,
    useTeamPeopleQuery,
  }
}
