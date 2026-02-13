<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useRouteQuery } from '@vueuse/router'
import { TabsContent, TabsRoot } from 'reka-ui'
import AssetFolderTree from '~/components/assets/AssetFolderTree.vue'
import AssetGrid from '~/components/assets/AssetGrid.vue'
import AssetListView from '~/components/assets/AssetListView.vue'
import AssetTagTree from '~/components/assets/AssetTagTree.vue'
import { Button } from '~/components/ui/button'
import { ScrollArea } from '~/components/ui/scroll-area'
import { TabsList, TabsTrigger } from '~/components/ui/tabs'
import ExportAssetsDialog from './assets/ExportAssetsDialog.vue'
import ImportAssetsDialog from './assets/ImportAssetsDialog.vue'

const tabs = {
  folders: {
    icon: 'lucide:folder',
    label: 'Folders',
  },
  tags: {
    icon: 'lucide:tag',
    label: 'Tags',
  },
}

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

defineProps<{
  spaceId: string
}>()

const selectedFolder = defineModel<string | null>('folderId', {
  default: null,
})
const selectedTag = defineModel<string | null>('tagId', {
  default: null,
})

const viewMode = useRouteQuery('view', 'grid') as Ref<'grid' | 'list'>
const sidebarMode = ref<'folders' | 'tags'>('folders')
const exportDialogOpen = ref(false)
const importDialogOpen = ref(false)

watch(sidebarMode, (newMode) => {
  if (newMode === 'folders') {
    selectedTag.value = null
  } else {
    selectedFolder.value = null
  }
})
</script>

<template>
  <div class="flex h-full w-full flex-col">
    <div class="flex flex-1 overflow-hidden">
      <div class="sticky top-0 flex h-full w-xs shrink-0 flex-col overflow-hidden bg-surface p-2">
        <TabsRoot
          v-model="sidebarMode"
          default-value="folders"
        >
          <TabsList class="mb-4 w-full">
            <TabsTrigger
              v-for="({ icon, label }, key) in tabs"
              :key="key"
              :value="key"
              class="grow"
            >
              <Icon :name="icon" />
              <span class="hidden sm:inline">{{ label }}</span>
            </TabsTrigger>
          </TabsList>
          <ScrollArea class="flex-1 overflow-y-auto">
            <TabsContent value="folders">
              <AssetFolderTree
                v-model="selectedFolder"
                :space-id="spaceId"
              />
            </TabsContent>
            <TabsContent value="tags">
              <AssetTagTree
                v-model="selectedTag"
                :space-id="spaceId"
              />
            </TabsContent>
          </ScrollArea>
        </TabsRoot>
      </div>
      <TabsRoot
        v-model="viewMode"
        class="flex flex-1 flex-col bg-background p-6"
      >
        <div class="flex-1">
          <TabsContent value="grid">
            <AssetGrid
              v-model:folder-id="selectedFolder"
              v-model:tag-id="selectedTag"
              :space-id="spaceId"
            />
          </TabsContent>
          <TabsContent value="list">
            <AssetListView
              v-model:folder-id="selectedFolder"
              :tag-id="selectedTag"
              :space-id="spaceId"
            />
          </TabsContent>
        </div>
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

    <Teleport to="#appHeaderActions">
      <div class="flex gap-2">
        <Button @click="importDialogOpen = true">
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
      v-model:open="importDialogOpen"
      :space-id="spaceId"
    />
  </div>
</template>
