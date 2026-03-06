<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'
import MigrationProgress from './MigrationProgress.vue'
import MigrationStatusBadge from './MigrationStatusBadge.vue'

const props = defineProps<{
  spaceId: string
}>()

const { $t } = useI18n()
const { alert } = useAlertDialog()
const { formatDateTime } = useFormat()
const route = useRoute()

const currentPage = ref(1)
const perPage = ref(24)

const queryParams = computed(() => ({
  page: currentPage.value,
  per_page: perPage.value,
}))

const { useMigrationsQuery, useMigrationQuery, useDeleteMigrationMutation } = useMigrations(
  props.spaceId
)
const { data: migrations, isLoading, refetch } = useMigrationsQuery(queryParams)
const { mutate: deleteMigration } = useDeleteMigrationMutation()

// Poll in-progress migrations
const inProgressIds = computed(() =>
  (migrations.value?.data || [])
    .filter((m) => m.state === 'pending' || m.state === 'processing')
    .map((m) => m.id)
)

watch(inProgressIds, (ids) => {
  if (ids.length > 0) {
    const interval = setInterval(async () => {
      await refetch()
      if (inProgressIds.value.length === 0) {
        clearInterval(interval)
      }
    }, 2000)
    onUnmounted(() => clearInterval(interval))
  }
})

const currentSpaceId = route.params.space as string

const scopeLabel = (scope: MigrationScope): string => {
  const parts: string[] = []
  if (scope.blocks) parts.push($t('labels.migrations.scope.blocks'))
  if (scope.block_templates) parts.push($t('labels.migrations.scope.block_templates'))
  if (scope.content) parts.push($t('labels.migrations.scope.content'))
  if (scope.assets) parts.push($t('labels.migrations.scope.assets'))
  if (scope.data_sources) parts.push($t('labels.migrations.scope.data_sources'))
  if (scope.redirects) parts.push($t('labels.migrations.scope.redirects'))
  return parts.join(', ') || '—'
}

const directionLabel = (migration: MigrationResource): string => {
  if (migration.source_space_id === currentSpaceId) {
    return `→ ${migration.target_space?.name ?? migration.target_space_id}`
  }
  return `← ${migration.source_space?.name ?? migration.source_space_id}`
}

const handleDelete = async (migration: MigrationResource) => {
  const confirmed = await alert.confirm($t('labels.migrations.deleteConfirmMessage'), {
    title: $t('labels.migrations.deleteConfirmTitle'),
    confirmLabel: $t('actions.migrations.delete'),
    cancelLabel: $t('alertDialog.cancel'),
    variant: 'destructive',
  })
  if (confirmed) {
    deleteMigration(migration.id)
  }
}
</script>

<template>
  <div class="space-y-2">
    <div class="overflow-hidden rounded-md border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{{ $t('labels.migrations.columns.direction') }}</TableHead>
            <TableHead>{{ $t('labels.migrations.columns.state') }}</TableHead>
            <TableHead>{{ $t('labels.migrations.columns.progress') }}</TableHead>
            <TableHead>{{ $t('labels.migrations.columns.scope') }}</TableHead>
            <TableHead>{{ $t('labels.migrations.columns.conflictStrategy') }}</TableHead>
            <TableHead>{{ $t('labels.migrations.columns.createdAt') }}</TableHead>
            <TableHead class="w-16" />
          </TableRow>
        </TableHeader>

        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="7"
          />
          <template v-else-if="migrations?.data?.length > 0">
            <TableRow
              v-for="migration in migrations.data"
              :key="migration.id"
            >
              <TableCell>
                <div class="flex flex-col gap-0.5">
                  <span class="font-medium text-sm">{{ directionLabel(migration) }}</span>
                  <span
                    v-if="migration.error_message && migration.state === 'failed'"
                    class="text-destructive text-xs"
                  >
                    {{ migration.error_message }}
                  </span>
                </div>
              </TableCell>
              <TableCell>
                <MigrationStatusBadge :state="migration.state" />
              </TableCell>
              <TableCell>
                <MigrationProgress
                  :progress="migration.progress"
                  :state="migration.state"
                />
              </TableCell>
              <TableCell>
                <span class="text-muted-foreground text-sm">{{ scopeLabel(migration.scope) }}</span>
              </TableCell>
              <TableCell>
                <Badge variant="outline">
                  {{ $t(`labels.migrations.conflictStrategies.${migration.conflict_strategy}`) }}
                </Badge>
              </TableCell>
              <TableCell>
                <div class="flex flex-col">
                  <span class="text-sm">{{ formatDateTime(migration.created_at) }}</span>
                  <span
                    v-if="migration.created_by"
                    class="text-muted-foreground text-xs"
                  >
                    {{ migration.created_by.display_name }}
                  </span>
                </div>
              </TableCell>
              <TableCell>
                <div class="flex justify-end">
                  <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                      <Button
                        variant="ghost"
                        size="icon"
                      >
                        <span class="sr-only">{{ $t('labels.migrations.openMenu') }}</span>
                        <Icon name="lucide:more-horizontal" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                      <DropdownMenuItem
                        class="text-destructive focus:text-destructive"
                        @click="handleDelete(migration)"
                      >
                        <Icon
                          name="lucide:trash-2"
                          class="mr-1"
                        />
                        {{ $t('actions.migrations.delete') }}
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              </TableCell>
            </TableRow>
          </template>
          <TableEmptyRow
            v-else
            :colspan="7"
            :label="$t('labels.migrations.noMigrations')"
          />
        </TableBody>
      </Table>
    </div>

    <TablePaginationFooter
      v-if="migrations?.meta"
      :meta="migrations.meta"
      :current-page="currentPage"
      :per-page="perPage"
      @update:current-page="(val) => (currentPage = val)"
      @update:per-page="(val) => (perPage = val)"
    />
  </div>
</template>
