import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/vue-query'
import { toast } from 'vue-sonner'

import { api } from '~/api'
import type { AiOperationItemParams } from '~/api/resources/ai-operations'
import type { AiOperation, AiOperationAnswer, AiOperationApproval, AiOperationPlan, StartAiOperationRequest } from '~/lib/aiOperations'
import { apiErrorReason, isOperationLive, scopeEmpty, scopeTooLarge } from '~/lib/aiOperations'
import { toastError } from '~/lib/toast-error'

/** How often a running operation refetches when no broadcast arrives. */
const LIVE_POLL_MS = 4000
const PLAN_POLL_MS = 2000

/**
 * AI content operations: the operations of a thread with live progress,
 * steering, the ledger and grouped review. Lists poll only while an
 * operation runs; `ai_thread:changed` broadcasts invalidate them as well.
 */
export function useAiOperations(spaceId: MaybeRefOrGetter<string>) {
  const { t } = useI18n()
  const queryClient = useQueryClient()
  const keys = computed(() => queryKeys.ai(toValue(spaceId)))
  const resource = () => api.forSpace(toValue(spaceId)).aiOperations

  /** Every operation query (lists, ledgers, reviews) and the threads they live in. */
  const refresh = () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: keys.value.operations() }),
      queryClient.invalidateQueries({ queryKey: keys.value.threads() }),
    ])

  /** The grouped review of useAiThreads, after proposals or decisions; a fetch in flight is kept. */
  const refreshReview = (operationId: string) =>
    queryClient.invalidateQueries({ queryKey: keys.value.operationReview(operationId) }, { cancelRefetch: false })

  /** The ledger pages, after entries changed status. */
  const refreshLedger = (operationId: string) =>
    queryClient.invalidateQueries({ queryKey: keys.value.operationItems(operationId) })

  /** Put a returned operation into the thread lists at once, then refetch around it. */
  const store = (operation: AiOperation) => {
    queryClient.setQueriesData<AiOperation[]>({ queryKey: [...keys.value.operations(), 'list'] }, (list) =>
      list?.map((entry) => (entry.id === operation.id ? operation : entry))
    )
    return refresh()
  }

  const useThreadOperationsQuery = (conversationId: MaybeRefOrGetter<string | null>) =>
    useQuery({
      queryKey: computed(() => keys.value.operationList(toValue(conversationId) ?? '')),
      queryFn: async () => (await resource().list(toValue(conversationId))).data,
      enabled: computed(() => !!toValue(spaceId) && !!toValue(conversationId)),
      refetchInterval: (query) => (query.state.data?.some(isOperationLive) ? LIVE_POLL_MS : false),
    })

  /** The planning run, followed until it finishes; then the drafted plan is fetched. */
  const usePlanRunQuery = (runId: MaybeRefOrGetter<string | null>) =>
    useQuery({
      queryKey: computed(() => [...keys.value.operations(), 'plan-run', toValue(runId) ?? ''] as const),
      queryFn: async () => (await api.forSpace(toValue(spaceId)).ai.getRun(toValue(runId) ?? '')).data,
      enabled: computed(() => !!toValue(spaceId) && !!toValue(runId)),
      refetchInterval: (query) => (query.state.data?.state === 'running' ? PLAN_POLL_MS : false),
    })

  /** A batch run, followed while it runs for its provider wait. */
  const useRunQuery = (runId: MaybeRefOrGetter<string | null>) =>
    useQuery({
      queryKey: computed(() => [...keys.value.operations(), 'run', toValue(runId) ?? ''] as const),
      queryFn: async () => (await api.forSpace(toValue(spaceId)).ai.getRun(toValue(runId) ?? '')).data,
      enabled: computed(() => !!toValue(spaceId) && !!toValue(runId)),
      refetchInterval: (query) => (query.state.data?.state === 'running' ? LIVE_POLL_MS : false),
    })

  const useItemsQuery = (
    operationId: MaybeRefOrGetter<string>,
    params: MaybeRefOrGetter<AiOperationItemParams>,
    enabled: MaybeRefOrGetter<boolean> = true
  ) =>
    useQuery({
      queryKey: computed(() => [...keys.value.operationItems(toValue(operationId)), toValue(params)] as const),
      queryFn: () => resource().items(toValue(operationId), toValue(params)),
      enabled: computed(() => !!toValue(spaceId) && toValue(enabled)),
      placeholderData: keepPreviousData,
    })

  /** New entries in the scope since it was frozen; walked on request only. */
  const useArrivalsQuery = (operationId: MaybeRefOrGetter<string>, enabled: MaybeRefOrGetter<boolean>) =>
    useQuery({
      queryKey: computed(() => [...keys.value.operation(toValue(operationId)), 'arrivals'] as const),
      queryFn: async () => (await resource().arrivals(toValue(operationId))).data,
      enabled: computed(() => !!toValue(spaceId) && toValue(enabled)),
      staleTime: 60_000,
    })

  const questionsKey = (operationId: string) => [...keys.value.operation(operationId), 'questions'] as const

  /** Open questions; `revision` refetches them when batches move on instead of polling a full ledger scan. */
  const useQuestionsQuery = (
    operationId: MaybeRefOrGetter<string>,
    params: MaybeRefOrGetter<{ page: number; revision: string }>
  ) =>
    useQuery({
      queryKey: computed(() => [...questionsKey(toValue(operationId)), 'open', toValue(params)] as const),
      queryFn: () => resource().questions(toValue(operationId), { page: toValue(params).page }),
      enabled: computed(() => !!toValue(spaceId)),
      placeholderData: keepPreviousData,
    })

  const useQuestionMembersQuery = (
    operationId: MaybeRefOrGetter<string>,
    questionId: MaybeRefOrGetter<string>,
    params: MaybeRefOrGetter<{ page: number; revision: string }>,
    enabled: MaybeRefOrGetter<boolean>
  ) =>
    useQuery({
      queryKey: computed(() => [...questionsKey(toValue(operationId)), 'members', toValue(questionId), toValue(params)] as const),
      queryFn: () => resource().questionMembers(toValue(operationId), toValue(questionId), { page: toValue(params).page }),
      enabled: computed(() => !!toValue(spaceId) && toValue(enabled)),
      placeholderData: keepPreviousData,
    })

  const useAnsweredQuestionsQuery = (operationId: MaybeRefOrGetter<string>, page: MaybeRefOrGetter<number>, enabled: MaybeRefOrGetter<boolean>) =>
    useQuery({
      queryKey: computed(() => [...questionsKey(toValue(operationId)), 'answered', toValue(page)] as const),
      queryFn: () => resource().answeredQuestions(toValue(operationId), { page: toValue(page) }),
      enabled: computed(() => !!toValue(spaceId) && toValue(enabled)),
      placeholderData: keepPreviousData,
    })

  /** Results go to the caller, which keeps the text of answers that were stale. */
  const answerQuestionsMutation = useMutation({
    mutationFn: async ({ operationId, answers }: { operationId: string; answers: AiOperationAnswer[] }) =>
      (await resource().answerQuestions(operationId, answers)).data,
    onSuccess: async ({ operation }) => {
      await Promise.all([store(operation), queryClient.invalidateQueries({ queryKey: questionsKey(operation.id) })])
    },
    onError: (error: unknown, { operationId }) => {
      void refresh()
      void queryClient.invalidateQueries({ queryKey: questionsKey(operationId) })
      if (apiErrorReason(error) === 'concurrency_limit') toast.error(t('components.aiOperations.errors.concurrency'))
    },
  })

  const createMutation = useMutation({
    mutationFn: async (payload: StartAiOperationRequest) => (await resource().create(payload)).data,
    onSuccess: refresh,
  })

  const savePlanMutation = useMutation({
    mutationFn: async ({ operationId, plan }: { operationId: string; plan: AiOperationPlan }) =>
      (await resource().savePlan(operationId, plan)).data,
    onSuccess: store,
  })

  const planMutation = useMutation({
    mutationFn: async (operationId: string) => (await resource().plan(operationId)).data,
    onSuccess: refresh,
    onError: (error: unknown) =>
      apiErrorReason(error) === 'concurrency_limit'
        ? toast.error(t('components.aiOperations.errors.concurrency'))
        : toastError(t, 'components.aiOperations.errors.plan', error),
  })

  /**
   * Approval sends the hashes of the plan and scope preview on screen. A plan
   * changed since is refused with `plan_changed`; the refetch then shows the
   * current plan. `scope_changed` is left to the caller, which offers a recount.
   */
  const approveMutation = useMutation({
    mutationFn: async ({ operationId, approval }: { operationId: string; approval: AiOperationApproval }) =>
      (await resource().approve(operationId, approval)).data,
    onSuccess: store,
    onError: (error: unknown) => {
      void refresh()
      const reason = apiErrorReason(error)
      if (reason === 'scope_changed') return
      if (reason === 'plan_changed') toast.warning(t('components.aiOperations.errors.planChanged'))
      else if (reason === 'concurrency_limit') toast.error(t('components.aiOperations.errors.concurrency'))
      else toastError(t, 'components.aiOperations.errors.approve', error)
    },
  })

  /** Recount a draft's scope; a scope too large or empty now is left to the caller. */
  const refreshScopeMutation = useMutation({
    mutationFn: async (operationId: string) => (await resource().refreshScope(operationId)).data,
    onSuccess: store,
    onError: (error: unknown) => {
      if (!scopeTooLarge(error) && !scopeEmpty(error)) toastError(t, 'components.aiOperations.errors.recount', error)
    },
  })

  const steer = (action: 'pause' | 'resume' | 'cancel') =>
    useMutation({
      mutationFn: async (operationId: string) => (await resource()[action](operationId)).data,
      onSuccess: store,
      onError: (error: unknown) => {
        void refresh()
        const reason = apiErrorReason(error)
        if (reason === 'budget') toast.warning(t('components.aiOperations.errors.budgetSpent'))
        else if (reason === 'concurrency_limit') toast.error(t('components.aiOperations.errors.concurrency'))
        else toastError(t, `components.aiOperations.errors.${action}`, error)
      },
    })

  const pauseMutation = steer('pause')
  const resumeMutation = steer('resume')
  const cancelMutation = steer('cancel')

  const budgetMutation = useMutation({
    mutationFn: async ({ operationId, maxUsd }: { operationId: string; maxUsd: number }) =>
      (await resource().extendBudget(operationId, maxUsd)).data,
    onSuccess: store,
  })

  return {
    refresh,
    refreshReview,
    refreshLedger,
    useThreadOperationsQuery,
    usePlanRunQuery,
    useRunQuery,
    useItemsQuery,
    useArrivalsQuery,
    useQuestionsQuery,
    useQuestionMembersQuery,
    useAnsweredQuestionsQuery,
    answerQuestionsMutation,
    createMutation,
    savePlanMutation,
    planMutation,
    approveMutation,
    refreshScopeMutation,
    pauseMutation,
    resumeMutation,
    cancelMutation,
    budgetMutation,
  }
}
