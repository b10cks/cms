<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { TabsList, TabsRoot, TabsTrigger, TreeItem, TreeRoot } from 'reka-ui'
import type { BlockFolderResource } from '~/api/resources/block-folders'
import CreateBlockFolderDialog from '~/components/blocks/CreateBlockFolderDialog.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { ScrollArea } from '~/components/ui/scroll-area'

const mode = defineModel<'list' | 'tags'>('mode', {
  default: 'list',
})

const selectedFolder = defineModel<string>('selectedFolder')

const props = defineProps<{
  spaceId: string
}>()

const { $t } = useI18n()
const { alert } = useAlertDialog()

const { useBlocksQuery } = useBlocks(props.spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })

const { useFolderStructure, useDeleteBlockFolderMutation } = useBlockFolders(props.spaceId)
const { rootFolders, getChildrenOfFolder } = useFolderStructure()
const { mutate: deleteBlockFolder } = useDeleteBlockFolderMutation()

const { useBlockTagsQuery } = useBlockTags(props.spaceId)
const { data: blockTags } = useBlockTagsQuery({ per_page: 500 })

const showCreateFolderDialog = ref(false)

function handleSelectFolder(folder?: BlockFolderResource) {
  selectedFolder.value = folder ? folder?.id : undefined
  mode.value = 'list'
}

const initDeleteFolder = async (folder: BlockFolderResource) => {
  const confirmed = await alert.confirm(
    `Are you sure you want to delete the folder "${folder.name}"?`,
    {
      title: 'Delete Block',
      confirmLabel: 'Delete',
      cancelLabel: 'Cancel',
    }
  )

  if (confirmed) {
    deleteBlockFolder(folder.id)
    if (selectedFolder.value === folder.id) {
      selectedFolder.value = folder.parent_id || null
    }
  }
}

const tabs = computed(() => ({
  list: {
    icon: 'lucide:blocks',
    label: $t('labels.blocks.title'),
    count: blocks.value?.data.length || 0,
  },
  tags: {
    icon: 'lucide:tag',
    label: $t('labels.blockTags.title'),
    count: blockTags.value?.data.length || 0,
  },
}))
</script>

<template>
  <div class="sticky top-14 flex h-[calc(100vh-3.5rem)] min-w-2xs flex-col overflow-hidden p-2">
    <TabsRoot
      v-model="mode"
      default-value="list"
    >
      <TabsList class="mb-6 flex w-full flex-col rounded-md bg-input p-1">
        <TabsTrigger
          v-for="({ icon, label, count }, key) in tabs"
          :key="key"
          :value="key"
          class="flex w-full cursor-pointer items-center gap-2 rounded-md p-2 text-sm font-semibold transition-colors hover:text-primary data-[state=active]:bg-gray-900 data-[state=active]:text-primary"
        >
          <Icon :name="icon" />
          <span>{{ label }}</span>
          <Badge
            class="ml-auto"
            size="xs"
            variant="outline"
            >{{ count }}
          </Badge>
        </TabsTrigger>
      </TabsList>
      <ScrollArea class="flex-1 overflow-y-auto">
        <TreeRoot
          v-slot="{ flattenItems }"
          class="w-full list-none select-none"
          :items="rootFolders"
          :get-key="(item) => item?.id"
          :get-children="(folder) => getChildrenOfFolder(folder?.id)"
        >
          <div class="mb-2 flex items-center px-1">
            <h2 class="text-sm font-semibold text-primary">
              {{ $t('labels.blockFolders.title') }}
            </h2>
            <Button
              class="ml-auto"
              size="xs"
              @click="showCreateFolderDialog = true"
            >
              <Icon name="lucide:plus" />
            </Button>
          </div>
          <div
            :class="[
              'group relative my-0.5 flex items-center gap-2 rounded-md py-1 pr-2 pl-2 outline-none',
              'transition-colors duration-200 hover:bg-input',
              'cursor-pointer font-semibold',
              !selectedFolder && mode === 'list' ? 'bg-input text-primary' : '',
            ]"
            @click="handleSelectFolder()"
            @keydown.enter="handleSelectFolder()"
          >
            <Icon name="lucide:folder" />
            <span>{{ $t('labels.blockFolders.allBlocks') }}</span>
          </div>
          <TreeItem
            v-for="item in flattenItems"
            :key="item._id"
            :value="item"
            :level="item.level"
            :style="{ 'padding-left': `${item.level - 0.5}rem` }"
            :class="[
              'group relative my-0.5 flex items-center gap-2 rounded-md py-1 pr-2 pl-0 outline-none',
              'transition-colors duration-200 hover:bg-input',
              'cursor-pointer font-semibold',
              item.value.id === selectedFolder ? 'bg-input text-primary' : '',
            ]"
            @select="handleSelectFolder(item.value)"
          >
            <Icon
              v-if="item.value.icon"
              :name="`lucide:${item.value.icon}`"
              :style="{ color: item.value.color }"
            />
            <div class="truncate">
              {{ item.value.name }}
            </div>
            <div
              class="absolute right-2 flex items-center gap-1 overflow-clip bg-border opacity-0 transition-opacity duration-200 group-hover:w-auto group-hover:opacity-100"
            >
              <button
                type="button"
                title="Delete block"
                class="flex transform cursor-pointer items-center p-1 hover:text-red-500"
                @click.stop="initDeleteFolder(item.value)"
              >
                <Icon
                  name="lucide:trash-2"
                  size="14"
                />
              </button>
            </div>
          </TreeItem>
        </TreeRoot>
      </ScrollArea>
    </TabsRoot>

    <CreateBlockFolderDialog
      v-model:open="showCreateFolderDialog"
      :space-id="spaceId"
    />
  </div>
</template>
