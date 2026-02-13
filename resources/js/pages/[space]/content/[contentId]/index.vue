<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useRouteQuery } from '@vueuse/router'
import type { CleanTranslation } from 'nuxt-i18n-micro-types/src'
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui'
import BlockTemplateCreateDialog from '~/components/blocks/BlockTemplateCreateDialog.vue'
import CommentsSidebar from '~/components/comments/CommentsSidebar.vue'
import ContentHeader from '~/components/content/ContentHeader.vue'
import HeaderActions from '~/components/content/HeaderActions.vue'
import ContentInfo from '~/components/ContentInfo.vue'
import ContentSettings from '~/components/ContentSettings.vue'
import EditorComponent from '~/components/editor/EditorComponent.vue'
import Preview from '~/components/Preview.vue'
import { Badge, type BadgeVariants } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { ScrollArea } from '~/components/ui/scroll-area'
import { SimpleTooltip } from '~/components/ui/tooltip'
import { useGlobalClipboard } from '~/composables/useGlobalClipboard'
import type { ContentResource } from '~/types/contents'

const { t } = useI18n()
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
watch(
  originalContent,
  (newContent) => {
    if (newContent) {
      content.value = JSON.parse(JSON.stringify(newContent))
    }
  },
  { immediate: true }
)

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
  label: string | CleanTranslation
  badge?: { content: string | number; show: boolean; variant: BadgeVariants['variant'] }
}

const unresolvedCommentsCount = computed(() => {
  if (!comments.value) return 0
  return comments.value.filter((c) => !c.is_resolved).length
})

const tabs = computed((): Tab[] => [
  { value: 'edit', icon: 'lucide:pencil', label: t('labels.content.tabs.edit') },
  { value: 'config', icon: 'lucide:wrench', label: t('labels.content.tabs.config') },
  { value: 'info', icon: 'lucide:badge-info', label: t('labels.content.tabs.info') },
  {
    value: 'comments',

    icon: 'lucide:message-square',
    label: t('labels.content.tabs.comments'),
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

const findNestedObjectById = (data: unknown[], id: string): Record<string, unknown> | null => {
  for (const item of data) {
    if (typeof item === 'object' && item !== null) {
      const obj = item as Record<string, unknown>
      if (obj.id === id) return obj

      for (const key in obj) {
        if (Object.hasOwn(obj, key) && Array.isArray(obj[key])) {
          const result = findNestedObjectById(obj[key] as unknown[], id)
          if (result) return result
        }
      }
    }
  }
  return null
}

const updateField = (update: { itemId: string; field: string; value: unknown }) => {
  if (!content.value?.content || !Array.isArray(content.value.content)) return

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
provide('updatePreviewItem', updatePreviewItem)
provide('updateHoverItem', (id: string) => {
  if (previewRef.value) {
    previewRef.value.updateHover(id)
  }
})
</script>

<template>
  <div class="flex grow">
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
      :class="['flex', showPreview ? 'w-2xl' : 'w-full']"
      orientation="vertical"
    >
      <ScrollArea
        v-if="content"
        :class="[
          'grow overflow-y-auto',
          showPreview ? 'max-h-[calc(100svh-3.5rem)]' : 'h-[calc(100svh-3.5rem)] bg-background',
        ]"
      >
        <TabsContent
          value="edit"
          :class="['p-4', showPreview ? '' : 'mx-auto max-w-3xl']"
        >
          <EditorComponent
            v-model="content.content"
            :root-id="content.id"
            :block-id="content.block.id"
            :space-id="spaceId"
            :item-id="selectedItemId"
            @navigate="handleNavigate"
            @create-template="handleTemplateTrigger"
          />
          <Button
            v-if="hasClipboardItem"
            title="Clear clipboard"
            size="xs"
            variant="ghost"
            class="absolute bottom-4 left-1/2 z-50 -translate-x-1/2"
            @click="clearClipboard"
          >
            <Icon name="lucide:trash-2" />
            <span>{{ t('actions.clearClipboard') }}</span>
          </Button>
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
        :space-id="spaceId"
      />
    </Teleport>
  </div>
</template>
