<script setup lang="ts">
import AiOperationItemOutputs from '~/components/ai/operations/AiOperationItemOutputs.vue'
import AiOperationPager from '~/components/ai/operations/AiOperationPager.vue'
import { fieldLabelOf, useAssetFieldOptions, useMetaFieldOptions } from '~/components/ai/operations/useOperationFields'
import { useOperationI18n } from '~/components/ai/operations/useOperationI18n'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { SelectField } from '~/components/ui/form'
import { Skeleton } from '~/components/ui/skeleton'
import type { AiOperation, AiOperationItem, AiOperationItemStatus } from '~/lib/aiOperations'
import { AI_OPERATION_ITEM_STATUSES, outputRows, outputSummary } from '~/lib/aiOperations'

export interface AiLedgerFilter {
  status: AiOperationItemStatus | null
  reason: string | null
}

/**
 * Every frozen entry of an operation with its outcome, a page at a time and
 * filterable by status and reason. The run's own words show for entries it
 * reported, and a changed version since freezing is marked. An entry that owes
 * outputs says how they came out and opens into each one.
 */
const props = defineProps<{
  spaceId: string
  operation: AiOperation
}>()

const filter = defineModel<AiLedgerFilter>('filter', { required: true })

const { t } = useI18n()
const { t: tOp, reasonLabel, outcomeLabel } = useOperationI18n()
const { formatNumber } = useFormat()
const { useItemsQuery } = useAiOperations(() => props.spaceId)

const PER_PAGE = 25
const page = ref(1)
watch(filter, () => (page.value = 1), { deep: true })

const params = computed(() => ({
  status: filter.value.status ?? undefined,
  reason: filter.value.reason ?? undefined,
  page: page.value,
  per_page: PER_PAGE,
}))
const { data, isLoading, isFetching } = useItemsQuery(() => props.operation.id, params)

const STATUS_VARIANTS = {
  pending: 'surface',
  running: 'ai',
  written: 'success',
  proposed: 'success',
  conflict: 'warning',
  unchanged: 'secondary',
  skipped: 'surface',
  failed: 'destructive',
} as const satisfies Record<AiOperationItemStatus, string>

const statusOptions = computed(() => [
  { value: 'all', label: t('components.aiOperations.ledger.all', { count: formatNumber(props.operation.counts.total) }) },
  ...AI_OPERATION_ITEM_STATUSES.map((status) => ({
    value: status,
    label: `${t(`components.aiOperations.status.${status}`)} (${formatNumber(props.operation.progress.by_status[status])})`,
    disabled: props.operation.progress.by_status[status] === 0,
  })),
])

const setStatus = (value: string) => {
  const status = AI_OPERATION_ITEM_STATUSES.find((entry) => entry === value) ?? null
  filter.value = { status, reason: null }
}

const assetFields = props.operation.kind === 'assets' ? useAssetFieldOptions(props.spaceId, () => props.operation.scope) : shallowRef([])
const metaFields = useMetaFieldOptions(
  props.spaceId,
  () => Object.keys(props.operation.counts.groups),
  () => props.operation.approved_plan?.metadata !== undefined
)
const fieldLabel = (key: string) => fieldLabelOf([...assetFields.value, ...metaFields.value], key)

const rowsOf = (item: AiOperationItem) => outputRows(props.operation, item)

const summaryOf = (item: AiOperationItem) => {
  const summary = outputSummary(rowsOf(item))
  const counts = { done: formatNumber(summary.fulfilled), total: formatNumber(summary.total) }
  const others = summary.others.map(({ count, outcome }) => tOp('ledger.other', { count: formatNumber(count), outcome: outcomeLabel(outcome) })).join(', ')
  return others ? tOp('ledger.summaryWith', { ...counts, others }) : tOp('ledger.summary', counts)
}

const expanded = ref(new Set<string>())
const toggleItem = (id: string) => {
  const next = new Set(expanded.value)
  if (!next.delete(id)) next.add(id)
  expanded.value = next
}
</script>

<template>
  <section
    class="grid min-w-0 gap-3"
    :aria-label="$t('components.aiOperations.ledger.title')"
  >
    <div class="flex flex-wrap items-end gap-2">
      <SelectField
        id="ai-operation-ledger-status"
        name="ai-operation-ledger-status"
        class="w-56"
        :label="$t('components.aiOperations.ledger.status')"
        :model-value="filter.status ?? 'all'"
        :options="statusOptions"
        @update:model-value="setStatus(String($event))"
      />
      <Button
        v-if="filter.reason"
        size="sm"
        variant="ghost"
        @click="filter = { ...filter, reason: null }"
      >
        {{ reasonLabel(filter.reason) }}
        <Icon name="lucide:x" />
      </Button>
    </div>

    <ul
      class="grid min-w-0 divide-y divide-border rounded-lg border border-border"
      :class="isFetching && !isLoading && 'opacity-70'"
    >
      <template v-if="isLoading">
        <li
          v-for="index in 5"
          :key="index"
          class="px-3 py-2.5"
        >
          <Skeleton class="h-5 w-2/3" />
        </li>
      </template>
      <li
        v-else-if="!data?.data.length"
        class="px-3 py-2.5 text-sm text-muted"
      >
        {{ $t('components.aiOperations.ledger.empty') }}
      </li>
      <li
        v-for="item in data?.data ?? []"
        v-else
        :key="item.id"
        class="grid min-w-0 grid-cols-1 gap-0.5 px-3 py-2"
      >
        <div class="flex items-center gap-2">
          <span class="min-w-0 flex-1 truncate text-sm">{{ item.label }}</span>
          <Icon
            v-if="item.version_changed"
            name="lucide:git-compare"
            class="shrink-0 text-warning"
            :aria-label="$t('components.aiOperations.ledger.versionChanged')"
            :title="$t('components.aiOperations.ledger.versionChanged')"
          />
          <Badge
            :variant="STATUS_VARIANTS[item.status]"
            size="sm"
          >
            {{ $t(`components.aiOperations.status.${item.status}`) }}
          </Badge>
        </div>
        <p
          v-if="item.reason || item.note"
          class="text-sm text-muted"
        >
          {{ [item.reason ? reasonLabel(item.reason) : null, item.note].filter(Boolean).join(': ') }}
        </p>
        <template v-if="rowsOf(item).length > 0">
          <button
            type="button"
            class="flex w-fit max-w-full items-center gap-1 rounded-md text-left text-sm text-muted transition-colors hover:text-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-expanded="expanded.has(item.id)"
            data-output-summary
            @click="toggleItem(item.id)"
          >
            <span class="min-w-0">{{ summaryOf(item) }}</span>
            <Icon
              name="lucide:chevron-right"
              class="size-3.5 shrink-0 transition-transform"
              :class="expanded.has(item.id) && 'rotate-90'"
            />
          </button>
          <div
            v-if="expanded.has(item.id)"
            class="mt-1 ml-1.5 border-l border-border pl-3"
            :aria-label="tOp('ledger.outputs', { label: item.label })"
            role="group"
          >
            <AiOperationItemOutputs
              :space-id="spaceId"
              :rows="rowsOf(item)"
              :field-label="fieldLabel"
            />
          </div>
        </template>
      </li>
    </ul>

    <AiOperationPager
      v-if="data"
      v-model:page="page"
      :last-page="data.meta.last_page"
      :busy="isFetching"
    />
  </section>
</template>
