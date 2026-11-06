<script setup lang="ts">
import AiOperationPager from '~/components/ai/operations/AiOperationPager.vue'
import AiOperationQuestionGroup from '~/components/ai/operations/AiOperationQuestionGroup.vue'
import { useOperationI18n } from '~/components/ai/operations/useOperationI18n'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import type { AiOperation, AiOperationAnswer, AiOperationAnswerResult, AiOperationQuestion } from '~/lib/aiOperations'
import { apiErrorReason, questionAnswers } from '~/lib/aiOperations'
import { errorMessage } from '~/lib/toast-error'

/**
 * The questions an operation collected, grouped by what they ask. They can be
 * read as they come in and answered once the independent work has finished.
 * Answers resume only the outputs they name and spend from the same budget;
 * text whose question changed meanwhile stays here to be checked and resent.
 */
const props = defineProps<{
  spaceId: string
  operation: AiOperation
}>()

const { t } = useOperationI18n()
const { formatCurrency, formatDateTime, formatNumber } = useFormat()
const { useQuestionsQuery, useAnsweredQuestionsQuery, answerQuestionsMutation } = useAiOperations(() => props.spaceId)

/** Batches moving on or the state changing is what can change the questions. */
const revision = computed(() => `${props.operation.state}:${props.operation.progress.batches}`)
const page = ref(1)
const { data, isFetching } = useQuestionsQuery(
  () => props.operation.id,
  () => ({ page: page.value, revision: revision.value })
)
const summary = computed(() => data.value?.summary)
const accepting = computed(() => summary.value?.accepting ?? false)

/** Every group seen, so a draft on another page still goes out at the membership it was written for. */
const seen = ref<Record<string, AiOperationQuestion>>({})
watch(
  () => data.value?.data,
  (questions) => {
    for (const question of questions ?? []) seen.value[question.id] = question
  },
  { immediate: true }
)

const groupDrafts = ref<Record<string, string>>({})
const memberDrafts = ref<Record<string, Record<string, string>>>({})
const answers = computed(() => {
  const known = { ...seen.value, ...Object.fromEntries((data.value?.data ?? []).map((question) => [question.id, question])) }
  return questionAnswers(Object.values(known), groupDrafts.value, memberDrafts.value)
})

type Notice = 'stale' | 'duplicate' | 'closed' | 'failed'
const notices = ref<Notice[]>([])
const failure = ref('')

const remaining = computed(() => props.operation.budget.remaining_usd)

/** Answers that took effect leave their drafts; stale ones stay for the editor to check. */
const settle = (sent: AiOperationAnswer[], results: AiOperationAnswerResult[]) => {
  const stale = new Set<string>()
  sent.forEach((answer, index) => {
    const result = results[index]
    if (result.status === 'stale' && !answer.member_ids) stale.add(answer.question_id)
    if (result.status === 'stale') return
    if (answer.member_ids) {
      const drafts = { ...memberDrafts.value[answer.question_id] }
      for (const id of answer.member_ids) delete drafts[id]
      memberDrafts.value[answer.question_id] = drafts
    } else {
      delete groupDrafts.value[answer.question_id]
    }
    delete seen.value[answer.question_id]
  })
  for (const id of stale) delete seen.value[id]
  notices.value = [
    ...(results.some((result) => result.status === 'stale') ? (['stale'] as const) : []),
    ...(results.some((result) => result.status === 'duplicate') ? (['duplicate'] as const) : []),
  ]
}

const send = async () => {
  const sent = answers.value
  notices.value = []
  try {
    const { results } = await answerQuestionsMutation.mutateAsync({ operationId: props.operation.id, answers: sent })
    settle(sent, results)
    page.value = 1
  } catch (error) {
    failure.value = errorMessage(error)
    notices.value = [apiErrorReason(error) === 'questions_closed' ? 'closed' : 'failed']
  }
}

const showAnswered = ref(false)
const answeredPage = ref(1)
const { data: answered, isFetching: answeredFetching } = useAnsweredQuestionsQuery(
  () => props.operation.id,
  answeredPage,
  showAnswered
)

const visible = computed(() => (summary.value?.questions ?? 0) > 0 || (summary.value?.answered ?? 0) > 0)
</script>

<template>
  <section
    v-if="visible && summary"
    class="grid min-w-0 grid-cols-1 gap-4 border-t border-border px-4 py-4"
    :aria-label="t('questions.title')"
    data-operation-questions
  >
    <div
      v-if="summary.questions > 0"
      class="grid min-w-0 gap-1"
    >
      <h4 class="flex flex-wrap items-baseline justify-between gap-x-3 font-medium text-primary">
        {{ t('questions.title') }}
        <span class="text-sm font-normal text-muted tabular-nums">
          {{ t('questions.summary', { questions: formatNumber(summary.questions), outputs: formatNumber(summary.outputs) }, summary.questions) }}
        </span>
      </h4>
      <p
        class="text-sm text-muted"
        data-questions-state
      >
        {{ t(`questions.state.${accepting ? 'accepting' : operation.state === 'cancelled' ? 'cancelled' : operation.state === 'paused' ? 'paused' : 'running'}`) }}
      </p>
    </div>

    <p
      v-if="summary.resuming > 0"
      class="text-sm text-muted"
    >
      {{ t('questions.resuming', { count: formatNumber(summary.resuming) }, summary.resuming) }}
    </p>

    <ul
      v-if="data && data.data.length > 0"
      class="grid min-w-0 grid-cols-1 gap-5"
    >
      <AiOperationQuestionGroup
        v-for="question in data.data"
        :key="question.id"
        v-model:group-draft="groupDrafts[question.id]"
        v-model:member-drafts="memberDrafts[question.id]"
        :space-id="spaceId"
        :operation="operation"
        :question="question"
        :accepting="accepting"
        :revision="revision"
      />
    </ul>
    <AiOperationPager
      v-if="data"
      v-model:page="page"
      :last-page="data.meta.last_page"
      :busy="isFetching"
    />

    <div
      v-if="accepting && summary.questions > 0"
      class="grid min-w-0 gap-2"
    >
      <p class="text-sm text-muted">
        {{ remaining > 0 ? t('questions.bounds', { remaining: formatCurrency(remaining, 'USD') }) : t('questions.budgetSpent') }}
      </p>
      <div>
        <Button
          variant="ai"
          :disabled="answers.length === 0"
          :loading="answerQuestionsMutation.isPending.value"
          data-questions-send
          @click="send"
        >
          <Icon name="ai:bot" />
          {{ answers.length > 0 ? t('questions.send', { count: formatNumber(answers.length) }, answers.length) : t('questions.sendNone') }}
        </Button>
      </div>
    </div>

    <template v-if="notices.length > 0">
      <p
        v-for="notice in notices"
        :key="notice"
        class="text-sm"
        :class="notice === 'duplicate' ? 'text-muted' : 'text-warning'"
        role="status"
        data-questions-notice
      >
        {{ t(`questions.${notice}`, { error: failure }) }}
      </p>
    </template>

    <div
      v-if="summary.answered > 0"
      class="grid min-w-0 gap-2"
    >
      <div>
        <Button
          size="sm"
          variant="ghost"
          class="-ml-2"
          :aria-expanded="showAnswered"
          @click="showAnswered = !showAnswered"
        >
          <Icon :name="showAnswered ? 'lucide:chevron-down' : 'lucide:chevron-right'" />
          {{ t('questions.answered', { count: formatNumber(summary.answered) }) }}
        </Button>
      </div>
      <template v-if="showAnswered && answered">
        <ul class="grid min-w-0 grid-cols-1 gap-3 text-sm">
          <li
            v-for="row in answered.data"
            :key="`${row.answered_at}:${row.answer}`"
            class="grid min-w-0 gap-0.5"
            data-answered
          >
            <p class="break-words text-muted">{{ row.question ?? t('questions.noQuestion') }}</p>
            <p class="break-words whitespace-pre-line text-primary">{{ row.answer }}</p>
            <p class="text-muted tabular-nums">
              {{
                row.answered_by
                  ? t('questions.answeredBy', { name: row.answered_by.name, time: formatDateTime(row.answered_at) })
                  : t('questions.answeredAt', { time: formatDateTime(row.answered_at) })
              }},
              {{ t('questions.answeredOutputs', { count: formatNumber(row.outputs) }, row.outputs) }}
            </p>
          </li>
        </ul>
        <AiOperationPager
          v-model:page="answeredPage"
          :last-page="answered.meta.last_page"
          :busy="answeredFetching"
        />
      </template>
    </div>
  </section>
</template>
