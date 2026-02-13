<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import BackupsTable from '~/components/backups/BackupsTable.vue'
import CreateBackupDialog from '~/components/backups/CreateBackupDialog.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = route.params.space as string

const { useBackupsQuery, useCreateBackupMutation } = useBackups(spaceId)
const { data: backups } = useBackupsQuery()
const { mutate: createBackup, isPending: isCreating } = useCreateBackupMutation()

useSeoMeta({
  title: computed(() => t('labels.settings.backups.title')),
})

const isCreateDialogOpen = ref(false)

const handleCreateBackup = (payload: CreateBackupPayload) => {
  createBackup(payload)
  isCreateDialogOpen.value = false
}
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.backups.title')"
      :description="$t('labels.backups.description')"
    >
      <template #actions>
        <Button @click="isCreateDialogOpen = true">
          <Icon name="lucide:plus" />
          {{ $t('actions.backups.create') }}
        </Button>
      </template>
    </ContentHeader>

    <BackupsTable :space-id="spaceId" />

    <CreateBackupDialog
      v-model:open="isCreateDialogOpen"
      :loading="isCreating"
      @create="handleCreateBackup"
    />
  </div>
</template>
