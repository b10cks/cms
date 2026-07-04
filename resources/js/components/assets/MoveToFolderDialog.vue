<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { Input } from '~/components/ui/input'
import type { AssetManagerDragItem } from '~/lib/assets/assetDragAndDrop'
import type { AssetFolderResource } from '~/types/assets'

const props = defineProps<{
  spaceId: string
  items: AssetManagerDragItem[]
}>()

const open = defineModel<boolean>('open', { default: false })

const emit = defineEmits<{
  moved: [targetFolderId: string | null]
}>()

const { $t } = useI18n()
const { useFolderStructure } = useAssetFolders(props.spaceId)
const { canMoveItems, moveItemsToFolder } = useAssetLibraryMoves(props.spaceId)
const { getChildrenOfFolder, folders } = useFolderStructure()

const search = ref('')
const targetFolderId = ref<string | null | undefined>(undefined)
const isMoving = ref(false)

interface FolderRow {
  folder: AssetFolderResource
  depth: number
}

const flattenedFolders = computed<FolderRow[]>(() => {
  const rows: FolderRow[] = []

  const walk = (parentId: string | null, depth: number) => {
    for (const folder of getChildrenOfFolder(parentId)) {
      rows.push({ folder, depth })
      walk(folder.id, depth + 1)
    }
  }

  walk(null, 0)
  return rows
})

const visibleFolders = computed<FolderRow[]>(() => {
  const term = search.value.trim().toLowerCase()

  if (!term) {
    return flattenedFolders.value
  }

  return (folders.value ?? [])
    .filter((folder) => folder.name.toLowerCase().includes(term))
    .map((folder) => ({ folder, depth: 0 }))
})

const isValidTarget = (folderId: string | null): boolean => {
  return canMoveItems(props.items, folderId)
}

const canSubmit = computed(() => {
  return targetFolderId.value !== undefined && isValidTarget(targetFolderId.value)
})

const handleSubmit = async () => {
  if (!canSubmit.value || targetFolderId.value === undefined) {
    return
  }

  isMoving.value = true

  try {
    await moveItemsToFolder(props.items, targetFolderId.value)
    emit('moved', targetFolderId.value)
    open.value = false
  } catch {
    toast.error(String($t('messages.assetFolders.invalidMoveToChild')))
  } finally {
    isMoving.value = false
  }
}

watch(open, (isOpen) => {
  if (isOpen) {
    search.value = ''
    targetFolderId.value = undefined
  }
})
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-h-[85svh] sm:max-w-lg">
      <DialogHeaderCombined
        :title="$t('labels.assets.move.title', { count: items.length })"
        :description="$t('labels.assets.move.description')"
      />

      <div class="flex min-h-0 flex-col gap-3">
        <Input
          v-model="search"
          :placeholder="String($t('labels.assets.move.searchPlaceholder'))"
        />

        <div class="max-h-80 overflow-y-auto rounded-md border border-input p-1">
          <button
            type="button"
            :class="[
              'flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm transition-colors',
              targetFolderId === null ? 'bg-accent text-accent-foreground' : 'hover:bg-input',
            ]"
            :disabled="!isValidTarget(null)"
            @click="targetFolderId = null"
          >
            <Icon name="lucide:home" />
            <span class="font-medium">{{ $t('labels.assets.allAssets') }}</span>
          </button>

          <button
            v-for="{ folder, depth } in visibleFolders"
            :key="folder.id"
            type="button"
            :class="[
              'flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm transition-colors',
              targetFolderId === folder.id ? 'bg-accent text-accent-foreground' : 'hover:bg-input',
              !isValidTarget(folder.id) ? 'pointer-events-none opacity-40' : '',
            ]"
            :style="{ paddingLeft: `${0.5 + depth * 1.25}rem` }"
            :disabled="!isValidTarget(folder.id)"
            @click="targetFolderId = folder.id"
          >
            <Icon
              :name="`lucide:${folder.icon || 'folder'}`"
              :style="{ color: folder.color || 'inherit' }"
            />
            <span class="truncate font-medium">{{ folder.name }}</span>
            <span class="ml-auto shrink-0 text-xs text-muted">
              {{ folder.assets_count ?? 0 }}
            </span>
          </button>

          <p
            v-if="!visibleFolders.length"
            class="px-2 py-3 text-center text-sm text-muted"
          >
            {{ $t('labels.assets.move.noFolders') }}
          </p>
        </div>
      </div>

      <DialogFooter>
        <Button
          type="button"
          variant="outline"
          @click="open = false"
        >
          {{ $t('alertDialog.cancel') }}
        </Button>
        <Button
          type="button"
          variant="primary"
          :disabled="!canSubmit || isMoving"
          @click="handleSubmit"
        >
          <Icon
            v-if="isMoving"
            name="lucide:loader"
            class="animate-spin"
          />
          <Icon
            v-else
            name="lucide:folder-input"
          />
          {{ $t('actions.move') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
