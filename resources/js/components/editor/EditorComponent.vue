<script setup lang="ts">
import { useElementHover } from '@vueuse/core'
import { TabsContent, TabsRoot } from 'reka-ui'
import type { ComputedRef } from 'vue'

import ContentBreadcrumbs from '~/components/editor/ContentBreadcrumbs.vue'
import FieldEditor from '~/components/editor/FieldEditor.vue'
import Icon from '~/components/Icon.vue'
import { TabsList, TabsTrigger } from '~/components/ui/tabs'
import type {
  CollaborationPresenceUser,
  ContentBlockOperationPayload,
  ContentFieldFocusPayload,
  ContentFieldUpdatePayload,
} from '~/composables/useContentLiveCollaboration'
import { isFieldVisible, normalizeSchema } from '~/composables/useContentSchemaState'
import type { ContentTreeItem, FindResult } from '~/composables/useContentTree'
import { useContentTree } from '~/composables/useContentTree'
import type { ContentBlock } from '~/types/contents'

import { Badge } from '../ui/badge'
import { Button } from '../ui/button'
import { SimpleTooltip } from '../ui/tooltip'

const { $t } = useI18n()

interface Breadcrumb {
  id: string
  label: string
  block: string
}

type HoverUpdateFunction = (data: string | null) => void
type PreviewUpdateFunction = (data: Record<string, unknown>) => void

const content = defineModel<ContentTreeItem | Record<string, unknown>>({ required: true })
const containerRef = useTemplateRef<HTMLElement>('containerRef')
const isHovered = useElementHover(containerRef)

const props = withDefaults(
  defineProps<{
    blockId?: string | null
    blockSlug?: string | null
    spaceId: string
    readOnly?: boolean
    isChild?: boolean
    getActiveCollaborators?: (itemId: string, field: string) => CollaborationPresenceUser[]
    rootId?: string
    itemId?: string | null
    pathPrefix?: Array<string | number>
  }>(),
  {
    blockId: null,
    blockSlug: null,
    getActiveCollaborators: () => [],
    rootId: undefined,
    isChild: false,
    itemId: null,
    pathPrefix: () => [],
  }
)

const emit = defineEmits<{
  (e: 'navigate', itemId: string | null): void
  (e: 'createTemplate', blockId: string, content: object): void
  (e: 'fieldUpdate', payload: ContentFieldUpdatePayload): void
  (e: 'fieldFocus', payload: ContentFieldFocusPayload): void
  (e: 'blockOperation', payload: ContentBlockOperationPayload): void
}>()

const hoverRegistry = inject<Map<string, boolean>>('hoverRegistry', new Map())
const componentId = computed((): string => (content.value?.id || props.itemId || '') as string)

provide('hoverRegistry', hoverRegistry)

const updateHoverItem = inject<HoverUpdateFunction>('updateHoverItem')

const updateRegistry = (isComponentHovered: boolean): void => {
  hoverRegistry.set(componentId.value, isComponentHovered)
  let innermostId: string | null = null
  for (const [id, hovered] of hoverRegistry.entries()) {
    if (hovered) {
      innermostId = id
    }
  }
  if (updateHoverItem) {
    updateHoverItem(innermostId)
  }
}

watch(
  isHovered,
  (hovered: boolean) => {
    updateRegistry(hovered)
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  hoverRegistry.delete(componentId.value)
})

// Initialize on mount
onMounted(() => {
  updateRegistry(isHovered.value)
})

const { useBlocksQuery } = useBlocks(props.spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })

const rootBlock = inject<ContentBlock>('rootBlock')
const updatePreviewItem = inject<PreviewUpdateFunction>('updatePreviewItem')

const contentModel = computed({
  get: (): ContentTreeItem =>
    (content.value as ContentTreeItem | undefined) ??
    ({
      id: props.rootId || '',
      block: props.blockSlug || '',
    } as ContentTreeItem),
  set: (value: ContentTreeItem) => {
    content.value = value
  },
})

const rootTreeBlock = computed<ContentBlock>(() => {
  if (rootBlock) return rootBlock

  return {
    id: props.rootId || '',
    icon: '',
    name: '',
    slug: props.blockSlug || '',
    type: 'root',
  }
})

const contentTree = useContentTree(
  contentModel as unknown as ComputedRef<ContentTreeItem>,
  rootTreeBlock
)
const currentItem = computed<FindResult | null>(() =>
  props.itemId ? contentTree.findItemById(props.itemId) : null
)
const currentContentItem = computed<ContentTreeItem | null>(() => currentItem.value?.item ?? null)
const selectedContentModel = computed<Record<string, unknown>>({
  get: () => (currentContentItem.value ?? contentModel.value) as Record<string, unknown>,
  set: (value) => {
    applyContentUpdate(value)
  },
})
const breadcrumbs = computed((): Breadcrumb[] =>
  props.itemId
    ? contentTree.buildBreadcrumbs(props.itemId).map((crumb) => ({
        id: crumb.id ?? '',
        label: crumb.label,
        block: crumb.label,
      }))
    : []
)
const id = computed((): string => props.itemId || rootBlock?.slug || '')
const currentPages = computed<EditorPage[]>(() => currentBlock.value?.editor ?? [])
const currentBlockSchema = computed<Record<string, SchemaType>>(
  () => (currentBlock.value?.schema || {}) as Record<string, SchemaType>
)
const rootContentId = computed(
  () => props.rootId || String((contentModel.value as { id?: string }).id || '')
)

const currentBlock = computed((): BlockResource | null => {
  const blockList = blocks.value?.data ?? []

  if (!currentContentItem.value) {
    if (props.blockSlug) {
      return blockList.find((block) => block.slug === props.blockSlug) ?? null
    }
    return blockList.find((block) => block.id === props.blockId) ?? null
  }

  const currentBlockSlug = currentContentItem.value?.block
  if (currentBlockSlug) {
    return blockList.find((block) => block.slug === currentBlockSlug) ?? null
  }

  return null
})

const currentSchema = computed(
  (): Record<string, SchemaType & { key: string }> => normalizeSchema(currentBlockSchema.value)
)

const findItemPathPrefix = (
  value: unknown,
  targetId: string,
  currentPath: Array<string | number> = []
): Array<string | number> | null => {
  if (!value || typeof value !== 'object') return null

  if (Array.isArray(value)) {
    for (const [index, item] of value.entries()) {
      const result = findItemPathPrefix(item, targetId, [...currentPath, index])
      if (result) return result
    }

    return null
  }

  const objectValue = value as Record<string, unknown>

  if (objectValue.id === targetId) {
    return currentPath
  }

  for (const [key, nestedValue] of Object.entries(objectValue)) {
    if (!nestedValue || typeof nestedValue !== 'object') continue

    const result = findItemPathPrefix(nestedValue, targetId, [...currentPath, key])
    if (result) return result
  }

  return null
}

const currentPathPrefix = computed<Array<string | number>>(() => {
  if (props.itemId) {
    return (
      findItemPathPrefix(contentModel.value, props.itemId, props.pathPrefix) || props.pathPrefix
    )
  }

  return props.pathPrefix
})

const isVisibleField = (itemKey: string) => {
  const field = currentSchema.value[itemKey]
  if (!field) return false

  const scope = (currentContentItem.value || contentModel.value || {}) as Record<string, unknown>

  return isFieldVisible(
    field as SchemaType & { key: string },
    currentSchema.value as Record<string, SchemaType>,
    scope
  )
}

const handleBreadcrumbNavigation = (itemId: string | null): void => {
  emit('navigate', itemId)
}

const handleTemplateTrigger = (): void => {
  if (!currentBlock.value) return

  emit(
    'createTemplate',
    currentBlock.value.id,
    (currentContentItem.value as Record<string, unknown> | null) || contentModel.value
  )
}

const handleCreateTemplate = (blockId: string, content: Record<string, unknown>): void => {
  emit('createTemplate', blockId, content)
}

const forwardFieldUpdate = (payload: ContentFieldUpdatePayload): void => {
  emit('fieldUpdate', payload)
}

const forwardFieldFocus = (payload: ContentFieldFocusPayload): void => {
  emit('fieldFocus', payload)
}

const forwardBlockOperation = (payload: ContentBlockOperationPayload): void => {
  emit('blockOperation', payload)
}

const getAggregatedCollaboratorsForField = inject<
  ((itemId: string, field: string) => CollaborationPresenceUser[]) | undefined
>('getAggregatedCollaboratorsForField', undefined)

const activeItemId = computed(() => currentContentItem.value?.id || rootContentId.value)

const getPagePresence = (page: EditorPage): CollaborationPresenceUser[] => {
  if (!getAggregatedCollaboratorsForField || !activeItemId.value) return []

  const users = new Map<string, CollaborationPresenceUser>()
  for (const fieldKey of page?.items || []) {
    for (const user of getAggregatedCollaboratorsForField(activeItemId.value, fieldKey)) {
      users.set(user.id, user)
    }
  }

  return Array.from(users.values())
}

const INTERNAL_CONTENT_KEYS = new Set(['id', 'block', 'hidden'])

const contentData = computed<Record<string, unknown>>(
  () => (currentContentItem.value ?? contentModel.value) as unknown as Record<string, unknown>
)

const outOfSchemaEntries = computed(() =>
  Object.entries(contentData.value).filter(
    ([key]) => !INTERNAL_CONTENT_KEYS.has(key) && !(key in currentSchema.value)
  )
)

const isOutOfSchemaExpanded = ref(false)

const formatOutOfSchemaValue = (value: unknown): string => {
  if (value === null) return 'null'
  if (value === undefined) return 'undefined'
  if (typeof value === 'string') return value.length > 80 ? `${value.slice(0, 80)}…` : value
  const json = JSON.stringify(value)
  return json.length > 80 ? `${json.slice(0, 80)}…` : json
}

const applyContentUpdate = (data: Record<string, unknown>): void => {
  if (currentContentItem.value && props.itemId) {
    // Focused block: the tree is mutated in place, so nothing bubbles up to the
    // whole-tree push the expanded path gets for free. Push the updated tree
    // here — a block-scoped push alone is lost on any site that cannot address
    // that block by id, and it is not a valid state snapshot for replay.
    contentTree.updateItem(props.itemId, data as ContentTreeItem)
    updatePreviewItem?.({ ...(contentModel.value as unknown as Record<string, unknown>) })
  } else {
    contentModel.value = data as ContentTreeItem
    updatePreviewItem?.({ id: props.rootId, ...data })
  }
}

const removeOutOfSchemaKey = (key: string): void => {
  const data = { ...contentData.value }
  delete data[key]
  applyContentUpdate(data)
}

const removeAllOutOfSchemaKeys = (): void => {
  const data = { ...contentData.value }
  for (const [key] of outOfSchemaEntries.value) {
    delete data[key]
  }
  applyContentUpdate(data)
  isOutOfSchemaExpanded.value = false
}
</script>

<template>
  <div
    ref="containerRef"
    class="flex w-full flex-col"
  >
    <ContentBreadcrumbs
      v-if="breadcrumbs.length > 0"
      :breadcrumbs="breadcrumbs"
      @navigate="(itemId) => handleBreadcrumbNavigation(itemId || null)"
    />
    <div
      class="flex"
      v-if="!isChild"
    >
      <h2 class="mb-2 text-xl font-bold text-primary">
        {{ currentBlock?.name || currentBlock?.slug }}
      </h2>
      <SimpleTooltip
        class="ml-auto flex"
        side="bottom"
        :tooltip="$t('labels.blockTemplates.createFromBlock')"
      >
        <Button
          v-if="!props.readOnly"
          size="xs"
          variant="ghost"
          :aria-label="$t('labels.blockTemplates.createFromBlock')"
          @click="handleTemplateTrigger"
          ><Icon name="lucide:notepad-text-dashed"
        /></Button>
      </SimpleTooltip>
    </div>
    <TabsRoot
      :key="`${id}-tabs`"
      :default-value="`${id}-page-0`"
    >
      <TabsList
        v-if="currentPages.length > 1"
        class="mb-4 flex w-full max-w-full"
      >
        <TabsTrigger
          v-for="(page, i) in currentPages"
          :key="i"
          :value="`${id}-page-${i}`"
        >
          {{ page.header }}
          <span
            v-if="getPagePresence(page).length > 0"
            class="size-2 shrink-0 rounded-full"
            :style="{ backgroundColor: getPagePresence(page)[0].color }"
          />
          <Badge
            v-if="i === 0 && outOfSchemaEntries.length > 0"
            variant="warning"
            size="xs"
            >{{ outOfSchemaEntries.length }}</Badge
          >
        </TabsTrigger>
      </TabsList>
      <TabsContent
        v-for="(page, i) in currentPages"
        :key="i"
        :value="`${id}-page-${i}`"
      >
        <div class="grid items-start gap-4">
          <template v-if="currentContentItem">
            <template
              v-for="fieldKey in page?.items"
              :key="fieldKey"
            >
              <FieldEditor
                v-if="isVisibleField(fieldKey)"
                v-model="selectedContentModel"
                :item-id="currentContentItem.id"
                :item="currentSchema[fieldKey]"
                :path-segments="[...currentPathPrefix, fieldKey]"
                :space-id="spaceId"
                :read-only="props.readOnly"
                :active-collaborators="
                  props.getActiveCollaborators(currentContentItem.id, fieldKey)
                "
                @create-template="handleCreateTemplate"
                @field-update="forwardFieldUpdate"
                @field-focus="forwardFieldFocus"
                @block-operation="forwardBlockOperation"
              />
            </template>
          </template>
          <template v-else>
            <template
              v-for="fieldKey in page?.items"
              :key="fieldKey"
            >
              <FieldEditor
                v-if="isVisibleField(fieldKey)"
                v-model="selectedContentModel"
                :item-id="rootContentId"
                :item="currentSchema[fieldKey]"
                :path-segments="[...currentPathPrefix, fieldKey]"
                :space-id="spaceId"
                :read-only="props.readOnly"
                :active-collaborators="props.getActiveCollaborators(rootContentId, fieldKey)"
                @create-template="handleCreateTemplate"
                @field-update="forwardFieldUpdate"
                @field-focus="forwardFieldFocus"
                @block-operation="forwardBlockOperation"
              />
            </template>
          </template>

          <div
            v-if="i === 0 && outOfSchemaEntries.length > 0"
            class="rounded-lg border border-warning-background/40 bg-warning-background/10"
          >
            <button
              type="button"
              class="flex w-full items-center gap-2 px-3 py-2"
              @click="isOutOfSchemaExpanded = !isOutOfSchemaExpanded"
            >
              <Icon
                :name="isOutOfSchemaExpanded ? 'lucide:chevron-down' : 'lucide:chevron-right'"
                class="size-4 shrink-0 text-warning"
              />
              <span class="text-sm font-medium text-warning">{{
                $t('labels.contents.outOfSchema.label')
              }}</span>
              <Badge
                variant="warning"
                size="xs"
                >{{ outOfSchemaEntries.length }}</Badge
              >
              <Button
                v-if="!props.readOnly"
                type="button"
                variant="ghost"
                size="xs"
                class="ml-auto text-xs text-destructive hover:text-destructive"
                @click.stop="removeAllOutOfSchemaKeys"
              >
                {{ $t('labels.contents.outOfSchema.removeAll') }}
              </Button>
            </button>
            <div
              v-if="isOutOfSchemaExpanded"
              class="border-t border-warning/20 px-3 pt-2"
            >
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-left text-xs text-warning">
                    <th class="pb-1 w-1/3 font-medium">
                      {{ $t('labels.contents.outOfSchema.key') }}
                    </th>
                    <th class="pb-1 w-2/3 font-medium">
                      {{ $t('labels.contents.outOfSchema.value') }}
                    </th>
                    <th class="pb-1 w-12 font-medium" />
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="[key, value] in outOfSchemaEntries"
                    :key="key"
                    class="border-t border-warning/20"
                  >
                    <td class="py-1.5 text-xs text-warning">{{ key }}</td>
                    <td class="py-1.5 text-sm text-primary">
                      <span :title="JSON.stringify(value)">{{
                        formatOutOfSchemaValue(value)
                      }}</span>
                    </td>
                    <td class="py-1.5 text-right">
                      <Button
                        v-if="!props.readOnly"
                        type="button"
                        variant="ghost"
                        size="xs"
                        class="text-destructive hover:text-destructive"
                        :aria-label="$t('actions.remove')"
                        @click="removeOutOfSchemaKey(key)"
                      >
                        <Icon name="lucide:trash-2" />
                      </Button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </TabsContent>
    </TabsRoot>
  </div>
</template>
