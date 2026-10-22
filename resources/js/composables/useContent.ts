import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { ContentsQueryParams } from '~/api/resources/contents'
import {
  collectSubtreeIds,
  type ContentMenu,
  withMovedItems,
  withRenamedItem,
  withSavedContent,
} from '~/lib/content-menu-cache'
import type {
  ContentTranslationExportFormat,
  ContentTranslationImportMode,
} from '~/types/content-translations'
import type {
  ContentBulkPublishRun,
  ContentResource,
  ContentTreeMoveOperation,
  ContentTreeOperationPayload,
  CreateContentPayload,
  UpdateContentPayload,
} from '~/types/contents'
import type { ImportProgress } from '~/types/import-export'

import { queryKeys } from './useQueryClient'

type BulkPublishMode = 'publish' | 'unpublish'

const BULK_RUN_POLL_INTERVAL = 2000
// Matches the server lock: a run that has not finished by then is gone.
const BULK_RUN_MAX_POLLS = 900

const isBulkRunActive = (run: ContentBulkPublishRun) =>
  run.state === 'queued' || run.state === 'running'

// A followed run was started by someone else and is phrased as "another run".
const bulkKey = (mode: BulkPublishMode, followed: boolean) =>
  followed ? `composables.content.bulk.other.${mode}` : `composables.content.bulk.${mode}`

export function useContent(spaceId: MaybeRef<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const spaceAPI = computed(() => api.forSpace(toValue(spaceId)))

  const hasFullDetail = (content: ContentResource) =>
    content.content !== undefined &&
    content.raw_content !== undefined &&
    content.current_version_id !== undefined &&
    Array.isArray(content.language_versions)

  const invalidateContentFamily = (content: ContentResource, reuseDetail = false) => {
    const familyContentIds = new Set<string>()

    if (content.id) {
      familyContentIds.add(content.id)
    }

    if (content.i18n_canonical_id) {
      familyContentIds.add(content.i18n_canonical_id)
    }

    // A write response may omit the language versions entirely; a missing
    // field must not turn a committed save into an error toast.
    content.language_versions?.forEach((version) => {
      if (version.content_id) {
        familyContentIds.add(version.content_id)
      }
    })

    familyContentIds.forEach((contentId) => {
      const detailKey = queryKeys.contents(spaceId).detail(contentId)
      if (reuseDetail && contentId === content.id) {
        queryClient.setQueryData(detailKey, content)
      } else {
        queryClient.invalidateQueries({ queryKey: detailKey })
      }
      queryClient.invalidateQueries({
        queryKey: queryKeys.contentVersions(spaceId, contentId).lists(),
      })
    })
  }

  const menuKey = () => queryKeys.contentMenu(spaceId).all()
  const isMenuFetching = () => queryClient.isFetching({ queryKey: menuKey() }) > 0

  // Folds a write response into the menu instead of refetching the whole tree.
  // A fetch already in flight may predate the write, so that one restarts.
  const syncMenuEntry = (content: ContentResource) => {
    const menu = queryClient.getQueryData<ContentMenu>(menuKey())
    const inFlight = isMenuFetching()
    const patched = menu && !inFlight ? withSavedContent(menu, content) : null

    if (patched) {
      queryClient.setQueryData(menuKey(), patched)
    } else if (menu || inFlight) {
      queryClient.invalidateQueries({ queryKey: menuKey() })
    }
  }

  // A write that answers with the whole entry: the detail and the menu entry
  // take the response, so neither refetches and the open editor syncs once.
  const applySavedContent = async (data: ContentResource, id: string, refetchMenu = false) => {
    const reuseDetail = data.id === id && hasFullDetail(data)
    if (reuseDetail) {
      await queryClient.cancelQueries({
        queryKey: queryKeys.contents(spaceId).detail(data.id),
        exact: true,
      })
    }
    queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
    if (refetchMenu) {
      queryClient.invalidateQueries({ queryKey: menuKey() })
    } else {
      syncMenuEntry(data)
    }
    invalidateContentFamily(data, reuseDetail)
  }

  const useContentsQuery = (params: MaybeRef<ContentsQueryParams> = {}) => {
    return useQuery({
      queryKey: computed(() => queryKeys.contents(spaceId).list(params)),
      queryFn: async ({ signal }) => {
        const response = await spaceAPI.value.contents.index(toValue(params), { signal })
        return response
      },
      placeholderData: keepPreviousData,
    })
  }

  const getContent = async (id: string, signal?: AbortSignal) => {
    const response = await spaceAPI.value.contents.get(id, {}, { signal })
    return response.data
  }

  // Query to fetch a single content item
  const useContentQuery = (id: MaybeRef<string | null | undefined>) => {
    const resolvedId = computed(() => toValue(id) || '')

    return useQuery({
      queryKey: computed(() => queryKeys.contents(spaceId).detail(resolvedId.value)),
      queryFn: async ({ signal }) => {
        const contentId = toValue(id)
        if (!contentId) {
          throw new Error('Content ID is required')
        }

        return getContent(contentId, signal)
      },
      enabled: computed(() => !!toValue(id)),
    })
  }

  /** The content from the cache `useContentQuery` fills, fetched only when it holds none. */
  const fetchContent = (id: string) =>
    queryClient.ensureQueryData({
      queryKey: queryKeys.contents(spaceId).detail(id),
      queryFn: ({ signal }) => getContent(id, signal),
    })

  // Query to fetch children of a specific content item.
  // Keyed by the params it actually sends, and caching the same envelope shape
  // as useContentsQuery — both live under contents.lists().
  // ContentFilter reads `parent_id` off the top level and takes the literal
  // string 'null' as "root level"; a real null is dropped from the query
  // string, which would return the whole unfiltered list.
  const useContentChildrenQuery = (parentId: MaybeRef<string | null>) => {
    const params = computed(() => ({ parent_id: toValue(parentId) ?? 'null' }))

    return useQuery({
      queryKey: computed(() => queryKeys.contents(spaceId).list(params.value)),
      queryFn: async ({ signal }) => {
        return await spaceAPI.value.contents.index(params.value, { signal })
      },
    })
  }

  const useCreateContentMutation = () => {
    return useMutation({
      mutationFn: async (payload: CreateContentPayload) => {
        const response = await spaceAPI.value.contents.create(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        invalidateContentFamily(data)

        toast.success(t('composables.content.createSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.createError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUpdateContentMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateContentPayload }) => {
        const response = await spaceAPI.value.contents.update(id, payload)
        return response.data
      },
      // A rename shows in the tree right away and rolls back if the save fails.
      onMutate: async ({ id, payload }) => {
        const name = payload.name
        if (name === undefined || !queryClient.getQueryData<ContentMenu>(menuKey())?.[id]) {
          return undefined
        }

        // A cancelled fetch never lands, so the menu refetches once settled.
        const refetchMenu = isMenuFetching()
        await queryClient.cancelQueries({ queryKey: menuKey() })
        const menu = queryClient.getQueryData<ContentMenu>(menuKey())
        const renamed = menu && withRenamedItem(menu, id, name)
        if (!menu || !renamed) return { refetchMenu }

        queryClient.setQueryData(menuKey(), renamed)
        return { previousName: menu[id].name, refetchMenu }
      },
      onSuccess: async (data, variables, context) => {
        await applySavedContent(data, variables.id, context?.refetchMenu)

        toast.success(t('composables.content.updateSuccess', { name: data.name }) as string)
      },
      onError: (error: any, { id, payload }, context) => {
        if (context?.previousName !== undefined) {
          // Only undo our own name; a newer write may have landed meanwhile.
          const menu = queryClient.getQueryData<ContentMenu>(menuKey())
          const restored =
            menu && menu[id]?.name === payload.name
              ? withRenamedItem(menu, id, context.previousName)
              : null
          if (restored) queryClient.setQueryData(menuKey(), restored)
        }
        if (context?.refetchMenu) {
          queryClient.invalidateQueries({ queryKey: menuKey() })
        }

        if (error?.status === 409 && error?.data?.conflict) return
        toast.error(
          t('composables.content.updateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const usePublishContentMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateContentPayload }) => {
        const response = await spaceAPI.value.contents.publish(id, payload)
        return response.data
      },
      onSuccess: async (data, variables) => {
        await applySavedContent(data, variables.id)

        toast.success(t('composables.content.publishSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.publishError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useCreateAndPublishContentMutation = () => {
    return useMutation({
      mutationFn: async (payload: UpdateContentPayload) => {
        const response = await spaceAPI.value.contents.createAndPublish(payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        invalidateContentFamily(data)

        toast.success(t('composables.content.publishSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.publishError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useScheduleContentMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateContentPayload }) => {
        const response = await spaceAPI.value.contents.schedule(id, payload)
        return response.data
      },
      onSuccess: async (data, variables) => {
        await applySavedContent(data, variables.id)

        toast.success(t('composables.content.scheduleSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.scheduleError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useUnpublishContentMutation = () => {
    return useMutation({
      mutationFn: async ({ id, payload }: { id: string; payload: UpdateContentPayload }) => {
        const response = await spaceAPI.value.contents.unpublish(id, payload)
        return response.data
      },
      onSuccess: async (data, variables) => {
        await applySavedContent(data, variables.id)

        toast.success(t('composables.content.unpublishSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.unpublishError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  // Polled like asset package builds; a run of 500 entries takes minutes.
  const waitForBulkRun = async (
    run: ContentBulkPublishRun,
    mode: BulkPublishMode,
    followed = false
  ): Promise<ContentBulkPublishRun> => {
    let current = run
    let toastId: string | number | undefined

    for (let attempt = 0; isBulkRunActive(current); attempt++) {
      if (attempt >= BULK_RUN_MAX_POLLS) throw new Error('Bulk run timed out')

      toastId = toast.loading(
        t(`${bulkKey(mode, followed)}Progress`, { progress: current.progress }) as string,
        toastId === undefined ? undefined : { id: toastId }
      )
      await new Promise((resolve) => setTimeout(resolve, BULK_RUN_POLL_INTERVAL))
      current = (await spaceAPI.value.contents.bulkPublishRun(current.id)).data
    }

    if (toastId !== undefined) toast.dismiss(toastId)
    if (current.state === 'failed') throw new Error(current.error || 'Bulk run failed')

    return current
  }

  // Another run holds the space: show it to the end under its own action and
  // numbers, then reject, since the requested run never started. Whether the
  // followed run failed is its starter's concern.
  const startBulkRun = async (
    start: () => Promise<{ data: ContentBulkPublishRun }>,
    mode: BulkPublishMode
  ): Promise<ContentBulkPublishRun> => {
    let started: ContentBulkPublishRun

    try {
      started = (await start()).data
    } catch (error) {
      const { status, data } = error as { status?: number; data?: { data?: { run?: string } } }
      const activeRunId = status === 409 ? data?.data?.run : undefined
      if (!activeRunId) throw error

      const { data: active } = await spaceAPI.value.contents.bulkPublishRun(activeRunId)
      try {
        settleBulkPublish(await waitForBulkRun(active, active.action, true), active.action, true)
      } catch {}
      throw new Error(t('composables.content.bulk.notStarted') as string)
    }

    return waitForBulkRun(started, mode)
  }

  const invalidateContentLists = () => {
    queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
    queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
  }

  // A batch reports once: a count when every entry went through, otherwise the
  // first failure's reason alongside how many were affected. Search misses get
  // their own warning, those entries did change.
  const settleBulkPublish = (
    result: ContentBulkPublishRun,
    mode: BulkPublishMode,
    followed = false
  ) => {
    invalidateContentLists()
    result.succeeded.forEach((contentId) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).detail(contentId) })
      queryClient.invalidateQueries({
        queryKey: queryKeys.contentVersions(spaceId, contentId).lists(),
      })
    })

    const indexFailures = result.index_failed.length
    if (indexFailures > 0) {
      toast.warning(
        t('composables.content.bulk.indexFailed', { count: indexFailures }, indexFailures) as string
      )
    }

    const [firstFailure] = result.failed
    if (!firstFailure) {
      const count = result.succeeded.length
      toast.success(t(`${bulkKey(mode, followed)}Success`, { count }, count) as string)
      return
    }

    const count = result.failed.length
    toast.error(
      t(
        `${bulkKey(mode, followed)}Failed`,
        {
          count,
          total: count + result.succeeded.length,
          name: firstFailure.name ?? firstFailure.id,
          error: firstFailure.message,
        },
        count
      ) as string
    )
  }

  const useBulkPublishContentMutation = () => {
    return useMutation({
      mutationFn: async (payload: { ids: string[]; message?: string }) => {
        return startBulkRun(() => spaceAPI.value.contents.bulkPublish(payload), 'publish')
      },
      onSuccess: (result) => settleBulkPublish(result, 'publish'),
      onError: (error: Error) => {
        invalidateContentLists()
        toast.error(
          t('composables.content.publishError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useBulkUnpublishContentMutation = () => {
    return useMutation({
      mutationFn: async (ids: string[]) => {
        return startBulkRun(() => spaceAPI.value.contents.bulkUnpublish({ ids }), 'unpublish')
      },
      onSuccess: (result) => settleBulkPublish(result, 'unpublish'),
      onError: (error: Error) => {
        invalidateContentLists()
        toast.error(
          t('composables.content.unpublishError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDuplicateContentMutation = () => {
    return useMutation({
      mutationFn: async ({
        id,
        options,
      }: {
        id: string
        options?: { name?: string; parent_id?: string | null }
      }) => {
        const response = await spaceAPI.value.contents.duplicate(id, options)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })

        toast.success(t('composables.content.duplicateSuccess', { name: data.name }) as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.duplicateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useDeleteContentMutation = () => {
    return useMutation({
      mutationFn: async (id: string) => {
        await spaceAPI.value.contents.delete(id)
        return id
      },
      onSuccess: (id) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.removeQueries({ queryKey: queryKeys.contents(spaceId).detail(id) })
        // The version history is a sibling of the detail key, not a child of it.
        queryClient.removeQueries({ queryKey: queryKeys.contentVersions(spaceId, id).all() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })

        toast.success(t('composables.content.deleteSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.deleteError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  /**
   * The values a not-yet-created entry would receive for its generated fields.
   *
   * Not cached beyond the dialog's lifetime: another editor creating an entry
   * in the same scope moves the number, and a stale preview is more confusing
   * than a slightly slower one.
   */
  const useSerialPreviewQuery = (
    params: MaybeRef<{
      block_id: string | null | undefined
      parent_id?: string | null
      language_iso?: string | null
      name?: string | null
      i18n_parent_id?: string | null
      except_content_id?: string | null
    }>,
    enabled: MaybeRef<boolean>
  ) => {
    const resolvedParams = computed(() => {
      const value = toValue(params)

      return {
        block_id: String(value.block_id ?? ''),
        parent_id: value.parent_id ?? null,
        language_iso: value.language_iso ?? null,
        name: value.name ?? null,
        i18n_parent_id: value.i18n_parent_id ?? null,
        except_content_id: value.except_content_id ?? null,
      }
    })

    return useQuery({
      queryKey: computed(() => [
        ...queryKeys.contents(spaceId).all(),
        'serial-preview',
        resolvedParams.value,
      ]),
      queryFn: async () => spaceAPI.value.contents.serialPreview(resolvedParams.value),
      enabled: computed(() => Boolean(toValue(enabled) && toValue(params).block_id)),
      // The preview reflects live state — another editor creating an entry in
      // the same scope moves the number — so a cached answer is a wrong answer.
      staleTime: 0,
      gcTime: 0,
      placeholderData: keepPreviousData,
    })
  }

  const useBulkCreateContentMutation = () => {
    return useMutation({
      mutationFn: async (payload: {
        items: Array<{
          name: string
          slug: string
          block_id: string
          parent_id?: string | null
          temp_id?: string
        }>
      }) => {
        const response = await spaceAPI.value.contents.bulkCreate(payload)
        return response.data
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.bulkCreateError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useMoveContentMutation = () => {
    return useMutation({
      mutationFn: async ({
        id,
        payload,
      }: {
        id: string
        payload: { parent_id?: string | null; position?: number }
      }) => {
        const response = await spaceAPI.value.contents.move(id, payload)
        return response.data
      },
      onSuccess: (data) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        invalidateContentFamily(data)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.moveError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useTreeOperationsMutation = () => {
    return useMutation({
      mutationFn: async ({
        operations,
      }: {
        operations: ContentTreeOperationPayload[]
        /** Whether a parent keeps manual order, so a move resequences it like the server does. */
        allowsManualSort?: (parentId: string | null) => boolean
      }) => {
        const response = await spaceAPI.value.contents.treeOperations({ operations })
        return response.data
      },
      // Moves show in the tree right away and roll back if the batch fails.
      onMutate: async ({ operations, allowsManualSort }) => {
        const moves = operations.filter(
          (operation): operation is ContentTreeMoveOperation => operation.type === 'move'
        )
        if (moves.length === 0 || !queryClient.getQueryData<ContentMenu>(menuKey())) {
          return { previous: queryClient.getQueryData<ContentMenu>(menuKey()) }
        }

        await queryClient.cancelQueries({ queryKey: menuKey() })
        const previous = queryClient.getQueryData<ContentMenu>(menuKey())
        if (previous) {
          queryClient.setQueryData(
            menuKey(),
            moves.reduce(
              (menu, move) =>
                withMovedItems(menu, move, allowsManualSort?.(move.parent_id ?? null) ?? true),
              previous
            )
          )
        }

        return { previous }
      },
      onSuccess: (result, { operations }, context) => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })

        // The response carries the changed menu rows (server resequencing, new
        // ids, timestamps). Deletes are not in them, and a fetch already in
        // flight may predate the batch, so those refetch the menu instead.
        const cached = queryClient.getQueryData<ContentMenu>(menuKey())
        if (
          cached &&
          result.menu &&
          !isMenuFetching() &&
          !operations.some((operation) => operation.type === 'delete')
        ) {
          queryClient.setQueryData(menuKey(), { ...cached, ...result.menu })
        } else {
          queryClient.invalidateQueries({ queryKey: menuKey() })
        }

        // Only the moved, deleted or re-blocked entries change: their parent,
        // slug path or block, and for moves and deletes their whole subtree.
        const subtreeRoots = operations.flatMap((operation) =>
          operation.type === 'move' || operation.type === 'delete' ? operation.ids : []
        )
        const reblocked = operations.flatMap((operation) =>
          operation.type === 'update_block' ? [operation.id] : []
        )
        if (subtreeRoots.length === 0 && reblocked.length === 0) return

        const menu = context?.previous ?? queryClient.getQueryData<ContentMenu>(menuKey())
        if (!menu) {
          queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).details() })
          return
        }

        const touched = collectSubtreeIds(menu, subtreeRoots)
        for (const id of reblocked) {
          touched.add(id)
          menu[id]?.i18n?.forEach((translation) => touched.add(translation.id))
        }
        touched.forEach((id) =>
          queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).detail(id) })
        )
      },
      onError: (error: Error, _variables, context) => {
        if (context?.previous) queryClient.setQueryData(menuKey(), context.previous)
        queryClient.invalidateQueries({ queryKey: menuKey() })

        toast.error(
          t('composables.content.moveError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useExportContentTranslationsMutation = () => {
    return useMutation({
      mutationFn: async (
        params: { as: ContentTranslationExportFormat } & Record<string, unknown>
      ) => {
        return await spaceAPI.value.contents.exportTranslations(params)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.translationExportError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  const useImportContentTranslationsMutation = () => {
    return useMutation({
      mutationFn: async (variables: {
        file: File
        mode: ContentTranslationImportMode
        createMissing: boolean
        grid?: boolean
        onProgress?: (progress: ImportProgress) => void
      }) => {
        return await spaceAPI.value.contents.importTranslations(variables.file, {
          mode: variables.mode,
          createMissing: variables.createMissing,
          grid: variables.grid,
          onProgress: variables.onProgress,
        })
      },
      onSuccess: () => {
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).lists() })
        // An import rewrites entry content, so an open editor must refetch.
        queryClient.invalidateQueries({ queryKey: queryKeys.contents(spaceId).details() })
        queryClient.invalidateQueries({ queryKey: queryKeys.contentMenu(spaceId).all() })
        toast.success(t('composables.content.translationImportSuccess') as string)
      },
      onError: (error: Error) => {
        toast.error(
          t('composables.content.translationImportError', {
            error: error.message || 'Unknown error',
          }) as string
        )
      },
    })
  }

  return {
    // Queries
    useContentsQuery,
    useContentQuery,
    fetchContent,
    useContentChildrenQuery,
    useSerialPreviewQuery,

    // Mutations
    useCreateContentMutation,
    useUpdateContentMutation,
    usePublishContentMutation,
    useCreateAndPublishContentMutation,
    useScheduleContentMutation,
    useUnpublishContentMutation,
    useBulkPublishContentMutation,
    useBulkUnpublishContentMutation,
    useDuplicateContentMutation,
    useDeleteContentMutation,
    useBulkCreateContentMutation,
    useMoveContentMutation,
    useTreeOperationsMutation,
    useExportContentTranslationsMutation,
    useImportContentTranslationsMutation,
  }
}
