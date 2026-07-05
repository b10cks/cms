<script setup lang="ts">
import { useRouteQuery } from '@vueuse/router'
import { TabsContent, TabsRoot } from 'reka-ui'

import AssetCollectionTree from '~/components/assets/AssetCollectionTree.vue'
import AssetFolderTree from '~/components/assets/AssetFolderTree.vue'
import AssetGrid from '~/components/assets/AssetGrid.vue'
import AssetListView from '~/components/assets/AssetListView.vue'
import AssetTagTree from '~/components/assets/AssetTagTree.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { ScrollArea } from '~/components/ui/scroll-area'
import { TabsList, TabsTrigger } from '~/components/ui/tabs'

import ExportAssetsDialog from './assets/ExportAssetsDialog.vue'
import ImportAssetsDialog from './assets/ImportAssetsDialog.vue'

const modes = {
  grid: {
    icon: 'lucide:grid-3x3',
    label: 'Grid',
  },
  list: {
    icon: 'lucide:list',
    label: 'Table',
  },
}

const props = defineProps<{
  spaceId: string
}>()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageAssets = computed(() => access.hasAbility('assets.manage'))
const canManageAssetFolders = computed(() => access.hasAbility('asset_folders.manage'))
const canViewAssetCollections = computed(() => access.hasAbility('asset_collections.view'))

const selectedFolder = defineModel<string | null>('folderId', {
  default: null,
})
const selectedTag = defineModel<string | null>('tagId', {
  default: null,
})
const selectedCollection = defineModel<string | null>('collectionId', {
  default: null,
})
const selectedAsset = defineModel<string | null | undefined>('assetId', {
  default: null,
})

const viewMode = useRouteQuery('view', 'grid') as Ref<'grid' | 'list'>
const exportDialogOpen = ref(false)
const importDialogOpen = ref(false)

watch(selectedFolder, (folderId) => {
  if (folderId !== null) {
    selectedTag.value = null
    selectedCollection.value = null
  }
})
watch(selectedTag, (tagId) => {
  if (tagId !== null) {
    selectedFolder.value = null
    selectedCollection.value = null
  }
})
watch(selectedCollection, (collectionId) => {
  if (collectionId !== null) {
    selectedFolder.value = null
    selectedTag.value = null
  }
})
</script>

<template>
  <div
    class="flex h-[calc(100svh-3.5rem)] max-h-[calc(100svh-3.5rem)] w-full flex-col overflow-hidden"
  >
    <div class="flex min-h-0 flex-1 overflow-hidden">
      <div class="flex h-full min-h-0 w-xs shrink-0 flex-col overflow-hidden bg-surface p-2">
        <ScrollArea class="min-h-0 flex-1 overflow-y-auto">
          <div class="flex flex-col">
            <AssetFolderTree
              v-model="selectedFolder"
              :space-id="spaceId"
              :has-active-tag="Boolean(selectedTag) || Boolean(selectedCollection)"
              @select-all="((selectedTag = null), (selectedCollection = null))"
            />
            <AssetTagTree
              v-model="selectedTag"
              :space-id="spaceId"
              class="mt-4"
            />
            <AssetCollectionTree
              v-if="canViewAssetCollections"
              v-model="selectedCollection"
              :space-id="spaceId"
              class="mt-4"
            />
          </div>
        </ScrollArea>
      </div>
      <TabsRoot
        v-model="viewMode"
        class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto bg-background p-6"
      >
        <TabsContent
          value="grid"
          class="flex-1"
        >
          <AssetGrid
            v-model:folder-id="selectedFolder"
            v-model:tag-id="selectedTag"
            v-model:collection-id="selectedCollection"
            v-model:asset-id="selectedAsset"
            :space-id="spaceId"
            :allow-upload="canManageAssets"
            :allow-folder-creation="canManageAssetFolders"
          />
        </TabsContent>
        <TabsContent
          value="list"
          class="flex-1"
        >
          <AssetListView
            v-model:folder-id="selectedFolder"
            :tag-id="selectedTag"
            :collection-id="selectedCollection"
            :space-id="spaceId"
            :allow-upload="canManageAssets"
            :allow-folder-creation="canManageAssetFolders"
          />
        </TabsContent>
        <div class="mt-6 flex">
          <TabsList class="mx-auto">
            <TabsTrigger
              v-for="({ icon, label }, key) in modes"
              :key="key"
              :value="key"
              class="grow"
            >
              <Icon :name="icon" />
              <span class="hidden sm:inline">{{ label }}</span>
            </TabsTrigger>
          </TabsList>
        </div>
      </TabsRoot>
    </div>

    <Teleport
      defer
      to="#appHeaderActions"
    >
      <div class="flex gap-2">
        <Button
          v-if="canManageAssets"
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

    <ExportAssetsDialog
      v-model:open="exportDialogOpen"
      :space-id="spaceId"
      :folder-id="selectedFolder"
      :tag-id="selectedTag"
      :filters="{}"
    />

    <ImportAssetsDialog
      v-if="canManageAssets"
      v-model:open="importDialogOpen"
      :space-id="spaceId"
    />
  </div>
</template>
