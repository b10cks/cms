<script setup lang="ts">
import { TreeItem, TreeRoot } from 'reka-ui'
import { RouterLink, type LocationQueryRaw } from 'vue-router'

import type { BlockFolderResource } from '~/api/resources/block-folders'
import CreateBlockFolderDialog from '~/components/blocks/CreateBlockFolderDialog.vue'
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { ScrollArea } from '~/components/ui/scroll-area'

const mode = defineModel<'list' | 'tags'>('mode', {
  default: 'list',
})

const selectedFolder = defineModel<string | null>('selectedFolder')

const props = defineProps<{
  spaceId: string
}>()

const route = useRoute()
const { $t } = useI18n()
const { alert } = useAlertDialog()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageBlockFolders = computed(() => access.hasAbility('blocks.manage'))

const { useBlocksQuery } = useBlocks(props.spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })

const { useFolderStructure, useDeleteBlockFolderMutation } = useBlockFolders(props.spaceId)
const { rootFolders, getChildrenOfFolder } = useFolderStructure()
const { mutate: deleteBlockFolder } = useDeleteBlockFolderMutation()

const { useBlockTagsQuery } = useBlockTags(props.spaceId)
const { data: blockTags } = useBlockTagsQuery({ per_page: 500 })

const showCreateFolderDialog = ref(false)

const currentMode = computed<'list' | 'tags'>(() => {
  const routeMode = route.query.mode
  if (routeMode === 'tags' || routeMode === 'list') {
    return routeMode
  }

  return mode.value
})

const currentFolder = computed<string | null>(() => {
  return typeof route.query.folder === 'string'
    ? route.query.folder
    : (selectedFolder.value ?? null)
})

const buildOverviewRoute = ({
  mode: nextMode = currentMode.value,
  folder = currentFolder.value,
}: {
  mode?: 'list' | 'tags'
  folder?: string | null
}) => {
  const query: LocationQueryRaw = {
    ...route.query,
    mode: nextMode,
  }

  delete query.page

  if (nextMode === 'list' && folder) {
    query.folder = folder
  } else {
    delete query.folder
  }

  return {
    name: 'space-blocks-index' as const,
    params: {
      space: props.spaceId,
    },
    query,
  }
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
    if (currentFolder.value === folder.id) {
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
  <div class="sticky top-0 flex h-[calc(100vh-3.5rem)] min-w-2xs flex-col overflow-hidden p-2">
    <div>
      <div class="mb-6 flex w-full flex-col rounded-md bg-input p-1">
        <RouterLink
          v-for="({ icon, label, count }, key) in tabs"
          :key="key"
          :to="buildOverviewRoute({ mode: key as 'list' | 'tags' })"
          class="flex w-full cursor-pointer items-center gap-2 rounded-md p-2 text-sm font-semibold transition-colors hover:text-primary data-[state=active]:bg-background data-[state=active]:text-primary"
          :class="currentMode === key ? 'bg-background text-primary' : ''"
        >
          <Icon :name="icon" />
          <span>{{ label }}</span>
          <Badge
            class="ml-auto"
            size="xs"
            variant="surface"
            >{{ count }}
          </Badge>
        </RouterLink>
      </div>
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
              v-if="canManageBlockFolders"
              class="ml-auto"
              size="xs"
              @click="showCreateFolderDialog = true"
            >
              <Icon name="lucide:plus" />
            </Button>
          </div>
          <RouterLink
            :to="buildOverviewRoute({ mode: 'list', folder: null })"
            :class="[
              'group relative my-0.5 flex items-center gap-2 rounded-md py-1 pr-2 pl-2 outline-none',
              'transition-colors duration-200 hover:bg-input',
              'cursor-pointer font-semibold',
              !currentFolder && currentMode === 'list' ? 'bg-input text-primary' : '',
            ]"
          >
            <Icon name="lucide:folder" />
            <span>{{ $t('labels.blockFolders.allBlocks') }}</span>
          </RouterLink>
          <TreeItem
            v-for="item in flattenItems"
            :key="item._id"
            :value="item"
            :level="item.level"
            :as="RouterLink"
            :to="buildOverviewRoute({ mode: 'list', folder: item.value.id })"
            :style="{ 'padding-left': `${item.level - 0.5}rem` }"
            :class="[
              'group relative my-0.5 flex items-center gap-2 rounded-md py-1 pr-2 pl-0 outline-none',
              'transition-colors duration-200 hover:bg-input',
              'cursor-pointer font-semibold',
              item.value.id === currentFolder ? 'bg-input text-primary' : '',
            ]"
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
                v-if="canManageBlockFolders"
                type="button"
                title="Delete block"
                class="flex transform cursor-pointer items-center p-1 hover:text-red-500"
                @click.prevent.stop="initDeleteFolder(item.value)"
              >
                <Icon name="lucide:trash-2" />
              </button>
            </div>
          </TreeItem>
        </TreeRoot>
      </ScrollArea>
    </div>

    <CreateBlockFolderDialog
      v-if="canManageBlockFolders"
      v-model:open="showCreateFolderDialog"
      :space-id="spaceId"
    />
  </div>
</template>
