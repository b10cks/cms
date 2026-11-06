<script setup lang="ts">
import Icon from '~/components/Icon.vue'

/** Inserts above its row: an accent line on the row's top edge with a 24px plus, shown on hover or focus. */
defineEmits<{ add: [] }>()

const { $t } = useI18n()
</script>

<template>
  <div
    class="absolute inset-x-0 top-0 z-10 flex h-3 -translate-y-1/2 items-center justify-center opacity-0 transition-opacity duration-100 focus-within:opacity-100 hover:opacity-100"
  >
    <div class="absolute inset-x-0 h-0.5 bg-accent" />
    <button
      type="button"
      class="relative flex size-6 cursor-pointer items-center justify-center rounded-full bg-accent-fill text-accent-foreground outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
      :aria-label="$t('labels.assets.addAsset') as string"
      :title="$t('labels.assets.addAsset') as string"
      @click.stop="$emit('add')"
    >
      <Icon name="lucide:plus" />
    </button>
  </div>
</template>
