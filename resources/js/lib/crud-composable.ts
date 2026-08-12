import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, toValue, type MaybeRef, type MaybeRefOrGetter } from 'vue'
import { toast } from 'vue-sonner'

import { useI18n } from '~/plugins/i18n'

import type { EntityKeys } from './query-keys'
import { toastError, type Translate } from './toast-error'

/**
 * The slice of `BaseResource` a plain CRUD composable talks to. Declared
 * structurally so a resource with extra endpoints still satisfies it.
 */
export interface CrudApiResource<TResource, TListResponse, TParams, TCreate, TUpdate> {
  index: (params?: TParams) => Promise<TListResponse>
  get: (id: string) => Promise<{ data: TResource }>
  create: (payload: TCreate) => Promise<{ data: TResource }>
  update: (id: string, payload: TUpdate) => Promise<{ data: TResource }>
  delete: (id: string) => Promise<unknown>
}

export type CrudOperation = 'create' | 'update' | 'delete'

/** The `{ id, payload }` pair the update mutation ultimately calls the API with. */
export interface UpdateArgs<TUpdate> {
  id: string
  payload: TUpdate
}

export interface CrudComposableConfig<
  TResource extends { id: string },
  TListResponse,
  TParams extends object,
  TCreate,
  TUpdate,
  TListResult = TListResponse,
  TUpdateVars = UpdateArgs<TUpdate>,
> {
  /** i18n namespace — messages live under `composables.<i18nKey>.{create,update,delete}{Success,Error}`. */
  i18nKey: string
  keys: (spaceId: string) => EntityKeys
  resource: (spaceId: string) => CrudApiResource<TResource, TListResponse, TParams, TCreate, TUpdate>
  /** Merged *under* the caller's params, so a caller can always override the default sort. */
  defaultParams?: TParams
  /**
   * Narrow the raw list response before it reaches the caller — several
   * composables hand components the bare `data` array rather than the envelope.
   */
  selectList?: (response: TListResponse) => TListResult
  /**
   * Whether the list query waits for a space id. `space` (default) matches the
   * common `!!spaceId && enabled`; `none` is for the couple of lists that
   * deliberately fire regardless.
   */
  listGate?: 'space' | 'none'
  /**
   * Map the caller's update variables onto `{ id, payload }`. Entities that key
   * their update on something other than `id` (`folderId`, `tagName`, …) declare
   * the mapping here rather than renaming their public API.
   */
  updateVariables?: (variables: TUpdateVars) => UpdateArgs<TUpdate>
  /**
   * How hard the detail query guards itself. A few entities never gated theirs
   * at all and components rely on it firing before the space/id resolves, so
   * this is opt-out rather than a silent behaviour change.
   *
   * - `space+id` (default): `spaceId && id && enabled`
   * - `space`: `spaceId && enabled` — id may still be empty
   * - `none`: only the caller's `enabled`
   */
  detailGate?: 'space+id' | 'space' | 'none'
  /** Interpolation values for the create/update success toasts. Omit for a message without placeholders. */
  toastValues?: (data: TResource) => Record<string, unknown>
  /** Keys outside this entity that a mutation makes stale (asset lists, block lists, …). */
  invalidateAlso?: (spaceId: string, operation: CrudOperation) => Array<readonly unknown[]>
  /** Poll interval for the detail query, e.g. while a job is still running. */
  detailRefetchInterval?: (data: TResource | undefined) => number | false
  /** Last chance to drop fields the server must not receive on update. */
  prepareUpdate?: (payload: TUpdate) => TUpdate
}

/**
 * Build the list/detail queries and the create/update/delete mutations that ~25
 * space-scoped composables all spell out by hand.
 *
 * Returns a composable; the caller re-exports its hooks under the entity's own
 * names and adds whatever is genuinely custom.
 */
export function createCrudComposable<
  TResource extends { id: string },
  TListResponse,
  TParams extends object,
  TCreate,
  TUpdate,
  TListResult = TListResponse,
  TUpdateVars = UpdateArgs<TUpdate>,
>(
  config: CrudComposableConfig<
    TResource,
    TListResponse,
    TParams,
    TCreate,
    TUpdate,
    TListResult,
    TUpdateVars
  >
) {
  const message = (suffix: string) => `composables.${config.i18nKey}.${suffix}`
  const detailGate = config.detailGate ?? 'space+id'
  const listGate = config.listGate ?? 'space'
  const selectList =
    config.selectList ?? ((response: TListResponse) => response as unknown as TListResult)
  const toUpdateArgs =
    config.updateVariables ??
    ((variables: TUpdateVars) => variables as unknown as UpdateArgs<TUpdate>)

  return (spaceIdSource: MaybeRefOrGetter<string>) => {
    const { t: translate } = useI18n()
    const t = translate as unknown as Translate
    const queryClient = useQueryClient()

    const spaceId = computed(() => toValue(spaceIdSource))
    const keys = computed(() => config.keys(spaceId.value))
    const resource = computed(() => config.resource(spaceId.value))

    const invalidate = (queryKey: readonly unknown[]) => queryClient.invalidateQueries({ queryKey })

    const invalidateAlso = (operation: CrudOperation) => {
      for (const key of config.invalidateAlso?.(spaceId.value, operation) ?? []) {
        invalidate(key)
      }
    }

    const invalidateLists = (operation: CrudOperation) => {
      invalidate(keys.value.lists())
      invalidateAlso(operation)
    }

    const notify = (suffix: string, data?: TResource) => {
      const values = data && config.toastValues?.(data)
      toast.success(values ? t(message(suffix), values) : t(message(suffix)))
    }

    const useListQuery = (
      params: MaybeRefOrGetter<TParams> = {} as TParams,
      enabled: MaybeRef<boolean> = true
    ) =>
      useQuery({
        queryKey: computed(() => keys.value.list(toValue(params))),
        queryFn: async () =>
          selectList(await resource.value.index({ ...config.defaultParams, ...toValue(params) })),
        enabled: computed(
          () => (listGate === 'none' || !!spaceId.value) && !!toValue(enabled)
        ),
        placeholderData: keepPreviousData,
      })

    const useDetailQuery = (
      id: MaybeRefOrGetter<string>,
      enabled: MaybeRef<boolean> = true
    ) =>
      useQuery({
        queryKey: computed(() => keys.value.detail(toValue(id))),
        queryFn: async () => (await resource.value.get(toValue(id))).data,
        enabled: computed(
          () =>
            (detailGate === 'none' || !!spaceId.value) &&
            (detailGate !== 'space+id' || !!toValue(id)) &&
            !!toValue(enabled)
        ),
        refetchInterval: config.detailRefetchInterval
          ? (query) => config.detailRefetchInterval!(query.state.data as TResource | undefined)
          : undefined,
      })

    const useCreateMutation = () =>
      useMutation({
        mutationFn: async (payload: TCreate) => (await resource.value.create(payload)).data,
        onSuccess: (data) => {
          invalidateLists('create')
          notify('createSuccess', data)
        },
        onError: (error: Error) => toastError(t, message('createError'), error),
      })

    const useUpdateMutation = () =>
      useMutation({
        mutationFn: async (variables: TUpdateVars) => {
          const { id, payload } = toUpdateArgs(variables)
          return (await resource.value.update(id, config.prepareUpdate?.(payload) ?? payload)).data
        },
        onSuccess: (data) => {
          // Lists, then the record itself, then anything outside the entity —
          // the order the hand-written mutations used, and the one tests assert.
          invalidate(keys.value.lists())
          invalidate(keys.value.detail(data.id))
          invalidateAlso('update')
          notify('updateSuccess', data)
        },
        onError: (error: Error) => toastError(t, message('updateError'), error),
      })

    const useDeleteMutation = () =>
      useMutation({
        mutationFn: async (id: string) => {
          await resource.value.delete(id)
          return id
        },
        onSuccess: (id) => {
          invalidateLists('delete')
          // A detail entry left behind keeps polling — and 404s — after the row is gone.
          queryClient.removeQueries({ queryKey: keys.value.detail(id) })
          notify('deleteSuccess')
        },
        onError: (error: Error) => toastError(t, message('deleteError'), error),
      })

    return {
      spaceId,
      keys,
      resource,
      invalidateLists,
      useListQuery,
      useDetailQuery,
      useCreateMutation,
      useUpdateMutation,
      useDeleteMutation,
    }
  }
}
