<script setup lang="ts">
import { combine } from '@atlaskit/pragmatic-drag-and-drop/combine'
import { draggable, dropTargetForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter'
import { TreeItem, type TreeItemToggleEvent, TreeRoot } from 'reka-ui'
import { toast } from 'vue-sonner'

import CreateFolderDialog from '~/components/assets/CreateFolderDialog.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import RenamableTitle from '~/components/ui/RenamableTitle.vue'
import {
  createAssetManagerDragData,
  getAssetManagerDragItems,
  setAssetManagerDragPreview,
} from '~/lib/assets/assetDragAndDrop'
import type { AssetFolderResource } from '~/types/assets'

const props = defineProps<{
  spaceId: string
}>()

const { $t } = useI18n()
const { alert } = useAlertDialog()
const { useAccessControl } = useAuthorization()
const { settings } = useSpaceSettings(props.spaceId)
const { useFolderStructure, useDeleteAssetFolderMutation, useUpdateAssetFolderMutation } =
  useAssetFolders(props.spaceId)
const { canMoveItems, moveItemsToFolder } = useAssetLibraryMoves(props.spaceId)
const { mutateAsync: deleteFolder } = useDeleteAssetFolderMutation()
const { mutateAsync: updateFolder } = useUpdateAssetFolderMutation()
const { getChildrenOfFolder, rootFolders } = useFolderStructure()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManageFolders = computed(() => access.hasAbility('asset_folders.manage'))

const selectedFolderId = defineModel<string | null>()

const folderDialogOpen = ref(false)
const dialogParentFolderId = ref<string | null>(null)
const editingFolder = ref<AssetFolderResource | null>(null)
const currentlyEditingId = ref<string | null>(null)
const activeDropTargetId = ref<string | null>(null)
const isRootDraggedOver = ref(false)
const treeContainerRef = ref<HTMLElement | null>(null)
const folderCleanupMap = new Map<string, () => void>()

const resolveElement = (value: Element | { $el?: Element } | null): HTMLElement | null => {
  if (value instanceof HTMLElement) {
    return value
  }

  if (value && '$el' in value && value.$el instanceof HTMLElement) {
    return value.$el
  }

  return null
}

const clearDropState = () => {
  activeDropTargetId.value = null
  isRootDraggedOver.value = false
}

const handleFolderRename = async (newName: string, folderId: string) => {
  if (!folderId || !newName) {
    currentlyEditingId.value = null
    return
  }

  await updateFolder({ id: folderId, payload: { name: newName } })
  currentlyEditingId.value = null
}

const openCreateFolderDialog = (parentId: string | null = null) => {
  editingFolder.value = null
  dialogParentFolderId.value = parentId
  folderDialogOpen.value = true
}

const openEditFolderDialog = (folder: AssetFolderResource) => {
  editingFolder.value = folder
  dialogParentFolderId.value = folder.parent_id
  folderDialogOpen.value = true
}

const handleItemsMove = async (targetFolderId: string | null, data: Record<string, unknown>) => {
  const items = getAssetManagerDragItems(data)

  if (!items.length) {
    return
  }

  if (!canMoveItems(items, targetFolderId)) {
    toast.error(String($t('messages.assetFolders.invalidMoveToChild')))
    clearDropState()
    return
  }

  try {
    await moveItemsToFolder(items, targetFolderId)
  } catch {
    toast.error(String($t('messages.assetFolders.invalidMoveToChild')))
  } finally {
    clearDropState()
  }
}

const registerFolderInteractions = (folder: AssetFolderResource, element: HTMLElement) => {
  const cleanup = combine(
    draggable({
      element,
      getInitialData: () =>
        createAssetManagerDragData([{ id: folder.id, type: 'folder' }], {
          id: folder.id,
          type: 'folder',
        }),
      onGenerateDragPreview: ({ nativeSetDragImage }) => {
        setAssetManagerDragPreview({
          nativeSetDragImage,
          count: 1,
          title: folder.name,
        })
      },
    }),
    dropTargetForElements({
      element,
      canDrop: ({ source }) => canMoveItems(getAssetManagerDragItems(source.data), folder.id),
      getIsSticky: () => true,
      onDragEnter: () => {
        activeDropTargetId.value = folder.id
      },
      onDragLeave: () => {
        if (activeDropTargetId.value === folder.id) {
          activeDropTargetId.value = null
        }
      },
      onDrop: async ({ source }) => {
        await handleItemsMove(folder.id, source.data)
      },
    })
  )

  folderCleanupMap.set(folder.id, cleanup)
}

const setFolderElement = (folder: AssetFolderResource) => {
  return (value: Element | { $el?: Element } | null) => {
    folderCleanupMap.get(folder.id)?.()
    folderCleanupMap.delete(folder.id)

    const element = resolveElement(value)
    if (!element) {
      return
    }

    registerFolderInteractions(folder, element)
  }
}

watch(treeContainerRef, (element, _, onCleanup) => {
  if (!element) {
    return
  }

  const cleanup = dropTargetForElements({
    element,
    canDrop: ({ source }) => canMoveItems(getAssetManagerDragItems(source.data), null),
    getIsSticky: () => true,
    onDragEnter: () => {
      isRootDraggedOver.value = true
    },
    onDragLeave: () => {
      isRootDraggedOver.value = false
    },
    onDropTargetChange: ({ location }) => {
      const isFolderDropActive = location.current.dropTargets.some(
        (dropTarget) => dropTarget.element !== element
      )
      isRootDraggedOver.value = !isFolderDropActive
    },
    onDrop: async ({ source }) => {
      await handleItemsMove(null, source.data)
    },
  })

  onCleanup(() => {
    isRootDraggedOver.value = false
    cleanup()
  })
})

onBeforeUnmount(() => {
  folderCleanupMap.forEach((cleanup) => cleanup())
  folderCleanupMap.clear()
})

const handleFolderDelete = async (folder: AssetFolderResource) => {
  const confirmed = await alert.confirm(
    $t('messages.assetFolders.deleteConfirmation', { name: folder.name }),
    {
      title: $t('labels.assetFolders.deleteTitle'),
      confirmLabel: $t('actions.delete'),
      variant: 'destructive',
    }
  )

  if (!confirmed) {
    return
  }

  await deleteFolder(folder.id)
}

const handleToggle = (event: TreeItemToggleEvent<AssetFolderResource>) => {
  if (event.detail.originalEvent instanceof PointerEvent) {
    event.preventDefault()
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
</script>

<template>
  <div ref="treeContainerRef">
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
          'cursor-pointer font-semibold transition-colors duration-200 hover:bg-input',
          !selectedFolderId ? 'bg-input text-primary' : '',
          isRootDraggedOver ? 'bg-input/70 ring-1 ring-border' : '',
        ]"
        @click="selectedFolderId = null"
      >
        <Icon name="lucide:home" />
        <span>{{ $t('labels.assets.allAssets') }}</span>
      </button>

      <div class="my-2 flex items-center px-2">
        <h2 class="text-sm font-semibold text-primary">
          {{ $t('labels.assetFolders.title') }}
        </h2>
        <Button
          v-if="canManageFolders"
          class="ml-auto"
          size="xs"
          @click="openCreateFolderDialog(null)"
        >
          <Icon name="lucide:plus" />
        </Button>
      </div>

      <TreeItem
        v-for="item in flattenItems"
        :ref="setFolderElement(item.value)"
        v-slot="{ isExpanded }"
        :key="item._id"
        v-bind="item.bind"
        :style="{ 'padding-left': `${item.level - 0.5}rem` }"
        :class="[
          'group my-0.5 flex items-center rounded-md px-2 py-1 outline-none',
          'cursor-pointer font-semibold transition-colors duration-200 hover:bg-input',
          item.value.id === selectedFolderId ? 'bg-input text-primary' : '',
          activeDropTargetId === item.value.id ? 'bg-input/70 ring-1 ring-border' : '',
        ]"
        tabindex="0"
        :aria-selected="item.value.id === selectedFolderId"
        :aria-expanded="
          item.value.children_count ? expanded.includes(item.value.id).toString() : undefined
        "
        @select="selectedFolderId = item.value.id"
        @toggle="handleToggle"
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
            :disabled="!canManageFolders"
            @update="handleFolderRename($event, item.value.id)"
            @edit-start="currentlyEditingId = item.value.id"
            @cancel="currentlyEditingId = null"
          />
        </div>
        <DropdownMenu v-if="canManageFolders">
          <DropdownMenuTrigger
            class="opacity-0 transition-all duration-200 group-hover:opacity-100 hover:text-primary data-[state=open]:opacity-100"
          >
            <Icon name="lucide:ellipsis-vertical" />
          </DropdownMenuTrigger>
          <DropdownMenuContent>
            <DropdownMenuItem @select="selectedFolderId = item.value.id">
              <Icon name="lucide:eye" />
              <span>{{ $t('actions.view') }}</span>
            </DropdownMenuItem>
            <DropdownMenuItem @select="openEditFolderDialog(item.value)">
              <Icon name="lucide:edit" />
              <span>{{ $t('actions.edit') }}</span>
            </DropdownMenuItem>
            <DropdownMenuItem @select="openCreateFolderDialog(item.value.id)">
              <Icon name="lucide:folder-plus" />
              <span>{{ $t('actions.createFolder') }}</span>
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
      v-if="canManageFolders"
      v-model:open="folderDialogOpen"
      :folder="editingFolder"
      :parent-folder-id="dialogParentFolderId"
      :space-id="spaceId"
    />
  </div>
</template>
