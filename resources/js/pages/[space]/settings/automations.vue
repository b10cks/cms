<script setup lang="ts">
import AutomationDialog from '~/components/automations/AutomationDialog.vue'
import AutomationsTable from '~/components/automations/AutomationsTable.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '~/components/ui/card'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const { alert } = useAlertDialog()
const { useAbility } = useAuthorization()

const spaceId = computed(() => route.params.space as string)

const canManageAutomations = useAbility(
  computed(() => 'automations.manage'),
  computed(() => ({ space_id: spaceId.value }))
)

const {
  useAutomationsQuery,
  useAutomationQuery,
  useAutomationTriggerCatalogQuery,
  useCreateAutomationMutation,
  useUpdateAutomationMutation,
  useDeleteAutomationMutation,
  useTriggerAutomationMutation,
} = useAutomations(spaceId)

const { useAutomationActionsQuery } = useAutomationActions(spaceId)

const currentPage = ref(1)
const perPage = ref(20)
const filters = ref<Record<string, unknown>>({})
const sortBy = ref<{
  column: string
  direction: 'asc' | 'desc'
}>({
  column: 'updated_at',
  direction: 'desc' as const,
})

const queryParams = computed(() => ({
  page: currentPage.value,
  per_page: perPage.value,
  sort: `${sortBy.value.direction === 'desc' ? '-' : ''}${sortBy.value.column}`,
  ...filters.value,
}))

const actionOptionsParams = computed(() => ({
  per_page: 500,
  sort: 'name',
}))

const { data: automationCollection, isLoading, isFetching } = useAutomationsQuery(queryParams)
const { data: triggerCatalog } = useAutomationTriggerCatalogQuery()
const { data: actionCollection, isLoading: isLoadingActions } =
  useAutomationActionsQuery(actionOptionsParams)

const createMutation = useCreateAutomationMutation()
const updateMutation = useUpdateAutomationMutation()
const deleteMutation = useDeleteAutomationMutation()
const triggerMutation = useTriggerAutomationMutation()

const dialogOpen = ref(false)
const selectedAutomationId = ref<string | null>(null)
const selectedAutomationPreview = ref<AutomationResource | null>(null)
const togglePending = ref<Record<string, boolean>>({})

const { data: selectedAutomationDetail, isLoading: isLoadingSelectedAutomation } =
  useAutomationQuery(computed(() => selectedAutomationId.value || ''))

const selectedAutomation = computed(
  () => selectedAutomationDetail.value || selectedAutomationPreview.value
)

const availableActions = computed(() => actionCollection.value?.data || [])
const hasActions = computed(() => availableActions.value.length > 0)
const isDialogLoading = computed(
  () =>
    createMutation.isPending.value ||
    updateMutation.isPending.value ||
    isLoadingActions.value ||
    isLoadingSelectedAutomation.value
)

const handleDialogOpenChange = (value: boolean) => {
  dialogOpen.value = value

  if (!value) {
    selectedAutomationId.value = null
    selectedAutomationPreview.value = null
  }
}

const handleCreate = () => {
  selectedAutomationId.value = null
  selectedAutomationPreview.value = null
  dialogOpen.value = true
}

const handleEdit = (automation: AutomationResource) => {
  selectedAutomationId.value = automation.id
  selectedAutomationPreview.value = automation
  dialogOpen.value = true
}

const handleSubmit = async (payload: CreateAutomationPayload | UpdateAutomationPayload) => {
  try {
    if (selectedAutomationId.value) {
      await updateMutation.mutateAsync({
        id: selectedAutomationId.value,
        payload,
      })
    } else {
      await createMutation.mutateAsync(payload as CreateAutomationPayload)
    }

    handleDialogOpenChange(false)
  } catch (_) {
    // Toasts are handled in the composable.
  }
}

const handleDelete = async (automation: AutomationResource) => {
  const confirmed = await alert.confirm(
    t('labels.automations.deleteConfirm.message', { name: automation.name }),
    {
      title: t('labels.automations.deleteConfirm.title'),
      confirmLabel: t('labels.automations.deleteConfirm.confirmLabel'),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )

  if (!confirmed) {
    return
  }

  try {
    await deleteMutation.mutateAsync(automation.id)
  } catch (_) {
    // Toasts are handled in the composable.
  }
}

const handleToggle = async (automation: AutomationResource, value: boolean) => {
  togglePending.value = {
    ...togglePending.value,
    [automation.id]: value,
  }

  try {
    await updateMutation.mutateAsync({
      id: automation.id,
      payload: { is_active: value },
    })
  } catch (_) {
    // Toasts are handled in the composable.
  } finally {
    const { [automation.id]: _, ...rest } = togglePending.value
    togglePending.value = rest
  }
}

const handleTrigger = async (automation: AutomationResource) => {
  try {
    await triggerMutation.mutateAsync({ id: automation.id })
  } catch (_) {
    // Toasts are handled in the composable.
  }
}

useSeoMeta({
  title: computed(() => t('labels.automations.title')),
})
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.automations.title')"
      :description="$t('labels.automations.description')"
    >
      <template #actions>
        <Button
          v-if="canManageAutomations"
          :disabled="!hasActions"
          @click="handleCreate"
        >
          <Icon name="lucide:plus" />
          {{ $t('actions.automations.create') }}
        </Button>
      </template>
    </ContentHeader>

    <Card
      v-if="!hasActions"
      class="border-dashed"
    >
      <CardHeader>
        <CardTitle>{{ $t('labels.automations.createPrerequisiteTitle') }}</CardTitle>
        <CardDescription>
          {{ $t('labels.automations.createPrerequisiteDescription') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Button
          v-if="canManageAutomations"
          as-child
          variant="outline"
        >
          <RouterLink :to="{ name: 'space-automation-actions', params: { space: spaceId } }">
            <Icon name="lucide:arrow-right" />
            {{ $t('labels.automations.createPrerequisiteCta') }}
          </RouterLink>
        </Button>
      </CardContent>
    </Card>

    <AutomationsTable
      :automations="automationCollection?.data || []"
      :trigger-catalog="triggerCatalog"
      :is-loading="isLoading"
      :is-fetching="isFetching"
      :meta="automationCollection?.meta"
      :current-page="currentPage"
      :per-page="perPage"
      :sort-by="sortBy"
      :can-manage="canManageAutomations"
      :toggle-pending="togglePending"
      @edit="handleEdit"
      @delete="handleDelete"
      @toggle="handleToggle"
      @trigger="handleTrigger"
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

    <AutomationDialog
      :open="dialogOpen"
      :automation="selectedAutomation"
      :actions="availableActions"
      :trigger-catalog="triggerCatalog"
      :loading="isDialogLoading"
      @update:open="handleDialogOpenChange"
      @submit="handleSubmit"
    />
  </div>
</template>
