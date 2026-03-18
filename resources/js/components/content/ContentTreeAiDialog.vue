<script setup lang="ts">
import { TreeItem, TreeRoot } from 'reka-ui'
import type { MentionItem } from '~/api/resources/ai'
import Icon from '~/components/Icon.vue'
import IconName from '~/components/ui/IconName.vue'
import AiText from '~/components/ui/AiText.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import { ScrollArea } from '~/components/ui/scroll-area'
import {
  parseTreeOperations,
  useAiContentTree,
  type TreeOperation,
} from '~/composables/useAiContentTree'

function extractStreamingOperations(partial: string): TreeOperation[] {
  const match = partial.match(/"operations"\s*:\s*\[([\s\S]*)$/)
  if (!match) return []

  const arrayContent = match[1]
  const ops: TreeOperation[] = []
  let depth = 0
  let start = -1

  for (let i = 0; i < arrayContent.length; i++) {
    const ch = arrayContent[i]
    if (ch === '{') {
      if (depth === 0) start = i
      depth++
    } else if (ch === '}') {
      depth--
      if (depth === 0 && start !== -1) {
        try {
          const obj = JSON.parse(arrayContent.slice(start, i + 1))
          if (obj.type === 'create' || obj.type === 'move') ops.push(obj as TreeOperation)
        } catch {}
        start = -1
      }
    }
  }

  return ops
}
import type { ContentResource } from '~/types/contents'

const props = defineProps<{
  spaceId: string
  tree: ContentResource[]
}>()

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const { streamTreeInteraction, cancelStream, isStreaming } = useAiContentTree(
  toRef(props, 'spaceId')
)
const { useContentMutation, useBulkCreateContentMutation, useMoveContentMutation } = useContent(
  props.spaceId
)

const aiTextRef = useTemplateRef('aiTextRef')
const selectedConfigId = ref<string | null>(null)
const previewContent = ref<string>('')
const operations = ref<TreeOperation[]>([])
const isApplying = ref(false)
const showPreview = ref(false)
const expandedKeys = ref<string[]>([])
const previewExpandedKeys = ref<string[]>([])
const operationStatus = ref<{ message: string; type: 'info' | 'success' | 'error' } | null>(null)

const { mutateAsync: bulkCreate } = useBulkCreateContentMutation()
const { mutateAsync: moveContent } = useMoveContentMutation()

// Flatten tree to array (like ContentMenu data)
const flattenTree = (items: ContentResource[]): ContentResource[] => {
  if (!Array.isArray(items)) return []

  const result: ContentResource[] = []
  const flatten = (nodes: ContentResource[]) => {
    nodes.forEach((node) => {
      result.push(node)
      const children = node.children as unknown as ContentResource[]
      if (Array.isArray(children) && children.length > 0) {
        flatten(children)
      }
    })
  }
  flatten(items)
  return result
}

// Flatten current tree
const flatData = computed(() => flattenTree(props.tree))

// Get root items (items without parent_id)
const getRootItems = (data: ContentResource[] | null | undefined): ContentResource[] => {
  if (!data || !Array.isArray(data)) return []
  return data.filter((item) => !item.parent_id)
}

// Get children for an item (items where parent_id matches)
const getChildren = (
  data: ContentResource[] | null | undefined,
  itemId: string
): ContentResource[] => {
  if (!data || !Array.isArray(data)) return []
  return data.filter((item) => item.parent_id === itemId)
}

// Root items for current tree
const rootItems = computed(() => getRootItems(flatData.value))

// Auto-expand all items on load
watch(
  () => flatData.value,
  (data) => {
    if (data && data.length > 0) {
      expandedKeys.value = data.map((item) => item.id)
    }
  },
  { immediate: true }
)

// Preview data (flat)
const previewFlatData = computed(() => {
  if (!showPreview.value || operations.value.length === 0) {
    return flatData.value
  }

  return applyOperationsToFlatData(flatData.value, operations.value)
})

// Root items for preview tree
const previewRootItems = computed(() => getRootItems(previewFlatData.value))

// Auto-expand preview items
watch(
  () => previewFlatData.value,
  (data) => {
    if (data && data.length > 0) {
      previewExpandedKeys.value = data.map((item) => item.id)
    }
  }
)

function applyOperationsToFlatData(
  flatData: ContentResource[],
  ops: TreeOperation[]
): ContentResource[] {
  if (!Array.isArray(flatData)) return []

  // Create a map of existing items
  const itemMap = new Map<string, ContentResource & { _isNew?: boolean; _isMoved?: boolean }>()

  flatData.forEach((item) => {
    itemMap.set(item.id, { ...item })
  })

  // Apply operations
  ops.forEach((op) => {
    if (op.type === 'create') {
      const newItem: ContentResource & { _isNew?: boolean } = {
        id: op.temp_id || `temp_${Math.random()}`,
        name: op.name || 'New Item',
        slug: op.slug || 'new-item',
        parent_id: op.parent_id || null,
        block_id: op.block_id || '',
        block: {
          id: op.block_id || '',
          icon: 'file',
          name: op.name || 'New Item',
          slug: op.slug || 'new-item',
        },
        content: {},
        settings: { disablePreview: false },
        full_slug: op.slug || 'new-item',
        i18n_parent_id: null,
        description: '',
        published_version_id: null,
        current_version_id: null,
        first_published_at: null,
        published_at: null,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        _isNew: true,
      }

      itemMap.set(newItem.id, newItem)
    } else if (op.type === 'move' && op.id) {
      const item = itemMap.get(op.id)
      if (item) {
        item.parent_id = op.parent_id || null
        item._isMoved = true
      }
    }
  })

  // Return as flat array (like ContentMenu data structure)
  return Array.from(itemMap.values())
}

const handleSubmit = async (
  rawText: string,
  _files: never[],
  configId: string | null,
  mentions: MentionItem[]
) => {
  selectedConfigId.value = configId
  previewContent.value = ''
  operations.value = []
  showPreview.value = false
  operationStatus.value = { message: t('components.contentTreeAi.generating') as string, type: 'info' }

  await streamTreeInteraction(
    {
      prompt: rawText,
      tree: props.tree,
      config_id: configId,
      mentions,
    },
    {
      onStatus: (message) => {
        operationStatus.value = { message, type: 'info' }
      },
      onDelta: (chunk) => {
        previewContent.value += chunk

        const partial = extractStreamingOperations(previewContent.value)
        if (partial.length > 0) {
          operations.value = partial
          showPreview.value = true
          operationStatus.value = {
            message: t('components.contentTreeAi.streamingProgress', { count: partial.length }) as string,
            type: 'info',
          }
        }
      },
      onDone: (content) => {
        const parsed = parseTreeOperations(content)
        if (parsed) {
          operations.value = parsed.operations
          showPreview.value = true

          const createCount = parsed.operations.filter((op) => op.type === 'create').length
          const moveCount = parsed.operations.filter((op) => op.type === 'move').length
          const parts: string[] = []
          if (createCount > 0)
            parts.push(t('components.contentTreeAi.willCreate', { count: createCount }) as string)
          if (moveCount > 0)
            parts.push(t('components.contentTreeAi.willMove', { count: moveCount }) as string)

          operationStatus.value = parts.length
            ? { message: parts.join(' • '), type: 'success' }
            : null
        } else {
          operationStatus.value = {
            message: t('components.contentTreeAi.parseError') as string,
            type: 'error',
          }
        }
      },
      onError: (message) => {
        operationStatus.value = { message, type: 'error' }
      },
    }
  )
}

const handleApply = async () => {
  if (operations.value.length === 0) return

  isApplying.value = true

  try {
    // First, create all new items
    const createOps = operations.value.filter((op) => op.type === 'create')
    if (createOps.length > 0) {
      operationStatus.value = {
        message: t('components.contentTreeAi.creatingItems', {
          count: createOps.length,
        }) as string,
        type: 'info',
      }

      const items = createOps.map((op) => {
        if (!op.block_id) {
          throw new Error(`Missing block_id for item: ${op.name}`)
        }
        return {
          name: op.name!,
          slug: op.slug!,
          block_id: op.block_id,
          parent_id: op.parent_id || null,
          temp_id: op.temp_id,
        }
      })

      await bulkCreate({ items })
    }

    // Then, move existing items
    const moveOps = operations.value.filter((op) => op.type === 'move')
    if (moveOps.length > 0) {
      operationStatus.value = {
        message: t('components.contentTreeAi.movingItems', {
          count: moveOps.length,
        }) as string,
        type: 'info',
      }

      for (const op of moveOps) {
        if (op.id) {
          await moveContent({
            id: op.id,
            payload: {
              parent_id: op.parent_id || null,
              position: op.position,
            },
          })
        }
      }
    }

    operationStatus.value = {
      message: t('components.contentTreeAi.success') as string,
      type: 'success',
    }

    // Wait a bit to show success message
    await new Promise((resolve) => setTimeout(resolve, 1000))

    open.value = false
    operations.value = []
    showPreview.value = false
    operationStatus.value = null
  } catch (error: any) {
    operationStatus.value = {
      message: t('components.contentTreeAi.applyError', { error: error.message }) as string,
      type: 'error',
    }
  } finally {
    isApplying.value = false
  }
}

const handleCancel = () => {
  cancelStream()
  previewContent.value = ''
  operationStatus.value = null
}

const handleClose = () => {
  if (isStreaming.value) {
    cancelStream()
  }
  operations.value = []
  showPreview.value = false
  previewContent.value = ''
  operationStatus.value = null
  open.value = false
}

const handleToggle = (e: any) => {
  if (e.detail.originalEvent instanceof PointerEvent) {
    e.preventDefault()
  }
}

const toggleExpanded = (contentId: string, isPreview: boolean = false) => {
  const keys = isPreview ? previewExpandedKeys.value : expandedKeys.value
  const index = keys.indexOf(contentId)
  if (index > -1) {
    keys.splice(index, 1)
  } else {
    keys.push(contentId)
  }
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleClose"
  >
    <DialogContent class="max-w-6xl max-h-[90vh] flex flex-col">
      <DialogHeader>
        <DialogTitle>{{ $t('components.contentTreeAi.title') }}</DialogTitle>
        <DialogDescription>
          {{ $t('components.contentTreeAi.description') }}
        </DialogDescription>
      </DialogHeader>

      <div class="flex-1 flex gap-4 min-h-0">
        <!-- Current Tree -->
        <div class="flex-1 flex flex-col">
          <h3 class="text-sm font-semibold mb-2">
            {{ $t('components.contentTreeAi.currentTree') }}
          </h3>
          <ScrollArea class="flex-1 bg-surface rounded-lg">
            <TreeRoot
              v-slot="{ flattenItems }"
              v-model:expanded="expandedKeys"
              class="w-full list-none p-2 select-none"
              :items="rootItems"
              :get-children="(item) => getChildren(flatData, item.id)"
              :get-key="({ id }) => id"
            >
              <TreeItem
                v-for="item in flattenItems"
                v-slot="{ isExpanded }"
                :key="item._id"
                :style="{ 'padding-left': `${item.level - 0.5}rem` }"
                v-bind="item.bind"
                class="group relative my-0.5 flex items-center gap-2 rounded-md py-1 pr-2 pl-0 outline-none transition-colors duration-200 hover:bg-border cursor-pointer font-semibold"
                @toggle="(e) => handleToggle(e)"
              >
                <button
                  v-if="item.value.children"
                  class="h-4 w-3"
                  @click.stop.prevent="toggleExpanded(item.value.id, false)"
                >
                  <Icon
                    name="lucide:chevron-right"
                    :class="['transition-transform duration-200', isExpanded && 'rotate-90']"
                  />
                </button>
                <span
                  v-else
                  class="size-3"
                />

                <IconName
                  :icon="item.value.block?.icon || 'file'"
                  :color="item.value.color"
                  :name="item.value.name"
                />
              </TreeItem>
            </TreeRoot>
          </ScrollArea>
        </div>
        <div
          v-if="showPreview"
          class="flex-1 flex flex-col"
        >
          <h3 class="text-sm font-semibold mb-2">
            {{ $t('components.contentTreeAi.preview') }}
          </h3>
          <ScrollArea class="flex-1 bg-surface rounded-lg">
            <TreeRoot
              v-slot="{ flattenItems }"
              v-model:expanded="previewExpandedKeys"
              class="w-full list-none p-2 select-none"
              :items="previewRootItems"
              :get-children="(item) => getChildren(previewFlatData, item.id)"
              :get-key="({ id }) => id"
            >
              <TreeItem
                v-for="item in flattenItems"
                v-slot="{ isExpanded }"
                :key="item._id"
                :style="{ 'padding-left': `${item.level - 0.5}rem` }"
                v-bind="item.bind"
                :class="[
                  'group relative my-0.5 flex items-center gap-2 rounded-md py-1 pr-2 pl-0 outline-none transition-colors duration-200 cursor-pointer',
                  item.value._isNew && 'bg-success/10',
                  item.value._isMoved && 'bg-warning/10',
                  !item.value._isNew && !item.value._isMoved && 'hover:bg-muted',
                ]"
                @toggle="(e) => handleToggle(e)"
              >
                <button
                  v-if="item.value.children"
                  class="h-4 w-3"
                  @click.stop.prevent="toggleExpanded(item.value.id, true)"
                >
                  <Icon
                    name="lucide:chevron-right"
                    :class="['transition-transform duration-200', isExpanded && 'rotate-90']"
                  />
                </button>
                <span
                  v-else
                  class="size-3"
                />

                <Icon
                  v-if="item.value._isNew"
                  name="lucide:plus-circle"
                  class="shrink-0 size-4 text-success"
                />
                <Icon
                  v-else-if="item.value._isMoved"
                  name="lucide:move"
                  class="shrink-0 size-4 text-warning"
                />
                <Icon
                  v-else
                  :name="`lucide:${item.value.block?.icon || 'file'}`"
                  class="shrink-0 size-4"
                  :style="{ color: item.value.color }"
                />

                <span class="truncate text-sm">{{ item.value.name }}</span>
              </TreeItem>
            </TreeRoot>
          </ScrollArea>
        </div>
      </div>

      <!-- Operation Status Banner -->
      <Transition
        mode="out-in"
        enter-active-class="transition-all duration-300 ease-out"
        enter-from-class="opacity-0 -translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition-all duration-200 ease-in"
        leave-from-class="opacity-100 translate-y-0"
        leave-to-class="opacity-0 -translate-y-2"
      >
        <div
          v-if="operationStatus"
          :class="[
            'flex items-center gap-3 rounded-lg border px-4 py-3 text-sm font-medium',
            operationStatus.type === 'info' && 'border-primary/20 bg-primary/5 text-primary',
            operationStatus.type === 'success' && 'border-success/20 bg-success/5 text-success',
            operationStatus.type === 'error' &&
              'border-destructive/20 bg-destructive/5 text-destructive',
          ]"
        >
          <Icon
            v-if="operationStatus.type === 'info'"
            name="lucide:loader-2"
            class="size-5 shrink-0 animate-spin"
          />
          <Icon
            v-else-if="operationStatus.type === 'success'"
            name="lucide:check-circle-2"
            class="size-5 shrink-0"
          />
          <Icon
            v-else-if="operationStatus.type === 'error'"
            name="lucide:alert-circle"
            class="size-5 shrink-0"
          />
          <span
            class="flex-1"
            :class="operationStatus.type === 'info' && 'ai-animate-text'"
          >{{ operationStatus.message }}</span>
        </div>
      </Transition>

      <AiText
        ref="aiTextRef"
        :space-id="spaceId"
        :placeholder="$t('components.contentTreeAi.placeholder')"
        :loading="isStreaming || isApplying"
        :direct-emit="true"
        @send="handleSubmit"
        @cancel="handleCancel"
      />
      <div
        v-if="showPreview"
        class="flex justify-end gap-2 pt-2"
      >
        <Button
          variant="ghost"
          :disabled="isApplying"
          @click="() => (showPreview = false)"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          :disabled="isApplying || operations.length === 0"
          @click="handleApply"
        >
          <Icon
            v-if="isApplying"
            name="lucide:loader-2"
            class="animate-spin mr-2"
          />
          {{ $t('components.contentTreeAi.apply') }}
        </Button>
      </div>
    </DialogContent>
  </Dialog>
</template>
