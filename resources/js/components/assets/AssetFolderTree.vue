<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { TreeItem, type TreeItemToggleEvent, TreeRoot } from 'reka-ui'
import CreateFolderDialog from '~/components/assets/CreateFolderDialog.vue'
import RenamableTitle from '~/components/ui/RenamableTitle.vue'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'

const props = defineProps<{
  spaceId: string
}>()

const { $t } = useI18n()
const { alert } = useAlertDialog()

const { useFolderStructure, useUpdateAssetFolderMutation, useDeleteAssetFolderMutation } =
  useAssetFolders(props.spaceId)
const { useUpdateAssetMutation } = useAssets(props.spaceId)
const { settings } = useSpaceSettings(props.spaceId)

const { mutate: updateAsset } = useUpdateAssetMutation()
const { mutate: deleteFolder } = useDeleteAssetFolderMutation()
const { mutate: updateFolder } = useUpdateAssetFolderMutation()

const { rootFolders, getChildrenOfFolder } = useFolderStructure()

const selectedFolderId = defineModel<string>()
const parentFolderId = ref(null)
const showCreateFolderDialog = ref(false)

defineEmits<{
  view: [folder: AssetFolderResource]
  edit: [folder: AssetFolderResource]
  move: [folder: AssetFolderResource]
}>()

const isDraggingOver = ref<string | null>(null)
const currentlyEditingId = ref<string | null>(null)

function handleRename(newName: string, folderId: string) {
  if (folderId && newName) {
    updateFolder({ id: folderId, payload: { name: newName } })
  }
  currentlyEditingId.value = null
}

function handleEditStart(folderId: string) {
  currentlyEditingId.value = folderId
}

function handleEditCancel() {
  currentlyEditingId.value = null
}

async function handleDrop(event: DragEvent, targetFolderId: string) {
  event.preventDefault()
  if (!event.dataTransfer) return

  isDraggingOver.value = null

  try {
    const dragData = JSON.parse(event.dataTransfer.getData('application/json'))

    if (dragData.type === 'asset') {
      await updateAsset({ id: dragData.id, payload: { folder_id: targetFolderId } })

      if (dragData.selected) {
        const selectedAssetsData = JSON.parse(
          event.dataTransfer.getData('application/json+selected-assets') || '[]'
        )

        for (const assetId of selectedAssetsData) {
          if (assetId !== dragData.id) {
            await updateAsset({ id: assetId, payload: { folder_id: targetFolderId } })
          }
        }
      }
    } else if (dragData.type === 'folder') {
      if (dragData.id !== targetFolderId) {
        await updateFolder({ id: dragData.id, payload: { parent_id: targetFolderId } })

        if (dragData.selected) {
          const selectedFoldersData = JSON.parse(
            event.dataTransfer.getData('application/json+selected-folders') || '[]'
          )

          for (const folderId of selectedFoldersData) {
            if (folderId !== dragData.id && folderId !== targetFolderId) {
              await updateFolder({ id: folderId, payload: { parent_id: targetFolderId } })
            }
          }
        }
      }
    }
  } catch (error) {
    console.error('Error handling drop event:', error)
  }
}

function triggerCreateFolderDialog(pid: string | null = null) {
  parentFolderId.value = pid
  showCreateFolderDialog.value = true
}

const handleToggle = (e: TreeItemToggleEvent<AssetFolderResource>) => {
  if (e.detail.originalEvent instanceof PointerEvent) {
    e.preventDefault()
  }
}

const toggleExpanded = (folderId: string) => {
  const expanded = settings.value.assets.expanded || []
  const index = expanded.indexOf(folderId)
  if (index > -1) {
    expanded.splice(index, 1)
  } else {
    expanded.push(folderId)
  }
  settings.value.assets.expanded = expanded
}

function handleDragOver(event: DragEvent, folderId: string) {
  event.preventDefault()
  if (!event.dataTransfer) return

  event.dataTransfer.dropEffect = 'move'
  isDraggingOver.value = folderId
}

function handleDragLeave() {
  isDraggingOver.value = null
}

const handleFolderDelete = async (folder: AssetFolderResource) => {
  const confirmed = await alert.confirm(
    $t('messages.assetFolders.deleteConfirmation', { name: folder.name }),
    {
      title: $t('labels.assetFolders.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      variant: 'destructive',
    }
  )

  if (confirmed) {
    try {
      await deleteFolder(folder.id)
    } catch (error) {
      console.error('Error deleting folder:', error)
    }
  }
}
</script>

<template>
  <TreeRoot
    v-slot="{ flattenItems, expanded }"
    v-model:expanded="settings.assets.expanded"
    class="w-full list-none select-none"
    :items="rootFolders"
    :get-key="(item) => item?.id"
    :get-children="({ id }) => getChildrenOfFolder(id)"
  >
    <button
      type="button"
      :class="[
        'group relative my-0.5 flex w-full items-center gap-2 rounded-md py-1 pr-2 pl-2 outline-none',
        'transition-colors duration-200 hover:bg-input',
        'cursor-pointer font-semibold',
        !selectedFolderId ? 'bg-input text-primary' : '',
      ]"
      @click="selectedFolderId = undefined"
    >
      <Icon name="lucide:home" />
      <span>{{ $t('labels.assets.allAssets') }}</span>
    </button>
    <div class="my-2 flex items-center px-2">
      <h2 class="text-sm font-semibold text-primary">
        {{ $t('labels.assetFolders.title') }}
      </h2>
      <Button
        class="ml-auto"
        size="xs"
        @click="triggerCreateFolderDialog(null)"
      >
        <Icon name="lucide:plus" />
      </Button>
    </div>
    <TreeItem
      v-for="item in flattenItems"
      v-slot="{ isExpanded }"
      :key="item._id"
      v-bind="item.bind"
      :style="{ 'padding-left': `${item.level - 0.5}rem` }"
      :class="[
        'group my-0.5 flex items-center rounded-md px-2 py-1 outline-none',
        'transition-colors duration-200 hover:bg-input',
        'cursor-pointer font-semibold',
        item.value.id === selectedFolderId ? 'bg-input text-primary' : '',
        isDraggingOver === item.value.id
          ? 'bg-opacity-20 border border-dashed border-accent bg-blue-800'
          : '',
      ]"
      tabindex="0"
      :aria-selected="item.value.id === selectedFolderId"
      :aria-expanded="
        item.value.children_count ? expanded.includes(item.value.id).toString() : undefined
      "
      @select="selectedFolderId = item.value.id"
      @toggle="(e) => handleToggle(e)"
      @drop="handleDrop($event, item.value.id)"
      @dragover="handleDragOver($event, item.value.id)"
      @dragleave="handleDragLeave"
    >
      <div class="flex w-5 items-center">
        <button
          v-if="item.value.children_count"
          @click.stop.prevent="toggleExpanded(item.value.id)"
        >
          <Icon
            name="lucide:chevron-right"
            :class="['transition-transform duration-200', isExpanded && 'rotate-90']"
            aria-hidden="true"
          />
        </button>
      </div>
      <div class="flex flex-1 items-center gap-2">
        <Icon
          v-if="item.value.icon"
          :name="`lucide:${item.value.icon}`"
          :style="{ color: item.value.color }"
          aria-hidden="true"
        />
        <RenamableTitle
          :name="item.value.name"
          @update="handleRename($event, item.value.id)"
          @edit-start="handleEditStart(item.value.id)"
          @cancel="handleEditCancel"
        />
      </div>
      <DropdownMenu>
        <DropdownMenuTrigger
          class="opacity-0 transition-all duration-200 group-hover:opacity-100 hover:text-primary data-[state=open]:opacity-100"
        >
          <Icon name="lucide:ellipsis-vertical" />
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem @select="$emit('view', item.value)">
            <Icon name="lucide:eye" />
            <span>{{ $t('actions.view') }}</span>
          </DropdownMenuItem>
          <DropdownMenuItem @select="$emit('edit', item.value)">
            <Icon name="lucide:edit" />
            <span>{{ $t('actions.edit') }}</span>
          </DropdownMenuItem>
          <DropdownMenuItem @select="triggerCreateFolderDialog(item.value.id)">
            <Icon name="lucide:folder-plus" />
            <span>{{ $t('actions.createFolder') }}</span>
          </DropdownMenuItem>
          <DropdownMenuItem @select="$emit('move', item.value)">
            <Icon name="lucide:folder-input" />
            <span>{{ $t('actions.move') }}</span>
          </DropdownMenuItem>
          <DropdownMenuItem
            class="text-destructive"
            @select="handleFolderDelete(item.value)"
          >
            <Icon name="lucide:trash-2" />
            <span>{{ $t('actions.delete') }}</span>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </TreeItem>
  </TreeRoot>

  <CreateFolderDialog
    v-model:open="showCreateFolderDialog"
    :parent-folder-id="parentFolderId"
    :space-id="spaceId"
  />
</template>
