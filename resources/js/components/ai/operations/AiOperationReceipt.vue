<script setup lang="ts">
import { useOperationI18n } from '~/components/ai/operations/useOperationI18n'
import Icon from '~/components/Icon.vue'
import type { AiChangeset } from '~/lib/aiChangesets'
import type { AiOperation, AiOutputTone } from '~/lib/aiOperations'
import { operationCoverage } from '~/lib/aiOperations'
import { changesLine } from '~/lib/aiChangesets'

/**
 * What the operation delivered. Coverage says whether the requested outputs
 * were fulfilled, which finishing the queue does not. A plan naming no outputs
 * says so instead of claiming more. One line says what happened to content:
 * entries changed in their drafts, conflicts and flagged wording, the only things
 * that need a person. Proposals for assets and data entries wait for one.
 */
const props = defineProps<{
  spaceId: string
  operation: AiOperation
  changeset: (AiChangeset & { operation_id: string }) | null
}>()

const { t, outcomeLabel } = useOperationI18n()
const { formatNumber } = useFormat()
const { useOperationReviewQuery, useOperationChangesQuery } = useAiThreads(() => props.spaceId)
const { data: reviewGroups } = useOperationReviewQuery(() => (props.changeset ? props.operation.id : ''))
const { data: changes } = useOperationChangesQuery(() => (props.changeset ? props.operation.id : ''), 1, 1)

const coverage = computed(() => operationCoverage(props.operation))

const headline = computed(() => {
  switch (coverage.value.state) {
    case 'complete':
      return t('coverage.complete')
    case 'incomplete':
      return t(`coverage.incomplete.${props.operation.state === 'completed' || props.operation.state === 'cancelled' ? props.operation.state : 'paused'}`)
    case 'counting':
      return t('coverage.counting')
    default:
      return t('coverage.unverified')
  }
})

const STYLES = {
  complete: { icon: 'lucide:circle-check', class: 'text-success' },
  incomplete: { icon: 'lucide:circle-alert', class: 'text-warning' },
  counting: { icon: 'lucide:circle-dashed', class: 'text-muted' },
  unverified: { icon: 'lucide:circle-help', class: 'text-muted' },
} as const

const TONE_ICONS = {
  fulfilled: 'lucide:check',
  open: 'lucide:circle-dashed',
  exception: 'lucide:circle-alert',
} as const satisfies Record<AiOutputTone, string>

const summary = computed(() => (props.changeset ? (changes.value?.summary ?? null) : null))
const openProposals = computed(() => (reviewGroups.value ?? []).reduce((sum, group) => sum + group.counts.pending + group.counts.conflict, 0))

const { t: globalT } = useI18n()
const happened = computed(() => (summary.value ? changesLine(globalT, summary.value, formatNumber) : null))
</script>

<template>
  <section
    class="grid gap-3"
    :aria-label="t('coverage.title')"
    data-operation-receipt
  >
    <div class="grid gap-1">
      <p
        class="flex items-center gap-2 text-sm font-medium"
        :class="STYLES[coverage.state].class"
        data-coverage-state
      >
        <Icon :name="STYLES[coverage.state].icon" />
        {{ headline }}
      </p>
      <p
        v-if="coverage.state === 'unverified'"
        class="text-sm text-muted"
      >
        {{ t('coverage.unverifiedExplain') }}
      </p>
      <p
        v-else-if="coverage.state === 'incomplete'"
        class="text-sm text-muted"
      >
        {{ t(`coverage.incompleteExplain.${operation.state === 'completed' ? 'completed' : 'other'}`) }}
      </p>
    </div>

    <ul
      v-if="coverage.outputs.length > 0"
      class="grid gap-0.5 text-sm"
      :aria-label="t('coverage.title')"
    >
      <li
        v-for="row in coverage.outputs"
        :key="row.outcome"
        class="flex items-center gap-2"
      >
        <Icon
          :name="TONE_ICONS[row.tone]"
          :class="row.tone === 'exception' ? 'text-warning' : 'text-muted'"
        />
        <span class="min-w-0 flex-1 truncate">{{ outcomeLabel(row.outcome) }}</span>
        <span class="text-muted tabular-nums">{{ formatNumber(row.count) }}</span>
      </li>
    </ul>

    <p
      v-if="coverage.skipped + coverage.failed > 0"
      class="text-sm text-muted"
    >
      {{ t('coverage.entries', { skipped: formatNumber(coverage.skipped), failed: formatNumber(coverage.failed) }) }}
    </p>

    <p
      v-if="coverage.questions > 0 && operation.state !== 'cancelled'"
      class="rounded-md bg-info-background px-3 py-2 text-sm text-info-foreground"
      data-coverage-questions
    >
      {{ t('coverage.questions', { count: formatNumber(coverage.questions) }, coverage.questions) }}
    </p>

    <template v-if="happened">
      <p
        class="font-semibold text-primary tabular-nums"
        data-changes-happened
      >
        {{ happened }}
      </p>
      <p
        v-if="openProposals > 0"
        class="text-sm text-muted"
        data-proposals-waiting
      >
        {{ t('coverage.proposalsWaiting', { count: formatNumber(openProposals) }, openProposals) }}
      </p>
      <p class="text-sm text-muted">{{ t('coverage.publishing') }}</p>
    </template>
  </section>
</template>
