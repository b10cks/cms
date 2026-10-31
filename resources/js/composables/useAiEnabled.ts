/**
 * Whether the space has its AI features switched on (Settings → AI → Enable AI
 * Features). Only an explicit `false` turns them off; until the space has
 * loaded this is false, so AI buttons appear rather than flash and vanish.
 */
export function useAiEnabled(spaceId: MaybeRefOrGetter<string | null | undefined>) {
  const { useSpaceQuery } = useSpaces()
  const { data: space } = useSpaceQuery(spaceId)

  return computed(() => !!space.value && space.value.settings.ai?.enabled !== false)
}
