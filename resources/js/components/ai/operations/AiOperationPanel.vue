<script setup lang="ts">
import AiOperationDraft from '~/components/ai/operations/AiOperationDraft.vue'
import type { AiLedgerFilter } from '~/components/ai/operations/AiOperationLedger.vue'
import AiOperationLedger from '~/components/ai/operations/AiOperationLedger.vue'
import AiOperationPlanFields from '~/components/ai/operations/AiOperationPlanFields.vue'
import AiOperationProgress from '~/components/ai/operations/AiOperationProgress.vue'
import AiOperationQuestions from '~/components/ai/operations/AiOperationQuestions.vue'
import AiOperationReceipt from '~/components/ai/operations/AiOperationReceipt.vue'
import AiOperationReview from '~/components/ai/threads/AiOperationReview.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import type { AiChangeset, AiChangesetItem } from '~/lib/aiChangesets'
import type { AiOperation, AiOperationItemStatus } from '~/lib/aiOperations'
import { editablePlan } from '~/lib/aiOperations'
import { itemLocales } from '~/lib/aiThreads'
import { languageName } from '~/lib/languageNames'

/**
 * An AI operation inside its thread: the frozen scope with its counts and
 * estimate, then the draft plan and approval, or once approved the live
 * progress, what was delivered, the questions it collected, the approved
 * plan, the ledger, new arrivals and what changed.
 * What changed is the operation's changeset: from its planning turn, or the
 * operation's own changeset summary when no planning run made a turn.
 */
const props = defineProps<{
  spaceId: string
  operation: AiOperation
  /** The operation's changeset from its thread turn, listed here */
  changeset: (AiChangeset & { operation_id: string }) | null
}>()

const { locale } = useI18n()
const { formatCurrency, formatNumber } = useFormat()
const { usePlanRunQuery, useArrivalsQuery, refresh, refreshReview, refreshLedger } = useAiOperations(() => props.spaceId)

const reviewChangeset = computed(() => props.changeset ?? props.operation.changeset)
const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(() => props.spaceId)

const isDraft = computed(() => props.operation.state === 'draft')
const { data: planRun } = usePlanRunQuery(() => (isDraft.value ? props.operation.plan_run_id : null))

watch(
  () => planRun.value?.state,
  (state, previous) => {
    if (previous === 'running' && state !== 'running') void refresh()
  }
)

const GROUPS_SHOWN = 5
const groups = computed(() =>
  Object.entries(props.operation.counts.groups ?? {})
    .sort(([, a], [, b]) => b - a)
)

const scopeLine = computed(() => {
  const scope = props.operation.scope
  const mode = scope.recursive ? 'recursive' : 'flat'
  return scope.root_id ? `folder.${mode}` : `selection.${mode}`
})

const showPlan = ref(false)
const approvedPlan = computed(() => editablePlan(props.operation.approved_plan))

const showLedger = ref(false)
const ledgerFilter = ref<AiLedgerFilter>({ status: null, reason: null })
const inspect = (filter: { status: AiOperationItemStatus; reason?: string }) => {
  ledgerFilter.value = { status: filter.status, reason: filter.reason ?? null }
  showLedger.value = true
}

const checkArrivals = ref(false)
const { data: arrivals, isFetching: arrivalsLoading, refetch: refetchArrivals } = useArrivalsQuery(
  () => props.operation.id,
  checkArrivals
)
const findArrivals = () => {
  if (checkArrivals.value) void refetchArrivals()
  else checkArrivals.value = true
}

const locales = (item: AiChangesetItem) => {
  const settings = space.value?.settings
  const found = itemLocales(item, settings?.default_language ?? 'en')
  if (!found) return null
  return {
    source: languageName(found.source, settings?.languages, locale.value),
    target: languageName(found.target, settings?.languages, locale.value),
  }
}

/**
 * Polling and broadcasts refetch the operation only. The ledger follows its
 * progress; the review follows new proposals and decided changes, a queued
 * group decision included.
 */
watch(
  () => JSON.stringify([props.operation.progress.by_status, props.operation.progress.batches]),
  () => void refreshLedger(props.operation.id)
)
watch(
  () => [
    props.operation.progress.by_status.proposed,
    reviewChangeset.value?.item_count ?? 0,
    reviewChangeset.value?.pending_count ?? 0,
  ],
  (counts, previous) => {
    if (counts.some((count, index) => count !== previous[index])) void refreshReview(props.operation.id)
  }
)
</script>

<template>
  <section
    class="grid min-w-0 rounded-xl border border-border bg-card text-card-foreground shadow-soft-sm"
    data-operation
  >
    <header class="grid gap-1 px-4 pt-4 pb-3">
      <div class="flex items-center gap-2">
        <Icon
          name="ai:bot"
          class="shrink-0 text-ai"
        />
        <h3 class="min-w-0 flex-1 truncate font-semibold text-primary">
          {{ $t(`components.aiOperations.kinds.${operation.kind}.title`) }}
        </h3>
      </div>
      <p class="line-clamp-3 text-sm whitespace-pre-line text-muted">{{ operation.prompt }}</p>
    </header>

    <div class="grid gap-3 border-t border-border px-4 py-3">
      <dl class="grid grid-cols-3 gap-3">
        <div>
          <dt class="text-sm text-muted">{{ $t('components.aiOperations.counts.total') }}</dt>
          <dd class="font-semibold text-primary tabular-nums">{{ formatNumber(operation.counts.total) }}</dd>
        </div>
        <div>
          <dt class="text-sm text-muted">{{ $t('components.aiOperations.counts.supported') }}</dt>
          <dd class="font-semibold text-primary tabular-nums">{{ formatNumber(operation.counts.supported) }}</dd>
        </div>
        <div>
          <dt class="text-sm text-muted">
            {{ isDraft ? $t('components.aiOperations.counts.estimate') : $t('components.aiOperations.counts.spent') }}
          </dt>
          <dd class="font-semibold text-primary tabular-nums">
            {{ formatCurrency(isDraft ? operation.counts.estimate.cost_usd : operation.budget.spent_usd, 'USD') }}
          </dd>
        </div>
      </dl>
      <p class="text-sm text-muted">{{ $t(`components.aiOperations.scope.${scopeLine}`) }}</p>
      <p
        v-if="operation.counts.unsupported > 0"
        class="text-sm text-warning"
      >
        {{ $t('components.aiOperations.counts.unsupported', { count: formatNumber(operation.counts.unsupported) }, operation.counts.unsupported) }}
      </p>
      <ul
        v-if="groups.length > 1"
        class="grid gap-0.5 text-sm"
        :aria-label="$t('components.aiOperations.counts.groups')"
      >
        <li
          v-for="[group, count] in groups.slice(0, GROUPS_SHOWN)"
          :key="group"
          class="flex items-center gap-2"
        >
          <span class="min-w-0 flex-1 truncate">{{ group }}</span>
          <span class="text-muted tabular-nums">{{ formatNumber(count) }}</span>
        </li>
        <li
          v-if="groups.length > GROUPS_SHOWN"
          class="text-muted"
        >
          {{ $t('components.aiOperations.counts.moreGroups', { count: groups.length - GROUPS_SHOWN }, groups.length - GROUPS_SHOWN) }}
        </li>
      </ul>
    </div>

    <div
      v-if="isDraft"
      class="border-t border-border px-4 py-4"
    >
      <AiOperationDraft
        :space-id="spaceId"
        :operation="operation"
        :plan-run="planRun ?? null"
      />
    </div>

    <template v-else>
      <div class="border-t border-border px-4 py-4">
        <AiOperationProgress
          :space-id="spaceId"
          :operation="operation"
          @inspect="inspect"
        />
      </div>

      <div class="border-t border-border px-4 py-4">
        <AiOperationReceipt
          :space-id="spaceId"
          :operation="operation"
          :changeset="reviewChangeset"
        />
      </div>

      <AiOperationQuestions
        :space-id="spaceId"
        :operation="operation"
      />

      <div class="grid min-w-0 border-t border-border px-2 py-1">
        <Button
          size="sm"
          variant="ghost"
          class="justify-start"
          :aria-expanded="showPlan"
          @click="showPlan = !showPlan"
        >
          <Icon :name="showPlan ? 'lucide:chevron-down' : 'lucide:chevron-right'" />
          {{ $t('components.aiOperations.sections.plan') }}
        </Button>
        <div
          v-if="showPlan && operation.approved_plan"
          class="px-2 pt-2 pb-3"
        >
          <AiOperationPlanFields
            :plan="approvedPlan"
            :space-id="spaceId"
            :kind="operation.kind"
            :scope="operation.scope"
            :groups="Object.keys(operation.counts.groups)"
            readonly
          />
        </div>

        <Button
          size="sm"
          variant="ghost"
          class="justify-start"
          :aria-expanded="showLedger"
          @click="showLedger = !showLedger"
        >
          <Icon :name="showLedger ? 'lucide:chevron-down' : 'lucide:chevron-right'" />
          {{ $t('components.aiOperations.sections.ledger', { count: formatNumber(operation.counts.total) }) }}
        </Button>
        <div
          v-if="showLedger"
          class="min-w-0 px-2 pt-2 pb-3"
        >
          <AiOperationLedger
            v-model:filter="ledgerFilter"
            :space-id="spaceId"
            :operation="operation"
          />
        </div>

        <div class="flex flex-wrap items-center gap-2 py-1">
          <Button
            size="sm"
            variant="ghost"
            :loading="arrivalsLoading"
            @click="findArrivals"
          >
            <Icon name="lucide:search" />
            {{ $t('components.aiOperations.arrivals.check') }}
          </Button>
          <span
            v-if="arrivals && !arrivalsLoading"
            class="text-sm text-muted"
            role="status"
          >
            {{
              arrivals.count > 0
                ? $t('components.aiOperations.arrivals.found', { count: formatNumber(arrivals.count) }, arrivals.count)
                : $t('components.aiOperations.arrivals.none')
            }}
          </span>
        </div>
        <ul
          v-if="arrivals && arrivals.count > 0"
          class="grid gap-0.5 px-3 pb-3 text-sm"
        >
          <li
            v-for="entry in arrivals.entries"
            :key="entry.id"
            class="truncate"
          >
            {{ entry.name }}
          </li>
          <li
            v-if="arrivals.count > arrivals.entries.length"
            class="text-muted"
          >
            {{ $t('components.aiBulk.more', { count: formatNumber(arrivals.count - arrivals.entries.length) }) }}
          </li>
        </ul>
      </div>

      <div
        v-if="reviewChangeset"
        class="grid min-w-0 gap-3 border-t border-border px-4 py-4"
      >
        <h4 class="text-sm text-muted">{{ $t('components.aiOperations.review.title') }}</h4>
        <AiOperationReview
          :space-id="spaceId"
          :changeset="reviewChangeset"
          :locales="locales"
        />
      </div>
      <p
        v-else-if="operation.state === 'running'"
        class="border-t border-border px-4 py-3 text-sm text-muted"
      >
        {{ $t('components.aiOperations.review.arriving') }}
      </p>
    </template>
  </section>
</template>
