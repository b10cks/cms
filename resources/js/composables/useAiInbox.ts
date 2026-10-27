import { useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'

import { api } from '~/api'
import type { AiDecision } from '~/lib/aiChangesets'
import type { AiQuestionnaireAnswers } from '~/lib/aiRun'
import { toastError } from '~/lib/toast-error'

/**
 * The space's AI inbox (open changesets, background runs, glossary
 * suggestions) and changeset review. Live updates arrive over the space's `ai` channel, which
 * useSpaceBroadcasts subscribes to.
 */
export function useAiInbox(spaceId: MaybeRefOrGetter<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const keys = computed(() => queryKeys.ai(toValue(spaceId)))
  const resource = () => api.forSpace(toValue(spaceId)).ai

  const useInboxQuery = (enabled: MaybeRefOrGetter<boolean> = true) =>
    useQuery({
      queryKey: computed(() => keys.value.inbox()),
      queryFn: () => resource().getInbox(),
      enabled: computed(() => !!toValue(spaceId) && toValue(enabled)),
      staleTime: 30_000,
    })

  const useChangesetQuery = (changesetId: MaybeRefOrGetter<string | null>) =>
    useQuery({
      queryKey: computed(() => keys.value.changeset(toValue(changesetId) ?? '')),
      queryFn: async () => (await resource().getChangeset(toValue(changesetId) ?? '')).data,
      enabled: computed(() => !!toValue(changesetId)),
    })

  const refresh = (changesetId: string) => {
    void queryClient.invalidateQueries({ queryKey: keys.value.inbox() })
    void queryClient.invalidateQueries({ queryKey: keys.value.changeset(changesetId) })
  }

  const acceptMutation = useMutation({
    mutationFn: ({ changesetId, decision }: { changesetId: string; decision?: AiDecision }) =>
      resource().acceptChangeset(changesetId, decision),
    onSuccess: (_, { changesetId }) => refresh(changesetId),
    onError: (error: unknown, { changesetId }) => {
      refresh(changesetId)
      toastError(t, 'components.aiChangeset.acceptFailed', error)
    },
  })

  const rejectMutation = useMutation({
    mutationFn: ({ changesetId, decision }: { changesetId: string; decision?: AiDecision }) =>
      resource().rejectChangeset(changesetId, decision),
    onSuccess: (_, { changesetId }) => refresh(changesetId),
    onError: (error: unknown) => toastError(t, 'components.aiChangeset.rejectFailed', error),
  })

  const refreshInbox = () => queryClient.invalidateQueries({ queryKey: keys.value.inbox() })

  const answerMutation = useMutation({
    mutationFn: ({ runId, answers }: { runId: string; answers: AiQuestionnaireAnswers }) =>
      resource().answerRun(runId, answers),
    onSuccess: refreshInbox,
    onError: (error: unknown) => toastError(t, 'components.aiInbox.answerFailed', error),
  })

  const cancelMutation = useMutation({
    mutationFn: (runId: string) => resource().cancelRun(runId),
    onSuccess: refreshInbox,
    onError: (error: unknown) => toastError(t, 'components.aiInbox.cancelFailed', error),
  })

  const acceptSuggestionMutation = useMutation({
    mutationFn: ({ suggestionId, glossaryId }: { suggestionId: string; glossaryId: string }) =>
      resource().acceptSuggestion(suggestionId, glossaryId),
    onSuccess: refreshInbox,
    onError: (error: unknown) => toastError(t, 'components.aiInbox.suggestions.acceptFailed', error),
  })

  const dismissSuggestionMutation = useMutation({
    mutationFn: (suggestionId: string) => resource().dismissSuggestion(suggestionId),
    onSuccess: refreshInbox,
    onError: (error: unknown) => toastError(t, 'components.aiInbox.suggestions.dismissFailed', error),
  })

  return {
    useInboxQuery,
    useChangesetQuery,
    acceptMutation,
    rejectMutation,
    answerMutation,
    cancelMutation,
    acceptSuggestionMutation,
    dismissSuggestionMutation,
  }
}
