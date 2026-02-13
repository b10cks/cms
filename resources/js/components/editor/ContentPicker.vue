<template>
  <Dialog
    :open="open"
    @update:open="$emit('update:open', $event)"
  >
    <DialogContent class="max-h-[80vh] max-w-3xl">
      <DialogHeader>
        <DialogTitle>{{ title }}</DialogTitle>
      </DialogHeader>

      <ScrollArea class="h-[500px] pr-4">
        <TreeRoot
          v-slot="{ flattenItems }"
          class="w-full list-none select-none"
          :items="rootItems"
          :get-key="(item) => item?.id"
          :get-children="(item) => getChildren(contentMenu, item.id)"
        >
          <div class="space-y-1">
            <template
              v-for="item in flattenItems"
              :key="item._id"
            >
              <TreeItem
                v-slot="{ isExpanded }"
                :style="{ 'padding-left': `${item.level * 0.5}rem` }"
                v-bind="item.bind"
                :class="[
                  'group relative my-0.5 flex items-center gap-2 rounded-md pr-2 pl-0 outline-none',
                  'transition-colors duration-200 hover:bg-border',
                  'cursor-pointer font-semibold',
                ]"
              >
                <div class="flex flex-1 items-center gap-2">
                  <span
                    v-if="item.value.children"
                    class="h-4 w-3"
                  >
                    <Icon
                      name="lucide:chevron-right"
                      :class="['transition-transform duration-200', isExpanded && 'rotate-90']"
                    />
                  </span>
                  <span
                    v-else
                    class="size-3"
                  />
                  <button
                    class="flex grow cursor-pointer items-center gap-2 hover:text-primary"
                    tabindex="-1"
                    @click="selectContent(item.value.id)"
                  >
                    <Icon
                      :name="`lucide:${item.value.icon}`"
                      class="shrink-0"
                      :style="{ color: item.value.color }"
                    />
                    <span class="truncate">{{ item.value.name }}</span>
                  </button>
                </div>
                <Button
                  v-if="showElements"
                  variant="ghost"
                  size="sm"
                  class="h-6 w-6 p-1 opacity-0 group-hover:opacity-100"
                  @click.stop="toggleElementsView(item.value.id)"
                >
                  <Icon
                    name="lucide:arrow-right"
                    class="h-3 w-3 text-accent-foreground"
                  />
                </Button>
              </TreeItem>

              <!-- Page Elements Section -->
              <div
                v-if="showElements && showElementsForContent === item.value.id"
                class="space-y-2 rounded-md border bg-surface p-4"
                :style="{ 'margin-left': `${(item.level + 1) * 1.5}rem` }"
              >
                <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-primary">
                  <Icon name="lucide:hash" />
                  {{ $t('labels.content.pageElements') }}
                </h4>
                <div class="grid gap-2">
                  <div
                    v-for="element in getContentElements(item.value.id)"
                    :key="element.id"
                    class="flex cursor-pointer items-center justify-between rounded border p-3 hover:bg-input"
                    @click="selectContentWithAnchor(item.value.id, element.id)"
                  >
                    <div class="flex items-center gap-2">
                      <Icon
                        name="lucide:bookmark"
                        class="text-muted-foreground"
                      />
                      <span class="text-sm font-medium">{{ element.name }}</span>
                    </div>
                    <Icon
                      name="lucide:external-link"
                      class="text-muted-foreground"
                    />
                  </div>
                </div>
              </div>
            </template>
          </div>
        </TreeRoot>
      </ScrollArea>
    </DialogContent>
  </Dialog>
</template>

<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { TreeItem, TreeRoot } from 'reka-ui'
import { computed, ref } from 'vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import { ScrollArea } from '~/components/ui/scroll-area'

interface ContentElement {
  id: string
  name: string
}

const props = defineProps<{
  open: boolean
  spaceId: string
  title?: string
  showElements?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  'content-select': [contentId: string]
  'content-with-anchor-select': [contentId: string, anchorId: string]
}>()

const { $t } = useI18n()
const { useContentMenuQuery, getRootItems, getChildren } = useContentMenu(props.spaceId)
const { data: contentMenu } = useContentMenuQuery()

const rootItems = computed(() => getRootItems(contentMenu.value) || [])
const showElementsForContent = ref<string | null>(null)

// Get content elements for selected page (mock implementation)
const getContentElements = (contentId: string): ContentElement[] => {
  return [
    { id: 'header', name: $t('labels.content.elements.header') },
    { id: 'main-content', name: $t('labels.content.elements.mainContent') },
    { id: 'sidebar', name: $t('labels.content.elements.sidebar') },
    { id: 'footer', name: $t('labels.content.elements.footer') },
  ]
}

const selectContent = (contentId: string) => {
  emit('content-select', contentId)
}

const selectContentWithAnchor = (contentId: string, anchorId: string) => {
  emit('content-with-anchor-select', contentId, anchorId)
  showElementsForContent.value = null
}

const toggleElementsView = (contentId: string) => {
  showElementsForContent.value = showElementsForContent.value === contentId ? null : contentId
}
</script>
