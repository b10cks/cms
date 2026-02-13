<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import DataSourceDialog from '~/components/datasources/DataSourceDialog.vue'
import DataSourcesTable from '~/components/datasources/DataSourcesTable.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import { useAlertDialog } from '~/composables/useAlertDialog'
import type { DataSourceResource } from '~/types/data-sources'

const route = useRoute()
const spaceId = computed(() => route.params.space as string)

const createDialogOpen = ref(false)
const editingDataSource = ref<DataSourceResource | null>(null)

const { $t, t } = useI18n()
const { alert } = useAlertDialog()

useSeoMeta({
  title: computed(() => t('labels.datasets.title')),
})

const { useDeleteDataSourceMutation } = useDataSources(spaceId)
const { mutate: deleteDataSource } = useDeleteDataSourceMutation()

const handleEditDataSource = (dataSource: DataSourceResource) => {
  editingDataSource.value = dataSource
  createDialogOpen.value = true
}

const handleAddDataSource = () => {
  editingDataSource.value = null
  createDialogOpen.value = true
}
const closeDialog = () => {
  createDialogOpen.value = false
  editingDataSource.value = null
}

const handleDelete = async (dataSource: DataSourceResource) => {
  const confirmed = await alert.confirm(
    $t('labels.datasets.deleteConfirmMessage', { name: dataSource.name }),
    {
      title: $t('labels.datasets.deleteConfirmTitle'),
      confirmLabel: $t('actions.datasources.delete'),
      cancelLabel: $t('alertDialog.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    await deleteDataSource(dataSource.id)
  }
}
</script>

<template>
  <div class="w-full bg-background">
    <div class="content-grid">
      <ContentHeader
        :header="$t('labels.datasets.title')"
        :description="$t('labels.datasets.description')"
      >
        <template #actions>
          <Button
            variant="primary"
            @click="handleAddDataSource"
          >
            <Icon name="lucide:plus" />
            <span>{{ $t('actions.datasources.add') }}</span>
          </Button>
        </template>
      </ContentHeader>
      <div class="space-y-6">
        <DataSourcesTable
          :space-id="spaceId"
          @edit="handleEditDataSource"
          @delete="handleDelete"
        />
      </div>
      <DataSourceDialog
        v-model:open="createDialogOpen"
        :data-source="editingDataSource"
        @close="closeDialog"
      />
    </div>
  </div>
</template>
