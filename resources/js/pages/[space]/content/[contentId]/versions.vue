<script setup lang="ts">
import { useRouteQuery } from '@vueuse/router'

import ContentHeader from '~/components/content/ContentHeader.vue'
import LanguageSwitcher from '~/components/content/LanguageSwitcher.vue'
import ContentVersionHistory from '~/components/content/VersionHistory.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  getContentDefaultLanguage,
  resolveContentLanguage,
  resolveContentRouteName,
  withContentLanguageQuery,
} from '~/lib/content-i18n'

const route = useRoute()
const router = useRouter()
const spaceId = computed<string>(() => route.params.space as string)
const canonicalContentId = computed<string>(() => route.params.contentId as string)


const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId.value)
const { useContentQuery } = useContent(spaceId)
const { data: routeContent } = useContentQuery(canonicalContentId)


const defaultLanguage = computed(() =>
  getContentDefaultLanguage(
    space.value?.settings.default_language,
    routeContent.value?.language_versions,
    routeContent.value?.language_iso
  )
)
const activeLanguage = useRouteQuery<string | undefined>('lang')
const canonicalContent = computed(() =>
  routeContent.value
    ? routeContent.value.i18n_parent_id === null
      ? routeContent.value
      : {
          ...routeContent.value,
          id: routeContent.value.i18n_canonical_id,
        }
    : null
)
const resolvedActiveLanguage = computed(() =>
  resolveContentLanguage(
    activeLanguage.value,
    defaultLanguage.value,
    canonicalContent.value?.language_versions,
    routeContent.value?.language_iso
  )
)
const activeLanguageVersion = computed(
  () =>
    canonicalContent.value?.language_versions?.find(
      (version) => version.language_iso === resolvedActiveLanguage.value
    ) ||
    canonicalContent.value?.language_versions?.find(
      (version) => version.language_iso === defaultLanguage.value
    ) ||
    canonicalContent.value?.language_versions?.find((version) => version.is_default) ||
    canonicalContent.value?.language_versions?.[0] ||
    null
)
const activeContentId = computed(() => activeLanguageVersion.value?.content_id || null)
const { data: activeContent, isLoading: isLoadingContent } = useContentQuery(activeContentId)


const buildLanguageQuery = (language: string) =>
  withContentLanguageQuery(
    route.query as Record<string, unknown>,
    resolveContentLanguage(
      language,
      defaultLanguage.value,
      canonicalContent.value?.language_versions,
      routeContent.value?.language_iso
    ),
    defaultLanguage.value
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
        query: buildLanguageQuery(currentLanguage) as typeof route.query,
        hash: '',
      })
    }
  },
  { immediate: true }
)


watch(
  [canonicalContent, resolvedActiveLanguage, defaultLanguage],
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

    if (nextLanguage !== resolvedActiveLanguage.value) {
      activeLanguage.value = nextLanguage === currentDefaultLanguage ? undefined : nextLanguage
      return
    }

    if (activeLanguage.value && nextLanguage === currentDefaultLanguage) {
      activeLanguage.value = undefined
      return
    }

    const nextRouteName = resolveContentRouteName(
      route.name as string | undefined,
      currentCanonical.effective_i18n_mode,
      nextLanguage,
      currentDefaultLanguage
    )

    const nextQuery = buildLanguageQuery(nextLanguage)
    const currentQuery = withContentLanguageQuery(
      route.query as Record<string, unknown>,
      activeLanguage.value,
      currentDefaultLanguage
    )

    if (
      route.name !== nextRouteName ||
      JSON.stringify(currentQuery) !== JSON.stringify(nextQuery)
    ) {
      await router.replace({
        name: nextRouteName,
        params: {
          ...route.params,
          contentId: currentCanonical.id,
        },
        query: nextQuery as typeof route.query,
        hash: route.hash,
      })
    }
  },
  { immediate: true }
)


const navigateToContent = () => {
  if (!canonicalContent.value) {
    return
  }


  const targetLanguage = resolveContentLanguage(
    resolvedActiveLanguage.value,
    defaultLanguage.value,
    canonicalContent.value?.language_versions,
    routeContent.value?.language_iso
  )


  router.push({
    name: resolveContentRouteName(
      'space-content-contentId',
      canonicalContent.value.effective_i18n_mode,
      targetLanguage,
      defaultLanguage.value
    ),
    params: {
      space: spaceId.value,
      contentId: canonicalContent.value.id,
    },
    query: buildLanguageQuery(targetLanguage) as typeof route.query,
  })
}


useSeoMeta({
  title: computed(() =>
    activeContent.value?.name ? `History: ${activeContent.value.name}` : 'Version History'
  ),
})
</script>

<template>
  <div class="flex h-full w-full flex-col bg-background">
    <div
      v-if="isLoadingContent"
      class="flex h-20 items-center justify-center"
    >
      <Icon
        name="lucide:loader"
        class="mr-2 h-6 w-6 animate-spin"
      />
      <span>Loading content details...</span>
    </div>

    <div
      v-else-if="activeContent"
      class="flex h-full flex-col"
    >
      <div class="flex-1 overflow-hidden">
        <ContentVersionHistory
          :space-id="spaceId"
          :content="activeContent"
        />
      </div>
    </div>

    <div
      v-else
      class="flex h-full flex-col items-center justify-center p-8"
    >
      <Icon
        name="lucide:file-question"
        class="mb-4 h-16 w-16 text-muted"
      />
      <h2 class="mb-2 text-xl font-bold">No versions yet</h2>
      <p class="mb-6 text-muted">The selected language does not have a saved content row yet.</p>
      <Button @click="navigateToContent"> Open editor </Button>
    </div>
  </div>

  <Teleport
    defer
    to="#appHeader"
  >
    <ContentHeader
      v-if="activeContent"
      :content="activeContent"
    />
  </Teleport>

  <Teleport
    defer
    to="#appHeaderActions"
  >
    <div
      v-if="canonicalContent"
      class="flex items-center gap-3"
    >
      <LanguageSwitcher :content="canonicalContent" />
      <Button @click="navigateToContent">
        <Icon name="lucide:arrow-left" />
        Back to Editor
      </Button>
    </div>
  </Teleport>
</template>
