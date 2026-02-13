<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { TreeItem, TreeRoot } from 'reka-ui'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import IconGrid from '~/components/ui/IconGrid.vue'
import { Input } from '~/components/ui/input'

const route = useRoute()
const { useAssetTagsQuery } = useAssetTags(route.params.spaceId as string)
const { data: tags } = useAssetTagsQuery({
  per_page: 500,
  sort: '+name',
})

const selectedTagId = defineModel<string>()

const showNewTag = ref(false)
const newTag = ref<UpsertAssetTagPayload>({
  name: '',
  color: null,
  icon: 'tag',
})

const { useCreateAssetTagMutation } = useAssetTags(route.params.spaceId as string)
const { mutate: createTag } = useCreateAssetTagMutation()

function create() {
  if (newTag.value.name) {
    createTag(newTag.value)
    newTag.value = { name: '', color: null, icon: 'tag' }
    showNewTag.value = false
  }
}
</script>

<template>
  <TreeRoot
    v-slot="{ flattenItems }"
    class="w-full list-none select-none"
    :items="tags"
    :get-key="(item) => item?.id"
    :get-children="() => undefined"
  >
    <div class="flex">
      <h2 class="px-2 pt-1 pb-3 text-sm font-semibold text-primary">
        {{ $t('labels.assetTags.title') }}
      </h2>
      <Button
        class="ml-auto"
        variant="ghost"
        size="xs"
        @click="showNewTag = !showNewTag"
      >
        <Icon name="lucide:plus" />
      </Button>
    </div>
    <div
      v-if="showNewTag"
      class="flex gap-2"
    >
      <IconGrid v-model="newTag.icon" />
      <Input v-model="newTag.name" />
      <Button @click="create">
        <Icon name="lucide:plus" />
      </Button>
    </div>
    <TreeItem
      v-for="item in flattenItems"
      :key="item._id"
      :style="{ 'padding-left': `${item.level - 0.5}rem` }"
      v-bind="item.bind"
      :class="[
        'my-0.5 flex items-center gap-2 rounded-md px-2 py-2 outline-none',
        'transition-colors duration-200 hover:bg-input',
        'cursor-pointer font-semibold',
        item.value.id === selectedTagId ? 'bg-input text-primary' : '',
      ]"
      @select="selectedTagId = item.value.id"
    >
      <Icon
        v-if="item.value.icon"
        :name="`lucide:${item.value.icon}`"
        :style="{ color: item.value.color }"
      />
      <div class="truncate">
        {{ item.value.name }}
      </div>
      <Badge>{{ item.value.assets_count || 0 }}</Badge>
    </TreeItem>
  </TreeRoot>
</template>
