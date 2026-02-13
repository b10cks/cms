<script setup lang="ts">
import Icon from '~/components/Icon.vue'

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
import { Input } from '~/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  TableSortableHead,
} from '~/components/ui/table'
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'

import type { RedirectsQueryParams } from '~/api/resources/redirects'
import RedirectsIcon from '~/assets/images/redirects.svg?component'
import SortSelect from '~/components/ui/SortSelect.vue'
import TableEmptyRow from '~/components/ui/TableEmptyRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'

const { $t } = useI18n()
const { alert } = useAlertDialog()

const statusCodes = computed(() =>
  [301, 302, 303, 307, 308].map((code) => {
    return {
      value: code,
      label: `${code} - ${getStatusCodeDescription(code)}`,
    }
  })
)

const redirectFilters = computed(() => [
  {
    id: 'source',
    label: 'Source Path',
    operators: [
      { value: 'like', label: 'Contains' },
      { value: '^like', label: 'Starts with' },
      { value: 'like$', label: 'Ends with' },
      { value: 'eq', label: 'Equals' },
    ],
  },
  {
    id: 'target',
    label: 'Target Path',
    operators: [
      { value: 'like', label: 'Contains' },
      { value: '^like', label: 'Starts with' },
      { value: 'like$', label: 'Ends with' },
      { value: 'eq', label: 'Equals' },
    ],
  },
  {
    id: 'status_code',
    label: 'Status Code',
    operators: [{ value: 'eq', label: 'Equals' }],
    items: statusCodes.value,
  },
])

const sortOptions = [
  { value: 'source', label: $t('labels.redirects.columns.source') },
  { value: 'target', label: $t('labels.redirects.columns.target') },
  { value: 'status_code', label: $t('labels.redirects.columns.statusCode') },
  { value: 'last_used_at', label: $t('labels.redirects.columns.lastUsedAt') },
  { value: 'hits', label: $t('labels.redirects.columns.hits') },
  { value: 'created_at', label: $t('labels.redirects.columns.createdAt') },
  { value: 'updated_at', label: $t('labels.redirects.columns.updatedAt') },
]

const filters = ref<Record<string, unknown>>({})
const searchQuery = ref('')
const currentPage = ref(1)
const perPage = ref(24)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'created_at',
  direction: 'desc',
})

const selectedRedirects = ref<Map<string, RedirectResource>>(new Map())

const queryParams = computed<RedirectsQueryParams>(() => {
  return {
    ...filters.value,
    sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
    page: currentPage.value,
    per_page: perPage.value,
  }
})

const props = defineProps<{
  spaceId: string
}>()

const {
  useRedirectsQuery,
  useDeleteRedirectMutation,
  useUpdateRedirectMutation,
  useResetRedirectStatsMutation,
} = useRedirects(props.spaceId)
const { data: redirects, isLoading } = useRedirectsQuery(queryParams)
const { mutate: updateRedirect } = useUpdateRedirectMutation()
const { mutate: resetRedirectStats } = useResetRedirectStatsMutation()
const { mutate: deleteRedirect } = useDeleteRedirectMutation()

const { formatDateTime } = useFormat()

const editingState = reactive<
  Record<
    string,
    {
      isEditing: boolean
      source: string
      target: string
      status_code: number
    }
  >
>({})

const handleDelete = async (redirect: RedirectResource) => {
  const confirmed = await alert.confirm(
    $t('labels.redirects.deleteConfirmMessage', { from: redirect.source }),
    {
      title: $t('labels.redirects.deleteConfirmTitle'),
      confirmLabel: $t('actions.redirects.delete'),
      cancelLabel: $t('alertDialog.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    await deleteRedirect(redirect.id)
  }
}

const handleReset = async (redirect: RedirectResource) => {
  const confirmed = await alert.confirm(
    $t('labels.redirects.resetConfirmMessage', { from: redirect.source }),
    {
      title: $t('labels.redirects.resetConfirmTitle'),
      confirmLabel: $t('actions.redirects.reset'),
      cancelLabel: $t('alertDialog.cancel'),
    }
  )
  if (confirmed) {
    await resetRedirectStats(redirect.id)
  }
}

const handleBulkDelete = async () => {
  const confirmed = await alert.confirm(
    $t('labels.redirects.bulkDeleteConfirmMessage', { count: selectionCount.value }),
    {
      title: $t('labels.redirects.bulkDeleteConfirmTitle'),
      confirmLabel: `${$t('actions.redirects.delete')} (${selectionCount.value})`,
      cancelLabel: $t('alertDialog.cancel'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    const selectedIds = Array.from(selectedRedirects.value.keys())
    for (const id of selectedIds) {
      await deleteRedirect(id)
    }
    clearSelection()
  }
}

const handleBulkReset = async () => {
  const confirmed = await alert.confirm(
    $t('labels.redirects.bulkResetConfirmMessage', { count: selectionCount.value }),
    {
      title: $t('labels.redirects.bulkResetConfirmTitle'),
      confirmLabel: `${$t('actions.redirects.reset')} (${selectionCount.value})`,
      cancelLabel: $t('alertDialog.cancel'),
    }
  )
  if (confirmed) {
    const selectedIds = Array.from(selectedRedirects.value.keys())
    for (const id of selectedIds) {
      await resetRedirectStats(id)
    }
    clearSelection()
  }
}

const sortedRedirects = computed(() => {
  return redirects.value?.data || []
})

const selectionCount = computed(() => selectedRedirects.value.size)
const isAllSelected = computed(() => {
  return selectionCount.value > 0 && sortedRedirects.value.length === selectionCount.value
})
const handleSelectAll = (checked: boolean) => {
  if (checked) {
    sortedRedirects.value.forEach((redirect) => {
      selectedRedirects.value.set(redirect.id, redirect)
    })
  } else {
    clearSelection()
  }
}

const handleRedirectSelect = (redirect: RedirectResource, selected: boolean) => {
  if (selected) {
    selectedRedirects.value.set(redirect.id, redirect)
  } else {
    selectedRedirects.value.delete(redirect.id)
  }
}

const clearSelection = () => {
  selectedRedirects.value.clear()
}

const isRedirectSelected = (redirect: RedirectResource) => {
  return selectedRedirects.value.has(redirect.id)
}

const startEditing = (
  redirect: RedirectResource,
  field: 'source' | 'target' | 'status_code' = 'source'
) => {
  if (!editingState[redirect.id]) {
    editingState[redirect.id] = {
      isEditing: false,
      source: redirect.source,
      target: redirect.target,
      status_code: redirect.status_code,
    }
  }
  editingState[redirect.id].isEditing = true
}

const saveEdits = async (redirect: RedirectResource) => {
  const currentState = editingState[redirect.id]
  if (!currentState) return

  const payload: UpdateRedirectPayload = {
    source: editingState[redirect.id].source,
    target: editingState[redirect.id].target,
    status_code: editingState[redirect.id].status_code,
  }

  await updateRedirect({
    id: redirect.id,
    payload,
  })

  currentState.isEditing = false
}

const cancelEditing = (redirect: RedirectResource) => {
  if (editingState[redirect.id]) {
    editingState[redirect.id].source = redirect.source
    editingState[redirect.id].target = redirect.target
    editingState[redirect.id].status_code = redirect.status_code
    editingState[redirect.id].isEditing = false
  }
}

const navigateToRow = (
  direction: 'up' | 'down',
  currentRedirect: RedirectResource,
  field: 'source' | 'target' | 'status_code'
) => {
  saveEdits(currentRedirect)

  const currentIndex = sortedRedirects.value.findIndex((r) => r.id === currentRedirect.id)
  if (currentIndex === -1) return

  let nextIndex = direction === 'up' ? currentIndex - 1 : currentIndex + 1

  if (nextIndex < 0) {
    nextIndex = sortedRedirects.value.length - 1
  } else if (nextIndex >= sortedRedirects.value.length) {
    nextIndex = 0
  }

  const nextRedirect = sortedRedirects.value[nextIndex]
  if (nextRedirect) {
    startEditing(nextRedirect, field)
  }
}

const handleKeyDown = (event: KeyboardEvent, redirect: RedirectResource) => {
  const currentState = editingState[redirect.id]
  if (!currentState || !currentState.isEditing) return

  if (event.key === 'Escape') {
    event.preventDefault()
    cancelEditing(redirect)
  } else if (event.key === 'Enter') {
    event.preventDefault()
    saveEdits(redirect)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    navigateToRow('up', redirect, 'source')
  } else if (event.key === 'ArrowDown') {
    event.preventDefault()
    navigateToRow('down', redirect, 'source')
  }
}

watch(
  () => redirects.value,
  (newRedirects) => {
    if (!newRedirects?.data) return

    newRedirects.data.forEach((redirect) => {
      if (editingState[redirect.id] && !editingState[redirect.id].isEditing) {
        editingState[redirect.id].source = redirect.source
        editingState[redirect.id].target = redirect.target
        editingState[redirect.id].status_code = redirect.status_code
      }
    })
  },
  { deep: true }
)

const getStatusCodeDescription = (code: number): string => {
  return $t(
    `labels.redirects.statusCodes.${[301, 302, 303, 307, 308].includes(code) ? code : 'unknown'}`
  ) as string
}

watch(
  () => currentPage.value,
  () => {
    clearSelection()
  }
)
</script>

<template>
  <div class="space-y-2">
    <div class="space-y-4">
      <div class="ml-auto flex items-center gap-2">
        <SearchFilter
          v-model="filters"
          :filterable-fields="redirectFilters"
          class="lg:min-w-xs 2xl:min-w-md"
          @search="searchQuery = $event"
          @reset="searchQuery = ''"
        />
        <SortSelect
          v-model="sortBy"
          :options="sortOptions"
          :label="$t('labels.sortBy')"
          :placeholder="$t('labels.sortBy')"
        />
      </div>
    </div>
    <div
      v-if="selectionCount > 0"
      class="flex items-center justify-between gap-4 rounded-lg border border-border bg-surface p-4"
    >
      <div class="flex items-center gap-2">
        <Badge variant="secondary">{{
          $t('labels.selectionCount', { count: selectionCount })
        }}</Badge>
      </div>
      <div class="flex items-center gap-2">
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
            <TableHead class="w-8">
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

        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="8"
          />
          <template v-else-if="redirects?.data?.length > 0">
            <TableRow
              v-for="redirect in redirects.data"
              :key="redirect.id"
              class="hover:bg-muted/50"
              :data-state="isRedirectSelected(redirect) ? 'selected' : undefined"
            >
              <TableCell>
                <Checkbox
                  :model-value="isRedirectSelected(redirect)"
                  :aria-label="`Select redirect ${redirect.source}`"
                  @update:model-value="(checked) => handleRedirectSelect(redirect, checked)"
                />
              </TableCell>
              <TableCell @dblclick="startEditing(redirect, 'source')">
                <template v-if="editingState[redirect.id]?.isEditing">
                  <Input
                    v-model="editingState[redirect.id].source"
                    aria-label="Edit from path"
                    class="w-full"
                    @keydown="handleKeyDown($event, redirect)"
                  />
                </template>
                <template v-else>
                  <span class="font-medium">{{ redirect.source }}</span>
                </template>
              </TableCell>

              <TableCell @dblclick="startEditing(redirect, 'target')">
                <template v-if="editingState[redirect.id]?.isEditing">
                  <Input
                    v-model="editingState[redirect.id].target"
                    aria-label="Edit to path"
                    class="w-full"
                    @keydown="handleKeyDown($event, redirect)"
                  />
                </template>
                <template v-else>
                  {{ redirect.target }}
                </template>
              </TableCell>

              <TableCell @dblclick="startEditing(redirect, 'status_code')">
                <template v-if="editingState[redirect.id]?.isEditing">
                  <Select
                    v-model="editingState[redirect.id].status_code"
                    @keydown="handleKeyDown($event, redirect)"
                  >
                    <SelectTrigger class="w-full">
                      <SelectValue
                        :placeholder="$t('labels.redirects.selectStatusCode') as string"
                      />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem
                        v-for="code in statusCodes"
                        :key="code.value"
                        :value="code.value"
                      >
                        {{ code.label }}
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </template>
                <template v-else>
                  <div class="flex flex-col">
                    <span>{{ redirect.status_code }}</span>
                    <span class="text-xs text-muted">{{
                      getStatusCodeDescription(redirect.status_code)
                    }}</span>
                  </div>
                </template>
              </TableCell>
              <TableCell class="text-right">{{ redirect.hits }}</TableCell>
              <TableCell>{{
                redirect.last_used_at ? formatDateTime(redirect.last_used_at) : 'Never'
              }}</TableCell>
              <TableCell>{{
                redirect.created_at ? formatDateTime(redirect.created_at) : ''
              }}</TableCell>
              <TableCell>
                <div
                  v-if="editingState[redirect.id]?.isEditing"
                  class="flex space-x-1"
                >
                  <Button
                    variant="ghost"
                    size="icon"
                    @click="saveEdits(redirect)"
                  >
                    <Icon
                      name="lucide:check"
                      class="text-success"
                    />
                    <span class="sr-only">Save</span>
                  </Button>
                  <Button
                    variant="ghost"
                    size="icon"
                    @click="cancelEditing(redirect)"
                  >
                    <Icon
                      name="lucide:x"
                      class="text-destructive"
                    />
                    <span class="sr-only">Cancel</span>
                  </Button>
                </div>
                <div
                  v-else
                  class="flex space-x-1"
                >
                  <Button
                    variant="ghost"
                    size="icon"
                    class="h-8 w-8"
                    @click="startEditing(redirect, 'source')"
                  >
                    <Icon name="lucide:pencil" />
                    <span class="sr-only">Edit</span>
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
                      <DropdownMenuItem @click="handleReset(redirect)">
                        <Icon
                          name="lucide:refresh-cw"
                          class="mr-1"
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
                          class="mr-1"
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
            :colspan="8"
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
      @update:current-page="(val) => (currentPage = val)"
      @update:per-page="(val) => (perPage = val)"
    />
  </div>
</template>
