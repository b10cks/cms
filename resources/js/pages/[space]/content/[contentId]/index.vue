<script setup lang="ts">
import { useRouteQuery } from '@vueuse/router'
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui'
import { TransitionGroup } from 'vue'

import BlockTemplateCreateDialog from '~/components/blocks/BlockTemplateCreateDialog.vue'
import CommentsSidebar from '~/components/comments/CommentsSidebar.vue'
import ContentHeader from '~/components/content/ContentHeader.vue'
import HeaderActions from '~/components/content/HeaderActions.vue'
import ContentInfo from '~/components/ContentInfo.vue'
import ContentSettings from '~/components/ContentSettings.vue'
import EditorComponent from '~/components/editor/EditorComponent.vue'
import Icon from '~/components/Icon.vue'
import Preview from '~/components/Preview.vue'
import AiContentInteraction from '~/components/ui/AiContentInteraction.vue'
import { Badge, type BadgeVariants } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { ScrollArea } from '~/components/ui/scroll-area'
import { SimpleTooltip } from '~/components/ui/tooltip'
import { useAlertDialog } from '~/composables/useAlertDialog'
import {
  useContentLiveCollaboration,
  type ContentCommitAction,
} from '~/composables/useContentLiveCollaboration'
import { useGlobalClipboard } from '~/composables/useGlobalClipboard'
import type { ContentResource } from '~/types/contents'

const { t } = useI18n()
const { alert } = useAlertDialog()
const route = useRoute()
const router = useRouter()
const spaceId = computed<string>(() => route.params.space as string)
const contentId = computed<string>(() => route.params.contentId as string)

const { settings } = useSpaceSettings(spaceId.value)
const { hasClipboardItem, clearClipboard } = useGlobalClipboard()

const { useContentQuery } = useContent(spaceId)
const { data: originalContent } = useContentQuery(contentId)

const { useCommentsQuery } = useComments(spaceId, contentId)
const { data: comments } = useCommentsQuery()

const { useSpaceQuery } = useSpaces()
const { data: spaceData } = useSpaceQuery(spaceId.value)

const content = ref<ContentResource | null>(null)
const persistedContent = ref<ContentResource | null>(null)
const aiInteractionRef = useTemplateRef('aiInteractionRef')

const showAi = ref(false)

const cloneContent = (value: ContentResource): ContentResource => JSON.parse(JSON.stringify(value))

const syncPersistedContent = (
  nextContent: ContentResource,
  mode: 'replace' | 'preserve-local' = 'replace'
) => {
  const cloned = cloneContent(nextContent)

  persistedContent.value = cloned

  if (!content.value || mode === 'replace') {
    content.value = cloneContent(cloned)
    return
  }

  content.value = {
    ...content.value,
    ...cloned,
    content: content.value.content,
  }
}

watch(
  originalContent,
  (newContent) => {
    if (newContent) {
      const shouldReplace =
        !content.value ||
        !persistedContent.value ||
        JSON.stringify(content.value) === JSON.stringify(persistedContent.value)

      syncPersistedContent(newContent, shouldReplace ? 'replace' : 'preserve-local')
    }
  },
  { immediate: true }
)

const isDirty = computed(() => {
  if (!content.value || !persistedContent.value) return false
  return JSON.stringify(content.value) !== JSON.stringify(persistedContent.value)
})

async function guardLeave(to, from, next) {
  if (to && from && to.path === from.path) {
    return next()
  }

  if (isDirty.value) {
    const answer = await alert.confirm(
      t(
        'labels.content.unsavedChanges',
        'You have unsaved changes. Are you sure you want to leave?'
      )
    )
    if (answer) {
      discardOwnDrafts()
      next()
    } else {
      next(false)
    }
  } else {
    next()
  }
}

onBeforeRouteUpdate(guardLeave)
onBeforeRouteLeave(guardLeave)

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', handleBeforeUnload)
})

const handleBeforeUnload = (e: BeforeUnloadEvent) => {
  if (isDirty.value) {
    e.preventDefault()
    e.returnValue = ''
  }
}

watch(
  isDirty,
  (newValue) => {
    if (newValue) {
      window.addEventListener('beforeunload', handleBeforeUnload)
    } else {
      window.removeEventListener('beforeunload', handleBeforeUnload)
    }
  },
  { immediate: true }
)

const resetDirtyState = () => {
  if (content.value) {
    syncPersistedContent(content.value, 'replace')
  }
}

const selectedItemId = computed({
  get: () => (route.hash ? route.hash.substring(1) : null),
  set: (newId) => {
    if (newId) {
      router.replace({ ...route, hash: `#${newId}` })
    } else {
      router.replace({ ...route, hash: '' })
    }
  },
})

const handleNavigate = (itemId: string | null) => {
  selectedItemId.value = itemId
}

const previewRef = useTemplateRef('previewRef')

type Tab = {
  value: string
  icon: string
  label: string
  badge?: { content: string | number; show: boolean; variant: BadgeVariants['variant'] }
}

const unresolvedCommentsCount = computed(() => {
  if (!comments.value) return 0
  return comments.value.filter((c) => !c.is_resolved).length
})

const tabs = computed((): Tab[] => [
  { value: 'edit', icon: 'lucide:pencil', label: t('labels.contents.tabs.edit') },
  { value: 'config', icon: 'lucide:wrench', label: t('labels.contents.tabs.config') },
  { value: 'info', icon: 'lucide:badge-info', label: t('labels.contents.tabs.info') },
  {
    value: 'comments',
    icon: 'lucide:message-square',
    label: t('labels.contents.tabs.comments'),
    badge: {
      content: comments.value?.length,
      show: comments.value?.length > 0,
      variant: unresolvedCommentsCount.value > 0 ? 'warning' : 'default',
    },
  },
])

const mode = useRouteQuery('mode', 'edit') as Ref<'edit' | 'config' | 'info' | 'comments'>

useSeoMeta({
  title: computed(() => {
    return content.value?.name
  }),
})

const rootBlock = computed(() => {
  if (!content.value) return null

  const block = content.value.block
  if (block) {
    return block
  }

  return null
})

const isPreviewDisabled = computed(() => {
  if (!spaceData.value) return false

  return (
    spaceData.value.settings?.visual_editor === false || content.value?.settings?.disablePreview
  )
})

const showPreview = computed(() => {
  return !isPreviewDisabled.value && settings.value.content.showPreview
})

const isVisualEditorAvailable = computed(() => {
  if (!spaceData.value) return false
  return spaceData.value.settings?.visual_editor !== false
})

const updatePreviewItem = (item: Record<string, unknown>) => {
  if (previewRef.value) {
    previewRef.value.updateItem({ ...item })
  }
}

const {
  broadcastPersistedContent,
  collaborators,
  discardOwnDrafts,
  getCollaboratorsForField,
  queueFieldUpdate,
  updateFieldFocus,
} = useContentLiveCollaboration(spaceId, contentId, {
  content,
  hasLocalUnsavedChanges: () => isDirty.value,
  syncPersistedContent,
  syncPreviewItem: updatePreviewItem,
})

const commitPersistedContent = (
  nextContent: ContentResource,
  action: ContentCommitAction = 'save'
) => {
  syncPersistedContent(nextContent, 'replace')
  broadcastPersistedContent(nextContent, action)
}

const findNestedObjectById = (data: unknown, id: string): Record<string, unknown> | null => {
  if (typeof data !== 'object' || data === null) return null

  if (Array.isArray(data)) {
    for (const item of data) {
      const result = findNestedObjectById(item, id)
      if (result) return result
    }
    return null
  }

  const obj = data as Record<string, unknown>
  if (obj.id === id) return obj

  for (const key in obj) {
    if (Object.hasOwn(obj, key) && typeof obj[key] === 'object' && obj[key] !== null) {
      const result = findNestedObjectById(obj[key], id)
      if (result) return result
    }
  }

  return null
}

const updateField = (update: { itemId: string; field: string; value: unknown }) => {
  if (!content.value?.content) return

  if (update.itemId === content.value.id) {
    content.value.content = {
      ...(content.value.content as Record<string, unknown>),
      [update.field]: update.value,
    }
    return
  }

  const target = findNestedObjectById(content.value.content, update.itemId)
  if (target) {
    target[update.field] = update.value
  }
}

const template = reactive({
  isOpen: false,
  blockId: '',
  content: {},
})

const handleTemplateTrigger = (blockId: string, content: object) => {
  template.blockId = blockId
  template.content = content
  template.isOpen = true
}

provide('content', content)
provide('rootBlock', rootBlock)
provide('spaceId', spaceId.value)
provide('contentId', contentId)
provide(
  'contentVersionId',
  computed(() => content.value?.current_version_id)
)
provide('comments', comments)
provide('commitPersistedContent', commitPersistedContent)
provide('discardOwnDrafts', discardOwnDrafts)
provide('getActiveCollaborators', getCollaboratorsForField)
provide('updatePreviewItem', updatePreviewItem)
provide('updateHoverItem', (id: string) => {
  if (previewRef.value) {
    previewRef.value.updateHover(id)
  }
})
provide('resetDirtyState', resetDirtyState)
</script>

<template>
  <Preview
    v-if="showPreview"
    ref="previewRef"
    :full-slug="content?.full_slug"
    :content-id="content?.id"
    :updated-at="content?.updated_at"
    :item-id="selectedItemId"
    :space-id="spaceId"
    @select-item="(itemId) => (selectedItemId = itemId)"
    @update-field="updateField"
  />
  <TabsRoot
    v-model="mode"
    :class="['flex', showPreview ? 'w-lg' : 'w-full']"
    orientation="vertical"
  >
    <ScrollArea
      v-if="content"
      :class="[
        'grow overflow-y-auto bg-background',
        showPreview
          ? 'max-h-[calc(100svh-3.5rem)] border-l border-border'
          : 'h-[calc(100svh-3.5rem)]',
      ]"
    >
      <TabsContent
        value="edit"
        :class="['p-4', showPreview ? '' : 'mx-auto max-w-4xl', showAi ? 'pb-52' : '']"
      >
        <EditorComponent
          v-model="content.content"
          :root-id="content.id"
          :block-id="content.block!.id"
          :get-active-collaborators="getCollaboratorsForField"
          :space-id="spaceId"
          :item-id="selectedItemId"
          @navigate="handleNavigate"
          @create-template="handleTemplateTrigger"
          @field-update="queueFieldUpdate"
          @field-focus="updateFieldFocus"
        />
        <div
          :class="[
            showPreview ? 'inset-x-4' : 'w-full max-w-4xl',
            'py-4 overflow-clip absolute bottom-0 flex flex-col items-center gap-3 z-10',
          ]"
        >
          <TransitionGroup
            enter-active-class="transition duration-150 ease-butter"
            leave-active-class="transition duration-150 ease-butter"
            enter-from-class="opacity-0 translate-y-full"
            enter-to-class="opacity-100 translate-y-0"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-full"
          >
            <div
              v-if="hasClipboardItem"
              key="clearClipboard"
            >
              <Button
                title="Clear clipboard"
                size="xs"
                variant="ghost"
                @click="clearClipboard"
              >
                <Icon name="lucide:trash-2" />
                <span>{{ t('actions.clearClipboard') }}</span>
              </Button>
            </div>
            <AiContentInteraction
              v-if="showAi"
              key="ai"
              ref="aiInteractionRef"
              v-model:content="content"
              :space-id="spaceId"
              :content-id="contentId"
              class="mx-auto max-w-xl"
              :placeholder="t('labels.settings.ai.placeholder', 'Ask AI to modify your content...')"
            />
          </TransitionGroup>
        </div>
      </TabsContent>
      <TabsContent
        value="info"
        :class="['p-4', showPreview ? '' : 'mx-auto max-w-3xl']"
      >
        <ContentInfo :content="content" />
      </TabsContent>
      <TabsContent
        value="config"
        :class="['p-4', showPreview ? '' : 'mx-auto max-w-3xl']"
      >
        <ContentSettings v-model="content" />
      </TabsContent>
      <TabsContent
        value="comments"
        :class="['p-4', showPreview ? '' : 'mx-auto max-w-3xl']"
      >
        <CommentsSidebar
          :content-id="content.id"
          :content-version-id="content.current_version_id || undefined"
        />
      </TabsContent>
    </ScrollArea>
    <div
      v-else
      class="grow"
    />
    <TabsList class="flex h-full w-14 shrink-0 flex-col border-l border-l-border p-3 select-none">
      <div class="flex min-h-0 flex-1 flex-col">
        <div class="relative flex w-full min-w-0 flex-col gap-2">
          <SimpleTooltip
            v-for="tab in tabs"
            :tooltip="tab.label"
            :key="tab.value"
            side="left"
            class="flex cursor-pointer"
          >
            <TabsTrigger
              :value="tab.value"
              class="relative flex size-8 items-center justify-center rounded-lg transition-colors duration-200 ease-butter hover:bg-input data-[state=active]:bg-input data-[state=active]:text-primary data-[state=inactive]:cursor-pointer"
            >
              <Icon
                :name="tab.icon"
                size="1.25rem"
              />
              <Badge
                v-if="tab.badge?.show"
                :variant="tab.badge.variant"
                size="dot"
                class="absolute -top-1 -right-1"
              >
                {{ tab.badge.content }}
              </Badge>
            </TabsTrigger>
          </SimpleTooltip>
        </div>
      </div>
      <div>
        <Button
          size="toolbar"
          :variant="showAi ? 'default' : 'ghost'"
          @click="showAi = !showAi"
        >
          <Icon
            name="lucide:wand"
            :class="[
              showAi ? 'text-primary' : 'text-ai',
              'transition-colors duration-200 ease-butter ',
            ]"
          />
        </Button>
      </div>
    </TabsList>
  </TabsRoot>

  <BlockTemplateCreateDialog
    :space-id="spaceId"
    :block-id="template.blockId"
    :content="template.content"
    v-model:open="template.isOpen"
  />

  <Teleport to="#appHeader">
    <ContentHeader
      v-if="content"
      :content="content"
      :show-preview-toggle="!isPreviewDisabled"
    />
  </Teleport>

  <Teleport to="#appHeaderActions">
    <HeaderActions
      v-if="content"
      :content="content"
      :present-users="collaborators"
      :space-id="spaceId"
      :is-dirty="isDirty"
    />
  </Teleport>
</template>
