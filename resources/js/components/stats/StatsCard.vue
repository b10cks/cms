<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Card, CardContent } from '~/components/ui/card'

defineProps<{
  icon?: string
  title?: string
  description?: string
  delta?: string | number | null
  showDelta?: boolean
}>()
</script>

<template>
  <Card class="relative">
    <div class="flex flex-row items-center justify-between pb-3">
      <h2>{{ title }}</h2>
      <Icon
        v-if="icon"
        :name="icon"
        :size="18"
        class="shrink-0 text-muted"
      />
    </div>
    <CardContent>
      <div class="flex items-baseline gap-2 mb-1">
        <slot name="value" />
        <span
          v-if="showDelta && delta !== null && delta !== undefined && delta !== ''"
          class="font-medium text-success flex items-center gap-0.5"
        >
          <Icon name="lucide:arrow-up" />
          <span>+{{ delta }}</span>
        </span>
      </div>
      <slot />
      <p
        v-if="description"
        class="text-sm text-muted"
      >
        {{ description }}
      </p>
    </CardContent>
  </Card>
</template>
