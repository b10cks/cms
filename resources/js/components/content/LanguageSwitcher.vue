<script setup lang="ts">
import { useRouteQuery } from '@vueuse/router'

import ContentStateBadge from '~/components/content/ContentStateBadge.vue'
import Icon from '~/components/Icon.vue'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import {
  getContentDefaultLanguage,
  resolveContentLanguage,
  resolveContentRouteName,
  withContentLanguageQuery,
} from '~/lib/content-i18n'
import type { ContentResource } from '~/types/contents'

const route = useRoute()
const router = useRouter()


const props = defineProps<{
  content: ContentResource
}>()


const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(computed(() => route.params.space as string))


const defaultLanguage = computed(() =>
  getContentDefaultLanguage(
    space.value?.settings.default_language,
    props.content.language_versions,
    props.content.language_iso
  )
)


const languageVersions = computed(() => props.content.language_versions || [])
const activeLanguage = useRouteQuery<string | undefined>('lang')
const resolvedLanguage = computed(() =>
  resolveContentLanguage(
    activeLanguage.value,
    defaultLanguage.value,
    languageVersions.value,
    props.content.language_iso
  )
)


const currentLanguage = computed(() => {
  return (
    languageVersions.value.find((version) => version.language_iso === resolvedLanguage.value) ||
    languageVersions.value.find((version) => version.is_default) ||
    languageVersions.value[0]
  )
})


const isSwitcherDisabled = computed(() => languageVersions.value.length <= 1)


watch(
  [languageVersions, defaultLanguage, resolvedLanguage],
  ([versions, fallbackLanguage, currentLanguage]) => {
    if (versions.length === 0) {
      return
    }

    const nextLanguage = resolveContentLanguage(
      currentLanguage,
      fallbackLanguage,
      versions,
      props.content.language_iso
    )

    activeLanguage.value = nextLanguage === fallbackLanguage ? undefined : nextLanguage
  },
  { immediate: true }
)


const handleLanguageChange = async (value: string | number | null) => {
  if (isSwitcherDisabled.value || typeof value !== 'string' || value === '') {
    return
  }


  const languageIso = value
  const routeName = resolveContentRouteName(
    route.name as string | undefined,
    props.content.effective_i18n_mode,
    languageIso,
    defaultLanguage.value
  )


  await router.push({
    name: routeName,
    params: {
      ...route.params,
      contentId: props.content.i18n_canonical_id,
    },
    query: withContentLanguageQuery(route.query, languageIso, defaultLanguage.value),
    hash: '',
  })
}
</script>

<template>
  <Select
    v-if="!isSwitcherDisabled"
    :model-value="currentLanguage?.language_iso"
    :disabled="isSwitcherDisabled"
    @update:model-value="(value) => handleLanguageChange(value == null ? '' : String(value))"
  >
    <SelectTrigger
      class="w-56"
      :disabled="isSwitcherDisabled"
    >
      <SelectValue :placeholder="$t('labels.contents.localization.language')">
        <div
          v-if="currentLanguage"
          class="flex min-w-0 items-center justify-between gap-2"
        >
          <Icon name="lucide:languages" />
          <span class="truncate">{{ currentLanguage.label }}</span>
        </div>
      </SelectValue>
    </SelectTrigger>
    <SelectContent>
      <SelectItem
        v-for="version in languageVersions"
        :key="version.language_iso"
        :value="version.language_iso"
      >
        <div class="flex min-w-0 items-center justify-between gap-2">
          <ContentStateBadge
            :status="version.status"
            size="indicator"
          />
          <span class="truncate font-medium">{{ version.label }}</span>
          <span class="font-mono text-2xs opacity-50">[{{ version.language_iso }}]</span>
        </div>
      </SelectItem>
    </SelectContent>
  </Select>
</template>
