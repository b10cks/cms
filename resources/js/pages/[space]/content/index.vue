<script setup lang="ts">
import { RouterLink } from 'vue-router'

import ContentsIcon from '~/assets/images/contents.svg?component'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { getActionAccessRequirement } from '~/lib/access-control'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: spaceId.value })))
const canAccessCanvas = computed(() =>
  access.canAccessRoute(getActionAccessRequirement('space.canvas'))
)
const canViewContent = computed(() => access.hasAbility('content.view'))


const { useContentMenuQuery, getRootItems } = useContentMenu(spaceId)
const { data } = useContentMenuQuery(canViewContent)


useSeoMeta({
  title: computed(() => t('labels.contents.title')),
})


const rootItems = computed(() => getRootItems(data.value) || [])
</script>

<template>
  <div class="flex h-full grow bg-background p-6">
    <div class="grow flex justify-center flex-col text-center">
      <ContentsIcon class="mx-auto mb-6 w-32 text-muted" />
      <template v-if="rootItems.length === 0">
        <h3 class="mb-2 text-xl font-bold">No Content Selected</h3>
        <p class="mb-6 text-muted">Start by creating your first content page</p>
      </template>
      <template v-else>
        <h3 class="mb-2 text-xl font-bold">Content Manager</h3>
        <p class="mb-6 text-muted">
          Select an item from the content menu to edit or create new content.
        </p>
      </template>
      <div>
        <Button
          v-if="canAccessCanvas"
          :as="RouterLink"
          :to="{ name: 'space-canvas', params: { space: spaceId } }"
          variant="outline"
        >
          <Icon name="lucide:network" />
          {{ $t('labels.contents.canvas.entry') }}
        </Button>
      </div>
    </div>
  </div>
</template>
