import type { SseCallbacks } from '~/lib/sse'

import { useAiStream } from './useAiStream'

export interface MetaTagsPayload {
  context: Record<string, unknown>
  config_id?: string | null
  language?: string | null
}

export function useAiMetaTags(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const { stream, cancelStream, isStreaming } = useAiStream()

  const streamMetaTags = async (
    payload: MetaTagsPayload,
    callbacks: SseCallbacks
  ): Promise<void> => {
    const id = toValue(spaceId)
    if (!id) {
      callbacks.onError?.(t('composables.ai.errors.noSpace') as string)
      return
    }

    await stream(
      `/mgmt/v1/ai/meta-tags/stream?spaceId=${id}`,
      {
        context: payload.context,
        config_id: payload.config_id ?? null,
        language: payload.language ?? null,
      },
      callbacks
    )
  }

  return {
    streamMetaTags,
    cancelStream,
    isStreaming,
  }
}
