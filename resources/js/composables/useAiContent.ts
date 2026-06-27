import type { ContentInteractionPayload } from '~/api/resources/ai'
import type { SseCallbacks } from '~/lib/sse'

import { useAiStream } from './useAiStream'

export type { SseCallbacks as StreamCallbacks }

export function useAiContent(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const { stream, cancelStream, isStreaming } = useAiStream()

  const streamContentInteraction = async (
    payload: ContentInteractionPayload,
    callbacks: SseCallbacks
  ): Promise<void> => {
    const id = toValue(spaceId)
    if (!id) {
      callbacks.onError?.(t('composables.ai.errors.noSpace') as string)
      return
    }

    await stream(`/mgmt/v1/ai/content-interaction/stream?spaceId=${id}`, payload, callbacks)
  }

  return {
    streamContentInteraction,
    cancelStream,
    isStreaming,
  }
}
