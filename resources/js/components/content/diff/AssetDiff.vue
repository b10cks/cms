<script setup lang="ts">
import { chipClasses, type ChipStatus } from '~/components/content/diff/chips'
import Icon from '~/components/Icon.vue'

interface AssetLike {
  id?: unknown
  filename?: unknown
  full_path?: unknown
}

interface AssetItem {
  key: string
  name: string
}

const props = defineProps<{
  oldValue?: unknown
  newValue?: unknown
}>()

const toAssetItems = (value: unknown): AssetItem[] => {
  const assets = Array.isArray(value)
    ? value.filter((item): item is AssetLike => typeof item === 'object' && item !== null)
    : typeof value === 'object' && value !== null
      ? [value as AssetLike]
      : []

  return assets.map((asset) => ({
    key: String(asset.id ?? asset.full_path ?? asset.filename ?? ''),
    name:
      typeof asset.filename === 'string' && asset.filename !== ''
        ? asset.filename
        : typeof asset.full_path === 'string'
          ? (asset.full_path.split('/').pop() ?? asset.full_path)
          : String(asset.id ?? 'asset'),
  }))
}

const items = computed((): { name: string; status: ChipStatus; key: string }[] => {
  const oldAssets = toAssetItems(props.oldValue)
  const newAssets = toAssetItems(props.newValue)
  const oldKeys = new Set(oldAssets.map((asset) => asset.key))
  const newKeys = new Set(newAssets.map((asset) => asset.key))

  return [
    ...newAssets.map((asset) => ({
      ...asset,
      status: (oldKeys.has(asset.key) ? 'unchanged' : 'added') as ChipStatus,
    })),
    ...oldAssets
      .filter((asset) => !newKeys.has(asset.key))
      .map((asset) => ({ ...asset, status: 'removed' as ChipStatus })),
  ]
})
</script>

<template>
  <div class="flex flex-wrap gap-1.5">
    <span
      v-for="(item, index) in items"
      :key="`${item.status}-${item.key}-${index}`"
      class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs"
      :class="chipClasses(item.status)"
    >
      <Icon
        name="lucide:file"
        class="h-3 w-3 shrink-0"
      />
      {{ item.name }}
    </span>
  </div>
</template>
