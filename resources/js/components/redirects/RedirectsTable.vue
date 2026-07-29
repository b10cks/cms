<script setup lang="ts">
import type { RedirectsQueryParams } from '~/api/resources/redirects'
import RedirectsIcon from '~/assets/images/redirects.svg?component'
import Icon from '~/components/Icon.vue'
import type { FilterableField } from '~/components/SearchFilter.vue'
import SearchFilter from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { Checkbox } from '~/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import SortSelect from '~/components/ui/SortSelect.vue'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  TableSortableHead,
} from '~/components/ui/table'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'

import ExportRedirectsDialog from './ExportRedirectsDialog.vue'
import ImportRedirectsDialog from './ImportRedirectsDialog.vue'
import RedirectDialog from './RedirectDialog.vue'

const props = defineProps<{
  spaceId: string
}>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { useAccessControl } = useAuthorization()
const { formatDateTime } = useFormat()

const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageRedirects = computed(() => access.hasAbility('redirects.manage'))

const statusCodes = computed(() =>
  [301, 302, 303, 307, 308].map((code) => ({
    value: code,
    label: `${code} - ${getStatusCodeDescription(code)}`,
  }))
)

const redirectFilters = computed<FilterableField[]>(() => [
  {
    id: 'source',
    label: t('labels.redirects.columns.source') as string,
    operators: [
      { value: 'like', label: 'Contains' },
      { value: '^like', label: 'Starts with' },
      { value: 'like$', label: 'Ends with' },
      { value: 'eq', label: 'Equals' },
    ],
  },
  {
    id: 'target',
    label: t('labels.redirects.columns.target') as string,
    operators: [
      { value: 'like', label: 'Contains' },
      { value: '^like', label: 'Starts with' },
      { value: 'like$', label: 'Ends with' },
      { value: 'eq', label: 'Equals' },
    ],
  },
  {
    id: 'status_code',
    label: t('labels.redirects.columns.statusCode') as string,
    operators: [{ value: 'eq', label: 'Equals' }],
    items: statusCodes.value,
  },
])

const sortOptions = computed(() => [
  { value: 'source', label: t('labels.redirects.columns.source') as string },
  { value: 'target', label: t('labels.redirects.columns.target') as string },
  { value: 'status_code', label: t('labels.redirects.columns.statusCode') as string },
  { value: 'last_used_at', label: t('labels.redirects.columns.lastUsedAt') as string },
  { value: 'hits', label: t('labels.redirects.columns.hits') as string },
  { value: 'created_at', label: t('labels.redirects.columns.createdAt') as string },
  { value: 'updated_at', label: t('labels.redirects.columns.updatedAt') as string },
])

const filters = ref<Record<string, unknown>>({})
const currentPage = ref(1)
const perPage = ref(24)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})

const selectedRedirects = ref<Map<string, RedirectResource>>(new Map())
const redirectDialogOpen = ref(false)
const exportDialogOpen = ref(false)
const importDialogOpen = ref(false)
const redirectToEdit = ref<RedirectResource | null>(null)

const queryParams = computed<RedirectsQueryParams>(() => ({
  ...filters.value,
  sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
  page: currentPage.value,
  per_page: perPage.value,
}))

const tableColumnCount = computed(() => (canManageRedirects.value ? 8 : 7))

const {
  useRedirectsQuery,
  useCreateRedirectMutation,
  useUpdateRedirectMutation,
  useDeleteRedirectMutation,
  useResetRedirectStatsMutation,
} = useRedirects(props.spaceId)

const { data: redirects, isLoading, isFetching } = useRedirectsQuery(queryParams)
const createRedirectMutation = useCreateRedirectMutation()
const updateRedirectMutation = useUpdateRedirectMutation()
const deleteRedirectMutation = useDeleteRedirectMutation()
const resetRedirectStatsMutation = useResetRedirectStatsMutation()

const redirectRows = computed(() => redirects.value?.data ?? [])
const selectionCount = computed(() => selectedRedirects.value.size)
const isAllSelected = computed(() => {
  return selectionCount.value > 0 && redirectRows.value.length === selectionCount.value
})
const isRedirectDialogSubmitting = computed(
  () => createRedirectMutation.isPending.value || updateRedirectMutation.isPending.value
)

const clearSelection = () => {
  selectedRedirects.value.clear()
}

const handleSelectAll = (checked: boolean | 'indeterminate') => {
  if (checked === true) {
    redirectRows.value.forEach((redirect) => {
      selectedRedirects.value.set(redirect.id, redirect)
    })
    return
  }

  clearSelection()
}

const handleRedirectSelect = (redirect: RedirectResource, selected: boolean | 'indeterminate') => {
  if (selected === true) {
    selectedRedirects.value.set(redirect.id, redirect)
    return
  }

  selectedRedirects.value.delete(redirect.id)
}

const isRedirectSelected = (redirect: RedirectResource) => {
  return selectedRedirects.value.has(redirect.id)
}

const handleDelete = async (redirect: RedirectResource) => {
  const confirmed = await alert.confirm(
    t('labels.redirects.deleteConfirmMessage', { from: redirect.source }),
    {
      title: t('labels.redirects.deleteConfirmTitle'),
      confirmLabel: t('actions.redirects.delete'),
      cancelLabel: t('alertDialog.cancel'),
      variant: 'destructive',
    }
  )

  if (!confirmed) {
    return
  }

  await deleteRedirectMutation.mutateAsync(redirect.id)
}

const handleReset = async (redirect: RedirectResource) => {
  const confirmed = await alert.confirm(
    t('labels.redirects.resetConfirmMessage', { from: redirect.source }),
    {
      title: t('labels.redirects.resetConfirmTitle'),
      confirmLabel: t('actions.redirects.reset'),
      cancelLabel: t('alertDialog.cancel'),
    }
  )

  if (!confirmed) {
    return
  }

  await resetRedirectStatsMutation.mutateAsync(redirect.id)
}

const handleBulkDelete = async () => {
  const confirmed = await alert.confirm(
    t('labels.redirects.bulkDeleteConfirmMessage', { count: selectionCount.value }),
    {
      title: t('labels.redirects.bulkDeleteConfirmTitle'),
      confirmLabel: `${t('actions.redirects.delete')} (${selectionCount.value})`,
      cancelLabel: t('alertDialog.cancel'),
      variant: 'destructive',
    }
  )

  if (!confirmed) {
    return
  }

  for (const id of selectedRedirects.value.keys()) {
    await deleteRedirectMutation.mutateAsync(id)
  }

  clearSelection()
}

const handleBulkReset = async () => {
  const confirmed = await alert.confirm(
    t('labels.redirects.bulkResetConfirmMessage', { count: selectionCount.value }),
    {
      title: t('labels.redirects.bulkResetConfirmTitle'),
      confirmLabel: `${t('actions.redirects.reset')} (${selectionCount.value})`,
      cancelLabel: t('alertDialog.cancel'),
    }
  )

  if (!confirmed) {
    return
  }

  for (const id of selectedRedirects.value.keys()) {
    await resetRedirectStatsMutation.mutateAsync(id)
  }

  clearSelection()
}

const handleCreate = async (payload: CreateRedirectPayload) => {
  try {
    await createRedirectMutation.mutateAsync(payload)
    redirectDialogOpen.value = false
  } catch {
    // Shared mutation toasts already communicate the failure.
  }
}

const handleUpdate = async (id: string, payload: UpdateRedirectPayload) => {
  try {
    await updateRedirectMutation.mutateAsync({ id, payload })
    redirectDialogOpen.value = false
    redirectToEdit.value = null
  } catch {
    // Shared mutation toasts already communicate the failure.
  }
}

const openCreateDialog = () => {
  redirectToEdit.value = null
  redirectDialogOpen.value = true
}

const openEditDialog = (redirect: RedirectResource) => {
  redirectToEdit.value = redirect
  redirectDialogOpen.value = true
}

const handleRedirectDialogOpenChange = (value: boolean) => {
  redirectDialogOpen.value = value

  if (!value) {
    redirectToEdit.value = null
  }
}

const getStatusCodeDescription = (code: number): string => {
  return t(
    `labels.redirects.statusCodes.${[301, 302, 303, 307, 308].includes(code) ? code : 'unknown'}`
  ) as string
}

watch(
  () => currentPage.value,
  () => {
    clearSelection()
  }
)

defineExpose({
  openCreateDialog,
})
</script>

<template>
  <div class="space-y-2">
    <div class="ml-auto flex items-center gap-2">
      <SearchFilter
        v-model="filters"
        :filterable-fields="redirectFilters"
        class="lg:min-w-xs 2xl:min-w-md"
      />
      <SortSelect
        v-model="sortBy"
        :options="sortOptions"
        :label="$t('labels.sortBy')"
        :placeholder="$t('labels.sortBy')"
      />
    </div>

    <div
      v-if="canManageRedirects && selectionCount > 0"
      class="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4"
    >
      <Badge variant="secondary">
        {{ $t('labels.selectionCount', { count: selectionCount }) }}
      </Badge>

      <div class="flex flex-wrap items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          @click="handleBulkReset"
        >
          <Icon name="lucide:rotate-ccw" />
          {{ $t('actions.redirects.reset') }}
        </Button>
        <Button
          variant="destructive"
          size="sm"
          @click="handleBulkDelete"
        >
          <Icon name="lucide:trash-2" />
          {{ $t('actions.deleteSelected') }}
        </Button>
        <Button
          variant="outline"
          size="sm"
          @click="clearSelection"
        >
          <Icon name="lucide:x" />
          {{ $t('actions.clear') }}
        </Button>
      </div>
    </div>

    <div class="overflow-hidden rounded-md border border-input">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead
              v-if="canManageRedirects"
              class="w-8"
            >
              <Checkbox
                :model-value="isAllSelected"
                aria-label="Select all redirects"
                @update:model-value="handleSelectAll"
              />
            </TableHead>
            <TableSortableHead
              v-model="sortBy"
              column="source"
            >
              {{ $t('labels.redirects.columns.source') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="target"
            >
              {{ $t('labels.redirects.columns.target') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="status_code"
            >
              {{ $t('labels.redirects.columns.statusCode') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="hits"
              wrap-class="flex justify-end items-center gap-1"
            >
              {{ $t('labels.redirects.columns.hits') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="last_used_at"
            >
              {{ $t('labels.redirects.columns.lastUsedAt') }}
            </TableSortableHead>
            <TableSortableHead
              v-model="sortBy"
              column="created_at"
            >
              {{ $t('labels.redirects.columns.createdAt') }}
            </TableSortableHead>
            <TableHead class="w-24" />
          </TableRow>
        </TableHeader>

        <TableBody
          :class="
            isFetching && !isLoading
              ? 'opacity-50 transition-opacity duration-200'
              : 'transition-opacity duration-200'
          "
        >
          <TableLoadingRow
            v-if="isLoading"
            :colspan="tableColumnCount"
          />

          <template v-else-if="redirectRows.length > 0">
            <TableRow
              v-for="redirect in redirectRows"
              :key="redirect.id"
              class="hover:bg-muted/50"
              :data-state="isRedirectSelected(redirect) ? 'selected' : undefined"
            >
              <TableCell v-if="canManageRedirects">
                <Checkbox
                  :model-value="isRedirectSelected(redirect)"
                  :aria-label="`Select redirect ${redirect.source}`"
                  @update:model-value="(checked) => handleRedirectSelect(redirect, checked)"
                />
              </TableCell>

              <TableCell>
                <span class="font-medium">{{ redirect.source }}</span>
              </TableCell>

              <TableCell>{{ redirect.target }}</TableCell>

              <TableCell>
                <div class="flex flex-col">
                  <span>{{ redirect.status_code }}</span>
                  <span class="text-xs text-muted-foreground">
                    {{ getStatusCodeDescription(redirect.status_code) }}
                  </span>
                </div>
              </TableCell>

              <TableCell class="text-right">{{ redirect.hits }}</TableCell>

              <TableCell>
                {{
                  redirect.last_used_at
                    ? formatDateTime(redirect.last_used_at)
                    : $t('labels.redirects.neverUsed')
                }}
              </TableCell>

              <TableCell>
                {{ redirect.created_at ? formatDateTime(redirect.created_at) : '' }}
              </TableCell>

              <TableCell>
                <div
                  v-if="canManageRedirects"
                  class="flex items-center justify-end gap-1"
                >
                  <Button
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8"
                    @click="openEditDialog(redirect)"
                  >
                    <Icon name="lucide:pencil" />
                    <span class="sr-only">{{ $t('actions.edit') }}</span>
                  </Button>

                  <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                      <Button
                        variant="ghost"
                        size="icon"
                      >
                        <span class="sr-only">{{ $t('labels.redirects.openMenu') }}</span>
                        <Icon name="lucide:more-horizontal" />
                      </Button>
                    </DropdownMenuTrigger>

                    <DropdownMenuContent align="end">
                      <DropdownMenuItem @click="openEditDialog(redirect)">
                        <Icon
                          name="lucide:pencil"
                          class="mr-2"
                        />
                        {{ $t('actions.edit') }}
                      </DropdownMenuItem>
                      <DropdownMenuItem @click="handleReset(redirect)">
                        <Icon
                          name="lucide:refresh-cw"
                          class="mr-2"
                        />
                        {{ $t('actions.redirects.reset') }}
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem
                        class="text-destructive focus:text-destructive"
                        @click="handleDelete(redirect)"
                      >
                        <Icon
                          name="lucide:trash-2"
                          class="mr-2"
                        />
                        {{ $t('actions.redirects.delete') }}
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              </TableCell>
            </TableRow>
          </template>

          <TableEmptyRow
            v-else
            :colspan="tableColumnCount"
            :icon="RedirectsIcon"
            :label="$t('labels.redirects.noRedirects')"
          />
        </TableBody>
      </Table>
    </div>

    <TablePaginationFooter
      v-if="redirects?.meta"
      :meta="redirects.meta"
      :current-page="currentPage"
      :per-page="perPage"
      @update:current-page="(value) => (currentPage = value)"
      @update:per-page="(value) => (perPage = value)"
    />

    <RedirectDialog
      :open="redirectDialogOpen"
      :loading="isRedirectDialogSubmitting"
      :redirect-to-edit="redirectToEdit"
      @update:open="handleRedirectDialogOpenChange"
      @create="handleCreate"
      @update="handleUpdate"
    />

    <ExportRedirectsDialog
      v-model:open="exportDialogOpen"
      :space-id="spaceId"
      :filters="filters"
    />

    <ImportRedirectsDialog
      v-if="canManageRedirects"
      v-model:open="importDialogOpen"
      :space-id="spaceId"
    />

    <Teleport
      defer
      to="#appHeaderActions"
    >
      <div class="flex gap-2">
        <Button
          v-if="canManageRedirects"
          @click="importDialogOpen = true"
        >
          <Icon name="lucide:upload" />
          {{ $t('labels.assets.import') }}
        </Button>
        <Button @click="exportDialogOpen = true">
          <Icon name="lucide:download" />
          {{ $t('labels.assets.export') }}
        </Button>
      </div>
    </Teleport>
  </div>
</template>
