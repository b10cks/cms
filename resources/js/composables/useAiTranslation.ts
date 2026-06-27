import type { SseCallbacks } from '~/lib/sse'

import { useAiStream } from './useAiStream'

export interface TranslationPayload {
  source: string
  target: string
  fields: Record<string, string>
  config_id?: string | null
}

export function useAiTranslation(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const { stream, cancelStream, isStreaming } = useAiStream()

  const streamTranslation = async (
    payload: TranslationPayload,
    callbacks: SseCallbacks
  ): Promise<void> => {
    const id = toValue(spaceId)
    if (!id) {
      callbacks.onError?.(t('composables.ai.errors.noSpace') as string)
      return
    }

    await stream(
      `/mgmt/v1/ai/translate/stream?spaceId=${id}`,
      {
        source: payload.source,
        target: payload.target,
        fields: payload.fields,
        config_id: payload.config_id ?? null,
      },
      callbacks
    )
  }

  return {
    streamTranslation,
    cancelStream,
    isStreaming,
  }
}
