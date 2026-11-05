<script setup lang="ts">
import Icon from '~/components/Icon.vue'

/** One setting of the entry config pane: a one-line summary that opens its fields in place. */
defineProps<{ icon: string; label: string; summary: string }>()

const open = defineModel<boolean>('open', { default: false })
const bodyId = useId()
</script>

<template>
  <div class="border-border [&+&]:border-t">
    <button
      type="button"
      :class="[
        'flex h-12 w-full cursor-pointer items-center gap-3 px-3 text-left outline-none transition-colors duration-100 hover:bg-state-hover focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
        open && 'bg-state-hover',
      ]"
      :aria-expanded="open"
      :aria-controls="bodyId"
      @click="open = !open"
    >
      <Icon
        :name="icon"
        class="shrink-0 text-muted"
      />
      <span class="shrink-0 text-sm font-semibold text-primary">{{ label }}</span>
      <span class="ml-auto min-w-0 truncate text-sm text-muted">{{ summary }}</span>
      <Icon
        :name="open ? 'lucide:chevron-down' : 'lucide:chevron-right'"
        class="shrink-0 text-muted"
      />
    </button>
    <div
      v-if="open"
      :id="bodyId"
      class="grid gap-4 border-t border-border bg-surface p-3"
    >
      <slot />
    </div>
  </div>
</template>
