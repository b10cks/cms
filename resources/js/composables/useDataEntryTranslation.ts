import type { SseCallbacks } from '~/lib/sse'

import { useAiStream } from './useAiStream'

export function useDataEntryTranslation(spaceId: MaybeRef<string>, dataSourceId: MaybeRef<string>) {
  const { t } = useI18n()
  const { stream, cancelStream, isStreaming } = useAiStream()

  const streamMissingDimensionsTranslation = async (
    targetDimension: string,
    callbacks: SseCallbacks
  ): Promise<void> => {
    const currentSpaceId = toValue(spaceId)
    const currentDataSourceId = toValue(dataSourceId)

    if (!currentSpaceId || !currentDataSourceId) {
      callbacks.onError?.(t('composables.ai.errors.noSpaceOrDataSource') as string)
      return
    }

    await stream(
      `/mgmt/v1/spaces/${currentSpaceId}/data-sources/${currentDataSourceId}/entries/translate-missing-dimensions/stream`,
      { target_dimension: targetDimension },
      callbacks
    )
  }

  return {
    streamMissingDimensionsTranslation,
    cancelStream,
    isStreaming,
  }
}
