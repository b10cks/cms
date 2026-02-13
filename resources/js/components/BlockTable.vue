<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useRouteQuery } from '@vueuse/router'
import BlocksIcon from '~/assets/images/blocks.svg?component'
import CreateBlockDialog from '~/components/blocks/CreateBlockDialog.vue'
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

const { $t } = useI18n()
const { alert } = useAlertDialog()

const showCreateBlockDialog = ref(false)

const searchQuery = useRouteQuery('q')
const currentPage = useRouteQuery('page', 1, { transform: Number })
const perPage = useRouteQuery('per_page', 25, { transform: Number })
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'name',
  direction: 'asc',
})
const filters = ref<Record<string, unknown>>({})
const queryParams = computed(() => ({
  ...filters.value,
  folder_id: props.folder || undefined,
  page: currentPage.value,
  per_page: perPage.value,
  q: searchQuery.value,
  sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
}))

const { useBlocksQuery, useDeleteBlockMutation } = useBlocks(props.spaceId)
const { data: blocks, isLoading: isLoadingBlocks } = useBlocksQuery(queryParams)
const { mutate: deleteBlock } = useDeleteBlockMutation()

const { useBlockTagsQuery } = useBlockTags(props.spaceId)
const { data: blockTags, isLoading: isLoadingTags } = useBlockTagsQuery({ per_page: 500 })

const { useBlockFoldersQuery } = useBlockFolders(props.spaceId)
const { data: blockFolders, isLoading: isLoadingFolders } = useBlockFoldersQuery({ per_page: 500 })

const isLoading = computed(
  () => isLoadingBlocks.value || isLoadingTags.value || isLoadingFolders.value
)

const pageSizeOptions = [25, 50, 100, 500, 1000]
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

const getBlockTags = (tags: string[]) => {
  return tags
    .map((tag) => {
      return blockTags.value?.data.find((t) => t.name === tag)
    })
    .filter((tag) => tag !== null)
}

const getBlockFolder = (folderId: string | null) => {
  return folderId ? blockFolders.value.find((folder) => folder.id === folderId) : null
}

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

        <div class="overflow-hidden rounded-md border border-input">
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
            <TableBody>
              <TableLoadingRow
                v-if="isLoading"
                :colspan="6"
              />
              <TableEmptyRow
                v-else-if="blocks.meta?.total === 0"
                :icon="BlocksIcon"
                :colspan="6"
              />
              <template v-else>
                <TableRow
                  v-for="block in blocks.data"
                  :key="block.id"
                  class="cursor-pointer hover:bg-accent"
                >
                  <TableCell>
                    <RouterLink :href="`/${spaceId}/blocks/${block.id}`">
                      <IconName
                        v-bind="{ name: block.name, color: block.color, icon: block.icon }"
                        class="font-semibold"
                      />
                    </RouterLink>
                  </TableCell>
                  <TableCell>
                    <Badge
                      variant="outline"
                      class="text-nowrap"
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
                    <div class="flex gap-1">
                      <Button
                        variant="ghost"
                        size="icon"
                        @click.stop="() => $router.push(`/${props.spaceId}/blocks/${block.id}`)"
                      >
                        <Icon name="lucide:pencil" />
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
          :current-page="currentPage"
          :per-page="perPage"
          :page-size-options="pageSizeOptions"
          @update:current-page="(val) => (currentPage = val)"
          @update:per-page="(val) => (perPage = val)"
        />
      </div>
    </div>

    <CreateBlockDialog
      v-model:open="showCreateBlockDialog"
      :space-id="spaceId"
      :folder-id="folder"
    />
  </div>
</template>
