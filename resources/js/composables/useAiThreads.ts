import { keepPreviousData, useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'

import { api } from '~/api'
import type {
  AiChangesetItem,
  AiDecision,
  AiGroupDecisionResult,
  AiGroupRejectResult,
  AiReviewGroup,
} from '~/lib/aiChangesets'
import { overrideErrors } from '~/lib/aiChangesets'
import type { AiQuestionnaireAnswers } from '~/lib/aiRun'
import type { AiThreadListParams, AiThreadPatch } from '~/lib/aiThreads'
import { toastError } from '~/lib/toast-error'

/** How often a review with a queued group decision refetches when no broadcast arrives. */
const QUEUED_POLL_MS = 3000

/**
 * The space's AI threads and the decisions made in them: changeset accept and
 * reject, questionnaire answers, glossary suggestions. Live updates arrive
 * over the space's `ai` channel, which useSpaceBroadcasts subscribes to.
 */
export function useAiThreads(spaceId: MaybeRefOrGetter<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const keys = computed(() => queryKeys.ai(toValue(spaceId)))
  const resource = () => api.forSpace(toValue(spaceId)).ai

  const useThreadsQuery = (
    params: MaybeRefOrGetter<AiThreadListParams> = { archived: false, search: '' },
    enabled: MaybeRefOrGetter<boolean> = true
  ) =>
    useQuery({
      queryKey: computed(() => keys.value.threadList(toValue(params))),
      queryFn: () => resource().getThreads(toValue(params)),
      enabled: computed(() => !!toValue(spaceId) && toValue(enabled)),
      placeholderData: keepPreviousData,
      staleTime: 30_000,
    })

  const useThreadQuery = (threadId: MaybeRefOrGetter<string | null>) =>
    useQuery({
      queryKey: computed(() => keys.value.thread(toValue(threadId) ?? '')),
      queryFn: async () => (await resource().getThread(toValue(threadId) ?? '')).data,
      enabled: computed(() => !!toValue(spaceId) && !!toValue(threadId)),
    })

  /**
   * Lists, open threads and operations refetch; a decision changes all of
   * them, an operation's changeset counts included.
   */
  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: keys.value.threads() }),
      queryClient.invalidateQueries({ queryKey: keys.value.operations() }),
    ])

  const updateMutation = useMutation({
    mutationFn: ({ threadId, patch }: { threadId: string; patch: AiThreadPatch }) =>
      resource().updateThread(threadId, patch),
    onSuccess: refresh,
    onError: (error: unknown) => toastError(t, 'components.aiThreads.updateFailed', error),
  })

  /**
   * Accepting writes drafts on the server; refetch what it touched so an open
   * editor, list or grid shows the change without waiting for a broadcast.
   * The editor keeps unsaved local edits and replaces a clean document.
   */
  const refreshTargets = (items: readonly AiChangesetItem[], decision: AiDecision = {}) => {
    const id = toValue(spaceId)
    const decided = items.filter((item) => !decision.item_ids || decision.item_ids.includes(item.id))
    const accepted = decided.filter((item) => item.target_id)
    const invalidate = (queryKey: readonly unknown[]) => queryClient.invalidateQueries({ queryKey })

    for (const item of accepted) {
      if (item.target_type === 'content') void invalidate(queryKeys.contents(id).detail(item.target_id ?? ''))
      if (item.target_type === 'asset') void invalidate(queryKeys.assets(id).detail(item.target_id ?? ''))
    }
    if (accepted.some((item) => item.target_type === 'content')) {
      void invalidate(queryKeys.contentMenu(id).all())
      void invalidate(queryKeys.massEdit(id).all())
    }
    if (accepted.some((item) => item.target_type === 'data_entry')) void invalidate(queryKeys.dataSources(id).all())
    if (accepted.some((item) => item.target_type === 'asset')) void invalidate(queryKeys.assets(id).lists())
    // A created block has no ID until it is accepted.
    if (decided.some((item) => item.target_type === 'block')) void invalidate(queryKeys.blocks(id).all())
  }

  const acceptMutation = useMutation({
    mutationFn: ({
      changesetId,
      decision,
    }: {
      changesetId: string
      decision?: AiDecision
      /** The changeset's items as shown, to refetch the entries they target */
      items?: readonly AiChangesetItem[]
    }) => resource().acceptChangeset(changesetId, decision),
    onSuccess: (_result, { decision, items = [] }) => {
      refreshTargets(items, decision)
      return refresh()
    },
    onError: (error: unknown) => {
      void refresh()
      // Missing override reasons show on their findings.
      if (Object.keys(overrideErrors(error)).length === 0) toastError(t, 'components.aiChangeset.acceptFailed', error)
    },
  })

  /**
   * An operation's review groups; decisions in the thread refetch them.
   * While a group decision runs on the queue it polls until `queued` clears.
   */
  const useOperationReviewQuery = (operationId: MaybeRefOrGetter<string>) =>
    useQuery({
      queryKey: computed(() => keys.value.operationReview(toValue(operationId))),
      queryFn: async () => (await resource().getOperationReview(toValue(operationId))).data,
      enabled: computed(() => !!toValue(spaceId) && !!toValue(operationId)),
      placeholderData: keepPreviousData,
      refetchInterval: (query) => (query.state.data?.some((group) => group.queued !== null) ? QUEUED_POLL_MS : false),
    })

  /** What an operation changed in drafts, a page of entries at a time, with the figures for its receipt. */
  const useOperationChangesQuery = (operationId: MaybeRefOrGetter<string>, page: MaybeRefOrGetter<number>, perPage: number) =>
    useQuery({
      queryKey: computed(() => keys.value.operationChanges(toValue(operationId), toValue(page), perPage)),
      queryFn: () => resource().getOperationChanges(toValue(operationId), { page: toValue(page), per_page: perPage }),
      enabled: computed(() => !!toValue(spaceId) && !!toValue(operationId)),
      placeholderData: keepPreviousData,
    })

  /** The fields of one changed entry, loaded when it is opened. */
  const useEntryItemsQuery = (operationId: MaybeRefOrGetter<string>, targetId: MaybeRefOrGetter<string>, enabled: MaybeRefOrGetter<boolean>, perPage = 20) =>
    useInfiniteQuery({
      queryKey: computed(() => keys.value.operationEntryItems(toValue(operationId), toValue(targetId))),
      queryFn: ({ pageParam }) => resource().getOperationEntryItems(toValue(operationId), { target_id: toValue(targetId), page: pageParam, per_page: perPage }),
      initialPageParam: 1,
      getNextPageParam: (last) => (last.meta.page * last.meta.per_page < last.meta.total ? last.meta.page + 1 : undefined),
      enabled: computed(() => !!toValue(spaceId) && toValue(enabled)),
    })

  /** A review group's items, a page at a time; nothing loads until `enabled`. */
  const useReviewItemsQuery = (
    operationId: MaybeRefOrGetter<string>,
    group: MaybeRefOrGetter<string>,
    individual: MaybeRefOrGetter<boolean>,
    enabled: MaybeRefOrGetter<boolean>,
    perPage = 20
  ) =>
    useInfiniteQuery({
      queryKey: computed(() => keys.value.operationReviewItems(toValue(operationId), toValue(group), toValue(individual))),
      queryFn: ({ pageParam }) =>
        resource().getOperationReviewItems(toValue(operationId), {
          group: toValue(group),
          individual: toValue(individual),
          page: pageParam,
          per_page: perPage,
        }),
      initialPageParam: 1,
      getNextPageParam: (last) => (last.meta.page * last.meta.per_page < last.meta.total ? last.meta.page + 1 : undefined),
      enabled: computed(() => !!toValue(spaceId) && toValue(enabled)),
      placeholderData: keepPreviousData,
    })

  /** A group accept writes entries it never listed to the client; refetch their kind. */
  const refreshGroupTargets = (groups: readonly AiReviewGroup[]) => {
    const id = toValue(spaceId)
    const types = new Set(groups.map((group) => group.target_type))
    const invalidate = (queryKey: readonly unknown[]) => queryClient.invalidateQueries({ queryKey })
    if (types.has('content')) {
      void invalidate(queryKeys.contents(id).all())
      void invalidate(queryKeys.contentMenu(id).all())
      void invalidate(queryKeys.massEdit(id).all())
    }
    if (types.has('data_entry')) void invalidate(queryKeys.dataSources(id).all())
    if (types.has('asset') || types.has('asset_folder') || types.has('asset_tag')) void invalidate(queryKeys.assets(id).all())
  }

  /**
   * Accepts the selected groups one after another; each takes only its items
   * that need no individual look. A large group goes to the queue and counts
   * under `queued`, not as accepted.
   */
  const acceptGroupsMutation = useMutation({
    mutationFn: async ({ operationId, groups }: { operationId: string; groups: readonly AiReviewGroup[] }) => {
      const total: AiGroupDecisionResult = { accepted: 0, conflicts: 0, failed: 0, blocked: 0, individual: 0, queued: 0 }
      for (const group of groups) {
        const { data } = await resource().acceptOperationGroup(operationId, group.key)
        for (const key of Object.keys(total) as Array<keyof AiGroupDecisionResult>) total[key] += data[key]
      }
      return total
    },
    onSettled: (_result, _error, { groups }) => {
      refreshGroupTargets(groups)
      return refresh()
    },
    onError: (error: unknown) => toastError(t, 'components.aiChangeset.acceptFailed', error),
  })

  /** Rejects every open item of the selected groups, flagged and conflicting ones included. */
  const rejectGroupsMutation = useMutation({
    mutationFn: async ({ operationId, groups }: { operationId: string; groups: readonly AiReviewGroup[] }) => {
      const total: AiGroupRejectResult = { rejected: 0, queued: 0 }
      for (const group of groups) {
        const { data } = await resource().rejectOperationGroup(operationId, group.key)
        total.rejected += data.rejected
        total.queued += data.queued
      }
      return total
    },
    onSettled: refresh,
    onError: (error: unknown) => toastError(t, 'components.aiChangeset.rejectFailed', error),
  })

  const rejectMutation = useMutation({
    mutationFn: ({ changesetId, decision }: { changesetId: string; decision?: AiDecision }) =>
      resource().rejectChangeset(changesetId, decision),
    onSuccess: refresh,
    onError: (error: unknown) => toastError(t, 'components.aiChangeset.rejectFailed', error),
  })

  const answerMutation = useMutation({
    mutationFn: ({ runId, answers }: { runId: string; answers: AiQuestionnaireAnswers }) =>
      resource().answerRun(runId, answers),
    onSuccess: refresh,
    onError: (error: unknown) => toastError(t, 'components.aiThreads.answerFailed', error),
  })

  const cancelMutation = useMutation({
    mutationFn: (runId: string) => resource().cancelRun(runId),
    onSuccess: refresh,
    onError: (error: unknown) => toastError(t, 'components.aiThreads.cancelFailed', error),
  })

  const acceptSuggestionMutation = useMutation({
    mutationFn: ({ suggestionId, glossaryId }: { suggestionId: string; glossaryId: string }) =>
      resource().acceptSuggestion(suggestionId, glossaryId),
    onSuccess: refresh,
    onError: (error: unknown) => toastError(t, 'components.aiThreads.suggestions.acceptFailed', error),
  })

  const dismissSuggestionMutation = useMutation({
    mutationFn: (suggestionId: string) => resource().dismissSuggestion(suggestionId),
    onSuccess: refresh,
    onError: (error: unknown) => toastError(t, 'components.aiThreads.suggestions.dismissFailed', error),
  })

  /** Upload a file for the next message; the composer tracks progress itself. */
  const uploadAttachment = (file: File, options: { onProgress?: (progress: number) => void; signal?: AbortSignal }) =>
    resource().uploadAttachment(file, options)

  return {
    useThreadsQuery,
    uploadAttachment,
    useThreadQuery,
    refresh,
    updateMutation,
    acceptMutation,
    rejectMutation,
    useOperationReviewQuery,
    useOperationChangesQuery,
    useEntryItemsQuery,
    useReviewItemsQuery,
    acceptGroupsMutation,
    rejectGroupsMutation,
    refreshGroupTargets,
    answerMutation,
    cancelMutation,
    acceptSuggestionMutation,
    dismissSuggestionMutation,
  }
}
