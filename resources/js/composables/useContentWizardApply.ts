import { useQueryClient } from '@tanstack/vue-query'

import type { ContentWizardApplyResult, ContentWizardDraftNode } from '~/types/content-wizard'
import { CONTENT_WIZARD_ROOT_ID } from '~/types/content-wizard'
import type { ContentTreeOperationPayload } from '~/types/contents'

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
  const { useTreeOperationsMutation, useUpdateContentMutation } = useContent(spaceId)

  const treeOperationsMutation = useTreeOperationsMutation()
  const updateMutation = useUpdateContentMutation()

  const isApplying = computed(
    () => treeOperationsMutation.isPending.value || updateMutation.isPending.value
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
    const getNodeReferenceId = (nodeId: string | null) => {
      if (!nodeId) {
        return null
      }

      const node = treeApi.getNode(nodeId)
      if (!node || node.isRootVirtual) {
        return null
      }

      return node.backendId || node.id
    }

    const getOrderedActiveChildIds = (parentId: string | null) => {
      const parentNode =
        parentId === null
          ? treeApi.getNode(CONTENT_WIZARD_ROOT_ID)
          : treeApi.getNode(parentId)

      if (!parentNode) {
        return []
      }

      return parentNode.childrenIds.filter((childId) => {
        const child = treeApi.getNode(childId)
        return !!child && !child.deletedReason
      })
    }

    const getAfterReferenceId = (node: ContentWizardDraftNode) => {
      const siblings = getOrderedActiveChildIds(node.parentId)
      const nodeIndex = siblings.indexOf(node.id)
      const previousSiblingId = nodeIndex > 0 ? siblings[nodeIndex - 1] : null

      return getNodeReferenceId(previousSiblingId)
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
        },
      })
    }

    try {
      const structuralOperations: ContentTreeOperationPayload[] = []
      const createOperations = operations
        .filter(
          (operation): operation is Extract<(typeof operations)[number], { type: 'create' }> =>
            operation.type === 'create'
        )
        .sort((left, right) => left.depth - right.depth)

      structuralOperations.push(
        ...createOperations.flatMap((operation) => {
          const node = treeApi.getNode(operation.nodeId)
          if (!node) {
            return []
          }

          return [
            {
              type: 'create' as const,
              temp_id: node.id,
              name: node.title,
              slug: resolveEffectiveSlug(node.title, node.slug),
              block_id: node.blockId,
              parent_id: getNodeReferenceId(node.parentId),
              settings: node.settings,
            },
          ]
        })
      )

      const activeExistingNodes = Object.values(treeApi.tree.value.nodes)
        .filter(
          (node): node is ContentWizardDraftNode =>
            !node.isRootVirtual && !!node.backendId && !node.deletedReason
        )
        .sort((left, right) => left.depth - right.depth)

      for (const node of activeExistingNodes) {
        if (!node.backendId) {
          continue
        }

        const backendId = node.backendId
        const shouldUpdateBlock = node.blockId !== node.original?.blockId
        const shouldMove = node.parentId !== node.original?.parentId

        if (!shouldUpdateBlock && !shouldMove) {
          continue
        }

        const moveOperation: ContentTreeOperationPayload | null = shouldMove
          ? {
              type: 'move',
              ids: [backendId],
              parent_id: getNodeReferenceId(node.parentId),
              after_id: getAfterReferenceId(node),
            }
          : null
        const updateBlockOperation: ContentTreeOperationPayload | null = shouldUpdateBlock
          ? {
              type: 'update_block',
              id: backendId,
              block_id: node.blockId,
            }
          : null

        if (node.original?.blockType !== 'single' && node.blockType === 'single') {
          if (moveOperation) {
            structuralOperations.push(moveOperation)
          }
          if (updateBlockOperation) {
            structuralOperations.push(updateBlockOperation)
          }
          continue
        }

        if (updateBlockOperation) {
          structuralOperations.push(updateBlockOperation)
        }

        if (moveOperation) {
          structuralOperations.push(moveOperation)
        }
      }

      const deleteOperations = operations
        .filter(
          (operation): operation is Extract<(typeof operations)[number], { type: 'delete' }> =>
            operation.type === 'delete'
        )
        .sort((left, right) => right.depth - left.depth)

      structuralOperations.push(
        ...deleteOperations.flatMap((operation) => {
          const node = treeApi.getNode(operation.nodeId)
          if (!node?.backendId) {
            return []
          }

          return [
            {
              type: 'delete' as const,
              ids: [node.backendId],
            },
          ]
        })
      )

      if (structuralOperations.length > 0) {
        await treeOperationsMutation.mutateAsync({
          operations: structuralOperations,
        })
      }

      for (const node of activeExistingNodes) {
        const effectiveSlug = resolveEffectiveSlug(node.title, node.slug)
        const originalSlug = node.original
          ? resolveEffectiveSlug(node.original.title, node.original.slug)
          : ''
        const shouldUpdate =
          node.title !== node.original?.title ||
          effectiveSlug !== originalSlug

        if (!shouldUpdate) {
          continue
        }

        await executeUpdate(node.id)
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
