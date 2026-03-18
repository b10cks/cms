<script setup lang="ts">
import { useElementHover } from '@vueuse/core'
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui'
import type { ComputedRef } from 'vue'

import ContentBreadcrumbs from '~/components/editor/ContentBreadcrumbs.vue'
import FieldEditor from '~/components/editor/FieldEditor.vue'
import Icon from '~/components/Icon.vue'
import type {
  CollaborationPresenceUser,
  ContentFieldFocusPayload,
  ContentFieldUpdatePayload,
} from '~/composables/useContentLiveCollaboration'
import { isFieldVisible, normalizeSchema } from '~/composables/useContentSchemaState'
import type { ContentTreeItem, FindResult } from '~/composables/useContentTree'
import { useContentTree } from '~/composables/useContentTree'
import type { ContentBlock } from '~/types/contents'

import { Button } from '../ui/button'

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
    ((content.value as ContentTreeItem | undefined) ??
    ({
      id: props.rootId || '',
      block: props.blockSlug || '',
    } as ContentTreeItem)),
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


const updateSubItem = (updatedValue: unknown): void => {
  if (!props.itemId || !currentContentItem.value || !updatePreviewItem) return


  updatePreviewItem(updatedValue as Record<string, unknown>)
  contentTree.updateItem(props.itemId, updatedValue as ContentTreeItem)
}


const updateItem = (updatedValue: unknown): void => {
  if (!updatePreviewItem) return


  updatePreviewItem({
    id: props.rootId,
    ...(updatedValue as Record<string, unknown>),
  })
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
      <Button
        class="ml-auto"
        size="xs"
        variant="ghost"
        @click="handleTemplateTrigger"
        ><Icon name="lucide:notepad-text-dashed"
      /></Button>
    </div>
    <TabsRoot
      :key="`${id}-tabs`"
      :default-value="`${id}-page-0`"
    >
      <TabsList
        v-if="currentPages.length > 1"
        class="mb-4 flex w-full items-center gap-1 rounded-xl bg-input p-1"
      >
        <TabsTrigger
          v-for="(page, i) in currentPages"
          :key="i"
          :value="`${id}-page-${i}`"
          class="rounded-lg px-2 py-1 text-sm font-semibold transition-colors hover:text-primary data-[state=active]:bg-background data-[state=active]:text-primary"
        >
          {{ page.header }}
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
                v-model="currentContentItem"
                :item-id="currentContentItem.id"
                :item="currentSchema[fieldKey]"
                :path-segments="[...currentPathPrefix, fieldKey]"
                :space-id="spaceId"
                :active-collaborators="
                  props.getActiveCollaborators(currentContentItem.id, fieldKey)
                "
                @update:model-value="updateSubItem"
                @create-template="handleCreateTemplate"
                @field-update="forwardFieldUpdate"
                @field-focus="forwardFieldFocus"
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
                v-model="contentModel"
                :item-id="rootContentId"
                :item="currentSchema[fieldKey]"
                :path-segments="[...currentPathPrefix, fieldKey]"
                :space-id="spaceId"
                :active-collaborators="props.getActiveCollaborators(rootContentId, fieldKey)"
                @update:model-value="updateItem"
                @create-template="handleCreateTemplate"
                @field-update="forwardFieldUpdate"
                @field-focus="forwardFieldFocus"
              />
            </template>
          </template>
        </div>
      </TabsContent>
    </TabsRoot>
  </div>
</template>
