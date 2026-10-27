import { useQuery } from '@tanstack/vue-query'

import { api } from '~/api'

/** Skills a surface offers in this space (space, team and built-in layers resolved). */
export function useAiSurfaceSkills(
  spaceId: MaybeRefOrGetter<string | null | undefined>,
  surface: MaybeRefOrGetter<string>,
  block: MaybeRefOrGetter<string | null> = null
) {
  return useQuery({
    queryKey: computed(() =>
      queryKeys.ai(toValue(spaceId) ?? '').surfaceSkills(toValue(surface), toValue(block))
    ),
    queryFn: async () => {
      const id = toValue(spaceId)
      if (!id) return []

      return (await api.forSpace(id).ai.getSurfaceSkills(toValue(surface), toValue(block))).data
    },
    enabled: computed(() => !!toValue(spaceId)),
    staleTime: 60_000,
  })
}
