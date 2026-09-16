<template>
  <Dialog
    :open="open"
    @update:open="$emit('update:open', $event)"
  >
    <DialogContent class="max-w-3xl">
      <DialogHeader>
        <DialogTitle>{{ title }}</DialogTitle>
      </DialogHeader>

      <div
        class="flex items-center gap-2 rounded-md border border-border bg-background py-1 pr-1 pl-2 shadow-sm"
      >
        <Icon
          name="lucide:search"
          class="shrink-0 text-muted"
        />
        <input
          v-model="searchQuery"
          type="text"
          :placeholder="$t('labels.contentTree.search.placeholder')"
          :aria-label="$t('labels.contentTree.search.open')"
          class="h-7 min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted"
          @keydown.enter.prevent="selectBestMatch"
        />
        <span
          v-if="searchActive && searchMatches.length === 0"
          aria-live="polite"
          class="shrink-0 text-xs text-destructive"
        >
          {{ $t('labels.contentTree.search.noMatches') }}
        </span>
        <Button
          v-if="searchQuery"
          type="button"
          variant="ghost"
          size="toolbar"
          :aria-label="$t('labels.contentTree.search.close')"
          @click="searchQuery = ''"
        >
          <Icon name="lucide:x" />
        </Button>
      </div>

      <ScrollArea class="h-[500px] pr-4">
        <TreeRoot
          v-slot="{ flattenItems }"
          v-model:expanded="treeExpanded"
          class="w-full list-none select-none"
          :items="visibleRootItems"
          :get-key="(item) => item?.id"
          :get-children="getVisibleChildren"
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
                    v-if="searchActive ? !!getVisibleChildren(item.value) : item.value.children"
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
                    <span class="truncate">
                      <HighlightedText
                        :text="item.value.name"
                        :highlight="searchHighlights.get(item.value.id)"
                      />
                    </span>
                  </button>
                </div>
                <Button
                  v-if="showElements"
                  type="button"
                  variant="ghost"
                  size="xs"
                  :class="[
                    'shrink-0 gap-1 font-medium',
                    showElementsForContent === item.value.id
                      ? 'bg-secondary text-primary'
                      : 'text-muted-foreground hover:text-primary',
                  ]"
                  :aria-label="$t('labels.content.pageElements')"
                  :aria-expanded="showElementsForContent === item.value.id"
                  :title="$t('labels.content.pageElements')"
                  @click.stop="toggleElementsView(item.value.id)"
                >
                  <Icon name="lucide:list-tree" />
                  <Icon
                    name="lucide:chevron-down"
                    :class="[
                      'transition-transform duration-200',
                      showElementsForContent === item.value.id && 'rotate-180',
                    ]"
                  />
                </Button>
              </TreeItem>

              <div
                v-if="showElements && showElementsForContent === item.value.id"
                class="mb-2 rounded-2xl border border-border bg-surface px-2 pt-2"
                :style="{ 'margin-left': `${item.level * 0.5 + 1.25}rem` }"
              >
                <div class="flex items-center gap-2 pb-2 pl-1 text-xs font-medium text-muted-foreground">
                  <Icon name="lucide:list-tree" />
                  {{ $t('labels.content.pageElements') }}
                  <Button
                    type="button"
                    variant="ghost"
                    size="xs"
                    class="ml-auto text-primary"
                    @click="selectContent(item.value.id)"
                  >
                    <Icon name="lucide:file-symlink" />
                    {{ $t('labels.link.linkToPage') }}
                  </Button>
                </div>
                <div
                  v-if="elementsLoading"
                  class="grid gap-2 pb-2"
                >
                  <Skeleton
                    v-for="i in 3"
                    :key="i"
                    class="h-12 rounded-lg"
                  />
                </div>
                <p
                  v-else-if="anchors.length === 0"
                  class="flex items-center gap-2 rounded-lg border border-dashed border-border p-3 mb-2 text-sm text-muted-foreground"
                >
                  <Icon name="lucide:layers" />
                  {{ $t('labels.content.noPageElements') }}
                </p>
                <div
                  v-else
                  class="grid gap-2 pb-2"
                >
                  <div
                    v-for="anchor in anchors"
                    :key="anchor.id"
                    class="flex items-center gap-2"
                    :style="{ 'padding-left': `${Math.max(anchor.depth - 1, 0) * 1.5}rem` }"
                  >
                    <Icon
                      v-if="anchor.depth > 0"
                      name="lucide:corner-down-right"
                      class="shrink-0 text-muted-foreground"
                    />
                    <button
                      type="button"
                      class="group/anchor flex min-w-0 grow cursor-pointer items-center gap-2 rounded-lg border border-border bg-background p-2 text-left transition-colors hover:border-primary/40 hover:bg-input focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                      @click="selectContentWithAnchor(item.value.id, anchor.id)"
                    >
                      <Icon
                        :name="anchor.block.icon ? `lucide:${anchor.block.icon}` : 'lucide:box'"
                        :class="['shrink-0', !anchor.block.icon && 'text-muted-foreground']"
                        :style="{ color: anchor.block.color ?? undefined }"
                      />
                      <div class="grid min-w-0 grow leading-tight">
                        <span
                          class="truncate font-semibold text-primary [&_img]:inline [&_img]:size-4"
                          v-html="anchor.title"
                        />
                        <span class="truncate text-sm text-muted">
                          {{ anchor.block.name || anchor.block.slug }}
                        </span>
                      </div>
                      <Icon
                        name="lucide:link"
                        class="shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover/anchor:opacity-100 group-focus-visible/anchor:opacity-100"
                      />
                    </button>
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
import { TreeItem, TreeRoot } from 'reka-ui'
import { computed, ref, watch } from 'vue'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '~/components/ui/dialog'
import HighlightedText from '~/components/ui/HighlightedText.vue'
import { ScrollArea } from '~/components/ui/scroll-area'
import { Skeleton } from '~/components/ui/skeleton'
import { fuzzyMatch, prepareFuzzyQuery, prepareFuzzyTarget } from '~/lib/fuzzy-match'

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

const showElementsForContent = ref<string | null>(null)

const { anchors, isLoading: elementsLoading } = useContentAnchors(
  props.spaceId,
  showElementsForContent
)

const searchQuery = ref('')
const expanded = ref<string[]>([])

// Tree order, so the first best match is also the topmost one.
const flatItems = computed(() => {
  const menuData = contentMenu.value
  if (!menuData) return []

  const ordered: FlatContentMenuItem[] = []
  const visit = (item: FlatContentMenuItem) => {
    ordered.push(item)
    getChildren(menuData, item.id).forEach(visit)
  }
  getRootItems(menuData).forEach(visit)

  return ordered
})

// Names are normalized once per tree change, not once per keystroke.
const searchTargets = computed(() =>
  flatItems.value.map((item) => ({ item, target: prepareFuzzyTarget(item.name) }))
)

const searchActive = computed(() => searchQuery.value.trim().length > 0)

const searchMatches = computed(() => {
  const query = prepareFuzzyQuery(searchQuery.value)
  if (!query) return []

  return searchTargets.value.flatMap(({ item, target }) => {
    const match = fuzzyMatch(query, target)
    return match ? [{ item, score: match.score, indices: match.indices }] : []
  })
})

const searchHighlights = computed(
  () => new Map(searchMatches.value.map((match) => [match.item.id, match.indices]))
)

// Matches plus the ancestor chain needed to show them in context.
const searchAncestorIds = computed(() => {
  const ancestors = new Set<string>()
  for (const { item } of searchMatches.value) {
    let parentId = item.pid ?? null
    while (parentId && !ancestors.has(parentId)) {
      ancestors.add(parentId)
      parentId = contentMenu.value?.[parentId]?.pid ?? null
    }
  }

  return ancestors
})

const visibleIds = computed<Set<string> | null>(() =>
  searchActive.value
    ? new Set([...searchAncestorIds.value, ...searchHighlights.value.keys()])
    : null
)

const visibleRootItems = computed(() => {
  const roots = getRootItems(contentMenu.value)
  const visible = visibleIds.value
  return visible ? roots.filter((item) => visible.has(item.id)) : roots
})

const getVisibleChildren = (item: FlatContentMenuItem) => {
  const children = getChildren(contentMenu.value, item.id)
  const visible = visibleIds.value
  const filtered = visible ? children.filter((child) => visible.has(child.id)) : children
  return filtered.length ? filtered : undefined
}

// Searching expands every ancestor of a match; toggles made meanwhile are
// thrown away once the query changes, so the browsing state stays untouched.
const searchExpandedOverride = ref<string[] | null>(null)

const treeExpanded = computed<string[]>({
  get: () =>
    searchActive.value
      ? (searchExpandedOverride.value ?? [...searchAncestorIds.value])
      : expanded.value,
  set: (value) => {
    if (searchActive.value) searchExpandedOverride.value = value
    else expanded.value = value
  },
})

watch(searchQuery, () => {
  searchExpandedOverride.value = null
})

watch(
  () => props.open,
  (open) => {
    if (open) searchQuery.value = ''
  }
)

const selectContent = (contentId: string) => {
  emit('content-select', contentId)
}

const selectBestMatch = () => {
  const best = searchMatches.value.reduce<(typeof searchMatches.value)[number] | null>(
    (current, match) => (!current || match.score > current.score ? match : current),
    null
  )
  if (best) selectContent(best.item.id)
}

const selectContentWithAnchor = (contentId: string, anchorId: string) => {
  emit('content-with-anchor-select', contentId, anchorId)
  showElementsForContent.value = null
}

const toggleElementsView = (contentId: string) => {
  showElementsForContent.value = showElementsForContent.value === contentId ? null : contentId
}
</script>
