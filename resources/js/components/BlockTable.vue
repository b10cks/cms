<script setup lang="ts">
import { useRouteQuery } from '@vueuse/router'

import { BlockTagResource } from '~/api/resources/block-tags'
import BlocksIcon from '~/assets/images/blocks.svg?component'
import CreateBlockDialog from '~/components/blocks/CreateBlockDialog.vue'
import Icon from '~/components/Icon.vue'
import SearchFilter from '~/components/SearchFilter.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import IconName from '~/components/ui/IconName.vue'
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

const props = defineProps<{
  spaceId: string
  folder?: string | null
}>()

const { settings } = useSpaceSettings(props.spaceId)
const route = useRoute()
const router = useRouter()
const { $t } = useI18n()
const { alert } = useAlertDialog()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageBlocks = computed(() => access.hasAbility('blocks.manage'))

const showCreateBlockDialog = ref(false)
const showDuplicateBlockDialog = ref(false)
const duplicateSourceBlock = ref<BlockResource | null>(null)

const searchQuery = useRouteQuery('q')
const blockPageSize = computed({
  get: () => settings.value.blocks.pageSize || 25,
  set: (value: number) => (settings.value.blocks.pageSize = value),
})
const {
  currentPage,
  sortBy,
  filters,
  paginationBindings,
  queryParams: tableParams,
} = useTableQueryState({
  defaultSort: { column: 'name', direction: 'asc' },
  page: useRouteQuery('page', 1, { transform: Number }),
  perPage: blockPageSize,
  pageSizeOptions: [25, 50, 100, 500, 1000],
})

const queryParams = computed(() => ({
  ...tableParams.value,
  folder_id: props.folder || undefined,
  q: searchQuery.value,
}))

const { useBlocksQuery, useDeleteBlockMutation } = useBlocks(props.spaceId)
const {
  data: blocks,
  isLoading: isLoadingBlocks,
  isFetching: isFetchingBlocks,
} = useBlocksQuery(queryParams)
const { mutate: deleteBlock } = useDeleteBlockMutation()

const { container: tableRef } = useTableKeyboard({
  page: currentPage,
  lastPage: () => blocks.value?.meta?.last_page,
  onOpen: (row) => {
    const blockId = row.dataset.blockId
    if (blockId) {
      router.push(buildBlockRoute(blockId))
    }
  },
})

const { useBlockTagsQuery } = useBlockTags(props.spaceId)
const { data: blockTags, isLoading: isLoadingTags } = useBlockTagsQuery({ per_page: 500 })

const { useBlockFoldersQuery } = useBlockFolders(props.spaceId)
const { data: blockFolders, isLoading: isLoadingFolders } = useBlockFoldersQuery({ per_page: 500 })

const isLoading = computed(
  () => isLoadingBlocks.value || isLoadingTags.value || isLoadingFolders.value
)

const sortOptions = [
  { value: 'name', label: $t('labels.blocks.fields.name') },
  { value: 'type', label: $t('labels.blocks.fields.type') },
  { value: 'folder.name', label: $t('labels.blocks.fields.folder') },
  { value: 'created_at', label: $t('labels.blocks.fields.createdAt') },
  { value: 'updated_at', label: $t('labels.blocks.fields.updatedAt') },
]

const possibleFilters = computed(() => [
  { id: 'name', label: $t('labels.blocks.fields.name') },
  {
    id: 'type',
    label: $t('labels.blocks.fields.type'),
    items: [
      { value: 'root', label: $t('labels.blocks.types.root.label') },
      { value: 'nestable', label: $t('labels.blocks.types.nestable.label') },
      { value: 'single', label: $t('labels.blocks.types.single.label') },
      { value: 'universal', label: $t('labels.blocks.types.universal.label') },
    ],
  },
  {
    id: 'tags',
    label: $t('labels.blocks.fields.tags'),
    items: blockTags.value?.data.map((tag) => ({
      value: tag.name,
      label: tag.name,
    })),
  },
])

const getBlockTags = (tags: string[]): BlockTagResource[] => {
  return tags
    .map((tag) => {
      return blockTags.value?.data.find((t) => t.name === tag)
    })
    .filter((tag) => tag !== null && tag !== undefined)
}

const getBlockFolder = (folderId: string | null) => {
  return folderId ? blockFolders.value?.find((folder) => folder.id === folderId) : null
}

const buildBlockRoute = (blockId: string) => ({
  name: 'space-block' as const,
  params: { space: props.spaceId, block: blockId },
  query: route.query,
})

const handleDelete = async (block: BlockResource) => {
  const confirmed = await alert.confirm(
    $t('messages.blockTags.deleteConfirmation', { name: block.name }),
    {
      title: $t('labels.blockTags.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      cancelLabel: $t('actions.cancel'),
    }
  )

  if (confirmed) {
    await deleteBlock(block.id)
  }
}

const openDuplicateDialog = (block: BlockResource) => {
  duplicateSourceBlock.value = block
  showDuplicateBlockDialog.value = true
}

const handleDuplicateCreated = (block: BlockResource) => {
  duplicateSourceBlock.value = null
  router.push(buildBlockRoute(block.id))
}

const typeColor = (type: 'root' | 'nestable' | 'single' | 'universal') => {
  switch (type) {
    case 'root':
      return 'text-primary'
    case 'single':
      return 'text-primary'
    case 'universal':
      return 'text-primary'
    default:
      return ''
  }
}
</script>

<template>
  <div>
    <div class="content-grid">
      <ContentHeader
        :header="$t('labels.blocks.title')"
        :description="$t('labels.blocks.description')"
      >
        <template #actions>
          <Button
            v-if="canManageBlocks"
            variant="primary"
            @click="showCreateBlockDialog = true"
          >
            <Icon name="lucide:plus" />
            {{ $t('actions.blocks.add') }}
          </Button>
        </template>
      </ContentHeader>
      <div class="grid gap-2">
        <div class="ml-auto flex items-center gap-2">
          <SearchFilter
            v-model="filters"
            :filterable-fields="possibleFilters"
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

        <div
          ref="tableRef"
          class="overflow-hidden rounded-md border border-input"
        >
          <Table>
            <TableHeader>
              <TableRow>
                <TableSortableHead
                  v-model="sortBy"
                  class="w-1/4"
                  column="name"
                >
                  {{ $t('labels.blocks.fields.name') }}
                </TableSortableHead>
                <TableSortableHead
                  v-model="sortBy"
                  column="type"
                >
                  {{ $t('labels.blocks.fields.type') }}
                </TableSortableHead>
                <TableHead>
                  {{ $t('labels.blocks.fields.tags') }}
                </TableHead>
                <TableHead>
                  {{ $t('labels.blocks.fields.folder') }}
                </TableHead>
                <TableHead class="text-center">
                  {{ $t('labels.blocks.fields.templates') }}
                </TableHead>
                <TableHead class="w-12" />
              </TableRow>
            </TableHeader>
            <TableBody
              :class="
                isFetchingBlocks && !isLoading
                  ? 'opacity-50 transition-opacity duration-200'
                  : 'transition-opacity duration-200'
              "
            >
              <TableLoadingRow
                v-if="isLoading"
                :colspan="6"
              />
              <TableEmptyRow
                v-else-if="blocks?.meta?.total === 0"
                :icon="BlocksIcon"
                :colspan="6"
              />
              <template v-else>
                <TableRow
                  v-for="block in blocks?.data"
                  :key="block.id"
                  :data-block-id="block.id"
                  data-table-row
                  class="cursor-pointer hover:bg-accent focus-visible:outline focus-visible:outline-1 focus-visible:-outline-offset-1 focus-visible:outline-ring"
                >
                  <TableCell>
                    <RouterLink :to="buildBlockRoute(block.id)">
                      <IconName
                        v-bind="{ name: block.name, color: block.color, icon: block.icon }"
                        class="font-semibold"
                      />
                    </RouterLink>
                  </TableCell>
                  <TableCell>
                    <Badge
                      variant="surface"
                      :class="['text-nowrap', typeColor(block.type)]"
                      >{{ $t(`labels.blocks.types.${block.type}.label`) }}
                    </Badge>
                  </TableCell>
                  <TableCell>
                    <div
                      v-if="block.tags?.length"
                      class="flex gap-2"
                    >
                      <Badge
                        v-for="tag in getBlockTags(block.tags)"
                        :key="tag.name"
                      >
                        <IconName v-bind="{ name: tag.name, color: tag.color, icon: tag.icon }" />
                      </Badge>
                    </div>
                  </TableCell>
                  <TableCell>
                    <IconName
                      v-if="block.folder_id"
                      v-bind="getBlockFolder(block.folder_id)"
                    />
                  </TableCell>
                  <TableCell class="text-center">
                    <Badge
                      v-if="block.templates_count"
                      variant="secondary"
                      size="sm"
                    >
                      {{ block.templates_count }}
                    </Badge>
                    <span
                      v-else
                      class="text-muted-foreground"
                    >
                      -
                    </span>
                  </TableCell>
                  <TableCell class="text-right">
                    <div
                      v-if="canManageBlocks"
                      class="flex gap-1"
                    >
                      <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="$t('actions.edit')"
                        @click.stop="router.push(buildBlockRoute(block.id))"
                      >
                        <Icon name="lucide:pencil" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon"
                        @click.stop="openDuplicateDialog(block)"
                      >
                        <Icon name="lucide:copy" />
                        <span class="sr-only">{{ $t('actions.blocks.duplicate') }}</span>
                      </Button>
                      <Button
                        variant="destructive"
                        size="icon"
                        @click="handleDelete(block)"
                      >
                        <Icon name="lucide:trash-2" />
                        <span class="sr-only">{{ $t('actions.datasources.delete') }}</span>
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              </template>
            </TableBody>
          </Table>
        </div>

        <TablePaginationFooter
          v-if="blocks?.meta"
          :meta="blocks.meta"
          v-bind="paginationBindings"
        />
      </div>
    </div>

    <CreateBlockDialog
      v-if="canManageBlocks"
      v-model:open="showCreateBlockDialog"
      :space-id="spaceId"
      :folder-id="folder"
    />

    <CreateBlockDialog
      v-if="canManageBlocks && duplicateSourceBlock"
      v-model:open="showDuplicateBlockDialog"
      :space-id="spaceId"
      :initial-block="duplicateSourceBlock"
      :title="$t('actions.blocks.duplicate')"
      @created="handleDuplicateCreated"
    />
  </div>
</template>
