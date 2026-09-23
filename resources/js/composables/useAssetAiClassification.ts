import { useMutation } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { ClassifyAssetsPayload, ClassifyAssetsResult } from '~/api/resources/ai'

/**
 * AI asset classification: queues background jobs that fill empty metadata
 * fields from what a vision model sees. Asset broadcasts update open grids;
 * polling the run gives editors a count of completed and failed work.
 */
export function useAssetAiClassification(spaceId: MaybeRefOrGetter<string>) {
  const { t } = useI18n()
  const { useSpaceQuery } = useSpaces()
  const { data: space } = useSpaceQuery(() => toValue(spaceId))

  /** False only when the space explicitly turned AI features off. */
  const isAvailable = computed(() => space.value?.settings.ai?.enabled !== false)

  const isImage = (asset: Pick<AssetResource, 'mime_type'>) => asset.mime_type.startsWith('image/')
  const timers = new Set<ReturnType<typeof setTimeout>>()

  onScopeDispose(() => {
    for (const timer of timers) clearTimeout(timer)
  })

  const watchRun = async (runId: string) => {
    try {
      const { data } = await api.forSpace(toValue(spaceId)).ai.getClassificationRun(runId)
      const message = t('messages.assets.classifyProgress', { ...data })

      if (data.complete) {
        if (data.failed > 0) toast.warning(message, { id: runId })
        else toast.success(message, { id: runId })
        return
      }

      toast.loading(message, { id: runId })
      const timer = setTimeout(() => {
        timers.delete(timer)
        void watchRun(runId)
      }, 2000)
      timers.add(timer)
    } catch {
      toast.error(t('messages.assets.classifyProgressUnavailable'), { id: runId })
    }
  }

  const useClassifyAssetsMutation = () =>
    useMutation({
      mutationFn: async (payload: ClassifyAssetsPayload): Promise<ClassifyAssetsResult> => {
        const response = await api.forSpace(toValue(spaceId)).ai.classifyAssets(payload)
        return response.data
      },
      onSuccess: ({ queued, run_id }) => {
        if (queued > 0) {
          toast.success(t('messages.assets.classifyQueued', { queued }, queued))
          void watchRun(run_id)
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
