<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import NuxtImg from '~/components/NuxtImg.vue'
import { assetFacts, assetFileName } from '~/lib/assetFacts'

/** One asset in an asset field: thumbnail or type icon, file name, and type, pixels and size. */
const props = defineProps<{ asset: AssetValue }>()

const { getFileIcon, getFileType } = useFileUtils()
const { formatFileSize } = useFormat()

const name = computed(() => assetFileName(props.asset))
const facts = computed(() => assetFacts(props.asset, formatFileSize))
const fileType = computed(() => getFileType(props.asset.mime_type))
const thumbnail = computed(() =>
  fileType.value === 'image'
    ? props.asset.full_path
    : props.asset.metadata?.thumbnails?.[0]?.full_path
)
const altText = computed(() => {
  const alt = (props.asset.data as Record<string, unknown> | undefined)?.altText
  return typeof alt === 'string' && alt ? alt : props.asset.filename
})
</script>

<template>
  <div class="flex min-w-0 grow items-center gap-3">
    <NuxtImg
      v-if="thumbnail"
      :src="thumbnail"
      :alt="altText"
      :width="88"
      :height="88"
      :modifiers="{ crop: 'fill' }"
      class="size-11 shrink-0 rounded-md object-cover"
    />
    <span
      v-else
      class="flex size-11 shrink-0 items-center justify-center rounded-md bg-chip text-input-muted"
    >
      <Icon
        :name="getFileIcon(fileType)"
        size="20"
      />
    </span>
    <div class="min-w-0 grow">
      <p
        class="truncate font-medium text-primary"
        :title="name"
      >
        {{ name }}
      </p>
      <p
        v-if="facts"
        class="truncate text-sm text-input-muted tabular-nums"
      >
        {{ facts }}
      </p>
    </div>
  </div>
</template>
