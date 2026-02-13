<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import HeaderActions from '~/components/content/HeaderActions.vue'
import FlattenedLocalization from '~/components/localization/FlattendeLocalization.vue'
import { Input } from '~/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import type { ContentResource } from '~/types/contents'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const spaceId = computed<string>(() => route.params.space as string)
const contentId = computed<string>(() => route.params.contentId as string)

const { useSpaceQuery } = useSpaces()
const { data: currentSpace } = useSpaceQuery(spaceId.value)
const { useContentQuery } = useContent(spaceId)
const { data: originalContent } = useContentQuery(contentId)

const availableLanguages = computed(() => currentSpace.value?.settings?.languages ?? [])

const language = computed({
  get: () => (route.hash ? route.hash.substring(1) : availableLanguages.value[0]?.code),
  set: (l) => {
    if (l) {
      router.replace({ ...route, hash: `#${l}` })
    } else {
      router.replace({ ...route, hash: '' })
    }
  },
})

useSeoMeta({
  title: computed(() => originalContent.value?.name || t('labels.contents.localization.pageTitle')),
})

const translatableId = computed(() => {
  return originalContent.value?.i18n_translations?.find(
    ({ language_iso }) => language_iso === language.value
  )?.id
})

const { data: translatableOriginalContent } = useContentQuery(translatableId)

const translatableContent = ref<ContentResource>(null)

watch(
  [translatableOriginalContent, language, originalContent],
  () => {
    if (translatableOriginalContent.value) {
      translatableContent.value = JSON.parse(JSON.stringify(translatableOriginalContent.value))
    } else if (originalContent.value) {
      translatableContent.value = {
        parent_id: originalContent.value.parent_id,
        block_id: originalContent.value.block?.id,
        language_iso: language.value,
        i18n_parent_id: originalContent.value.id,
        name: originalContent.value.name,
        slug: originalContent.value.slug,
        content: {},
        settings: originalContent.value.settings || {},
      } as ContentResource
    }
  },
  { immediate: true }
)

const { useBlocksQuery } = useBlocks(spaceId)
const { data: blocks } = useBlocksQuery({ per_page: 1000 })

const block = computed(() => {
  if (!originalContent.value || !blocks.value) return null

  const blockId = originalContent.value.block?.id
  return blocks.value.data.find((b) => b.id === blockId)
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

const isLoading = computed(
  () =>
    !originalContent.value ||
    (!!translatableId.value && !translatableOriginalContent.value) ||
    !block.value
)

const getBlockSchemaFn = (blockSlug: string) => {
  return blockSchemaCache.value.get(blockSlug)
}
</script>

<template>
  <div class="flex grow flex-col bg-background p-6">
    <div class="content-grid">
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
          v-if="block && originalContent && translatableContent"
          class="grid gap-6"
        >
          <div class="rounded-lg bg-surface p-3">
            <div class="space-y-4">
              <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                  <label
                    for="original-name"
                    class="block text-sm font-medium"
                  >
                    Content Name (Original)
                  </label>
                  <Input
                    id="original-name"
                    :model-value="originalContent.name"
                    disabled
                    aria-label="Original content name"
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
                    Content Slug (Original)
                  </label>
                  <Input
                    id="original-slug"
                    :model-value="originalContent.slug"
                    disabled
                    aria-label="Original content slug"
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
            :original-content="originalContent.content"
            :translation-content="translatableContent.content"
            :block-schema="block.schema"
            :space-id="spaceId"
            :get-block-schema="getBlockSchemaFn"
            :target-language="language"
          />
        </div>
      </div>
    </div>
  </div>

  <Teleport to="#appHeaderActions">
    <div class="flex items-center gap-3">
      <Select
        v-model="language"
        aria-label="Select language"
      >
        <SelectTrigger class="w-40">
          <SelectValue placeholder="Select language" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem
            v-for="lang in availableLanguages"
            :key="lang.code"
            :value="lang.code"
          >
            {{ lang.name }}
          </SelectItem>
        </SelectContent>
      </Select>
      <HeaderActions
        v-if="translatableContent"
        :content="translatableContent"
        :space-id="spaceId"
      />
    </div>
  </Teleport>
</template>
