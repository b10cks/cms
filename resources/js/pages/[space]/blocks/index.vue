<script setup lang="ts">
import { useRouteQuery } from '@vueuse/router'
import BlockMenu from '~/components/BlockMenu.vue'
import BlockTable from '~/components/BlockTable.vue'
import BlockTagsTable from '~/components/BlockTagsTable.vue'

const route = useRoute()
const { t } = useI18n()
const spaceId = computed(() => route.params.space as string)

const mode = useRouteQuery('mode', 'list') as Ref<'list' | 'tags'>
const folder = useRouteQuery('folder', undefined) as Ref<string | undefined>

useSeoMeta({
  title: computed(() => t('labels.blocks.title')),
})

const component = computed(() => {
  if (mode.value === 'list') {
    return BlockTable
  } else if (mode.value === 'tags') {
    return BlockTagsTable
  }
  return null
})
</script>

<template>
  <BlockMenu
    v-model:mode="mode"
    v-model:selected-folder="folder"
    :space-id="spaceId"
  />
  <div class="flex grow bg-background">
    <component
      :is="component"
      :space-id="spaceId"
      :mode="mode"
      :folder="folder"
      class="mx-auto flex w-full max-w-7xl grow flex-col gap-6"
    />
  </div>
</template>
