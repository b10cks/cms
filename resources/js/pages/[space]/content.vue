<script setup lang="ts">
import ContentTree from '~/components/ContentTree.vue'
import Icon from '~/components/Icon.vue'
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '~/components/ui/resizable'
import useSpaceSettings from '~/composables/useSpaceSettings'

// Stored widths below this come from the old percent default, which clamped to one letter per row.
const MIN_TREE_WIDTH = 200

// The panel also reports a new size when the window changes. Only the handle sets the preference.
const handleActive = ref(false)
const saveTreeWidth = (size: number) => {
  if (handleActive.value) settings.value.content.treeWidth = size
}

const route = useRoute()
const { settings } = useSpaceSettings(route.params.space as string)

/** A phone shows the tree or the open entry. The tree stays mounted, so it keeps its position. */
const hasEntry = computed(() => Boolean(route.params.contentId))

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
    class="h-full min-h-0 w-full overflow-hidden phone:h-[calc(100dvh-3.5rem-var(--phone-tabbar))]"
  >
    <ResizablePanel
      id="content-panel-1"
      ref="sidebar"
      :class="[
        'min-h-0 h-full max-h-[calc(100svh-3.5rem)] overflow-hidden phone:flex phone:max-h-none phone:flex-[1_1_100%]! phone:flex-col',
        hasEntry && 'phone:hidden',
      ]"
      size-unit="px"
      :min-size="MIN_TREE_WIDTH"
      :max-size="512"
      :default-size="settings.content.treeWidth >= MIN_TREE_WIDTH ? settings.content.treeWidth : 320"
      @resize="saveTreeWidth"
    >
      <ContentTree
        ref="tree"
        class="phone:h-auto phone:flex-1"
        :space-id="route.params.space as string"
      />
      <div
        id="contentPhoneTreeActions"
        class="hidden shrink-0 flex-wrap gap-2 border-t border-sidebar-border bg-sidebar p-2 empty:hidden phone:flex [&>*]:flex-1 [&>*]:px-2"
      />
    </ResizablePanel>
    <ResizableHandle
      id="content-handle-1"
      class="phone:hidden"
      @dragging="handleActive = $event"
      @keydown="handleActive = true"
      @keyup="handleActive = false"
      @blur="handleActive = false"
      @dblclick="toggleSidebar"
    />
    <ResizablePanel
      id="content-panel-2"
      :class="[
        'flex min-h-0 grow overflow-hidden phone:flex-[1_1_100%]! phone:flex-col',
        !hasEntry && 'phone:hidden',
      ]"
    >
      <div
        class="hidden h-12 shrink-0 items-center gap-1 border-b border-border bg-sidebar px-1 phone:flex"
      >
        <RouterLink
          :to="{ name: 'space-content-index', params: { space: route.params.space } }"
          class="row-action"
          :aria-label="$t('actions.back')"
        >
          <Icon name="lucide:arrow-left" />
        </RouterLink>
        <div
          id="contentPhoneTitle"
          class="min-w-0 flex-1"
        />
        <div
          id="contentPhoneActions"
          class="flex shrink-0 items-center gap-1"
        />
      </div>
      <div class="flex min-h-0 min-w-0 flex-1">
        <RouterView />
      </div>
    </ResizablePanel>
  </ResizablePanelGroup>
</template>
