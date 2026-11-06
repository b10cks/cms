<script setup lang="ts">
import { useRouteQuery } from '@vueuse/router'

import BlockMenu from '~/components/BlockMenu.vue'
import BlockTable from '~/components/BlockTable.vue'
import BlockTagsTable from '~/components/BlockTagsTable.vue'
import Icon from '~/components/Icon.vue'
import { Tabs, TabsList, TabsTrigger } from '~/components/ui/tabs'

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
    v-if="mode === 'list'"
    v-model:mode="mode"
    v-model:selected-folder="folder"
    :space-id="spaceId"
  />
  <div class="flex grow flex-col bg-background">
    <Tabs
      v-model="mode"
      class="content-grid mx-auto w-full max-w-7xl pt-6"
    >
      <TabsList class="w-fit justify-self-start">
        <TabsTrigger value="list">
          <Icon name="lucide:blocks" />
          {{ $t('labels.blocks.title') }}
        </TabsTrigger>
        <TabsTrigger value="tags">
          <Icon name="lucide:tag" />
          {{ $t('labels.blockTags.title') }}
        </TabsTrigger>
      </TabsList>
    </Tabs>
    <component
      :is="component"
      :space-id="spaceId"
      :mode="mode"
      :folder="folder"
      class="mx-auto flex w-full max-w-7xl grow flex-col gap-6"
    />
  </div>
</template>
