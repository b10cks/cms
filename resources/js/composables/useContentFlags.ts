import { useQuery, useQueryClient } from '@tanstack/vue-query'

import { api } from '~/api'
import { flagTotals, type AiContentFlagTotals } from '~/lib/aiContentFlags'

/**
 * Open conflicts and flagged wording per entry, one cheap request for the whole
 * space. The tree reads the figures; a publish path asks `freshTotals` first,
 * since flags may have changed since the figures loaded.
 */
export function useContentFlags(spaceId: MaybeRefOrGetter<string>) {
  const queryClient = useQueryClient()
  const key = computed(() => queryKeys.ai(toValue(spaceId)).contentFlags())
  const fetchFlags = async () => (await api.forSpace(toValue(spaceId)).ai.contentFlags()).data

  const { data: flags } = useQuery({
    queryKey: key,
    queryFn: fetchFlags,
    enabled: computed(() => !!toValue(spaceId)),
    staleTime: 30_000,
  })

  const totals = (ids: readonly string[]): AiContentFlagTotals => flagTotals(flags.value, ids)

  /** Flags never block a publish, so a failed check answers with what is known and the publish goes on. */
  const freshTotals = async (ids: readonly string[]): Promise<AiContentFlagTotals> => {
    try {
      return flagTotals(await queryClient.fetchQuery({ queryKey: key.value, queryFn: fetchFlags, staleTime: 0 }), ids)
    } catch {
      return totals(ids)
    }
  }

  return { flags, totals, freshTotals }
}
