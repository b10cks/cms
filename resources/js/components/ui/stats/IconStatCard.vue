<script setup lang="ts">
import Icon from '~/components/Icon.vue'

defineProps<{
  title: string
  icon: string
  description?: string
}>()
</script>

<template>
  <div class="rounded-xl bg-input">
    <div class="flex flex-row items-center justify-between space-y-0 gap-y-1.5 p-3 pb-2">
      <h3 class="text-sm font-medium tracking-tight">{{ title }}</h3>
      <Icon
        :name="icon"
        size="1.25rem"
        class="text-muted"
      />
    </div>
    <div class="p-3 pt-0">
      <div class="text-2xl font-bold text-primary">
        <slot />
      </div>
      <p
        v-if="description"
        class="text-xs"
      >
        {{ description }}
      </p>
    </div>
  </div>
</template>
e
