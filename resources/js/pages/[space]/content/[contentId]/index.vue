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
import { useContentSchemaState } from '~/composables/useContentSchemaState'
import type { ContentTreeItem } from '~/composables/useContentTree'
import { useGlobalClipboard } from '~/composables/useGlobalClipboard'
import {
  buildMissingLanguageDraft,
  getContentDefaultLanguage,
  resolveContentLanguage,
  resolveContentRouteName,
  withContentLanguageQuery,
} from '~/lib/content-i18n'
import type { ContentResource } from '~/types/contents'

const { t } = useI18n()
const { alert } = useAlertDialog()
const route = useRoute()
const router = useRouter()
const { useAccessControl } = useAuthorization()
const spaceId = computed<string>(() => route.params.space as string)
const canonicalContentId = computed<string>(() => route.params.contentId as string)
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canManageContent = computed(() => access.hasAbility('content.manage'))


const { settings } = useSpaceSettings(spaceId.value)
const { hasClipboardItem, clearClipboard } = useGlobalClipboard()


const { useContentQuery } = useContent(spaceId)
const { data: routeContent } = useContentQuery(canonicalContentId)
const { useBlocksQuery } = useBlocks(spaceId)
const { data: blockResponse } = useBlocksQuery({ per_page: 1000 })
const blocks = computed(() => blockResponse.value?.data || [])


const { useSpaceQuery } = useSpaces()
const { data: spaceData } = useSpaceQuery(spaceId.value)
const defaultLanguage = computed(() =>
  getContentDefaultLanguage(
    spaceData.value?.settings.default_language,
    routeContent.value?.language_versions,
    routeContent.value?.language_iso
  )
)
const activeLanguage = useRouteQuery<string | undefined>('lang')
const canonicalContentIdForQuery = computed(
  () => routeContent.value?.i18n_canonical_id || canonicalContentId.value
)
const { data: canonicalContentResponse } = useContentQuery(canonicalContentIdForQuery)
const canonicalContent = computed(() => canonicalContentResponse.value || null)
const resolvedActiveLanguage = computed(() =>
  resolveContentLanguage(
    activeLanguage.value,
    defaultLanguage.value,
    canonicalContent.value?.language_versions,
    routeContent.value?.language_iso
  )
)
const activeLanguageVersion = computed(() => {
  const languageVersions = canonicalContent.value?.language_versions || []
  const resolvedLanguage = resolveContentLanguage(
    resolvedActiveLanguage.value,
    defaultLanguage.value,
    languageVersions,
    routeContent.value?.language_iso
  )

  return (
    languageVersions.find((version) => version.language_iso === resolvedLanguage) ||
    languageVersions.find((version) => version.is_default) ||
    languageVersions[0] ||
    null
  )
})
const activeContentId = computed(() => {
  if (activeLanguageVersion.value?.content_id) {
    return activeLanguageVersion.value.content_id
  }

  if (
    routeContent.value &&
    routeContent.value.language_iso === resolvedActiveLanguage.value &&
    routeContent.value.i18n_parent_id !== null
  ) {
    return routeContent.value.id
  }

  return null
})
const fallbackLanguageVersion = computed(
  () =>
    canonicalContent.value?.language_versions?.find(
      (version) => version.language_iso === activeLanguageVersion.value?.fallback_language
    ) || null
)
const { data: activeOriginalContent } = useContentQuery(activeContentId)
const { data: fallbackContent } = useContentQuery(
  computed(() => fallbackLanguageVersion.value?.content_id)
)


watch(
  [routeContent, defaultLanguage],
  async ([currentContent, currentDefaultLanguage]) => {
    if (!currentContent) {
      return
    }

    const currentLanguage = resolveContentLanguage(
      activeLanguage.value,
      currentDefaultLanguage,
      currentContent.language_versions,
      currentContent.language_iso
    )

    if (
      currentContent.i18n_parent_id &&
      currentContent.i18n_canonical_id !== canonicalContentId.value
    ) {
      await router.replace({
        name: resolveContentRouteName(
          route.name as string | undefined,
          currentContent.effective_i18n_mode,
          currentLanguage,
          currentDefaultLanguage
        ),
        params: {
          ...route.params,
          contentId: currentContent.i18n_canonical_id,
        },
        query: withContentLanguageQuery(route.query, currentLanguage, currentDefaultLanguage),
        hash: '',
      })
    }
  },
  { immediate: true }
)


watch(
  [canonicalContent, resolvedActiveLanguage, defaultLanguage],
  async ([currentCanonical, languageIso, currentDefaultLanguage]) => {
    if (!currentCanonical) {
      return
    }

    const nextLanguage = resolveContentLanguage(
      languageIso,
      currentDefaultLanguage,
      currentCanonical.language_versions,
      routeContent.value?.language_iso
    )

    if (nextLanguage !== resolvedActiveLanguage.value) {
      activeLanguage.value = nextLanguage === currentDefaultLanguage ? undefined : nextLanguage
      return
    }

    const nextRouteName = resolveContentRouteName(
      route.name as string | undefined,
      currentCanonical.effective_i18n_mode,
      nextLanguage,
      currentDefaultLanguage
    )

    if (route.name !== nextRouteName || route.query.lang !== activeLanguage.value) {
      await router.replace({
        name: nextRouteName,
        params: {
          ...route.params,
          contentId: currentCanonical.id,
        },
        query: withContentLanguageQuery(route.query, nextLanguage, currentDefaultLanguage),
        hash: route.hash,
      })
    }
  },
  { immediate: true }
)


const currentContentSource = computed<ContentResource | null>(() => {
  if (activeOriginalContent.value) {
    return activeOriginalContent.value
  }

  if (
    routeContent.value &&
    routeContent.value.language_iso === resolvedActiveLanguage.value &&
    routeContent.value.i18n_parent_id !== null
  ) {
    return routeContent.value
  }

  if (
    canonicalContent.value &&
    canonicalContent.value.language_iso === resolvedActiveLanguage.value &&
    canonicalContent.value.i18n_parent_id === null
  ) {
    return canonicalContent.value
  }

  if (
    canonicalContent.value &&
    canonicalContent.value.effective_i18n_mode === 'independent' &&
    !activeContentId.value &&
    resolvedActiveLanguage.value
  ) {
    return buildMissingLanguageDraft(
      canonicalContent.value,
      fallbackContent.value || canonicalContent.value,
      resolveContentLanguage(
        resolvedActiveLanguage.value,
        defaultLanguage.value,
        canonicalContent.value.language_versions,
        routeContent.value?.language_iso
      )
    )
  }

  return null
})


const content = ref<ContentResource | null>(null)
const persistedContent = ref<ContentResource | null>(null)
const editorContentModel = computed<ContentTreeItem>({
  get: () => ({
    id: content.value?.id || '',
    block: content.value?.block?.slug || '',
    ...((content.value?.content || {}) as Record<string, unknown>),
  }),
  set: (value) => {
    if (!content.value) return

    const { id: _id, block: _block, ...contentFields } = value
    content.value.content = contentFields
  },
})
const {
  sanitizedContent,
  markFieldDirty,
  setServerErrors,
  clearServerErrors,
  getFieldError,
  shouldShowFieldError,
  getClientErrors,
  validateAllForSubmit,
  focusFirstInvalidField,
  resetValidationState,
  submitAttempted,
} = useContentSchemaState({
  content,
  blocks,
})


const { useCommentsQuery } = useComments(
  spaceId,
  computed(() => content.value?.id || null)
)
const { data: comments } = useCommentsQuery()


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
  currentContentSource,
  (newContent) => {
    if (newContent) {
      const shouldReplace =
        !content.value ||
        !persistedContent.value ||
        JSON.stringify(content.value) === JSON.stringify(persistedContent.value)

      syncPersistedContent(newContent, shouldReplace ? 'replace' : 'preserve-local')
      resetValidationState()
    }
  },
  { immediate: true }
)


watch(
  sanitizedContent,
  (nextSanitized) => {
    if (!content.value) return

    const currentSerialized = JSON.stringify(content.value.content || {})
    const sanitizedSerialized = JSON.stringify(nextSanitized || {})

    if (currentSerialized === sanitizedSerialized) return

    content.value.content = JSON.parse(sanitizedSerialized)
  },
  { deep: true }
)


const isDirty = computed(() => {
  if (!content.value || !persistedContent.value) return false
  return JSON.stringify(content.value) !== JSON.stringify(persistedContent.value)
})


async function guardLeave(to: any, from: any, next: any) {
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
  resetValidationState()
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


const { settings: userSettings } = useUserSettings()


const isExtendedSidebar = computed(() => userSettings.extendedSidebar ?? true)


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
      content: comments.value?.length ?? 0,
      show: (comments.value?.length ?? 0) > 0,
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
    ;(previewRef.value as any).updateItem({ ...item })
  }
}


const {
  broadcastPersistedContent,
  collaborators,
  discardOwnDrafts,
  getCollaboratorsForField,
  queueFieldUpdate,
  updateFieldFocus,
} = useContentLiveCollaboration(
  spaceId,
  computed(() => content.value?.id || null),
  {
    content,
    hasLocalUnsavedChanges: () => isDirty.value,
    syncPersistedContent,
    syncPreviewItem: updatePreviewItem,
  }
)


const commitPersistedContent = (
  nextContent: ContentResource,
  action: ContentCommitAction = 'save'
) => {
  syncPersistedContent(nextContent, 'replace')
  clearServerErrors()
  resetValidationState()
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
provide(
  'contentId',
  computed(() => content.value?.id || null)
)
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
    ;(previewRef.value as any).updateHover(id)
  }
})
provide('resetDirtyState', resetDirtyState)
provide('markFieldDirty', markFieldDirty)
provide('getFieldError', getFieldError)
provide('shouldShowFieldError', shouldShowFieldError)
provide('setValidationErrors', setServerErrors)
provide('clearValidationErrors', clearServerErrors)
provide('getClientValidationErrors', getClientErrors)
provide('sanitizeContentForSubmit', () => sanitizedContent.value)
provide('validateContentForSubmit', validateAllForSubmit)
provide('submitValidationAttempted', submitAttempted)
provide('focusFirstValidationError', focusFirstInvalidField)
</script>

<template>
  <Preview
    v-if="showPreview"
    ref="previewRef"
    :full-slug="content?.full_slug"
    :content-id="content?.id || ''"
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
          v-if="content.block"
          v-model="editorContentModel"
          :root-id="content.id"
          :block-id="content.block.id"
          :read-only="!canManageContent"
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
              :content-id="content?.id"
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
    <TabsList
      :class="[
        'flex h-full flex-col shrink-0 border-l border-l-border select-none',
        isExtendedSidebar ? 'w-18 p-1' : 'w-14 p-3',
      ]"
    >
      <div class="flex min-h-0 flex-1 flex-col">
        <div
          :class="['relative flex w-full min-w-0 flex-col', isExtendedSidebar ? 'gap-1' : 'gap-2']"
        >
          <component
            :is="isExtendedSidebar ? 'div' : SimpleTooltip"
            v-for="tab in tabs"
            :key="tab.value"
            v-bind="isExtendedSidebar ? {} : { tooltip: tab.label, side: 'left' }"
            class="flex cursor-pointer"
          >
            <TabsTrigger
              :value="tab.value"
              :class="[
                'w-full relative flex items-center justify-center rounded-lg transition-colors duration-200 ease-butter hover:bg-border',
                isExtendedSidebar ? 'flex-col gap-1 p-2 text-center' : 'size-8',
                mode === tab.value ? 'bg-border text-primary' : '',
              ]"
            >
              <Icon
                :name="tab.icon"
                size="20"
              />
              <span
                v-if="isExtendedSidebar"
                class="line-clamp-2 text-[10px] leading-tight"
              >
                {{ tab.label }}
              </span>
              <Badge
                v-if="tab.badge?.show"
                :variant="tab.badge.variant"
                size="dot"
                :class="isExtendedSidebar ? 'absolute top-1 right-1' : 'absolute -top-1 -right-1'"
              >
                {{ tab.badge.content }}
              </Badge>
            </TabsTrigger>
          </component>
        </div>
      </div>
      <div class="flex flex-col">
        <Button
          :class="[
            'w-full flex items-center justify-center rounded-lg transition-colors duration-200 ease-butter hover:bg-border',
            isExtendedSidebar ? 'flex-col gap-1 p-2 text-center' : 'size-8 ',
          ]"
          :variant="showAi ? 'default' : 'ghost'"
          @click="showAi = !showAi"
        >
          <Icon
            name="lucide:wand-sparkles"
            size="20"
            :class="[
              showAi ? 'text-primary' : 'text-ai',
              'transition-colors duration-200 ease-butter ',
            ]"
          />
          <span
            v-if="isExtendedSidebar"
            class="line-clamp-2 text-[10px] leading-tight"
          >
            AI
          </span>
        </Button>
      </div>
    </TabsList>
  </TabsRoot>

  <BlockTemplateCreateDialog
    :space-id="spaceId"
    :block-id="template.blockId"
    :block-name="rootBlock?.name || ''"
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
