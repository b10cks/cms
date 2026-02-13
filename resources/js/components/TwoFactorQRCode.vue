<script setup lang="ts">
import { toDataURL } from 'qrcode'

const props = defineProps<{
  value: string
  size?: number
}>()

const src = ref<string | null>(null)

watch(
  () => props.value,
  async (newValue) => {
    try {
      src.value = await toDataURL(newValue, {
        type: 'svg',
        margin: 2,
        width: props.size || 400,
        errorCorrectionLevel: 'Q',
      })
    } catch (e) {
      src.value = null
    }
  },
  { immediate: true }
)
</script>

<template>
  <img
    :src="src"
    class="overflow-clip rounded-sm"
  />
</template>
