<script setup lang="ts">
import AutomationExecutionSheet from '~/components/automations/AutomationExecutionSheet.vue'
import AutomationExecutionsTable from '~/components/automations/AutomationExecutionsTable.vue'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const { useAbility } = useAuthorization()

const spaceId = computed(() => route.params.space as string)

const canManageAutomations = useAbility(
  computed(() => 'automations.manage'),
  computed(() => ({ space_id: spaceId.value }))
)

const { useAutomationExecutionsQuery, useReplayAutomationExecutionMutation } =
  useAutomationExecutions(spaceId)
const { useAutomationsQuery } = useAutomations(spaceId)

const currentPage = ref(1)
const perPage = ref(20)
const filters = ref<Record<string, unknown>>({})
const sortBy = ref<{
  column: string
  direction: 'asc' | 'desc'
}>({
  column: 'created_at',
  direction: 'desc' as const,
})

const queryParams = computed(() => ({
  page: currentPage.value,
  per_page: perPage.value,
  sort: `${sortBy.value.direction === 'desc' ? '-' : ''}${sortBy.value.column}`,
  ...filters.value,
}))

const automationOptionsParams = computed(() => ({
  per_page: 500,
  sort: 'name',
}))

const { data: executionCollection, isLoading } = useAutomationExecutionsQuery(queryParams)
const { data: automationCollection } = useAutomationsQuery(automationOptionsParams)
const replayMutation = useReplayAutomationExecutionMutation()

const selectedExecution = ref<AutomationExecutionResource | null>(null)
const sheetOpen = ref(false)
const replayingId = ref<string | null>(null)

const handleView = (execution: AutomationExecutionResource) => {
  selectedExecution.value = execution
  sheetOpen.value = true
}

const handleSheetOpenChange = (value: boolean) => {
  sheetOpen.value = value

  if (!value) {
    selectedExecution.value = null
  }
}

const handleReplay = async (execution: AutomationExecutionResource) => {
  replayingId.value = execution.id

  try {
    await replayMutation.mutateAsync(execution.id)
  } catch (_) {
    // Toasts are handled in the composable.
  } finally {
    replayingId.value = null
  }
}

useSeoMeta({
  title: computed(() => t('labels.automationExecutions.title')),
})
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.automationExecutions.title')"
      :description="$t('labels.automationExecutions.description')"
    />

    <AutomationExecutionsTable
      :executions="executionCollection?.data || []"
      :automations="automationCollection?.data || []"
      :is-loading="isLoading"
      :meta="executionCollection?.meta"
      :current-page="currentPage"
      :per-page="perPage"
      :sort-by="sortBy"
      :can-manage="canManageAutomations"
      :replaying-id="replayingId"
      @view="handleView"
      @replay="handleReplay"
      @update:current-page="(value) => (currentPage = value)"
      @update:per-page="(value) => (perPage = value)"
      @update:sort-by="
        (value) => {
          sortBy = value
          currentPage = 1
        }
      "
      @update:filters="
        (value) => {
          filters = value
          currentPage = 1
        }
      "
    />

    <AutomationExecutionSheet
      :open="sheetOpen"
      :execution="selectedExecution"
      :can-manage="canManageAutomations"
      :replaying-id="replayingId"
      @update:open="handleSheetOpenChange"
      @replay="handleReplay"
    />
  </div>
</template>
