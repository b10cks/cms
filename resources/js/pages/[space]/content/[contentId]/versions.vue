<script setup lang="ts">
import { useRouteQuery } from '@vueuse/router'

import LanguageSwitcher from '~/components/content/LanguageSwitcher.vue'
import Icon from '~/components/Icon.vue'

import ContentHeader from '~/components/content/ContentHeader.vue'
import ContentVersionHistory from '~/components/content/VersionHistory.vue'
import { Button } from '~/components/ui/button'
import { resolveContentRouteName } from '~/lib/content-i18n'

const route = useRoute()
const router = useRouter()
const spaceId = computed<string>(() => route.params.space as string)
const canonicalContentId = computed<string>(() => route.params.contentId as string)

const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId.value)
const { useContentQuery } = useContent(spaceId)
const { data: routeContent } = useContentQuery(canonicalContentId)

const defaultLanguage = computed(
  () =>
    space.value?.settings.default_language ||
    routeContent.value?.language_versions?.find((version) => version.is_default)?.language_iso ||
    'en'
)
const activeLanguage = useRouteQuery('lang', defaultLanguage.value)
const canonicalContent = computed(() => (routeContent.value?.i18n_parent_id === null ? routeContent.value : null))
const activeLanguageVersion = computed(
  () =>
    canonicalContent.value?.language_versions?.find((version) => version.language_iso === activeLanguage.value) ||
    canonicalContent.value?.language_versions?.find((version) => version.is_default) ||
    null
)
const activeContentId = computed(() => activeLanguageVersion.value?.content_id || null)
const { data: activeContent, isLoading: isLoadingContent } = useContentQuery(activeContentId)

watch(
  [routeContent, defaultLanguage],
  async ([currentContent, currentDefaultLanguage]) => {
    if (!currentContent) {
      return
    }

    if (!activeLanguage.value) {
      activeLanguage.value = currentContent.language_iso || currentDefaultLanguage
    }

    if (
      currentContent.i18n_parent_id &&
      currentContent.i18n_canonical_id !== canonicalContentId.value
    ) {
      await router.replace({
        name: resolveContentRouteName(
          route.name as string | undefined,
          currentContent.effective_i18n_mode,
          currentContent.language_iso,
          currentDefaultLanguage
        ),
        params: {
          ...route.params,
          contentId: currentContent.i18n_canonical_id,
        },
        query: {
          ...route.query,
          lang: currentContent.language_iso,
        },
        hash: '',
      })
    }
  },
  { immediate: true }
)

watch(
  [canonicalContent, activeLanguage, defaultLanguage],
  ([currentCanonical, currentLanguage, currentDefaultLanguage]) => {
    if (!currentCanonical) {
      return
    }

    const availableLanguages = currentCanonical.language_versions.map((version) => version.language_iso)
    const nextLanguage = availableLanguages.includes(currentLanguage || '')
      ? (currentLanguage as string)
      : currentDefaultLanguage

    if (nextLanguage !== activeLanguage.value) {
      activeLanguage.value = nextLanguage
    }
  },
  { immediate: true }
)

const navigateToContent = () => {
  if (!canonicalContent.value) {
    return
  }

  router.push({
    name: resolveContentRouteName(
      'space-content-contentId',
      canonicalContent.value.effective_i18n_mode,
      activeLanguage.value || defaultLanguage.value,
      defaultLanguage.value
    ),
    params: {
      space: spaceId.value,
      contentId: canonicalContent.value.id,
    },
    query: {
      ...route.query,
      lang: activeLanguage.value || defaultLanguage.value,
    },
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
      <p class="mb-6 text-muted">
        The selected language does not have a saved content row yet.
      </p>
      <Button @click="navigateToContent"> Open editor </Button>
    </div>
  </div>

  <Teleport to="#appHeader">
    <ContentHeader
      v-if="activeContent"
      :content="activeContent"
    />
  </Teleport>

  <Teleport to="#appHeaderActions">
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
