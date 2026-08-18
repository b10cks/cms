<script setup lang="ts">
import { useQueryClient } from '@tanstack/vue-query'
import { watchDebounced } from '@vueuse/core'
import { useRouteQuery } from '@vueuse/router'
import { TabsContent, TabsList, TabsRoot, TabsTrigger } from 'reka-ui'
import { TransitionGroup } from 'vue'

import BlockTemplateCreateDialog from '~/components/blocks/BlockTemplateCreateDialog.vue'
import CommentsSidebar from '~/components/comments/CommentsSidebar.vue'
import ContentPageHeaderPortals from '~/components/content/ContentPageHeaderPortals.vue'
import ContentInfo from '~/components/ContentInfo.vue'
import ContentSettings from '~/components/ContentSettings.vue'
import EditorComponent from '~/components/editor/EditorComponent.vue'
import Icon from '~/components/Icon.vue'
import { animatedIcons } from '~/components/icons'
import Preview from '~/components/Preview.vue'
import AiContentInteraction from '~/components/ui/AiContentInteraction.vue'
import { Badge, type BadgeVariants } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import { ScrollArea } from '~/components/ui/scroll-area'
import { SimpleTooltip } from '~/components/ui/tooltip'
import {
  createContentDefaultsBlockLookup,
  hydrateContentWithSchema,
} from '~/composables/useSchemaDefaults'
import { useContentEditorPage } from '~/composables/useContentEditorPage'
import {
  useContentLiveCollaboration,
  type ContentCommitAction,
} from '~/composables/useContentLiveCollaboration'
import { useContentSchemaState } from '~/composables/useContentSchemaState'
import type { ContentTreeItem } from '~/composables/useContentTree'
import { useGlobalClipboard } from '~/composables/useGlobalClipboard'
import {
  buildMissingLanguageDraft,
  resolveContentLanguage,
  resolveContentRouteName,
  withContentLanguageQuery,
} from '~/lib/content-i18n'
import { createVersionConflictState } from '~/lib/contentEditorState'
import { queryKeys } from '~/composables/useQueryClient'
import type { ContentResource } from '~/types/contents'
import type { FieldUpdateEvent } from '~/utils/preview-bridge'

const { t } = useI18n()
const queryClient = useQueryClient()
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
const canonicalContentIdForQuery = computed(
  () => routeContent.value?.i18n_canonical_id || canonicalContentId.value
)
const { data: canonicalContentResponse } = useContentQuery(canonicalContentIdForQuery)
const canonicalContent = computed(() => canonicalContentResponse.value || null)

const content = ref<ContentResource | null>(null)
// persistedContent is only ever replaced wholesale (never mutated in place), so a
// shallowRef + markRaw avoids paying for a deep reactive proxy over the baseline.
const persistedContent = shallowRef<ContentResource | null>(null)

const {
  defaultLanguage,
  languageQuery: activeLanguage,
  resolvedLanguage: resolvedActiveLanguage,
  isDirty,
  markSaved,
  provideValidationState,
} = useContentEditorPage({
  content,
  persistedContent,
  routeContent,
  canonicalContent,
  spaceDefaultLanguage: computed(() => spaceData.value?.settings.default_language),
  dirtyStrategy: 'edit-version',
  onDiscardChanges: () => {
    discardLocalContentChanges()
    discardOwnDrafts()
  },
})

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

const editorContentTree = ref<ContentTreeItem | null>(null)

// Guards for the two tree<->content sync watchers below. The paired flags cancel a
// write's echo in the opposite direction; suppressTreeSync hard-disables both while
// we rewrite both trees wholesale (load / discard / persist).
let contentWriteFromTree = false
let treeWriteFromContent = false
let suppressTreeSync = false

const versionConflict = createVersionConflictState({
  contentId: computed(() => currentContentSource.value?.id),
  serverVersionId: computed(() => currentContentSource.value?.current_version_id),
  persistedVersionId: computed(() => persistedContent.value?.current_version_id),
})

const buildEditorContentTree = (value: ContentResource | null): ContentTreeItem | null => {
  if (!value) return null

  return {
    id: value.id,
    block: value.block?.slug || '',
    ...JSON.parse(JSON.stringify((value.content || {}) as Record<string, unknown>)),
  } as ContentTreeItem
}

const stripEditorContentTree = (value: ContentTreeItem | null): Record<string, unknown> => {
  if (!value) return {}

  const {
    id: _id,
    block: _block,
    ...contentFields
  } = JSON.parse(JSON.stringify(value)) as ContentTreeItem

  return contentFields as Record<string, unknown>
}

const editorContentModel = computed<ContentTreeItem>({
  get: () =>
    editorContentTree.value ||
    ({
      id: content.value?.id || '',
      block: content.value?.block?.slug || '',
    } as ContentTreeItem),
  set: (value) => {
    if (!content.value) return

    editorContentTree.value = value
    content.value.content = stripEditorContentTree(value)
  },
})
const validation = useContentSchemaState({
  content,
  blocks,
})
provideValidationState(validation)
const { clearServerErrors, resetValidationState, sanitizedContent } = validation

const { useCommentsQuery } = useComments(
  spaceId,
  computed(() => content.value?.id || null)
)
const { data: comments } = useCommentsQuery()

const aiInteractionRef = useTemplateRef('aiInteractionRef')

const showAi = ref(false)

const cloneContent = (value: ContentResource): ContentResource => JSON.parse(JSON.stringify(value))

const isSameJsonValue = (left: unknown, right: unknown): boolean => {
  if (left === right) return true
  if (left == null || right == null || typeof left !== typeof right) return left === right
  if (typeof left !== 'object') return Object.is(left, right)

  if (Array.isArray(left) || Array.isArray(right)) {
    if (!Array.isArray(left) || !Array.isArray(right) || left.length !== right.length) {
      return false
    }

    return left.every((item, index) => isSameJsonValue(item, right[index]))
  }

  const leftRecord = left as Record<string, unknown>
  const rightRecord = right as Record<string, unknown>
  const leftKeys = Object.keys(leftRecord)
  if (leftKeys.length !== Object.keys(rightRecord).length) return false

  return leftKeys.every(
    (key) =>
      Object.prototype.hasOwnProperty.call(rightRecord, key) &&
      isSameJsonValue(leftRecord[key], rightRecord[key])
  )
}
const hydrationBlockLookup = computed<
  Record<string, Pick<BlockResource, 'slug' | 'schema' | 'name'>>
>(() => {
  const lookup = createContentDefaultsBlockLookup(blocks.value) as Record<
    string,
    Pick<BlockResource, 'slug' | 'schema' | 'name'>
  >

  if (currentContentSource.value?.block?.slug && currentContentSource.value.block_schema) {
    lookup[currentContentSource.value.block.slug] = {
      slug: currentContentSource.value.block.slug,
      schema: currentContentSource.value.block_schema,
      name: currentContentSource.value.block.name,
    }
  }

  return lookup
})

const syncPersistedContent = (
  nextContent: ContentResource,
  mode: 'replace' | 'preserve-local' = 'replace'
) => {
  const cloned = cloneContent(nextContent)
  cloned.content = hydrateContentWithSchema(
    cloned.block_schema,
    cloned.content,
    hydrationBlockLookup.value
  )

  persistedContent.value = markRaw(cloned)

  suppressTreeSync = true
  if (!content.value || mode === 'replace') {
    content.value = cloneContent(cloned)
    editorContentTree.value = buildEditorContentTree(content.value)
    // Baseline is now in sync with the editing model: clear dirty state.
    markSaved()
  } else {
    // preserve-local: keep the user's in-flight edits and the document they
    // are editing. Taking `id` / language identity from `cloned` would save
    // those edits onto another language version.
    content.value = {
      ...content.value,
      ...cloned,
      id: content.value.id,
      language_iso: content.value.language_iso,
      i18n_parent_id: content.value.i18n_parent_id,
      i18n_canonical_id: content.value.i18n_canonical_id,
      content: content.value.content,
    }

    if (!editorContentTree.value) {
      editorContentTree.value = buildEditorContentTree(content.value)
    }
  }
  nextTick(() => {
    suppressTreeSync = false
  })
}

watch(
  [currentContentSource, hydrationBlockLookup],
  ([newContent]) => {
    if (newContent) {
      const isIncomingDifferentDocument =
        !!content.value?.id && !!newContent.id && content.value.id !== newContent.id
      // A different content row while dirty is a leave, not a refetch. The
      // guard confirms first; until then keep the in-edit document as-is.
      if (isIncomingDifferentDocument && isDirty.value) {
        return
      }

      const shouldReplace = !content.value || !persistedContent.value || !isDirty.value

      syncPersistedContent(newContent, shouldReplace ? 'replace' : 'preserve-local')
      resetValidationState()
    }
  },
  { immediate: true }
)

// editorContentTree -> content.content: the editor mutates the tree in place as the
// user types; mirror those edits into the canonical content. The paired guard drops
// the echo when the change actually originated from a content -> tree rebuild.
watch(
  editorContentTree,
  (nextTree) => {
    if (!content.value || !nextTree || suppressTreeSync) return
    if (treeWriteFromContent) {
      treeWriteFromContent = false
      return
    }

    contentWriteFromTree = true
    content.value.content = stripEditorContentTree(nextTree)
  },
  { deep: true }
)

// content.content -> editorContentTree: remote collaboration, AI, preview edits and
// schema pruning all write into content.value.content; rebuild the editor tree so
// those changes show up in the form. Guarded so a local edit's write-back is ignored.
watch(
  () => content.value?.content,
  (nextContent) => {
    if (!content.value || !nextContent || suppressTreeSync) return
    if (contentWriteFromTree) {
      contentWriteFromTree = false
      return
    }

    treeWriteFromContent = true
    editorContentTree.value = buildEditorContentTree(content.value)
  },
  { deep: true }
)

// Schema pruning (dropping values of hidden conditional fields) walks the whole
// tree, so run it debounced instead of on every keystroke. Pruning a pristine
// document must not make it look dirty, so re-baseline when it was clean.
watchDebounced(
  () => content.value?.content,
  () => {
    if (!content.value) return

    const sanitized = sanitizedContent.value || {}
    const current = content.value.content || {}
    // pruneScope already cloned `sanitized`; only write it back when it
    // actually dropped something. Walk both trees instead of stringifying.
    if (isSameJsonValue(sanitized, current)) return

    const wasDirty = isDirty.value
    content.value.content = sanitized
    if (!wasDirty) markSaved()
  },
  { deep: true, debounce: 300 }
)

const discardLocalContentChanges = () => {
  if (persistedContent.value) {
    suppressTreeSync = true
    content.value = cloneContent(persistedContent.value)
    editorContentTree.value = buildEditorContentTree(content.value)
    markSaved()
    nextTick(() => {
      suppressTreeSync = false
    })
  }
}

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
  broadcastBlockOperation,
  broadcastCommentUpdate,
  collaborators,
  discardOwnDrafts,
  getCollaboratorsForField,
  getSubtreeCollaborators,
  getAggregatedCollaboratorsForField,
  getDraftOwners,
  remoteDraftCollaborators,
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
  versionConflict.anchor(nextContent.current_version_id)
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

const updateField = (update: FieldUpdateEvent) => {
  if (!content.value?.content) return

  // Newer site SDKs address fields by path; only flat (top-of-block) paths map
  // onto the (itemId, field) content model used here.
  const field = update.field ?? (update.path?.length === 1 ? String(update.path[0]) : null)
  if (!field) return

  if (update.itemId === content.value.id) {
    content.value.content = {
      ...(content.value.content as Record<string, unknown>),
      [field]: update.value,
    }
    return
  }

  const target = findNestedObjectById(content.value.content, update.itemId)
  if (target) {
    target[field] = update.value
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
provide('broadcastCommentUpdate', broadcastCommentUpdate)
provide('commitPersistedContent', commitPersistedContent)
provide('discardOwnDrafts', discardOwnDrafts)
provide('getActiveCollaborators', getCollaboratorsForField)
provide('getSubtreeCollaborators', getSubtreeCollaborators)
provide('getAggregatedCollaboratorsForField', getAggregatedCollaboratorsForField)
provide('getDraftOwners', getDraftOwners)
provide('updatePreviewItem', updatePreviewItem)
provide('updateHoverItem', (id: string) => {
  if (previewRef.value) {
    ;(previewRef.value as any).updateHover(id)
  }
})
provide('resetDirtyState', resetDirtyState)
provide('editingFromVersionId', versionConflict.editingFromVersionId)
provide('serverVersionDrifted', versionConflict.hasDrifted)
provide('serverCurrentVersion', computed(() => currentContentSource.value?.current_version))

const reloadServerContent = () => {
  versionConflict.reset()
  discardLocalContentChanges()
  const contentIdToInvalidate = activeContentId.value || canonicalContentId.value
  queryClient.invalidateQueries({
    queryKey: queryKeys.contents(spaceId).detail(contentIdToInvalidate),
  })
  if (canonicalContentId.value !== contentIdToInvalidate) {
    queryClient.invalidateQueries({
      queryKey: queryKeys.contents(spaceId).detail(canonicalContentId.value),
    })
  }
}
provide('reloadServerContent', reloadServerContent)
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
    :class="['flex min-h-0', showPreview ? 'w-132' : 'w-full']"
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
        :class="['min-w-0! p-4', showPreview ? '' : 'mx-auto max-w-4xl', showAi ? 'pb-52' : 'pb-8']"
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
          @block-operation="broadcastBlockOperation"
        />
        <div
          :class="[
            showPreview ? 'inset-x-4' : 'w-full max-w-4xl',
            'py-2 overflow-clip absolute bottom-0 flex flex-col items-center gap-3 z-10',
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
        'flex h-full flex-col shrink-0 border-l border-l-border select-none transition-[width,padding] duration-200 ease-butter',
        isExtendedSidebar ? 'w-18 p-1' : 'w-14 p-3',
      ]"
    >
      <div class="flex min-h-0 flex-1 flex-col">
        <div
          :class="[
            'relative flex w-full min-w-0 flex-col transition-[gap] duration-200 ease-butter',
            isExtendedSidebar ? 'gap-1' : 'gap-2',
          ]"
        >
          <SimpleTooltip
            v-for="tab in tabs"
            :key="tab.value"
            :tooltip="tab.label"
            :disabled="isExtendedSidebar"
            side="left"
            class="flex cursor-pointer"
          >
            <TabsTrigger
              :value="tab.value"
              :class="[
                'icon-anim relative flex w-full flex-col items-center justify-center rounded-lg text-center transition-[padding,gap,background-color,color] duration-200 ease-butter hover:bg-border',
                isExtendedSidebar ? 'gap-1 p-2' : 'gap-0 p-1.5',
                mode === tab.value ? 'bg-border text-primary' : '',
              ]"
            >
              <component
                :is="animatedIcons[tab.icon]"
                v-if="animatedIcons[tab.icon]"
                :size="20"
              />
              <Icon
                v-else
                :name="tab.icon"
                size="20"
              />
              <span
                :class="[
                  'grid w-full transition-[grid-template-rows,opacity] duration-200 ease-butter',
                  isExtendedSidebar ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0',
                ]"
                :aria-hidden="!isExtendedSidebar"
              >
                <span class="line-clamp-2 min-h-0 overflow-hidden text-[10px] leading-tight">
                  {{ tab.label }}
                </span>
              </span>
              <Badge
                v-if="tab.badge?.show"
                :variant="tab.badge.variant"
                size="dot"
                :class="[
                  'absolute transition-[top,right] duration-200 ease-butter',
                  isExtendedSidebar ? 'top-1 right-1' : '-top-1 -right-1',
                ]"
              >
                {{ tab.badge.content }}
              </Badge>
            </TabsTrigger>
          </SimpleTooltip>
        </div>
      </div>
      <div class="flex flex-col">
        <Button
          :class="[
            'flex w-full flex-col items-center justify-center rounded-lg transition-[padding,gap,background-color,color] duration-200 ease-butter hover:bg-border',
            isExtendedSidebar ? 'gap-1 p-2' : 'gap-0 p-1.5',
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
            :class="[
              'grid w-full transition-[grid-template-rows,opacity] duration-200 ease-butter',
              isExtendedSidebar ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0',
            ]"
            :aria-hidden="!isExtendedSidebar"
          >
            <span class="line-clamp-2 min-h-0 overflow-hidden text-[10px] leading-tight">AI</span>
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

  <ContentPageHeaderPortals
    :content="content"
    :space-id="spaceId"
    :is-dirty="isDirty"
    :show-preview-toggle="!isPreviewDisabled"
    :present-users="collaborators"
    :remote-draft-users="remoteDraftCollaborators"
  />
</template>
