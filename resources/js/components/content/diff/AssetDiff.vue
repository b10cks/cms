<script setup lang="ts">
import Icon from '~/components/Icon.vue'

type ItemStatus = 'added' | 'removed' | 'unchanged'

interface AssetLike {
  id?: unknown
  filename?: unknown
  full_path?: unknown
}

const props = defineProps<{
  oldValue?: unknown
  newValue?: unknown
}>()

const toAssets = (value: unknown): AssetLike[] => {
  if (Array.isArray(value)) {
    return value.filter((item): item is AssetLike => typeof item === 'object' && item !== null)
  }
  return typeof value === 'object' && value !== null ? [value as AssetLike] : []
}

const assetKey = (asset: AssetLike): string => String(asset.id ?? asset.full_path ?? asset.filename ?? '')

const assetName = (asset: AssetLike): string => {
  if (typeof asset.filename === 'string' && asset.filename !== '') return asset.filename
  if (typeof asset.full_path === 'string') return asset.full_path.split('/').pop() ?? asset.full_path
  return String(asset.id ?? 'asset')
}

const items = computed((): { name: string; status: ItemStatus; key: string }[] => {
  const oldAssets = toAssets(props.oldValue)
  const newAssets = toAssets(props.newValue)
  const oldKeys = new Set(oldAssets.map(assetKey))
  const newKeys = new Set(newAssets.map(assetKey))

  return [
    ...newAssets.map((asset) => ({
      key: assetKey(asset),
      name: assetName(asset),
      status: (oldKeys.has(assetKey(asset)) ? 'unchanged' : 'added') as ItemStatus,
    })),
    ...oldAssets
      .filter((asset) => !newKeys.has(assetKey(asset)))
      .map((asset) => ({ key: assetKey(asset), name: assetName(asset), status: 'removed' as ItemStatus })),
  ]
})

const chipClasses = (status: ItemStatus): string => {
  switch (status) {
    case 'added':
      return 'border-success/30 bg-success/15 text-success'
    case 'removed':
      return 'border-destructive/30 bg-destructive/10 text-destructive line-through'
    default:
      return 'border-border text-muted-foreground'
  }
}
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
