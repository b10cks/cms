<script setup lang="ts">
import AutomationActionDialog from '~/components/automation-actions/AutomationActionDialog.vue'
import AutomationActionsTable from '~/components/automation-actions/AutomationActionsTable.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const { alert } = useAlertDialog()
const { useAbility } = useAuthorization()

const spaceId = computed(() => route.params.space as string)
const canManageActions = useAbility(
  computed(() => 'automation_actions.manage'),
  computed(() => ({ space_id: spaceId.value }))
)

const {
  useAutomationActionsQuery,
  useAutomationActionQuery,
  useCreateAutomationActionMutation,
  useUpdateAutomationActionMutation,
  useDeleteAutomationActionMutation,
} = useAutomationActions(spaceId)
const { useAutomationTriggerCatalogQuery } = useAutomations(spaceId)

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

const { data: actionCollection, isLoading } = useAutomationActionsQuery(queryParams)
const { data: triggerCatalog } = useAutomationTriggerCatalogQuery()
const createMutation = useCreateAutomationActionMutation()
const updateMutation = useUpdateAutomationActionMutation()
const deleteMutation = useDeleteAutomationActionMutation()

const dialogOpen = ref(false)
const selectedActionId = ref<string | null>(null)
const selectedActionPreview = ref<AutomationActionResource | null>(null)
const togglePending = ref<Record<string, boolean>>({})

const { data: selectedActionDetail, isLoading: isLoadingSelectedAction } = useAutomationActionQuery(
  computed(() => selectedActionId.value || '')
)

const selectedAction = computed(() => selectedActionDetail.value || selectedActionPreview.value)

const isDialogLoading = computed(
  () =>
    createMutation.isPending.value ||
    updateMutation.isPending.value ||
    isLoadingSelectedAction.value
)

const handleDialogOpenChange = (value: boolean) => {
  dialogOpen.value = value

  if (!value) {
    selectedActionId.value = null
    selectedActionPreview.value = null
  }
}

const handleCreate = () => {
  selectedActionId.value = null
  selectedActionPreview.value = null
  dialogOpen.value = true
}

const handleEdit = (action: AutomationActionResource) => {
  selectedActionId.value = action.id
  selectedActionPreview.value = action
  dialogOpen.value = true
}

const handleSubmit = async (
  payload: CreateAutomationActionPayload | UpdateAutomationActionPayload
) => {
  try {
    if (selectedActionId.value) {
      await updateMutation.mutateAsync({
        id: selectedActionId.value,
        payload,
      })
    } else {
      await createMutation.mutateAsync(payload as CreateAutomationActionPayload)
    }

    handleDialogOpenChange(false)
  } catch (_) {
    // Toasts are handled in the composable.
  }
}

const handleDelete = async (action: AutomationActionResource) => {
  if ((action.automations_count ?? 0) > 0) {
    return
  }

  const confirmed = await alert.confirm(
    t('labels.automationActions.deleteConfirm.message', { name: action.name }),
    {
      title: t('labels.automationActions.deleteConfirm.title'),
      confirmLabel: t('labels.automationActions.deleteConfirm.confirmLabel'),
      cancelLabel: t('actions.cancel'),
      variant: 'destructive',
    }
  )

  if (!confirmed) {
    return
  }

  try {
    await deleteMutation.mutateAsync(action.id)
  } catch (_) {
    // Toasts are handled in the composable.
  }
}

const handleToggle = async (action: AutomationActionResource, value: boolean) => {
  togglePending.value = {
    ...togglePending.value,
    [action.id]: value,
  }

  try {
    await updateMutation.mutateAsync({
      id: action.id,
      payload: { is_active: value },
    })
  } catch (_) {
    // Toasts are handled in the composable.
  } finally {
    const { [action.id]: _, ...rest } = togglePending.value
    togglePending.value = rest
  }
}

useSeoMeta({
  title: computed(() => t('labels.automationActions.title')),
})
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.automationActions.title')"
      :description="$t('labels.automationActions.description')"
    >
      <template #actions>
        <Button
          v-if="canManageActions"
          @click="handleCreate"
        >
          <Icon name="lucide:plus" />
          {{ $t('actions.automationActions.create') }}
        </Button>
      </template>
    </ContentHeader>

    <AutomationActionsTable
      :actions="actionCollection?.data || []"
      :is-loading="isLoading"
      :meta="actionCollection?.meta"
      :current-page="currentPage"
      :per-page="perPage"
      :sort-by="sortBy"
      :can-manage="canManageActions"
      :toggle-pending="togglePending"
      @edit="handleEdit"
      @delete="handleDelete"
      @toggle="handleToggle"
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

    <AutomationActionDialog
      :open="dialogOpen"
      :action="selectedAction"
      :trigger-catalog="triggerCatalog"
      :loading="isDialogLoading"
      @update:open="handleDialogOpenChange"
      @submit="handleSubmit"
    />
  </div>
</template>
