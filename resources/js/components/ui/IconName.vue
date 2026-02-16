<script setup lang="ts">
import Icon from '~/components/Icon.vue'

defineProps<{
  icon?: string | null
  color?: string | null
  name?: string
}>()
</script>

<template>
  <div class="flex items-center gap-1">
    <Icon
      v-if="icon"
      :name="`lucide:${icon}`"
      :style="{ color }"
      class="mr-1"
    />
    <span
      v-else
      class="size-4 mr-1"
    ></span>
    <span>{{ name }}</span>
  </div>
</template>
