import { useQueryClient } from '@tanstack/vue-query'

import type {
  ContentWizardApplyResult,
  ContentWizardDraftNode,
  ContentWizardOperation,
} from '~/types/content-wizard'
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
  /**
   * How far the field-update pass got. That pass is sequential and cannot be
   * rolled back, so on a failure the caller needs to know that operations
   * 1..completed were applied and the structural batch went through as well.
   */
  const applyProgress = ref<{ completed: number; total: number } | null>(null)

  // `apply` reads the plan straight off the tree and never resets it, so without
  // these two guards a double click, or a caller that retries, re-sends an
  // identical batch. The signature covers the field state the plan does not.
  let inFlight: Promise<ContentWizardApplyResult> | null = null
  let appliedSignature: string | null = null

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

  const runApply = async (
    operations: ContentWizardOperation[]
  ): Promise<ContentWizardApplyResult> => {
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
    applyProgress.value = null

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
              // Omitted for blank nodes so the server applies its own schema
              // defaults instead of being handed an empty object.
              ...(Object.keys(node.content).length ? { content: node.content } : {}),
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
        const shouldMove =
          node.parentId !== node.original?.parentId || node.position !== node.original?.position

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

      const nodesToUpdate = activeExistingNodes.filter((node) => {
        const effectiveSlug = resolveEffectiveSlug(node.title, node.slug)
        const originalSlug = node.original
          ? resolveEffectiveSlug(node.original.title, node.original.slug)
          : ''

        return node.title !== node.original?.title || effectiveSlug !== originalSlug
      })

      const total = nodesToUpdate.length
      let completed = 0
      applyProgress.value = { completed, total }

      // Sequential and not rollback-able: a failure here leaves the structural
      // batch and every earlier update applied, which is what applyProgress
      // reports to the caller.
      for (const node of nodesToUpdate) {
        await executeUpdate(node.id)
        completed += 1
        applyProgress.value = { completed, total }
      }

      // Each mutation's own onSuccess covers contents and contentMenu, but not
      // blocks(...).lists() — which the failure path does invalidate.
      await invalidateContentQueries()

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

  // The plan alone does not describe an update — it names the node but not the
  // title or slug it is carrying — so the signature pins both.
  const planSignature = (operations: ContentWizardOperation[]) =>
    JSON.stringify(
      operations.map((operation) => {
        const node = treeApi.getNode(operation.nodeId)

        return [
          operation,
          node
            ? [
                node.title,
                resolveEffectiveSlug(node.title, node.slug),
                node.blockId,
                node.parentId,
                node.position,
              ]
            : null,
        ]
      })
    )

  const apply = (): Promise<ContentWizardApplyResult> => {
    if (inFlight) {
      return inFlight
    }

    const operations = treeApi.operationPlan.value
    const signature = planSignature(operations)

    if (signature === appliedSignature) {
      return Promise.resolve({ success: true, operations })
    }

    inFlight = runApply(operations)
      .then((result) => {
        appliedSignature = result.success ? signature : null

        return result
      })
      .finally(() => {
        inFlight = null
      })

    return inFlight
  }

  return {
    isApplying,
    applyError,
    applyProgress,
    apply,
    invalidateContentQueries,
  }
}
