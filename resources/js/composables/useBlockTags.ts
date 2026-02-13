import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import type { BlockTagsQueryParams, UpsertBlockTagPayload } from '~/api/resources/block-tags'

import { api } from '~/api'

import { queryKeys } from './useQueryClient'

export function useBlockTags(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const useBlockTagsQuery = (params: MaybeRef<BlockTagsQueryParams> = {}) => {
    return useQuery({
      queryKey: queryKeys.blockTags(spaceId).list(params),
      queryFn: async () => {
        return await spaceAPI.value.blockTags.index({
          sort: '+name',
          ...toValue(params),
        })
      },
    })
  }

  const useBlockTagQuery = (tagNameRef: string) => {
    const tagName = computed(() => unref(tagNameRef))

    return useQuery({
      queryKey: queryKeys.blockTags(spaceId).detail(tagName.value),
      queryFn: async () => {
        const response = await spaceAPI.value.blockTags.get(tagName.value)
        return response.data
      },
      enabled: computed(() => !!tagName.value),
    })
  }

  const useCreateBlockTagMutation = () => {
    return useMutation({
      mutationFn: async (payload: UpsertBlockTagPayload) => {
        const response = await spaceAPI.value.blockTags.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.blockTags(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.blocks(spaceId).lists() })
        toast.success(t('composables.blockTags.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.blockTags.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateBlockTagMutation = () => {
    return useMutation({
      mutationFn: async ({
        tagName,
        payload,
      }: {
        tagName: string
        payload: UpsertBlockTagPayload
      }) => {
        const response = await spaceAPI.value.blockTags.update(tagName, payload)
        return response.data
      },
      onSuccess: (data, variables) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.blockTags(spaceId).lists() })
        queryClient.invalidateQueries({
          queryKey: queryKeys.blockTags(spaceId).detail(variables.tagName),
        })
        queryClient.invalidateQueries({ queryKey: queryKeys.blockTags(spaceId).detail(data.name) })
        queryClient.invalidateQueries({ queryKey: queryKeys.blocks(spaceId).lists() })
        toast.success(t('composables.blockTags.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.blockTags.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteBlockTagMutation = () => {
    return useMutation({
      mutationFn: async (tagName: string) => {
        await spaceAPI.value.blockTags.delete(tagName)
        return tagName
      },
      onSuccess: (tagName) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.blockTags(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.blockTags(spaceId).detail(tagName) })
        queryClient.invalidateQueries({ queryKey: queryKeys.blocks(spaceId).lists() })
        toast.success(t('composables.blockTags.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.blockTags.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    useBlockTagsQuery,
    useBlockTagQuery,

    useCreateBlockTagMutation,
    useUpdateBlockTagMutation,
    useDeleteBlockTagMutation,
  }
}
