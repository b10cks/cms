<script setup lang="ts">
import { useQuery } from '@tanstack/vue-query'
import { refDebounced, useStorage } from '@vueuse/core'
import { toRaw } from 'vue'

import { api } from '~/api'
import ContentPageHeaderPortals from '~/components/content/ContentPageHeaderPortals.vue'
import Icon from '~/components/Icon.vue'
import FlattenedLocalization from '~/components/localization/FlattenedLocalization.vue'
import Preview from '~/components/Preview.vue'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'
import { ResizableHandle, ResizablePanel, ResizablePanelGroup } from '~/components/ui/resizable'
import { ScrollArea } from '~/components/ui/scroll-area'
import { Spinner } from '~/components/ui/spinner'
import { useContentEditorPage } from '~/composables/useContentEditorPage'
import {
  useContentLiveCollaboration,
  type ContentCommitAction,
} from '~/composables/useContentLiveCollaboration'
import { useContentSchemaState } from '~/composables/useContentSchemaState'
import {
  buildMissingLanguageDraft,
  resolveContentLanguage,
  resolveContentRouteName,
  withContentLanguageQuery,
} from '~/lib/content-i18n'
import {
  applyLocalizedFieldValue,
  getLocalizedFieldValue,
  type LocalizedFieldFocusPayload,
  type LocalizedFieldMeta,
  type LocalizedFieldUpdatePayload,
} from '~/lib/localizationCollab'
import { mergeLocalizedContentForSchema } from '~/lib/tableField'
import type { ContentResource } from '~/types/contents'
import type { FieldUpdateEvent } from '~/utils/preview-bridge'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const spaceId = computed<string>(() => route.params.space as string)
const canonicalContentId = computed<string>(() => route.params.contentId as string)
const { settings } = useSpaceSettings(spaceId.value)

const { useSpaceQuery } = useSpaces()
const { data: currentSpace } = useSpaceQuery(spaceId.value)
const { useContentQuery, useSerialPreviewQuery } = useContent(spaceId)
const { slugify } = useContentWizardSlug()
const spaceAPI = computed(() => api.forSpace(spaceId.value))
const { data: routeContent } = useContentQuery(canonicalContentId)

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

const translatableContent = ref<ContentResource | null>(null)
const persistedContent = ref<ContentResource | null>(null)

const {
  defaultLanguage,
  languageQuery: language,
  resolvedLanguage,
  isDirty,
  provideValidationState,
} = useContentEditorPage({
  content: translatableContent,
  persistedContent,
  routeContent,
  canonicalContent,
  spaceDefaultLanguage: computed(() => currentSpace.value?.settings?.default_language),
  // A translation is a handful of fields, so comparing against the baseline is
  // cheap — and undoing an edit by hand makes the page clean again.
  dirtyStrategy: 'snapshot',
  onDiscardChanges: () => discardOwnDrafts(),
})

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

/**
 * `auto` follows the block's slug pattern (or the translated name) and lets the
 * server compose the final value; `manual` is the editor's own slug. Existing
 * translations start manual so a persisted slug never moves on its own.
 */
const slugMode = ref<'auto' | 'manual'>('auto')
const translationContentPayload = computed<Record<string, unknown>>(
  () => (translatableContent.value?.content as Record<string, unknown>) || {}
)
const updateTranslationContent = (nextContent: Record<string, unknown>) => {
  if (!translatableContent.value) {
    return
  }

  translatableContent.value.content = nextContent
}
const previewRef = useTemplateRef<InstanceType<typeof Preview>>('previewRef')
const localizationRef =
  useTemplateRef<InstanceType<typeof FlattenedLocalization>>('localizationRef')
const previewContentRef = computed(
  () => translatableContent.value || canonicalContent.value || null
)

// Ids the preview iframe may report as the root item of an inline edit.
const previewRootItemIds = computed(() =>
  [
    translatableContent.value?.id,
    canonicalContent.value?.id,
    canonicalContentId.value,
    nearestSourceContent.value?.id,
  ].filter((id, index, ids): id is string => !!id && ids.indexOf(id) === index)
)

const handlePreviewFieldUpdate = (payload: FieldUpdateEvent) => {
  localizationRef.value?.applyPreviewFieldUpdate(payload)
}
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
    .reduce<Record<string, unknown>>((merged, content) => {
      const schema = (block.value?.schema || {}) as Record<string, SchemaType>

      return mergeLocalizedContentForSchema(
        merged,
        (content.content as Record<string, unknown>) || {},
        schema,
        getBlockSchemaFn
      ) as Record<string, unknown>
    }, {})
})

const mergePreviewContent = (source: unknown, overlay: unknown): unknown => {
  if (!source || typeof source !== 'object' || Array.isArray(source)) {
    return clonePreviewValue(overlay)
  }

  return mergeLocalizedContentForSchema(
    source as Record<string, unknown>,
    ((overlay as Record<string, unknown>) || {}) as Record<string, unknown>,
    (block.value?.schema || {}) as Record<string, SchemaType>,
    getBlockSchemaFn
  )
}

const previewContentPayload = computed<Record<string, unknown>>(
  () =>
    (mergePreviewContent(sourceContentPayload.value, translationContentPayload.value) as Record<
      string,
      unknown
    >) || {}
)

// Root id as the delivery API reports it to the site: the translation row when
// it exists, otherwise the nearest fallback in the source chain, otherwise the
// canonical content. Site SDKs patch blocks by id, so pushes must carry it.
const previewRootId = computed(
  () =>
    translatableContent.value?.id ||
    nearestSourceContent.value?.id ||
    canonicalContent.value?.id ||
    canonicalContentId.value
)

// CONTENT_UPDATE pushes must be shaped like the block the site renders
// ({ id, block, ...fields }); a bare field map matches no v-editable element.
const previewItemPayload = computed<Record<string, unknown>>(() => ({
  id: previewRootId.value,
  block: block.value?.slug || canonicalContent.value?.block?.slug || '',
  ...previewContentPayload.value,
}))

const cloneContent = (value: ContentResource): ContentResource => JSON.parse(JSON.stringify(value))
const getLocalizedDraftContent = (value: ContentResource): Record<string, unknown> => {
  if (value.raw_content && typeof value.raw_content === 'object') {
    return JSON.parse(JSON.stringify(value.raw_content)) as Record<string, unknown>
  }

  return JSON.parse(JSON.stringify((value.content || {}) as Record<string, unknown>))
}

const syncPersistedContent = (nextContent: ContentResource) => {
  const cloned = cloneContent(nextContent)
  cloned.content = getLocalizedDraftContent(cloned)
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
      const isNewContent =
        !persistedContent.value || persistedContent.value.id !== existingTranslation.id
      if (isNewContent || !isDirty.value) {
        syncPersistedContent(existingTranslation)
        // A persisted slug must not silently move when the name changes.
        slugMode.value = 'manual'
      }
      return
    }

    const seedSource = currentSource || currentCanonical
    const draft = buildMissingLanguageDraft(currentCanonical, seedSource, currentLanguage)

    draft.name = seedSource.name
    // Left empty rather than copied from the source: the slug is generated for
    // the target language — from the block's slug pattern, or from the name.
    draft.slug = ''
    draft.content = {}

    if (!persistedContent.value || !isDirty.value) {
      syncPersistedContent(draft)
      slugMode.value = 'auto'
    }
  },
  { immediate: true }
)

const { useBlocksQuery } = useBlocks(spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })
const blockList = computed(() => blocks.value?.data || [])
const validation = useContentSchemaState({
  content: translatableContent,
  blocks: blockList,
  effectiveContent: sourceContentPayload,
  ignoreAbsentNonTranslatableFields: true,
})
provideValidationState(validation)
const { clearServerErrors, resetValidationState } = validation

const block = computed(() => {
  if (!canonicalContent.value || !blocks.value) return null

  const blockId = canonicalContent.value.block?.id || canonicalContent.value.block_id
  return blocks.value.data.find((blockItem) => blockItem.id === blockId) || null
})

// Debounced so typing a name doesn't fire a preview request per keystroke.
const debouncedTranslationName = refDebounced(
  computed(() => translatableContent.value?.name || ''),
  250
)

// The slug (and the retained canonical serials) a translation would get,
// rendered by the same composer the create action uses. `i18n_parent_id` makes
// the preview read the canonical entry's serials instead of peeking at the
// counter; `except_content_id` lets an existing translation regenerate its
// slug without colliding with itself.
const { data: serialPreview, isFetching: isSlugPreviewFetching } = useSerialPreviewQuery(
  computed(() => ({
    block_id: block.value?.id,
    parent_id: translatableContent.value?.parent_id ?? canonicalContent.value?.parent_id ?? null,
    language_iso: resolvedLanguage.value,
    name: debouncedTranslationName.value,
    i18n_parent_id: canonicalContent.value?.id ?? null,
    except_content_id: translatableContent.value?.id || null,
  })),
  computed(() => Boolean(block.value && resolvedLanguage.value))
)

const hasSlugPattern = computed(() => Boolean(serialPreview.value?.slug_pattern))

/** What the translation would get if the editor does not intervene. */
const automaticSlug = computed(() => {
  if (hasSlugPattern.value) {
    return serialPreview.value?.slug_preview ?? ''
  }

  // The translation's own language, not the space default: a German
  // translation expands "Ü" to "ue" where the French one folds it to "u".
  return slugify(translatableContent.value?.name || '', resolvedLanguage.value)
})

// In auto mode the input mirrors the automatic value without writing it into
// the draft — the value the server composes on save is the real one, and a
// mirrored value must not make the page dirty on its own.
const displayedSlug = computed(() =>
  slugMode.value === 'auto' ? automaticSlug.value : (translatableContent.value?.slug ?? '')
)

const setTranslationSlug = (value: string) => {
  if (!translatableContent.value) {
    return
  }

  // Clearing the field is how you ask for the automatic value back.
  if (value.trim() === '') {
    slugMode.value = 'auto'
    translatableContent.value.slug = persistedContent.value?.slug ?? ''
    return
  }

  slugMode.value = 'manual'
  translatableContent.value.slug = value
}

const resetSlugToAutomatic = () => {
  setTranslationSlug('')
}

const handleTranslatedName = (name: string) => {
  if (translatableContent.value && name.trim() !== '') {
    translatableContent.value.name = name
  }
}

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

// Live collaboration: translations may not be persisted yet (no content id), so
// the presence channel is keyed by canonical content + language instead. That
// key stays stable when a peer's save creates the translation row mid-session.
const collaborationContentKey = computed(() =>
  canonicalContent.value && resolvedLanguage.value
    ? `${canonicalContent.value.id}-i18n-${resolvedLanguage.value}`
    : null
)

const isLocalizedFieldMeta = (meta: unknown): meta is LocalizedFieldMeta =>
  !!meta && typeof meta === 'object' && Array.isArray((meta as LocalizedFieldMeta).path)

const syncRemotePersistedContent = (
  nextContent: ContentResource,
  mode: 'replace' | 'preserve-local'
) => {
  if (mode === 'replace' || !translatableContent.value) {
    syncPersistedContent(nextContent)
    return
  }

  // preserve-local: keep the in-flight draft content, but adopt the committed
  // identity/version metadata (a peer's save may have just created the row).
  const cloned = cloneContent(nextContent)
  cloned.content = getLocalizedDraftContent(cloned)
  persistedContent.value = cloned
  translatableContent.value = {
    ...translatableContent.value,
    ...cloneContent(cloned),
    content: translatableContent.value.content,
  }
}

const {
  broadcastPersistedContent,
  collaborators,
  discardOwnDrafts,
  getCollaboratorsForField,
  getDraftOwners,
  remoteDraftCollaborators,
  queueFieldUpdate,
  updateFieldFocus,
} = useContentLiveCollaboration(spaceId, collaborationContentKey, {
  content: translatableContent,
  hasLocalUnsavedChanges: () => isDirty.value,
  syncPersistedContent: syncRemotePersistedContent,
  fieldValueAdapter: {
    get: (source, field) =>
      isLocalizedFieldMeta(field.meta)
        ? getLocalizedFieldValue(
            (source.content || {}) as Record<string, unknown>,
            field.meta.path,
            field.meta.blockStamps
          )
        : undefined,
    apply: (field, value) => {
      if (!translatableContent.value || !isLocalizedFieldMeta(field.meta)) return

      if (
        !translatableContent.value.content ||
        typeof translatableContent.value.content !== 'object'
      ) {
        translatableContent.value.content = {}
      }

      applyLocalizedFieldValue(
        translatableContent.value.content as Record<string, unknown>,
        field.meta.path,
        value,
        field.meta.blockStamps
      )
    },
  },
})

const handleLocalizedFieldUpdate = (payload: LocalizedFieldUpdatePayload) => {
  if (!collaborationContentKey.value) return

  queueFieldUpdate({
    itemId: collaborationContentKey.value,
    field: payload.key,
    previousValue: payload.previousValue,
    value: payload.value,
    debounceMs: payload.debounceMs,
    meta: { path: payload.path, blockStamps: payload.blockStamps },
  })
}

const handleLocalizedFieldFocus = (payload: LocalizedFieldFocusPayload) => {
  if (!collaborationContentKey.value) return

  updateFieldFocus({
    itemId: collaborationContentKey.value,
    field: payload.key,
    focused: payload.focused,
  })
}

const getLocalizedFieldCollaborators = (key: string) =>
  collaborationContentKey.value ? getCollaboratorsForField(collaborationContentKey.value, key) : []

const getLocalizedFieldDraftOwners = (key: string) =>
  collaborationContentKey.value ? getDraftOwners(collaborationContentKey.value, key) : []

watch(
  showPreview,
  (enabled) => {
    if (!enabled && viewMode.value === 'preview') {
      viewMode.value = 'localize'
    }
  },
  { immediate: true }
)

// Edit-driven preview pushes (matching the content editor): the iframe loads
// the persisted draft itself, so only in-session changes — local edits, remote
// collaboration, AI translation — are streamed. Not bound via :content, which
// would also push on connect and replace the site's delivery-resolved content
// (assets, links) with raw editor values before any edit happened.
watch(previewItemPayload, (item) => {
  previewRef.value?.updateItem(item)
})

provide(
  'commitPersistedContent',
  (nextContent: ContentResource, action: ContentCommitAction = 'save') => {
    syncPersistedContent(nextContent)
    // The slug is persisted now; further name edits must not move it.
    slugMode.value = 'manual'
    clearServerErrors()
    resetValidationState()
    broadcastPersistedContent(nextContent, action)
  }
)
provide('prepareMutationPayload', (payload: ContentResource): ContentResource => {
  if (slugMode.value !== 'auto') {
    return payload
  }

  // Creating: omit the slug entirely — only the server knows the canonical
  // serials and can guarantee uniqueness among the language's siblings.
  if (!payload.id) {
    const next = { ...payload } as Partial<ContentResource>
    delete next.slug
    return next as ContentResource
  }

  // Updating after a reset: send the previewed automatic value.
  return automaticSlug.value ? { ...payload, slug: automaticSlug.value } : payload
})
provide('resetDirtyState', () => {
  if (translatableContent.value) {
    syncPersistedContent(translatableContent.value)
  }
  resetValidationState()
})
provide('content', previewContentRef)

useSeoMeta({
  title: computed(
    () => canonicalContent.value?.name || t('labels.contents.localization.pageTitle')
  ),
})
</script>

<template>
  <div class="h-full min-h-0 w-full overflow-hidden">
    <ResizablePanelGroup
      id="localization-group-1"
      direction="horizontal"
      class="h-full min-h-0 w-full overflow-hidden"
    >
      <ResizablePanel
        v-if="showPreviewPane && translatableContent"
        id="localization-panel-1"
        class="min-h-0 h-full max-h-[calc(100svh-3.5rem)] overflow-hidden"
      >
        <Preview
          ref="previewRef"
          class="h-full"
          :space-id="spaceId"
          :content-id="translatableContent.id || canonicalContentId"
          :full-slug="translatableContent.full_slug"
          :updated-at="translatableContent.updated_at"
          @update-field="handlePreviewFieldUpdate"
        />
      </ResizablePanel>
      <ResizableHandle
        v-if="showPreviewPane && translatableContent"
        id="content-handle-1"
      />
      <ResizablePanel
        id="localization-panel-2"
        ref="editorPanel"
        class="flex min-h-0 h-full max-h-[calc(100svh-3.5rem)] overflow-hidden bg-background"
      >
        <div
          v-if="isLoading"
          class="flex h-full w-full items-center justify-center p-3"
        >
          <div :class="['w-full text-center', showPreviewPane ? '' : 'mx-auto max-w-4xl']">
            <Spinner class="mx-auto mb-4 size-8 text-text-muted" />
            <p class="text-muted">Loading content...</p>
          </div>
        </div>
        <ScrollArea
          v-else
          class="h-full w-full"
        >
          <div class="w-full p-3">
            <div
              v-if="block && canonicalContent && translatableContent"
              :class="['grid w-full gap-6', showPreviewPane ? '' : 'mx-auto max-w-4xl']"
            >
              <div
                class="flex flex-wrap items-start justify-between gap-4 rounded-lg bg-surface p-3"
              >
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
                      <div class="flex items-center gap-2">
                        <Input
                          id="translated-slug"
                          :model-value="displayedSlug"
                          class="font-mono"
                          aria-label="Translated content slug"
                          @update:model-value="setTranslationSlug(String($event))"
                        />
                        <Button
                          v-if="slugMode === 'manual'"
                          type="button"
                          size="sm"
                          variant="ghost"
                          :title="t('labels.contents.create.slugReset')"
                          @click="resetSlugToAutomatic"
                        >
                          <Icon name="lucide:rotate-ccw" />
                          <span class="sr-only">
                            {{ t('labels.contents.create.slugReset') }}
                          </span>
                        </Button>
                      </div>
                      <p class="flex items-center gap-1.5 text-xs text-muted">
                        <Icon
                          v-if="slugMode === 'auto' && isSlugPreviewFetching"
                          name="lucide:loader-circle"
                          class="animate-spin"
                        />
                        {{
                          slugMode === 'auto'
                            ? hasSlugPattern
                              ? t('labels.contents.create.slugFromPattern', {
                                  pattern: serialPreview?.slug_pattern,
                                })
                              : t('labels.contents.create.slugAuto')
                            : t('labels.contents.create.slugManual')
                        }}
                      </p>
                    </div>
                  </div>
                </div>
              </div>
              <FlattenedLocalization
                ref="localizationRef"
                :original-content="sourceContentPayload"
                :translation-content="translationContentPayload"
                :source-name="nearestSourceContent?.name"
                :translation-name="translatableContent.name"
                @update:translation-name="handleTranslatedName"
                :block-schema="block.schema"
                :space-id="spaceId"
                :get-block-schema="getBlockSchemaFn"
                :target-language="resolvedLanguage"
                :get-field-collaborators="getLocalizedFieldCollaborators"
                :get-field-draft-owners="getLocalizedFieldDraftOwners"
                :root-item-ids="previewRootItemIds"
                @update:translation-content="updateTranslationContent"
                @field-update="handleLocalizedFieldUpdate"
                @field-focus="handleLocalizedFieldFocus"
              />
            </div>
          </div>
        </ScrollArea>
      </ResizablePanel>
    </ResizablePanelGroup>
  </div>
  <ContentPageHeaderPortals
    :content="translatableContent"
    :space-id="spaceId"
    :is-dirty="isDirty"
    :show-preview-toggle="!isPreviewDisabled"
    :present-users="collaborators"
    :remote-draft-users="remoteDraftCollaborators"
  />
</template>
