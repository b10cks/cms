import { useMutation } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { ClassifyAssetsPayload, ClassifyAssetsResult } from '~/api/resources/ai'

/**
 * AI asset classification: queues background jobs that fill empty metadata
 * fields from what a vision model sees. Results arrive through the asset
 * broadcasts, so nothing here polls or refetches.
 */
export function useAssetAiClassification(spaceId: MaybeRefOrGetter<string>) {
  const { t } = useI18n()
  const { useSpaceQuery } = useSpaces()
  const { data: space } = useSpaceQuery(() => toValue(spaceId))

  /** False only when the space explicitly turned AI features off. */
  const isAvailable = computed(() => space.value?.settings.ai?.enabled !== false)

  const isImage = (asset: Pick<AssetResource, 'mime_type'>) => asset.mime_type.startsWith('image/')

  const useClassifyAssetsMutation = () =>
    useMutation({
      mutationFn: async (payload: ClassifyAssetsPayload): Promise<ClassifyAssetsResult> => {
        const response = await api.forSpace(toValue(spaceId)).ai.classifyAssets(payload)
        return response.data
      },
      onSuccess: ({ queued }) => {
        if (queued > 0) {
          toast.success(t('messages.assets.classifyQueued', { queued }, queued))
        } else {
          toast.info(t('messages.assets.classifyNothing'))
        }
      },
      onError: (error: Error & { data?: { message?: string } }) => {
        toast.error(error.data?.message || error.message || t('messages.assets.classifyFailed'))
      },
    })

  return {
    isAvailable,
    isImage,
    useClassifyAssetsMutation,
  }
}
