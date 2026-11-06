<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core'

import ContentTree from '~/components/ContentTree.vue'
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '~/components/ui/resizable'
import useSpaceSettings from '~/composables/useSpaceSettings'

// Stored widths below this come from the old percent default, which clamped to one letter per row.
const MIN_TREE_WIDTH = 200
// No phone layout yet: keep the tree small so the pane beside it has room.
const isPhone = useMediaQuery('(max-width: 767px)')

// The panel also reports a new size when the window changes. Only the handle sets the preference.
const handleActive = ref(false)
const saveTreeWidth = (size: number) => {
  if (handleActive.value && !isPhone.value) settings.value.content.treeWidth = size
}

const route = useRoute()
const { settings } = useSpaceSettings(route.params.space as string)

const sidebar = useTemplateRef<InstanceType<typeof ResizablePanel>>('sidebar')
const tree = useTemplateRef<InstanceType<typeof ContentTree>>('tree')

provide('createRootContent', () => tree.value?.initCreate(null))

const toggleSidebar = () => {
  if (sidebar.value) {
    if (sidebar.value.isCollapsed) {
      sidebar.value.expand()
    } else {
      sidebar.value.collapse()
    }
  }
}
</script>

<template>
  <ResizablePanelGroup
    id="content-group-1"
    direction="horizontal"
    class="h-full min-h-0 w-full overflow-hidden"
  >
    <ResizablePanel
      id="content-panel-1"
      ref="sidebar"
      class="min-h-0 h-full max-h-[calc(100svh-3.5rem)] overflow-hidden"
      size-unit="px"
      :min-size="isPhone ? 120 : MIN_TREE_WIDTH"
      :max-size="512"
      :default-size="isPhone ? 120 : settings.content.treeWidth >= MIN_TREE_WIDTH ? settings.content.treeWidth : 320"
      @resize="saveTreeWidth"
    >
      <ContentTree
        ref="tree"
        :space-id="route.params.space as string"
      />
    </ResizablePanel>
    <ResizableHandle
      id="content-handle-1"
      @dragging="handleActive = $event"
      @keydown="handleActive = true"
      @keyup="handleActive = false"
      @blur="handleActive = false"
      @dblclick="toggleSidebar"
    />
    <ResizablePanel
      id="content-panel-2"
      class="flex min-h-0 grow overflow-hidden"
    >
      <RouterView />
    </ResizablePanel>
  </ResizablePanelGroup>
</template>
