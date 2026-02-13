<script setup lang="ts">
import ContentsIcon from '~/assets/images/contents.svg?component'

const route = useRoute()
const { t } = useI18n()

const { useContentMenuQuery, getRootItems } = useContentMenu(route.params.space as string)
const { data } = useContentMenuQuery()

useSeoMeta({
  title: computed(() => t('labels.contents.title')),
})

const rootItems = computed(() => getRootItems(data.value) || [])
</script>

<template>
  <div class="flex-1 overflow-y-auto bg-background p-6">
    <div
      v-if="rootItems.length === 0"
      class="py-12 text-center"
    >
      <ContentsIcon class="mx-auto mb-6 w-32 text-muted" />
      <h3 class="mb-2 text-xl font-bold">No Content Selected</h3>
      <p class="mb-6 text-muted">Start by creating your first content page</p>
    </div>
    <div
      v-else
      class="py-12 text-center"
    >
      <ContentsIcon class="mx-auto mb-6 w-32 text-muted" />
      <h3 class="mb-2 text-xl font-bold">Content Manager</h3>
      <p class="mb-6 text-muted">
        Select an item from the content menu to edit or create new content.
      </p>
    </div>
  </div>
</template>
