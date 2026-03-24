<script setup lang="ts">
import BackupsTable from '~/components/backups/BackupsTable.vue'
import CreateBackupDialog from '~/components/backups/CreateBackupDialog.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = route.params.space as string
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId })))
const canManageBackups = computed(() => access.hasAbility('backups.manage'))


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
        <Button
          v-if="canManageBackups"
          @click="isCreateDialogOpen = true"
        >
          <Icon name="lucide:plus" />
          {{ $t('actions.backups.create') }}
        </Button>
      </template>
    </ContentHeader>

    <BackupsTable :space-id="spaceId" />

    <CreateBackupDialog
      v-if="canManageBackups"
      v-model:open="isCreateDialogOpen"
      :loading="isCreating"
      @create="handleCreateBackup"
    />
  </div>
</template>
