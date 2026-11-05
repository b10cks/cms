<script setup lang="ts">
import AiOperationPlanFields from '~/components/ai/operations/AiOperationPlanFields.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { InputField } from '~/components/ui/form'
import { Spinner } from '~/components/ui/spinner'
import type { AiOperation, AiScopeChanged, AiScopeTooLarge } from '~/lib/aiOperations'
import {
  editablePlan,
  planChanged,
  planErrors,
  planPayload,
  scopeChanged,
  scopeEmpty,
  scopeTooLarge,
  suggestedBudget,
} from '~/lib/aiOperations'
import type { AiRunRecord } from '~/lib/aiRun'
import { toastError } from '~/lib/toast-error'

/**
 * A draft operation: draft the plan with AI or by hand, edit it, save it and
 * approve it with a budget. Approval sends the hashes of the saved plan and
 * the scope preview on screen, so a plan that changed meanwhile is refused
 * and shown again. A scope that changed is refused too; the editor recounts
 * it, checks the new counts and approves again. Nothing approves on its own.
 */
const props = defineProps<{
  spaceId: string
  operation: AiOperation
  /** The planning run, while one is known */
  planRun: AiRunRecord | null
}>()

const { t } = useI18n()
const { formatCurrency, formatNumber } = useFormat()
const { savePlanMutation, planMutation, approveMutation, refreshScopeMutation, cancelMutation } = useAiOperations(
  () => props.spaceId
)

/** The server plan the draft started from; edits are measured against it. */
const base = shallowRef(props.operation.plan)
const draft = ref(editablePlan(base.value, props.operation))
const writing = ref(false)
const errors = ref<Record<string, string>>({})
/** The server plan moved on while the editor had unsaved edits. */
const stale = ref(false)

const dirty = computed(() => planChanged(props.operation.kind, draft.value, base.value))
const hasPlan = computed(() => props.operation.plan !== null)
const showEditor = computed(() => hasPlan.value || writing.value)

const reset = () => {
  base.value = props.operation.plan
  draft.value = editablePlan(base.value, props.operation)
  errors.value = {}
  stale.value = false
}

watch(
  () => props.operation.plan_hash,
  () => {
    if (dirty.value) stale.value = true
    else reset()
  }
)

const planning = computed(() => planMutation.isPending.value || props.planRun?.state === 'running')
const planAsks = computed(() => props.planRun?.state === 'awaiting_input')
const planFailed = computed(() => props.planRun?.state === 'failed' && !hasPlan.value)

const startPlanning = () => planMutation.mutate(props.operation.id)

const startWriting = () => {
  writing.value = true
  reset()
}

const save = () =>
  savePlanMutation.mutate(
    { operationId: props.operation.id, plan: planPayload(props.operation.kind, draft.value) },
    {
      onSuccess: (saved) => {
        base.value = saved.plan
        draft.value = editablePlan(saved.plan)
        errors.value = {}
        stale.value = false
      },
      onError: (error: unknown) => {
        errors.value = planErrors(error)
        if (Object.keys(errors.value).length === 0) toastError(t, 'components.aiOperations.errors.save', error)
      },
    }
  )

const budget = ref<number | null>(suggestedBudget(props.operation.counts.estimate))
const budgetValid = computed(() => budget.value !== null && budget.value >= 0.01 && budget.value <= 1000)

/** What stands between the scope preview and approval, from the last approve or recount. */
type ScopeProblem =
  | AiScopeChanged
  | AiScopeTooLarge
  | { reason: 'scope_empty' }
  | { reason: 'recounted'; before: number }
const scopeProblem = ref<ScopeProblem | null>(null)
const scopeBlocks = computed(() => scopeProblem.value !== null && scopeProblem.value.reason !== 'recounted')

const canApprove = computed(
  () =>
    hasPlan.value &&
    !dirty.value &&
    !stale.value &&
    budgetValid.value &&
    !scopeBlocks.value &&
    !!props.operation.plan_hash &&
    !!props.operation.counts.scope_hash &&
    !approveMutation.isPending.value
)

const approve = () => {
  const { plan_hash, counts } = props.operation
  if (!plan_hash || !counts.scope_hash || budget.value === null) return
  approveMutation.mutate(
    { operationId: props.operation.id, approval: { plan_hash, scope_hash: counts.scope_hash, max_usd: budget.value } },
    { onError: (error: unknown) => (scopeProblem.value = scopeChanged(error) ?? scopeProblem.value) }
  )
}

const recount = () => {
  const before = props.operation.counts.total
  refreshScopeMutation.mutate(props.operation.id, {
    onSuccess: () => (scopeProblem.value = { reason: 'recounted', before }),
    onError: (error: unknown) => {
      if (scopeEmpty(error)) scopeProblem.value = { reason: 'scope_empty' }
      else scopeProblem.value = scopeTooLarge(error) ?? scopeProblem.value
    },
  })
}

const cancel = () => cancelMutation.mutate(props.operation.id)
</script>

<template>
  <div class="grid gap-4">
    <div
      v-if="planning"
      class="flex items-center gap-2 text-sm text-muted"
      role="status"
    >
      <Spinner class="size-4" />
      {{ $t('components.aiOperations.draft.planning') }}
    </div>
    <p
      v-else-if="planAsks"
      class="rounded-md bg-info-background px-3 py-2 text-sm text-info-foreground"
    >
      {{ $t('components.aiOperations.draft.planAsks') }}
    </p>
    <p
      v-else-if="planFailed"
      class="rounded-md bg-destructive-background px-3 py-2 text-sm text-destructive-foreground"
    >
      {{ $t('components.aiOperations.draft.planFailed') }}
    </p>

    <div
      v-if="!showEditor && !planning"
      class="grid gap-3"
    >
      <p class="text-sm text-muted">{{ $t('components.aiOperations.draft.noPlan') }}</p>
      <div class="flex flex-wrap gap-2">
        <Button
          size="sm"
          variant="ai"
          :loading="planMutation.isPending.value"
          @click="startPlanning"
        >
          <Icon name="ai:bot-star" />
          {{ $t('components.aiOperations.draft.draftWithAi') }}
        </Button>
        <Button
          size="sm"
          variant="ghost"
          @click="startWriting"
        >
          {{ $t('components.aiOperations.draft.writeMyself') }}
        </Button>
      </div>
    </div>

    <template v-if="showEditor">
      <div
        v-if="stale"
        class="flex items-center justify-between gap-3 rounded-md bg-warning-background px-3 py-2 text-sm text-warning-foreground"
      >
        <span>{{ $t('components.aiOperations.draft.stale') }}</span>
        <Button
          size="xs"
          variant="ghost"
          @click="reset"
        >
          {{ $t('components.aiOperations.draft.loadPlan') }}
        </Button>
      </div>

      <AiOperationPlanFields
        v-model:plan="draft"
        :space-id="spaceId"
        :kind="operation.kind"
        :scope="operation.scope"
        :groups="Object.keys(operation.counts.groups)"
        :errors="errors"
      />

      <div
        v-if="dirty"
        class="flex flex-wrap items-center gap-2"
      >
        <Button
          size="sm"
          :loading="savePlanMutation.isPending.value"
          @click="save"
        >
          {{ $t('components.aiOperations.draft.save') }}
        </Button>
        <Button
          v-if="hasPlan"
          size="sm"
          variant="ghost"
          @click="reset"
        >
          {{ $t('components.aiOperations.draft.discard') }}
        </Button>
        <span class="text-sm text-muted">{{ $t('components.aiOperations.draft.saveFirst') }}</span>
      </div>

      <div
        v-if="hasPlan && !planning"
        class="flex justify-start"
      >
        <Button
          size="xs"
          variant="ghost"
          :loading="planMutation.isPending.value"
          @click="startPlanning"
        >
          <Icon name="ai:bot-star" />
          {{ $t('components.aiOperations.draft.redraft') }}
        </Button>
      </div>

      <section
        v-if="hasPlan"
        class="grid gap-3 border-t border-border pt-4"
        :aria-label="$t('components.aiOperations.draft.approval')"
      >
        <p class="text-sm text-muted">
          {{ $t('components.aiOperations.draft.approvalExplain', { estimate: formatCurrency(operation.counts.estimate.cost_usd, 'USD') }) }}
        </p>
        <div
          v-if="scopeProblem?.reason === 'scope_changed'"
          class="grid gap-2 rounded-md bg-warning-background px-3 py-2 text-sm text-warning-foreground"
          role="alert"
          data-scope-changed
        >
          <p>{{ $t('components.aiOperations.draft.scopeChanged', { added: formatNumber(scopeProblem.added), removed: formatNumber(scopeProblem.removed) }) }}</p>
          <Button
            size="sm"
            variant="outline"
            class="w-fit"
            :loading="refreshScopeMutation.isPending.value"
            @click="recount"
          >
            <Icon name="lucide:refresh-cw" />
            {{ $t('components.aiOperations.draft.recount') }}
          </Button>
        </div>
        <div
          v-else-if="scopeProblem?.reason === 'scope_too_large' || scopeProblem?.reason === 'scope_empty'"
          class="grid gap-2 rounded-md bg-destructive-background px-3 py-2 text-sm text-destructive-foreground"
          role="alert"
        >
          <p>
            {{
              scopeProblem.reason === 'scope_empty'
                ? $t('components.aiOperations.draft.scopeEmpty')
                : $t('components.aiOperations.draft.scopeTooLarge', { count: formatNumber(scopeProblem.count), limit: formatNumber(scopeProblem.limit) })
            }}
          </p>
          <Button
            size="sm"
            variant="outline"
            class="w-fit"
            :loading="cancelMutation.isPending.value"
            @click="cancel"
          >
            {{ $t('components.aiOperations.draft.cancel') }}
          </Button>
        </div>
        <p
          v-else-if="scopeProblem?.reason === 'recounted'"
          class="rounded-md bg-info-background px-3 py-2 text-sm text-info-foreground"
          role="status"
        >
          {{ $t('components.aiOperations.draft.recounted', { before: formatNumber(scopeProblem.before), count: formatNumber(operation.counts.total) }) }}
        </p>
        <p
          class="text-sm text-muted"
          data-writes-notice
        >
          {{ $t('components.aiOperations.draft.writesNotice') }}
        </p>
        <div class="flex flex-wrap items-end gap-3">
          <InputField
            id="ai-operation-budget"
            :model-value="budget ?? ''"
            name="ai-operation-budget"
            type="number"
            class="w-40"
            :label="$t('components.aiOperations.draft.budget')"
            :error="budget !== null && !budgetValid ? $t('components.aiOperations.draft.budgetRange') : null"
            @update:model-value="budget = $event === '' ? null : Number($event)"
          />
          <Button
            variant="ai"
            :disabled="!canApprove"
            :loading="approveMutation.isPending.value"
            @click="approve"
          >
            {{ $t('components.aiOperations.draft.approve', { count: formatNumber(operation.counts.supported) }) }}
          </Button>
        </div>
      </section>
    </template>
  </div>
</template>
