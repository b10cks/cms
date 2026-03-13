import { useQuery } from '@tanstack/vue-query'

import type { AuthorizationQueryParams } from '~/types/authorization'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useAuthorization() {
  const { isAuthenticated } = useAuth()

  const useAuthorizationQuery = (params: MaybeRef<AuthorizationQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.authorization.context(toValue(params))),
      queryFn: async () => {
        const response = await api.authorization.get(toValue(params))
        return response.data
      },
      enabled: computed(() => !!toValue(isAuthenticated)),
    })
  }

  const useAbility = (ability: MaybeRef<string>, params: MaybeRef<AuthorizationQueryParams> = {}) => {
    const query = useAuthorizationQuery(params)

    return computed(() => {
      if (query.data.value?.is_root) {
        return true
      }

      const abilities = [
        ...(query.data.value?.team?.abilities || []),
        ...(query.data.value?.space?.abilities || []),
      ]

      return abilities.includes(toValue(ability))
    })
  }

  return {
    useAuthorizationQuery,
    useAbility,
  }
}
