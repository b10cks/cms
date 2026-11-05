<script setup lang="ts">
import AiProviderRetryNotice from '~/components/ai/operations/AiProviderRetryNotice.vue'
import { useOperationI18n } from '~/components/ai/operations/useOperationI18n'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'
import { Progress } from '~/components/ui/progress'
import type { AiOperation, AiOperationItemStatus } from '~/lib/aiOperations'
import { operationOutcome, planErrors, progressPercent, settledCount } from '~/lib/aiOperations'
import { aiErrorMessage } from '~/lib/aiErrors'
import type { AiProviderRetryNotice as RetryNotice } from '~/lib/aiRun'
import { retryNoticeOfRun } from '~/lib/aiRun'
import { toastError } from '~/lib/toast-error'

/**
 * An approved operation's progress: the outcome in words, every frozen entry
 * accounted for by status, skipped and failed entries by reason, the shared
 * budget with any provider wait and unconfirmed calls, and pause, resume,
 * cancel and budget extension.
 */
const props = defineProps<{
  spaceId: string
  operation: AiOperation
}>()

const emit = defineEmits<{
  /** Open the ledger on these entries */
  inspect: [filter: { status: AiOperationItemStatus; reason?: string }]
}>()

const { t } = useI18n()
const { t: tOp, reasonLabel } = useOperationI18n()
const { formatCurrency, formatNumber } = useFormat()
const { alert } = useAlertDialog()
const { pauseMutation, resumeMutation, cancelMutation, budgetMutation, useRunQuery } = useAiOperations(() => props.spaceId)

const outcome = computed(() => operationOutcome(props.operation))
const status = computed(() => props.operation.progress.by_status)

const OUTCOME_STYLES = {
  draft: { icon: 'lucide:pencil', class: 'text-muted' },
  running: { icon: 'lucide:loader-circle', class: 'text-ai motion-safe:[&_svg]:animate-spin' },
  pausing: { icon: 'lucide:loader-circle', class: 'text-warning motion-safe:[&_svg]:animate-spin' },
  paused: { icon: 'lucide:circle-pause', class: 'text-warning' },
  completed: { icon: 'lucide:circle-check', class: 'text-success' },
  completed_with_exceptions: { icon: 'lucide:circle-alert', class: 'text-warning' },
  completed_with_gaps: { icon: 'lucide:circle-alert', class: 'text-warning' },
  cancelled: { icon: 'lucide:circle-x', class: 'text-muted' },
  cancelled_partial: { icon: 'lucide:circle-x', class: 'text-warning' },
} as const satisfies Record<ReturnType<typeof operationOutcome>, { icon: string; class: string }>

const outcomeLabel = computed(() => {
  const op = props.operation
  switch (outcome.value) {
    case 'running':
      return t('components.aiOperations.outcome.running', { batch: op.progress.batches })
    case 'paused':
      return t(`components.aiOperations.outcome.paused.${op.pause_reason ?? 'user'}`)
    case 'completed_with_exceptions':
      return t('components.aiOperations.outcome.completed_with_exceptions', {
        skipped: formatNumber(status.value.skipped),
        failed: formatNumber(status.value.failed),
      })
    case 'completed_with_gaps':
      return tOp('outcome.completedWithGaps')
    case 'cancelled_partial':
      return t('components.aiOperations.outcome.cancelled_partial', { count: formatNumber(op.progress.owed) }, op.progress.owed)
    default:
      return t(`components.aiOperations.outcome.${outcome.value}`)
  }
})

const FIGURES = ['written', 'proposed', 'conflict', 'unchanged', 'skipped', 'failed', 'pending'] as const satisfies readonly AiOperationItemStatus[]

const figureValue = (key: (typeof FIGURES)[number]) =>
  key === 'pending' ? props.operation.progress.owed : status.value[key]

/** Content writes and proposals are told apart, and a kind that has none of them needs no empty figure. */
const figures = computed(() => FIGURES.filter((key) => !['written', 'proposed', 'conflict'].includes(key) || figureValue(key) > 0))

const reasons = computed(() =>
  (['skipped', 'failed'] as const).flatMap((kind) =>
    Object.entries(props.operation.progress.reasons[kind] ?? {}).map(([reason, count]) => ({ kind, reason, count }))
  )
)

/** A stored error is `<reason>: <reference>`; known reasons have copy, others show as they are. */
const errorMessage = computed(() => {
  const error = props.operation.error
  return error ? aiErrorMessage(t, error.split(':')[0], error) : null
})

/** The running batch, for its provider wait; its unconfirmed calls join the operation's once it settles. */
const { data: currentRun } = useRunQuery(() => (props.operation.state === 'running' ? (props.operation.current_run?.id ?? null) : null))

const retryNotice = computed<RetryNotice | null>(() => {
  const { provider_retries: retries = 0, unconfirmed_provider_calls: unconfirmed = 0 } = props.operation.counts
  const run = currentRun.value && props.operation.state === 'running' ? retryNoticeOfRun(currentRun.value) : null
  if (!run && retries === 0 && unconfirmed === 0) return null

  return {
    retry_at: run?.retry_at ?? null,
    attempt: run?.attempt ?? 0,
    max_attempts: null,
    failure: run?.failure ?? null,
    unconfirmed_calls: unconfirmed + (run?.unconfirmed_calls ?? 0),
    retries: retries + (run?.retries ?? 0),
    cost_status: 'estimated',
  }
})

const budget = computed(() => props.operation.budget)
const budgetPercent = computed(() => (budget.value.max_usd > 0 ? (budget.value.spent_usd / budget.value.max_usd) * 100 : 0))
const budgetPaused = computed(() => props.operation.state === 'paused' && props.operation.pause_reason === 'budget')
const unfinished = computed(() => props.operation.state === 'running' || props.operation.state === 'paused')

const extending = ref(false)
const newBudget = ref<number | null>(null)
const budgetError = ref<string | null>(null)

watch(budgetPaused, (paused) => {
  if (paused) openExtend()
}, { immediate: true })

function openExtend() {
  extending.value = true
  budgetError.value = null
  const { max_usd: max, spent_usd: spent } = props.operation.budget
  newBudget.value = Math.min(1000, Math.ceil(Math.max(max * 1.5, spent + 1) * 10) / 10)
}

const extend = () => {
  if (newBudget.value === null) return
  budgetMutation.mutate(
    { operationId: props.operation.id, maxUsd: newBudget.value },
    {
      onSuccess: () => {
        extending.value = false
      },
      onError: (error: unknown) => {
        budgetError.value = planErrors(error).max_usd ?? null
        if (!budgetError.value) toastError(t, 'components.aiOperations.errors.budget', error)
      },
    }
  )
}

const canResume = computed(() => props.operation.state === 'paused' && !budgetPaused.value)
const canPause = computed(() => props.operation.state === 'running' && !props.operation.pause_requested)
const busy = computed(
  () => pauseMutation.isPending.value || resumeMutation.isPending.value || cancelMutation.isPending.value
)

const cancel = async () => {
  const owed = props.operation.progress.owed
  const confirmed = await alert.confirm(t('components.aiOperations.steer.cancelConfirm', { count: formatNumber(owed) }, owed), {
    title: t('components.aiOperations.steer.cancelTitle'),
    confirmLabel: t('components.aiOperations.steer.cancel'),
    variant: 'destructive',
  })
  if (confirmed) cancelMutation.mutate(props.operation.id)
}
</script>

<template>
  <div class="grid gap-4">
    <div class="grid gap-2">
      <p
        class="flex items-center gap-2 text-sm font-medium"
        :class="OUTCOME_STYLES[outcome].class"
        role="status"
      >
        <Icon :name="OUTCOME_STYLES[outcome].icon" />
        {{ outcomeLabel }}
      </p>
      <Progress
        :model-value="progressPercent(operation)"
        :aria-label="$t('components.aiOperations.progress.label')"
      />
      <p class="text-sm text-muted tabular-nums">
        {{ $t('components.aiOperations.progress.settled', { done: formatNumber(settledCount(operation)), total: formatNumber(operation.counts.total) }) }}
      </p>
      <p
        v-if="errorMessage"
        class="rounded-md bg-destructive-background px-3 py-2 text-sm text-destructive-foreground"
      >
        {{ errorMessage }}
      </p>
    </div>

    <dl class="grid grid-cols-3 gap-3 sm:grid-cols-5">
      <div
        v-for="key in figures"
        :key="key"
      >
        <dt class="text-sm text-muted">{{ $t(`components.aiOperations.status.${key}`) }}</dt>
        <dd>
          <button
            v-if="figureValue(key) > 0"
            type="button"
            class="font-semibold text-primary tabular-nums hover:underline"
            :aria-label="$t('components.aiOperations.progress.inspect', { status: $t(`components.aiOperations.status.${key}`) })"
            @click="emit('inspect', { status: key })"
          >
            {{ formatNumber(figureValue(key)) }}
          </button>
          <span
            v-else
            class="font-semibold text-muted tabular-nums"
          >
            0
          </span>
        </dd>
      </div>
    </dl>

    <ul
      v-if="reasons.length > 0"
      class="grid gap-1"
      :aria-label="$t('components.aiOperations.progress.reasons')"
    >
      <li
        v-for="row in reasons"
        :key="`${row.kind}:${row.reason}`"
      >
        <button
          type="button"
          class="flex w-full items-center gap-2 rounded-md px-2 py-1 text-left text-sm hover:bg-tree-hover"
          @click="emit('inspect', { status: row.kind, reason: row.reason })"
        >
          <Icon
            :name="row.kind === 'failed' ? 'lucide:circle-x' : 'lucide:circle-minus'"
            :class="row.kind === 'failed' ? 'text-destructive' : 'text-muted'"
          />
          <span class="min-w-0 flex-1 truncate">{{ reasonLabel(row.reason) }}</span>
          <span class="text-muted tabular-nums">{{ formatNumber(row.count) }}</span>
        </button>
      </li>
    </ul>

    <div class="grid gap-2">
      <div class="flex items-baseline justify-between gap-3 text-sm">
        <span class="text-muted">{{ $t('components.aiOperations.budget.title') }}</span>
        <span class="tabular-nums">
          {{ $t('components.aiOperations.budget.spent', { spent: formatCurrency(budget.spent_usd, 'USD'), max: formatCurrency(budget.max_usd, 'USD') }) }}
        </span>
      </div>
      <Progress
        :model-value="budgetPercent"
        :variant="budgetPercent >= 100 ? 'destructive' : budgetPercent >= 80 ? 'warning' : 'default'"
        :aria-label="$t('components.aiOperations.budget.title')"
      />
      <AiProviderRetryNotice
        v-if="retryNotice"
        :notice="retryNotice"
      />
      <p
        v-if="budgetPaused"
        class="text-sm text-warning"
      >
        {{ $t('components.aiOperations.budget.pausedExplain') }}
      </p>
      <div
        v-if="unfinished && extending"
        class="flex flex-wrap items-end gap-2"
      >
        <InputField
          id="ai-operation-extend"
          :model-value="newBudget ?? ''"
          name="ai-operation-extend"
          type="number"
          class="w-40"
          :label="$t('components.aiOperations.budget.newTotal')"
          :error="budgetError"
          @update:model-value="newBudget = $event === '' ? null : Number($event)"
        />
        <Button
          size="sm"
          variant="ai"
          :disabled="newBudget === null || newBudget <= budget.spent_usd"
          :loading="budgetMutation.isPending.value"
          @click="extend"
        >
          {{ budgetPaused ? $t('components.aiOperations.budget.extendAndResume') : $t('components.aiOperations.budget.extend') }}
        </Button>
        <Button
          v-if="!budgetPaused"
          size="sm"
          variant="ghost"
          @click="extending = false"
        >
          {{ $t('components.aiBulk.cancel') }}
        </Button>
      </div>
    </div>

    <div
      v-if="unfinished"
      class="flex flex-wrap gap-2"
    >
      <Button
        v-if="canPause"
        size="sm"
        variant="outline"
        :disabled="busy"
        :loading="pauseMutation.isPending.value"
        @click="pauseMutation.mutate(operation.id)"
      >
        <Icon name="lucide:pause" />
        {{ $t('components.aiOperations.steer.pause') }}
      </Button>
      <Button
        v-if="canResume"
        size="sm"
        variant="ai"
        :disabled="busy"
        :loading="resumeMutation.isPending.value"
        @click="resumeMutation.mutate(operation.id)"
      >
        <Icon name="lucide:play" />
        {{ $t('components.aiOperations.steer.resume') }}
      </Button>
      <Button
        v-if="!extending"
        size="sm"
        variant="ghost"
        @click="openExtend"
      >
        {{ $t('components.aiOperations.budget.extend') }}
      </Button>
      <Button
        size="sm"
        variant="ghost"
        class="text-destructive"
        :disabled="busy"
        @click="cancel"
      >
        {{ $t('components.aiOperations.steer.cancel') }}
      </Button>
    </div>
  </div>
</template>
