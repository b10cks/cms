import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'

import { api } from '~/api'
import type { AiChangesetItem, AiDecision } from '~/lib/aiChangesets'
import type { AiQuestionnaireAnswers } from '~/lib/aiRun'
import type { AiThreadListParams, AiThreadPatch } from '~/lib/aiThreads'
import { toastError } from '~/lib/toast-error'

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

  /** Lists and open threads refetch; a decision changes both. */
  const refresh = () => queryClient.invalidateQueries({ queryKey: keys.value.threads() })

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
    const accepted = items.filter(
      (item) => item.target_id && (!decision.item_ids || decision.item_ids.includes(item.id))
    )
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
      toastError(t, 'components.aiChangeset.acceptFailed', error)
    },
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
    answerMutation,
    cancelMutation,
    acceptSuggestionMutation,
    dismissSuggestionMutation,
  }
}
