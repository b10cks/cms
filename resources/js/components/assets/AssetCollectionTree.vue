<script setup lang="ts">
import { TreeRoot } from 'reka-ui'

import AssetCollectionTreeItem from '~/components/assets/AssetCollectionTreeItem.vue'
import CollectionSharesSheet from '~/components/assets/CollectionSharesSheet.vue'
import CreateAssetCollectionDialog from '~/components/assets/CreateAssetCollectionDialog.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import type { AssetCollectionResource } from '~/types/assets'

const props = defineProps<{
  spaceId: string
}>()

const { $t } = useI18n()
const { alert } = useAlertDialog()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageCollections = computed(() => access.hasAbility('asset_collections.manage'))
const canShareCollections = computed(() => access.hasAbility('asset_shares.manage'))

const {
  useAssetCollectionsQuery,
  useUpdateAssetCollectionMutation,
  useDeleteAssetCollectionMutation,
  useAddAssetsToCollectionMutation,
} = useAssetCollections(props.spaceId)
const { data: collections } = useAssetCollectionsQuery({ per_page: 500, sort: '+name' })
const { mutate: updateCollection } = useUpdateAssetCollectionMutation()
const { mutate: deleteCollection } = useDeleteAssetCollectionMutation()
const { mutate: addAssetsToCollection } = useAddAssetsToCollectionMutation()

const selectedCollectionId = defineModel<string | null>()

const collectionDialogOpen = ref(false)
const editingCollection = ref<AssetCollectionResource | null>(null)
const sharesSheetOpen = ref(false)
const sharesCollection = ref<AssetCollectionResource | null>(null)

function openSharesSheet(collection: AssetCollectionResource) {
  sharesCollection.value = collection
  sharesSheetOpen.value = true
}

function openCreateDialog() {
  editingCollection.value = null
  collectionDialogOpen.value = true
}

function openEditDialog(collection: AssetCollectionResource) {
  editingCollection.value = collection
  collectionDialogOpen.value = true
}

function handleRename(newName: string, collection: AssetCollectionResource) {
  if (!newName || newName === collection.name) return
  updateCollection({ id: collection.id, payload: { name: newName } })
}

function handleAssignDrop(collectionId: string, assetIds: string[]) {
  addAssetsToCollection({ collectionId, assetIds })
}

async function handleDelete(collection: AssetCollectionResource) {
  const confirmed = await alert.confirm(
    $t('labels.assetCollections.deleteConfirmation', { name: collection.name }),
    {
      title: $t('labels.assetCollections.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      variant: 'destructive',
    }
  )
  if (confirmed) {
    deleteCollection(collection.id)
    if (selectedCollectionId.value === collection.id) {
      selectedCollectionId.value = null
    }
  }
}
</script>

<template>
  <div>
    <TreeRoot
      v-slot="{ flattenItems }"
      class="w-full list-none select-none"
      :items="collections?.data ?? []"
      :get-key="(item) => item?.id"
      :get-children="() => undefined"
    >
      <div class="group my-2 flex items-center gap-2 px-2">
        <Icon
          name="lucide:layers"
          class="text-muted-foreground"
          aria-hidden="true"
        />
        <h2 class="text-sm font-semibold text-primary">
          {{ $t('labels.assetCollections.title') }}
        </h2>
        <Button
          v-if="canManageCollections"
          class="ml-auto opacity-0 transition-opacity duration-200 group-hover:opacity-100"
          size="toolbar"
          @click="openCreateDialog"
        >
          <Icon name="lucide:plus" />
        </Button>
      </div>

      <AssetCollectionTreeItem
        v-for="item in flattenItems"
        :key="item._id"
        :item="item"
        :selected-collection-id="selectedCollectionId ?? null"
        :can-manage-collections="canManageCollections"
        :can-share-collections="canShareCollections"
        class="my-0.5"
        @select="selectedCollectionId = $event"
        @rename="(name, collection) => handleRename(name, collection)"
        @edit="openEditDialog"
        @manage-shares="openSharesSheet"
        @delete="handleDelete"
        @assign-drop="handleAssignDrop"
      />
    </TreeRoot>

    <CreateAssetCollectionDialog
      v-if="canManageCollections"
      v-model:open="collectionDialogOpen"
      :space-id="spaceId"
      :collection="editingCollection"
    />

    <CollectionSharesSheet
      v-if="canShareCollections"
      v-model:open="sharesSheetOpen"
      :space-id="spaceId"
      :collection="sharesCollection"
    />
  </div>
</template>
