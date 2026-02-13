<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import ReleasesIcon from '~/assets/images/releases.svg?component'
import ReleaseBadge from '~/components/releases/ReleaseBadge.vue'
import SearchFilter from '~/components/SearchFilter.vue'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
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
import type { Release } from '~/types/releases'

const { $t } = useI18n()

const spaceId = inject<string>('spaceId') || ''
const { getReleaseState, useReleasesQuery } = useReleases(spaceId)

const props = defineProps<{
  isLoading?: boolean
  onEdit?: (release: Release) => void
  onDelete?: (release: Release) => void
  onCommit?: (release: Release) => void
  onCancel?: (release: Release) => void
}>()

const emit = defineEmits<{
  edit: [release: Release]
  delete: [release: Release]
  commit: [release: Release]
  cancel: [release: Release]
}>()

const releaseStates = computed(() => [
  { value: 'draft', label: $t('labels.releases.states.draft') },
  { value: 'scheduled', label: $t('labels.releases.states.scheduled') },
  { value: 'published', label: $t('labels.releases.states.published') },
])

const releaseFilters = computed(() => [
  {
    id: 'name',
    label: $t('labels.releases.fields.name'),
    operators: [
      { value: 'like', label: 'Contains' },
      { value: '^like', label: 'Starts with' },
      { value: 'like$', label: 'Ends with' },
      { value: 'eq', label: 'Equals' },
    ],
  },
  {
    id: 'scheduled',
    label: $t('labels.releases.fields.publishAt'),
    items: [
      { value: 'true', label: 'Yes' },
      { value: 'false', label: 'No' },
    ],
  },
  {
    id: 'state',
    label: $t('labels.releases.fields.state'),
    operators: [{ value: 'eq', label: 'Equals' }],
    items: releaseStates.value,
  },
])

const sortOptions = [
  { value: 'name', label: 'Name' },
  { value: 'publish_at', label: $t('labels.releases.fields.publishAt') },
  { value: 'created_at', label: $t('labels.releases.fields.createdAt') },
  { value: 'updated_at', label: $t('labels.releases.fields.updatedAt') },
]

const filters = ref<Record<string, unknown>>({})
const searchQuery = ref('')
const currentPage = ref(1)
const perPage = ref(20)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'publish_at',
  direction: 'desc',
})

const queryParams = computed(() => ({
  ...filters.value,
  page: currentPage.value,
  per_page: perPage.value,
  sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
  direction: sortBy.value.direction,
}))

const { data: queryData, isLoading: isLoadingReleases } = useReleasesQuery(queryParams)

const releases = computed(() => queryData.value?.data || [])
const paginationMeta = computed(() => queryData.value?.meta)

const isLoading = computed(() => isLoadingReleases.value || props.isLoading)

const getVersionsLabel = (count: number) => {
  return `${count} ${count === 1 ? 'version' : 'versions'}`
}

const handleEditClick = (release: Release) => {
  emit('edit', release)
  props.onEdit?.(release)
}

const handleDelete = (release: Release) => {
  emit('delete', release)
  props.onDelete?.(release)
}

const handleCommit = (release: Release) => {
  emit('commit', release)
  props.onCommit?.(release)
}

const handleCancel = (release: Release) => {
  emit('cancel', release)
  props.onCancel?.(release)
}

const formatDate = (dateStr: string | null) => {
  if (!dateStr) {
    return 'Not scheduled'
  }
  return new Date(dateStr).toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const isDraft = (release: Release) => getReleaseState(release) === 'draft'
const isScheduled = (release: Release) => getReleaseState(release) === 'scheduled'
const isPublished = (release: Release) => getReleaseState(release) === 'published'

const handleSort = (column: string) => {
  if (sortBy.value.column === column) {
    sortBy.value.direction = sortBy.value.direction === 'asc' ? 'desc' : 'asc'
  } else {
    sortBy.value.column = column
    sortBy.value.direction = 'asc'
  }
  currentPage.value = 1
}
</script>

<template>
  <div class="space-y-2">
    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
      <div class="flex flex-1 flex-col gap-2 md:flex-row md:items-center">
        <SearchFilter
          v-model="filters"
          :filterable-fields="releaseFilters"
          class="flex-1"
          @search="
            (query) => {
              searchQuery = query
              currentPage = 1
            }
          "
          @reset="
            () => {
              searchQuery = ''
              currentPage = 1
            }
          "
        />
        <SortSelect
          v-model="sortBy"
          :options="sortOptions"
        />
      </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableSortableHead
              column="name"
              v-model="sortBy"
            >
              Name
            </TableSortableHead>
            <TableSortableHead
              column="state"
              v-model="sortBy"
            >
              Status
            </TableSortableHead>
            <TableSortableHead
              column="publish_at"
              v-model="sortBy"
            >
              Publish Date
            </TableSortableHead>
            <TableHead class="text-right">Versions</TableHead>
            <TableHead class="text-right"></TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          <TableLoadingRow
            v-if="isLoading"
            :colspan="5"
          />
          <template v-else-if="releases && releases.length > 0">
            <TableRow
              v-for="release in releases"
              :key="release.id"
            >
              <TableCell class="font-medium">
                <div>
                  <p class="font-semibold">{{ release.name }}</p>
                  <p
                    v-if="release.description"
                    class="line-clamp-1 text-sm text-muted"
                  >
                    {{ release.description }}
                  </p>
                </div>
              </TableCell>
              <TableCell>
                <ReleaseBadge :release="release" />
              </TableCell>
              <TableCell class="text-sm">
                {{ formatDate(release.publish_at) }}
              </TableCell>
              <TableCell class="text-right text-sm">
                <span class="cursor-help">{{ release.versions_count }}</span>
              </TableCell>
              <TableCell class="text-right">
                <DropdownMenu>
                  <DropdownMenuTrigger as-child>
                    <Button
                      variant="ghost"
                      size="icon"
                    >
                      <Icon name="lucide:more-horizontal" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuLabel>Actions</DropdownMenuLabel>

                    <DropdownMenuItem
                      v-if="isDraft(release)"
                      @click="handleEditClick(release)"
                    >
                      <Icon
                        name="lucide:pencil"
                        class="mr-2"
                      />
                      <span>Edit</span>
                    </DropdownMenuItem>

                    <DropdownMenuItem
                      v-if="isDraft(release)"
                      @click="handleCommit(release)"
                    >
                      <Icon
                        name="lucide:check"
                        class="mr-2"
                      />
                      <span>Commit</span>
                    </DropdownMenuItem>

                    <DropdownMenuItem
                      v-if="isScheduled(release) || isPublished(release)"
                      @click="handleCancel(release)"
                    >
                      <Icon
                        name="lucide:x"
                        class="mr-2"
                      />
                      <span>Cancel</span>
                    </DropdownMenuItem>

                    <DropdownMenuSeparator />

                    <DropdownMenuItem
                      @click="handleDelete(release)"
                      class="text-destructive focus:bg-destructive focus:text-destructive-foreground"
                    >
                      <Icon
                        name="lucide:trash-2"
                        class="mr-2 h-4 w-4"
                      />
                      <span>Delete</span>
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </TableCell>
            </TableRow>
          </template>
          <TableEmptyRow
            v-else
            :colspan="5"
            :icon="ReleasesIcon"
            :label="$t('labels.releases.noReleases')"
          />
        </TableBody>
      </Table>
    </div>

    <TablePaginationFooter
      v-if="releases?.meta"
      :meta="releases.meta"
      :current-page="currentPage"
      :per-page="perPage"
      @update:current-page="currentPage = $event"
      @update:per-page="
        (v) => {
          perPage = v
          currentPage = 1
        }
      "
    />
  </div>
</template>
