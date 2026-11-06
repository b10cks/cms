<script setup lang="ts">
import Icon from '~/components/Icon.vue'

/** A frozen asset a planned folder or tag names as its example: thumbnail and file name. */
const props = defineProps<{
  spaceId: string
  assetId: string
}>()

const { useAssetQuery } = useAssets(props.spaceId)
const { data: asset } = useAssetQuery(computed(() => props.assetId))
const isImage = computed(() => !!asset.value?.mime_type.startsWith('image/'))
</script>

<template>
  <figure
    class="flex w-20 shrink-0 flex-col gap-1"
    :title="asset?.filename ?? assetId"
  >
    <div class="flex aspect-square items-center justify-center overflow-hidden rounded-md bg-surface">
      <img
        v-if="asset && isImage"
        :src="asset.full_path"
        :alt="asset.filename"
        loading="lazy"
        class="size-full object-cover"
      >
      <Icon
        v-else
        name="lucide:file"
        class="text-muted"
      />
    </div>
    <figcaption class="truncate text-sm text-muted">{{ asset?.filename ?? '' }}</figcaption>
  </figure>
</template>
