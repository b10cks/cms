<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Card, CardContent } from '~/components/ui/card'

defineProps<{
  icon?: string
  title?: string
  description?: string
}>()
</script>

<template>
  <Card class="relative">
    <div class="flex flex-row items-center justify-between pb-2">
      <h2>{{ title }}</h2>
      <Icon
        v-if="icon"
        :name="icon"
        :size="18"
        class="shrink-0 text-muted"
      />
    </div>
    <CardContent>
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
