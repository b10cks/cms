<script setup lang="ts">
import ContentTree from '~/components/ContentTree.vue'
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '~/components/ui/resizable'
import useSpaceSettings from '~/composables/useSpaceSettings'

const route = useRoute()
const { settings } = useSpaceSettings(route.params.space as string)

const sidebar = useTemplateRef<InstanceType<typeof ResizablePanel>>('sidebar')

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
  >
    <ResizablePanel
      id="content-panel-1"
      ref="sidebar"
      size-unit="px"
      collapsible
      :min-size="120"
      :max-size="512"
      :default-size="settings.content.treeWidth"
      @resize="(size) => (settings.content.treeWidth = size)"
    >
      <ContentTree :space-id="route.params.space as string" />
    </ResizablePanel>
    <ResizableHandle
      id="content-handle-1"
      @dblclick="toggleSidebar"
    />
    <ResizablePanel
      id="content-panel-2"
      class="flex grow"
    >
      <RouterView />
    </ResizablePanel>
  </ResizablePanelGroup>
</template>
