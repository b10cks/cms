<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { useStorage } from '@vueuse/core'
import { useRouteQuery } from '@vueuse/router'
import { toRaw } from 'vue'

import { api } from '~/api'
import ContentHeader from '~/components/content/ContentHeader.vue'
import HeaderActions from '~/components/content/HeaderActions.vue'
import Icon from '~/components/Icon.vue'
import FlattenedLocalization from '~/components/localization/FlattendeLocalization.vue'
import Preview from '~/components/Preview.vue'
import { Input } from '~/components/ui/input'
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '~/components/ui/resizable'
import { useAlertDialog } from '~/composables/useAlertDialog'
import { useContentSchemaState } from '~/composables/useContentSchemaState'
import {
  buildMissingLanguageDraft,
  getContentDefaultLanguage,
  resolveContentLanguage,
  resolveContentRouteName,
  withContentLanguageQuery,
} from '~/lib/content-i18n'
import type { ContentResource } from '~/types/contents'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { alert } = useAlertDialog()
const spaceId = computed<string>(() => route.params.space as string)
const canonicalContentId = computed<string>(() => route.params.contentId as string)
const { settings } = useSpaceSettings(spaceId.value)


const { useSpaceQuery } = useSpaces()
const { data: currentSpace } = useSpaceQuery(spaceId.value)
const { useContentQuery } = useContent(spaceId)
const spaceAPI = computed(() => api.forSpace(spaceId.value))
const { data: routeContent } = useContentQuery(canonicalContentId)


const defaultLanguage = computed(() =>
  getContentDefaultLanguage(
    currentSpace.value?.settings?.default_language,
    routeContent.value?.language_versions,
    routeContent.value?.language_iso
  )
)
const language = useRouteQuery<string | undefined>('lang')
const canonicalContent = computed(() => {
  if (!routeContent.value) {
    return null
  }

  if (routeContent.value.i18n_parent_id === null) {
    return routeContent.value
  }

  return {
    ...routeContent.value,
    id: routeContent.value.i18n_canonical_id,
  }
})
const resolvedLanguage = computed(() =>
  resolveContentLanguage(
    language.value,
    defaultLanguage.value,
    canonicalContent.value?.language_versions,
    routeContent.value?.language_iso
  )
)
const selectedLanguageVersion = computed(
  () =>
    canonicalContent.value?.language_versions?.find(
      (version) => version.language_iso === resolvedLanguage.value
    ) ||
    canonicalContent.value?.language_versions?.[0] ||
    null
)
const translatableId = computed(() => selectedLanguageVersion.value?.content_id || null)
const fallbackLanguageVersion = computed(
  () =>
    canonicalContent.value?.language_versions?.find(
      (version) => version.language_iso === selectedLanguageVersion.value?.fallback_language
    ) || null
)
const { data: translatableOriginalContent } = useContentQuery(translatableId)
const sourceChainVersions = computed(() => {
  if (!canonicalContent.value || !selectedLanguageVersion.value) {
    return []
  }

  const versionsByLanguage = new Map(
    canonicalContent.value.language_versions.map(
      (version) => [version.language_iso, version] as const
    )
  )
  const chain = []
  const visited = new Set<string>([selectedLanguageVersion.value.language_iso])
  let nextLanguage = selectedLanguageVersion.value.fallback_language

  while (nextLanguage && !visited.has(nextLanguage)) {
    const version = versionsByLanguage.get(nextLanguage)
    if (!version) {
      break
    }

    chain.push(version)
    visited.add(nextLanguage)
    nextLanguage = version.fallback_language
  }

  return chain
})
const sourceChainIds = computed(() =>
  sourceChainVersions.value
    .map((version) => version.content_id)
    .filter((contentId): contentId is string => !!contentId)
)
const { data: sourceChainContents } = useQuery({
  queryKey: computed(() => ['localization-source-chain', spaceId.value, sourceChainIds.value]),
  queryFn: async () => {
    if (sourceChainIds.value.length === 0) {
      return []
    }

    const contents = await Promise.all(
      sourceChainIds.value.map(async (contentId) => {
        const response = await spaceAPI.value.contents.get(contentId)
        return response.data
      })
    )

    const contentsById = new Map(contents.map((content) => [content.id, content] as const))

    return sourceChainIds.value
      .map((contentId) => contentsById.get(contentId))
      .filter((content): content is ContentResource => !!content)
  },
})


const translatableContent = ref<ContentResource | null>(null)
const persistedContent = ref<ContentResource | null>(null)
const translationContentPayload = computed<Record<string, unknown>>(
  () => (translatableContent.value?.content as Record<string, unknown>) || {}
)
const previewRef = useTemplateRef<InstanceType<typeof Preview>>('previewRef')
const previewContentRef = computed(
  () => translatableContent.value || canonicalContent.value || null
)
const viewMode = useStorage<'split' | 'localize' | 'preview'>(
  `space-${spaceId.value}-localization-view`,
  'split'
)


const clonePreviewValue = <T>(value: T): T => {
  if (value === undefined || value === null || typeof value !== 'object') {
    return value
  }


  return JSON.parse(JSON.stringify(toRaw(value))) as T
}


const mergeOverlayContent = (base: unknown, overlay: unknown): unknown => {
  if (Array.isArray(base) && Array.isArray(overlay)) {
    return base.map((item, index) =>
      index in overlay ? mergeOverlayContent(item, overlay[index]) : clonePreviewValue(item)
    )
  }


  if (
    base &&
    overlay &&
    typeof base === 'object' &&
    typeof overlay === 'object' &&
    !Array.isArray(base) &&
    !Array.isArray(overlay)
  ) {
    const merged: Record<string, unknown> = clonePreviewValue(base as Record<string, unknown>)


    Object.entries(overlay as Record<string, unknown>).forEach(([key, value]) => {
      merged[key] =
        key in merged ? mergeOverlayContent(merged[key], value) : clonePreviewValue(value)
    })


    return merged
  }


  if (overlay !== undefined) {
    return clonePreviewValue(overlay)
  }


  return clonePreviewValue(base)
}


const nearestSourceContent = computed(
  () => sourceChainContents.value?.[0] || canonicalContent.value || null
)
const sourceContentPayload = computed<Record<string, unknown>>(() => {
  const chain = sourceChainContents.value || []

  if (chain.length === 0) {
    return (canonicalContent.value?.content as Record<string, unknown>) || {}
  }

  return chain
    .slice()
    .reverse()
    .reduce<Record<string, unknown>>(
      (merged, content) =>
        mergeOverlayContent(merged, content.content as Record<string, unknown>) as Record<
          string,
          unknown
        >,
      {}
    )
})


const mergePreviewContent = (source: unknown, overlay: unknown): unknown => {
  return mergeOverlayContent(source, overlay)
}


const previewContentPayload = computed<Record<string, unknown>>(
  () =>
    (mergePreviewContent(sourceContentPayload.value, translationContentPayload.value) as Record<
      string,
      unknown
    >) || {}
)


const cloneContent = (value: ContentResource): ContentResource => JSON.parse(JSON.stringify(value))


const syncPersistedContent = (nextContent: ContentResource) => {
  const cloned = cloneContent(nextContent)
  persistedContent.value = cloned
  translatableContent.value = cloneContent(cloned)
}


watch(
  [routeContent, defaultLanguage],
  async ([currentContent, currentDefaultLanguage]) => {
    if (!currentContent) {
      return
    }

    const currentLanguage = resolveContentLanguage(
      language.value,
      currentDefaultLanguage,
      currentContent.language_versions,
      currentContent.language_iso
    )
    const nextQuery = withContentLanguageQuery(
      route.query as Record<string, unknown>,
      currentLanguage,
      currentDefaultLanguage
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
        query: nextQuery as any,
        hash: '',
      })
      return
    }

    if (route.query.lang !== nextQuery.lang) {
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
        query: nextQuery as any,
        hash: route.hash,
      })
    }
  },
  { immediate: true }
)


watch(
  [canonicalContent, language, defaultLanguage],
  async ([currentCanonical, currentLanguage, currentDefaultLanguage]) => {
    if (!currentCanonical) {
      return
    }

    const nextLanguage = resolveContentLanguage(
      currentLanguage,
      currentDefaultLanguage,
      currentCanonical.language_versions,
      routeContent.value?.language_iso
    )

    if (
      (nextLanguage === currentDefaultLanguage && language.value !== undefined) ||
      (nextLanguage !== currentDefaultLanguage && language.value !== nextLanguage)
    ) {
      language.value = nextLanguage === currentDefaultLanguage ? undefined : nextLanguage
      return
    }

    const nextRouteName = resolveContentRouteName(
      route.name as string | undefined,
      currentCanonical.effective_i18n_mode,
      nextLanguage,
      currentDefaultLanguage
    )

    const nextQuery = withContentLanguageQuery(
      route.query as Record<string, unknown>,
      nextLanguage,
      currentDefaultLanguage
    )

    if (
      route.name !== nextRouteName ||
      route.params.contentId !== currentCanonical.id ||
      route.query.lang !== nextQuery.lang
    ) {
      await router.replace({
        name: nextRouteName,
        params: {
          ...route.params,
          contentId: currentCanonical.id,
        },
        query: nextQuery as any,
        hash: route.hash,
      })
    }
  },
  { immediate: true }
)


watch(
  [translatableOriginalContent, canonicalContent, nearestSourceContent, resolvedLanguage],
  ([existingTranslation, currentCanonical, currentSource, currentLanguage]) => {
    if (!currentCanonical || !currentLanguage) {
      return
    }

    if (existingTranslation) {
      syncPersistedContent(existingTranslation)
      return
    }

    const seedSource = currentSource || currentCanonical
    const draft = buildMissingLanguageDraft(currentCanonical, seedSource, currentLanguage)

    draft.name = seedSource.name
    draft.slug = seedSource.slug
    draft.content = {}

    syncPersistedContent(draft)
  },
  { immediate: true }
)


const { useBlocksQuery } = useBlocks(spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })
const blockList = computed(() => blocks.value?.data || [])
const {
  sanitizedContent,
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
  content: translatableContent,
  blocks: blockList,
  effectiveContent: previewContentPayload,
})


const block = computed(() => {
  if (!canonicalContent.value || !blocks.value) return null

  const blockId = canonicalContent.value.block?.id || canonicalContent.value.block_id
  return blocks.value.data.find((blockItem) => blockItem.id === blockId) || null
})


const blockSchemaCache = ref(new Map())


watch(
  blocks,
  (newBlocks) => {
    if (!newBlocks) return

    blockSchemaCache.value.clear()

    newBlocks.data.forEach((blockItem) => {
      blockSchemaCache.value.set(blockItem.slug, {
        id: blockItem.id,
        name: blockItem.name,
        schema: blockItem.schema,
      })
    })
  },
  { immediate: true }
)


watch(
  sanitizedContent,
  (nextSanitized) => {
    if (!translatableContent.value) return

    const currentSerialized = JSON.stringify(translatableContent.value.content || {})
    const sanitizedSerialized = JSON.stringify(nextSanitized || {})

    if (currentSerialized === sanitizedSerialized) return

    translatableContent.value.content = JSON.parse(sanitizedSerialized)
  },
  { deep: true }
)


const isLoading = computed(
  () =>
    !canonicalContent.value ||
    (!!translatableId.value && !translatableOriginalContent.value) ||
    sourceChainIds.value.some(
      (contentId) => !(sourceChainContents.value || []).some((content) => content.id === contentId)
    ) ||
    !block.value ||
    !translatableContent.value
)
const isPreviewDisabled = computed(
  () =>
    currentSpace.value?.settings?.visual_editor === false ||
    translatableContent.value?.settings?.disablePreview === true
)
const showPreview = computed(() => {
  return (
    currentSpace.value?.settings?.visual_editor !== false &&
    settings.value.content.showPreview &&
    translatableContent.value?.settings?.disablePreview !== true
  )
})
const showPreviewPane = computed(() => showPreview.value && viewMode.value !== 'localize')
const editorPanel = useTemplateRef<InstanceType<typeof ResizablePanel>>('editorPanel')


const toggleEditorPanel = () => {
  if (editorPanel.value) {
    if (editorPanel.value.isCollapsed) {
      editorPanel.value.expand()
    } else {
      editorPanel.value.collapse()
    }
  }
}


const getBlockSchemaFn = (blockSlug: string) => {
  return blockSchemaCache.value.get(blockSlug)
}


const isDirty = computed(() => {
  if (!translatableContent.value || !persistedContent.value) return false
  return JSON.stringify(translatableContent.value) !== JSON.stringify(persistedContent.value)
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


watch(
  showPreview,
  (enabled) => {
    if (!enabled && viewMode.value === 'preview') {
      viewMode.value = 'localize'
    }
  },
  { immediate: true }
)


watch(
  previewContentPayload,
  (content) => {
    previewRef.value?.updateItem(content)
  },
  { deep: true }
)


provide('commitPersistedContent', (nextContent: ContentResource) => {
  syncPersistedContent(nextContent)
  clearServerErrors()
  resetValidationState()
})
provide('resetDirtyState', () => {
  if (translatableContent.value) {
    syncPersistedContent(translatableContent.value)
  }
  resetValidationState()
})
provide('content', previewContentRef)
provide('getFieldError', getFieldError)
provide('shouldShowFieldError', shouldShowFieldError)
provide('setValidationErrors', setServerErrors)
provide('clearValidationErrors', clearServerErrors)
provide('getClientValidationErrors', getClientErrors)
provide('sanitizeContentForSubmit', () => sanitizedContent.value)
provide('validateContentForSubmit', validateAllForSubmit)
provide('submitValidationAttempted', submitAttempted)
provide('focusFirstValidationError', focusFirstInvalidField)


useSeoMeta({
  title: computed(
    () => canonicalContent.value?.name || t('labels.contents.localization.pageTitle')
  ),
})
</script>

<template>
  <div class="w-full">
    <ResizablePanelGroup
      id="localization-group-1"
      direction="horizontal"
    >
      <ResizablePanel
        v-if="showPreviewPane && translatableContent"
        id="localization-panel-1"
      >
        <Preview
          ref="previewRef"
          class="h-full"
          :space-id="spaceId"
          :content-id="translatableContent.id || canonicalContentId"
          :full-slug="translatableContent.full_slug"
          :updated-at="translatableContent.updated_at"
          :content="previewContentPayload"
        />
      </ResizablePanel>
      <ResizableHandle
        v-if="showPreviewPane && translatableContent"
        id="content-handle-1"
      />
      <ResizablePanel
        id="localization-panel-2"
        ref="editorPanel"
        class="overflow-y-auto p-3 bg-background"
      >
        <div
          v-if="isLoading"
          class="flex h-full items-center justify-center"
        >
          <div class="text-center">
            <Icon
              name="lucide:loader"
              class="mx-auto mb-4 h-8 w-8 animate-spin text-text-muted"
            />
            <p class="text-muted">Loading content...</p>
          </div>
        </div>
        <div
          v-else
          class="flex h-full flex-col"
        >
          <div
            v-if="block && canonicalContent && translatableContent"
            class="grid gap-6"
          >
            <div class="flex flex-wrap items-start justify-between gap-4 rounded-lg bg-surface p-3">
              <div class="min-w-0 flex-1 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                  <div class="space-y-2">
                    <label
                      for="original-name"
                      class="block text-sm font-medium"
                    >
                      Content Name (Source)
                    </label>
                    <Input
                      id="original-name"
                      :model-value="nearestSourceContent?.name"
                      disabled
                      aria-label="Source content name"
                    />
                  </div>
                  <div class="space-y-2">
                    <label
                      for="translated-name"
                      class="block text-sm font-medium"
                    >
                      Content Name (Translation)
                    </label>
                    <Input
                      id="translated-name"
                      v-model="translatableContent.name"
                      aria-label="Translated content name"
                    />
                  </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                  <div class="space-y-2">
                    <label
                      for="original-slug"
                      class="block text-sm font-medium"
                    >
                      Content Slug (Source)
                    </label>
                    <Input
                      id="original-slug"
                      :model-value="nearestSourceContent?.slug"
                      disabled
                      aria-label="Source content slug"
                    />
                  </div>
                  <div class="space-y-2">
                    <label
                      for="translated-slug"
                      class="block text-sm font-medium"
                    >
                      Content Slug (Translation)
                    </label>
                    <Input
                      id="translated-slug"
                      v-model="translatableContent.slug"
                      aria-label="Translated content slug"
                    />
                  </div>
                </div>
              </div>
            </div>
            <FlattenedLocalization
              :original-content="sourceContentPayload"
              :translation-content="translationContentPayload"
              :block-schema="block.schema"
              :space-id="spaceId"
              :get-block-schema="getBlockSchemaFn"
              :target-language="resolvedLanguage"
            />
          </div>
        </div>
      </ResizablePanel>
    </ResizablePanelGroup>
  </div>
  <Teleport
    defer
    to="#appHeader"
  >
    <ContentHeader
      v-if="translatableContent"
      :content="translatableContent"
      :show-preview-toggle="!isPreviewDisabled"
    />
  </Teleport>

  <Teleport
    defer
    to="#appHeaderActions"
  >
    <HeaderActions
      v-if="translatableContent"
      :content="translatableContent"
      :space-id="spaceId"
      :is-dirty="isDirty"
    />
  </Teleport>
</template>
