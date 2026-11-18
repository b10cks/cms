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
import { phonePreviewOpen, useIsPhone } from '~/lib/viewport'
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

const isPhone = useIsPhone()
const previewOn = computed(() =>
  isPhone.value ? phonePreviewOpen.value : settings.value.content.showPreview
)

const togglePreview = () => {
  if (isPhone.value) {
    phonePreviewOpen.value = !phonePreviewOpen.value
    return
  }

  settings.value.content.showPreview = !settings.value.content.showPreview
}
</script>

<template>
  <div class="flex items-center gap-3 phone:gap-1">
    <div
      v-if="content"
      class="phone:min-w-0"
    >
      <h1 class="-mb-1 flex items-center gap-2 phone:mb-0">
        <span class="text-panel font-semibold text-primary phone:truncate phone:text-base">{{ content?.name }}</span>
        <ContentStateBadge
          :status="status"
          :size="isPhone ? 'indicator' : undefined"
        />
      </h1>
      <div class="flex items-center phone:hidden">
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
      v-if="showPreviewToggle"
      :tooltip="
        previewOn
          ? $t('actions.content.modes.toField')
          : $t('actions.content.modes.toVisual')
      "
      as-child
    >
      <Button
        variant="ghost"
        size="toolbar"
        :aria-label="
          previewOn
            ? $t('actions.content.modes.toField')
            : $t('actions.content.modes.toVisual')
        "
        @click="togglePreview()"
      >
        <Icon :name="previewOn ? 'lucide:eye' : 'lucide:eye-off'" />
      </Button>
    </SimpleTooltip>
  </div>
</template>
