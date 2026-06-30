<script setup lang="ts">
import type { AssetTagResource } from '~/types/assets'
import CreateAssetTagDialog from '~/components/assets/CreateAssetTagDialog.vue'
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
const canManageTags = computed(() => access.hasAbility('asset_tags.manage'))

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
  q: searchQuery.value || undefined,
  sort: `${sortBy.value.direction === 'asc' ? '+' : '-'}${sortBy.value.column}`,
}))

const { useAssetTagsQuery, useDeleteAssetTagMutation } = useAssetTags(props.spaceId)
const { data: assetTags, isLoading } = useAssetTagsQuery(queryParams)
const { mutate: deleteAssetTag } = useDeleteAssetTagMutation()

const showTagDialog = ref(false)
const selectedTag = ref<AssetTagResource | null>(null)

const pageSizeOptions = [25, 50, 100, 500]
const sortOptions = [
  { value: 'name', label: $t('labels.assetTags.fields.name') },
  { value: 'assets_count', label: $t('labels.assetTags.fields.assetsCount') },
  { value: 'created_at', label: $t('labels.assetTags.fields.createdAt') },
  { value: 'updated_at', label: $t('labels.assetTags.fields.updatedAt') },
]

const possibleFilters = [{ id: 'name', label: $t('labels.assetTags.fields.name') }]

const handleDelete = async (tag: AssetTagResource) => {
  const confirmed = await alert.confirm(
    $t('labels.assetTags.deleteConfirmation', { name: tag.name }),
    {
      title: $t('labels.assetTags.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      cancelLabel: $t('actions.cancel'),
    }
  )
  if (confirmed) {
    deleteAssetTag(tag.id)
  }
}

const handleCreateClick = () => {
  selectedTag.value = null
  showTagDialog.value = true
}

const handleEditClick = (tag: AssetTagResource) => {
  selectedTag.value = tag
  showTagDialog.value = true
}

watch(showTagDialog, (isOpen) => {
  if (!isOpen) selectedTag.value = null
})
</script>

<template>
  <div>
    <div class="content-grid">
      <ContentHeader
        :header="$t('labels.assetTags.title')"
        :description="$t('labels.assetTags.description')"
      >
        <template #actions>
          <Button
            v-if="canManageTags"
            variant="primary"
            @click="handleCreateClick"
          >
            <Icon name="lucide:plus" />
            {{ $t('actions.assetTags.add') }}
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
                  {{ $t('labels.assetTags.fields.name') }}
                </TableSortableHead>
                <TableHead class="text-right">
                  {{ $t('labels.assetTags.fields.assetsCount') }}
                </TableHead>
                <TableHead class="w-12" />
              </TableRow>
            </TableHeader>
            <TableBody>
              <TableLoadingRow
                v-if="isLoading"
                :colspan="3"
              />
              <template v-else-if="assetTags?.data.length">
                <TableRow
                  v-for="tag in assetTags.data"
                  :key="tag.id"
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
                    {{ tag.assets_count ?? 0 }}
                  </TableCell>
                  <TableCell class="text-right">
                    <div
                      v-if="canManageTags"
                      class="flex justify-end gap-1"
                    >
                      <Button
                        variant="ghost"
                        size="icon"
                        @click="handleEditClick(tag)"
                      >
                        <Icon name="lucide:pencil" />
                        <span class="sr-only">{{ $t('actions.assetTags.edit') }}</span>
                      </Button>
                      <Button
                        variant="destructive"
                        size="icon"
                        @click="handleDelete(tag)"
                      >
                        <Icon name="lucide:trash-2" />
                        <span class="sr-only">{{ $t('actions.assetTags.delete') }}</span>
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              </template>
              <TableRow v-else-if="!isLoading">
                <TableCell
                  colspan="3"
                  class="py-8 text-center text-muted"
                >
                  {{ $t('labels.assetTags.noTags') }}
                </TableCell>
              </TableRow>
            </TableBody>
          </Table>
        </div>

        <TablePaginationFooter
          v-if="assetTags?.meta"
          :meta="assetTags.meta"
          :current-page="currentPage"
          :per-page="perPage"
          :page-size-options="pageSizeOptions"
          @update:current-page="(val) => (currentPage = val)"
          @update:per-page="(val) => (perPage = val)"
        />
      </div>
    </div>

    <CreateAssetTagDialog
      v-if="canManageTags"
      v-model:open="showTagDialog"
      :space-id="spaceId"
      :tag="selectedTag"
    />
  </div>
</template>
