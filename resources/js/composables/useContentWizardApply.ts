import { useQueryClient } from '@tanstack/vue-query'

import type { ContentWizardApplyResult, ContentWizardDraftNode } from '~/types/content-wizard'

import { useContentWizardSlug } from './useContentWizardSlug'
import { useContentWizardTree } from './useContentWizardTree'
import { queryKeys } from './useQueryClient'

export function useContentWizardApply(
  spaceId: MaybeRef<string>,
  treeApi: Pick<
    ReturnType<typeof useContentWizardTree>,
    'tree' | 'operationPlan' | 'validations' | 'getNode'
  >
) {
  const queryClient = useQueryClient()
  const { resolveEffectiveSlug } = useContentWizardSlug()
  const {
    useBulkCreateContentMutation,
    useDeleteContentMutation,
    useMoveContentMutation,
    useUpdateContentMutation,
  } = useContent(spaceId)

  const bulkCreateMutation = useBulkCreateContentMutation()
  const updateMutation = useUpdateContentMutation()
  const moveMutation = useMoveContentMutation()
  const deleteMutation = useDeleteContentMutation()

  const isApplying = computed(
    () =>
      bulkCreateMutation.isPending.value ||
      updateMutation.isPending.value ||
      moveMutation.isPending.value ||
      deleteMutation.isPending.value
  )

  const applyError = ref<string | null>(null)

  const invalidateContentQueries = async () => {
    await Promise.all([
      queryClient.invalidateQueries({
        queryKey: queryKeys.contentMenu(spaceId).all(),
      }),
      queryClient.invalidateQueries({
        queryKey: queryKeys.contents(spaceId).lists(),
      }),
      queryClient.invalidateQueries({
        queryKey: queryKeys.blocks(spaceId).lists(),
      }),
    ])
  }

  const apply = async (): Promise<ContentWizardApplyResult> => {
    const operations = treeApi.operationPlan.value

    if (treeApi.validations.value.length > 0) {
      const message = treeApi.validations.value[0]?.message || 'Validation failed.'
      applyError.value = message

      return {
        success: false,
        operations,
        error: message,
      }
    }

    applyError.value = null
    const createdIdMap = new Map<string, string>()

    const resolveParentId = (parentId: string | null) => {
      if (!parentId) {
        return null
      }

      return createdIdMap.get(parentId) || parentId
    }

    const executeUpdate = async (nodeId: string) => {
      const node = treeApi.getNode(nodeId)
      if (!node?.backendId) {
        return
      }

      await updateMutation.mutateAsync({
        id: node.backendId,
        payload: {
          name: node.title,
          slug: resolveEffectiveSlug(node.title, node.slug),
          block_id: node.blockId,
        },
      })
    }

    const executeMove = async (nodeId: string) => {
      const node = treeApi.getNode(nodeId)
      if (!node?.backendId) {
        return
      }

      await moveMutation.mutateAsync({
        id: node.backendId,
        payload: {
          parent_id: resolveParentId(node.parentId),
          position: node.position,
        },
      })
    }

    try {
      const createOperations = operations
        .filter(
          (operation): operation is Extract<(typeof operations)[number], { type: 'create' }> =>
            operation.type === 'create'
        )
        .sort((left, right) => left.depth - right.depth)

      if (createOperations.length > 0) {
        const items = createOperations.flatMap((operation) => {
          const node = treeApi.getNode(operation.nodeId)
          if (!node) {
            return []
          }

          return [
            {
              temp_id: node.id,
              name: node.title,
              slug: resolveEffectiveSlug(node.title, node.slug),
              block_id: node.blockId,
              parent_id: resolveParentId(node.parentId),
            },
          ]
        })

        const createdItems = await bulkCreateMutation.mutateAsync({ items })
        createdItems.forEach((item) => {
          if (item.temp_id) {
            createdIdMap.set(item.temp_id, item.id)
          }
        })
      }

      const activeExistingNodes = Object.values(treeApi.tree.value.nodes)
        .filter(
          (node): node is ContentWizardDraftNode =>
            !node.isRootVirtual && !!node.backendId && !node.deletedReason
        )
        .sort((left, right) => left.depth - right.depth)

      for (const node of activeExistingNodes) {
        const effectiveSlug = resolveEffectiveSlug(node.title, node.slug)
        const originalSlug = node.original
          ? resolveEffectiveSlug(node.original.title, node.original.slug)
          : ''
        const shouldUpdate =
          node.title !== node.original?.title ||
          effectiveSlug !== originalSlug ||
          node.blockId !== node.original?.blockId
        const shouldMove = node.parentId !== node.original?.parentId

        if (!shouldUpdate && !shouldMove) {
          continue
        }

        if (node.original?.blockType === 'single' && node.blockType !== 'single') {
          if (shouldUpdate) {
            await executeUpdate(node.id)
          }
          if (shouldMove) {
            await executeMove(node.id)
          }
          continue
        }

        if (node.original?.blockType !== 'single' && node.blockType === 'single') {
          if (shouldMove) {
            await executeMove(node.id)
          }
          if (shouldUpdate) {
            await executeUpdate(node.id)
          }
          continue
        }

        if (shouldUpdate) {
          await executeUpdate(node.id)
        }

        if (shouldMove) {
          await executeMove(node.id)
        }
      }

      const deleteOperations = operations
        .filter(
          (operation): operation is Extract<(typeof operations)[number], { type: 'delete' }> =>
            operation.type === 'delete'
        )
        .sort((left, right) => right.depth - left.depth)

      for (const operation of deleteOperations) {
        const node = treeApi.getNode(operation.nodeId)
        if (!node?.backendId) {
          continue
        }

        await deleteMutation.mutateAsync(node.backendId)
      }

      return {
        success: true,
        operations,
      }
    } catch (error) {
      await invalidateContentQueries()
      const message = error instanceof Error ? error.message : 'Apply failed.'
      applyError.value = message

      return {
        success: false,
        operations,
        error: message,
      }
    }
  }

  return {
    isApplying,
    applyError,
    apply,
    invalidateContentQueries,
  }
}
