<script setup lang="ts">
import AssetCollectionTree from '~/components/assets/AssetCollectionTree.vue'
import AssetFolderTree from '~/components/assets/AssetFolderTree.vue'
import AssetTagTree from '~/components/assets/AssetTagTree.vue'

/** Folders, tags and collections: the left column on wide screens, a sheet on narrow ones. */
defineProps<{
  spaceId: string
  showCollections: boolean
}>()

const folder = defineModel<string | null>('folder', { required: true })
const tag = defineModel<string | null>('tag', { required: true })
const collection = defineModel<string | null>('collection', { required: true })

const emit = defineEmits<{ 'select-all': [] }>()
</script>

<template>
  <div class="flex min-w-0! flex-col">
    <AssetFolderTree
      v-model="folder"
      :space-id="spaceId"
      :has-active-tag="Boolean(tag) || Boolean(collection)"
      @select-all="((tag = null), (collection = null), emit('select-all'))"
    />
    <AssetTagTree
      v-model="tag"
      :space-id="spaceId"
      class="mt-4"
    />
    <AssetCollectionTree
      v-if="showCollections"
      v-model="collection"
      :space-id="spaceId"
      class="mt-4"
    />
  </div>
</template>
