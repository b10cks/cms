<script setup lang="ts">
import { RouterLink } from 'vue-router'
import Icon from '~/components/Icon.vue'
import type { BadgeVariants } from '~/components/ui/badge'
import { Badge } from '~/components/ui/badge'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
} from '~/components/ui/breadcrumb'
import { Button } from '~/components/ui/button'
import type { ContentResource } from '~/types/contents'
import { SimpleTooltip } from '../ui/tooltip'

const props = defineProps<{
  content: ContentResource
  showPreviewToggle?: boolean
}>()

const spaceId = inject('spaceId')

const { useContentMenuQuery, buildBreadcrumbs } = useContentMenu(spaceId)
const { data: contentMenu } = useContentMenuQuery()
const { settings } = useSpaceSettings(spaceId)
const breadcrumbs = computed(
  () => props.content && buildBreadcrumbs(contentMenu.value, props.content.id)
)

watch(breadcrumbs, (crumbs) => {
  const path = crumbs.map(({ id }) => id)
  settings.value.content.expanded = [...settings.value.content.expanded, ...path]
})

const status = computed<{ color: string; label: string }>(() => {
  if (props.content?.published_at) {
    return {
      color: 'success',
      label: 'published',
    }
  }

  return {
    color: 'default',
    label: 'draft',
  }
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
        <Badge
          size="sm"
          :variant="status.color as BadgeVariants['variant']"
        >
          {{ $t(`labels.contents.status.${status.label}`) }}
        </Badge>
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
                  :to="{
                    name: 'space-content-contentId',
                    params: { space: spaceId, contentId: id },
                  }"
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
        @click="togglePreview()"
      >
        <Icon :name="settings.content.showPreview ? 'lucide:eye' : 'lucide:eye-off'" />
      </Button>
    </SimpleTooltip>
  </div>
</template>
