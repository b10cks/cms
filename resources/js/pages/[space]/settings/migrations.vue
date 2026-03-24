<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import CreateMigrationDialog from '~/components/migrations/CreateMigrationDialog.vue'
import MigrationsTable from '~/components/migrations/MigrationsTable.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = route.params.space as string
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId })))
const canManageMigrations = computed(() => access.hasAbility('migrations.manage'))


const { useCreateMigrationMutation } = useMigrations(spaceId)
const { mutate: createMigration, isPending: isCreating } = useCreateMigrationMutation()


useSeoMeta({
  title: computed(() => t('labels.settings.migrations.title')),
})


const isCreateDialogOpen = ref(false)


const handleCreate = (payload: CreateMigrationPayload) => {
  createMigration(payload)
  isCreateDialogOpen.value = false
}
</script>

<template>
  <div class="content-grid">
    <ContentHeader
      :header="$t('labels.migrations.title')"
      :description="$t('labels.migrations.description')"
    >
      <template #actions>
        <Button
          v-if="canManageMigrations"
          @click="isCreateDialogOpen = true"
        >
          <Icon name="lucide:plus" />
          {{ $t('actions.migrations.create') }}
        </Button>
      </template>
    </ContentHeader>

    <MigrationsTable :space-id="spaceId" />

    <CreateMigrationDialog
      v-if="canManageMigrations"
      v-model:open="isCreateDialogOpen"
      :space-id="spaceId"
      :loading="isCreating"
      @create="handleCreate"
    />
  </div>
</template>
