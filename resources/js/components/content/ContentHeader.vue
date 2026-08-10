<script setup lang="ts">
import { RouterLink } from 'vue-router'

import ContentStateBadge from '~/components/content/ContentStateBadge.vue'
import Icon from '~/components/Icon.vue'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
} from '~/components/ui/breadcrumb'
import { Button } from '~/components/ui/button'
import { resolveContentRouteName, withContentLanguageQuery } from '~/lib/content-i18n'
import type { ContentResource } from '~/types/contents'

import { SimpleTooltip } from '../ui/tooltip'

const props = defineProps<{
  content: ContentResource
  showPreviewToggle?: boolean
}>()

const route = useRoute()
const spaceId = inject<string>('spaceId', '')

const { useContentMenuQuery, buildBreadcrumbs } = useContentMenu(spaceId)
const { data: contentMenu } = useContentMenuQuery()
const { settings } = useSpaceSettings(spaceId)
const { useSpaceQuery } = useSpaces()
const { data: space } = useSpaceQuery(spaceId)
const defaultLanguage = computed(
  () =>
    space.value?.settings.default_language ||
    props.content.language_versions?.find((version) => version.is_default)?.language_iso ||
    props.content.language_iso
)
const breadcrumbs = computed(
  () => props.content && buildBreadcrumbs(contentMenu.value, props.content.i18n_canonical_id)
)
const breadcrumbRoute = (contentId: string) => ({
  name: resolveContentRouteName(
    route.name as string | undefined,
    props.content.effective_i18n_mode,
    props.content.language_iso,
    defaultLanguage.value
  ),
  params: { space: spaceId, contentId },
  query: withContentLanguageQuery(route.query, props.content.language_iso, defaultLanguage.value),
})

watch(breadcrumbs, (crumbs) => {
  const path = crumbs.map(({ id }) => id)
  settings.value.content.expanded = [...settings.value.content.expanded, ...path]
})

// `published_at` is live-since, not up-to-date: an entry can be live and still
// carry a newer draft, which is its own state rather than either extreme.
const status = computed<'draft' | 'published' | 'changed'>(() => {
  if (!props.content?.published_at) return 'draft'

  return props.content.current_version_id !== props.content.published_version_id
    ? 'changed'
    : 'published'
})

const togglePreview = () => {
  settings.value.content.showPreview = !settings.value.content.showPreview
}
</script>

<template>
  <div class="flex items-center gap-3">
    <div v-if="content">
      <h1 class="-mb-1 flex items-center gap-2">
        <span class="text-lg font-semibold text-primary">{{ content?.name }}</span>
        <ContentStateBadge :status="status" />
      </h1>
      <div class="flex items-center">
        <Breadcrumb>
          <BreadcrumbList>
            <template
              v-for="{ id, name } in breadcrumbs"
              :key="id"
            >
              <li
                role="presentation"
                aria-hidden="true"
              >
                /
              </li>
              <BreadcrumbItem>
                <BreadcrumbLink
                  :as="RouterLink"
                  :to="breadcrumbRoute(id)"
                >
                  {{ name }}
                </BreadcrumbLink>
              </BreadcrumbItem>
            </template>
          </BreadcrumbList>
        </Breadcrumb>
      </div>
    </div>

    <SimpleTooltip
      :tooltip="
        settings.content.showPreview
          ? $t('actions.content.modes.toField')
          : $t('actions.content.modes.toVisual')
      "
    >
      <Button
        v-if="showPreviewToggle"
        variant="ghost"
        size="toolbar"
        :aria-label="
          settings.content.showPreview
            ? $t('actions.content.modes.toField')
            : $t('actions.content.modes.toVisual')
        "
        @click="togglePreview()"
      >
        <Icon :name="settings.content.showPreview ? 'lucide:eye' : 'lucide:eye-off'" />
      </Button>
    </SimpleTooltip>
  </div>
</template>
