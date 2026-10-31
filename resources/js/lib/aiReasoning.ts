import type { MaybeRefOrGetter, Ref } from 'vue'

import { type AiReasoningEffort, DEFAULT_REASONING_EFFORT } from '~/api/resources/ai'
import { useAiConfigs } from '~/composables/useAiModels'

/**
 * The reasoning level a prompt box sends with its config. It follows the
 * config's default (or `fallback`, e.g. the thread's last level) whenever the
 * config changes, and models that always reason never get `off`. `effort` is
 * what to send: null for models that don't reason, so the server leaves the
 * request alone.
 */
export function useAiReasoning(
  spaceId: MaybeRefOrGetter<string>,
  configId: Ref<string | null>,
  fallback: MaybeRefOrGetter<AiReasoningEffort | null> = null
) {
  const { useAiConfigsQuery } = useAiConfigs(spaceId)
  const { data: configs } = useAiConfigsQuery()

  const config = computed(() => configs.value?.find((item) => item.id === configId.value) ?? null)
  const modelInfo = computed(() => config.value?.model_info ?? null)
  const level = ref<AiReasoningEffort>(DEFAULT_REASONING_EFFORT)

  watch(
    [config, () => toValue(fallback)],
    ([current, preferred]) => {
      const next = preferred ?? current?.reasoning_effort ?? DEFAULT_REASONING_EFFORT
      level.value = next === 'off' && current?.model_info?.reasoning_required ? 'low' : next
    },
    { immediate: true }
  )

  const effort = computed(() => (modelInfo.value?.supports_reasoning ? level.value : null))

  return { modelInfo, level, effort }
}
