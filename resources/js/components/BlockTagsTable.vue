<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import type { BlockTagResource } from '~/api/resources/block-tags'
import CreateBlockTagDialog from '~/components/blocks/CreateBlockTagDialog.vue'
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
import TableLoadingRow from '~/components/ui/TableLoadingRow.vue'
import TablePaginationFooter from '~/components/ui/TablePaginationFooter.vue'

const props = defineProps<{
  spaceId: string
}>()

const { $t } = useI18n()
const { alert } = useAlertDialog()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageBlockTags = computed(() => access.hasAbility('blocks.manage'))

const searchQuery = ref('')
const currentPage = ref(1)
const perPage = ref(25)
const sortBy = ref<{ column: string; direction: 'asc' | 'desc' }>({
  column: 'name',
  direction: 'asc',
})
const filters = ref<Record<string, unknown>>({})
const queryParams = computed(() => ({
  ...filters.value,
  page: currentPage.value,
  per_page: perPage.value,
  q: searchQuery.value,
  sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
}))

const { useBlockTagsQuery, useDeleteBlockTagMutation } = useBlockTags(props.spaceId)
const { data: blockTags, isLoading, isFetching } = useBlockTagsQuery(queryParams)
const { mutate: deleteBlockTag } = useDeleteBlockTagMutation()

const showTagDialog = ref(false)
const selectedTag = ref<BlockTagResource | null>(null)

const pageSizeOptions = [25, 50, 100, 500, 1000]
const sortOptions = [
  { value: 'name', label: $t('labels.blockTags.fields.name') },
  { value: 'blocks_count', label: $t('labels.blockTags.fields.blocks_count') },
  { value: 'created_at', label: $t('labels.blockTags.fields.createdAt') },
  { value: 'updated_at', label: $t('labels.blockTags.fields.updatedAt') },
]

const possibleFilters = [
  { id: 'name', label: $t('labels.blockTags.fields.name') },
  { id: 'block_count', label: $t('labels.blockTags.fields.blocks_count') },
]

const handleDelete = async (tag: BlockTagResource) => {
  const confirmed = await alert.confirm(
    $t('labels.blockTags.deleteConfirmation', { name: tag.name }),
    {
      title: $t('labels.blockTags.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      cancelLabel: $t('actions.cancel'),
    }
  )

  if (confirmed) {
    await deleteBlockTag(tag.name)
  }
}

const handleCreateClick = () => {
  selectedTag.value = null
  showTagDialog.value = true
}

const handleEditClick = (tag: BlockTagResource) => {
  selectedTag.value = tag
  showTagDialog.value = true
}

watch(showTagDialog, (isOpen) => {
  if (!isOpen) {
    selectedTag.value = null
  }
})
</script>

<template>
  <div>
    <div class="content-grid">
      <ContentHeader
        :header="$t('labels.blockTags.title')"
        :description="$t('labels.blockTags.description')"
      >
        <template #actions>
          <Button
            v-if="canManageBlockTags"
            variant="primary"
            @click="handleCreateClick"
          >
            <Icon name="lucide:plus" />
            {{ $t('actions.blockTags.add') }}
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
                  column="name"
                >
                  {{ $t('labels.blockTags.fields.name') }}
                </TableSortableHead>
                <TableHead class="text-right">
                  {{ $t('labels.blockTags.fields.blockCount') }}
                </TableHead>
                <TableHead class="w-12" />
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
                :colspan="3"
              />
              <template v-else-if="blockTags?.data.length">
                <TableRow
                  v-for="tag in blockTags?.data"
                  :key="tag.name"
                >
                  <TableCell>
                    <Badge>
                      <IconName
                        v-bind="{ name: tag.name, color: tag.color, icon: tag.icon }"
                        class="font-semibold"
                      />
                    </Badge>
                  </TableCell>
                  <TableCell class="text-right">
                    {{ tag.blocks_count }}
                  </TableCell>
                  <TableCell class="text-right">
                    <div
                      v-if="canManageBlockTags"
                      class="flex gap-1"
                    >
                      <Button
                        variant="ghost"
                        size="icon"
                        @click="handleEditClick(tag)"
                      >
                        <Icon name="lucide:pencil" />
                        <span class="sr-only">{{ $t('actions.blockTags.edit') }}</span>
                      </Button>
                      <Button
                        variant="destructive"
                        size="icon"
                        @click="handleDelete(tag)"
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
          v-if="blockTags?.meta"
          :meta="blockTags.meta"
          :current-page="currentPage"
          :per-page="perPage"
          :page-size-options="pageSizeOptions"
          @update:current-page="(val) => (currentPage = val)"
          @update:per-page="(val) => (perPage = val)"
        />
      </div>
    </div>

    <CreateBlockTagDialog
      v-if="canManageBlockTags"
      v-model:open="showTagDialog"
      :space-id="spaceId"
      :tag="selectedTag"
    />
  </div>
</template>
