<script setup lang="ts">
import Icon from '~/components/Icon.vue'

defineProps<{
  icon: string
  color?: string
  name?: string
}>()
</script>

<template>
  <div class="flex items-center gap-1">
    <Icon
      :name="`lucide:${icon}`"
      :style="{ color }"
      class="mr-1"
    />
    <span>{{ name }}</span>
  </div>
</template>
