<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'

const props = withDefaults(
  defineProps<{
    assetCount: number
    folderCount?: number
    offPageCount?: number
    totalMatching?: number
    canSelectAllMatching?: boolean
    isSelectingAllMatching?: boolean
    canManage?: boolean
    canDownload?: boolean
  }>(),
  {
    folderCount: 0,
    offPageCount: 0,
    totalMatching: 0,
    canSelectAllMatching: false,
    isSelectingAllMatching: false,
    canManage: true,
    canDownload: true,
  }
)

const emit = defineEmits<{
  move: []
  tag: []
  download: []
  delete: []
  clear: []
  selectAllMatching: []
}>()

const selectionCount = computed(() => props.assetCount + props.folderCount)
</script>

<template>
  <Transition
    enter-active-class="transition duration-150 ease-out"
    enter-from-class="translate-y-4 opacity-0"
    leave-active-class="transition duration-100 ease-in"
    leave-to-class="translate-y-4 opacity-0"
  >
    <div
      v-if="selectionCount > 0"
      class="fixed bottom-6 left-1/2 z-40 flex -translate-x-1/2 items-center gap-1 rounded-full border border-border bg-popover py-1.5 pr-2 pl-4 text-popover-foreground shadow-soft-lg"
      role="toolbar"
      :aria-label="String($t('labels.assets.selection.toolbar'))"
    >
      <div class="flex items-center gap-2 pr-2">
        <Badge variant="secondary">
          {{ $t('labels.selectionCount', { count: selectionCount }) }}
        </Badge>
        <span
          v-if="offPageCount > 0"
          class="text-xs whitespace-nowrap text-muted"
        >
          {{ $t('labels.assets.selection.onOtherPages', { count: offPageCount }) }}
        </span>
        <Button
          v-if="canSelectAllMatching"
          variant="ghost"
          size="sm"
          class="whitespace-nowrap"
          :loading="isSelectingAllMatching"
          @click="emit('selectAllMatching')"
        >
          {{ $t('labels.assets.selection.selectAllMatching', { count: totalMatching }) }}
        </Button>
      </div>

      <div class="h-5 w-px bg-border" />

      <Button
        v-if="canManage"
        variant="ghost"
        size="sm"
        @click="emit('move')"
      >
        <Icon name="lucide:folder-input" />
        {{ $t('actions.move') }}
      </Button>
      <Button
        v-if="canManage && assetCount > 0"
        variant="ghost"
        size="sm"
        @click="emit('tag')"
      >
        <Icon name="lucide:tags" />
        {{ $t('actions.assets.tag') }}
      </Button>
      <Button
        v-if="canDownload && assetCount > 0"
        variant="ghost"
        size="sm"
        @click="emit('download')"
      >
        <Icon name="lucide:download" />
        {{ $t('actions.assets.download') }}
      </Button>
      <Button
        v-if="canManage"
        variant="ghost"
        size="sm"
        class="text-destructive hover:text-destructive"
        @click="emit('delete')"
      >
        <Icon name="lucide:trash-2" />
        {{ $t('actions.delete') }}
      </Button>

      <div class="h-5 w-px bg-border" />

      <Button
        variant="ghost"
        size="sm"
        :aria-label="String($t('actions.clearSelection'))"
        @click="emit('clear')"
      >
        <Icon name="lucide:x" />
      </Button>
    </div>
  </Transition>
</template>
