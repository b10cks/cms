<script setup lang="ts">
import Icon from '~/components/Icon.vue'

defineEmits<{ add: [] }>()

const { $t } = useI18n()
</script>

<template>
  <div class="flex opacity-0 transition-opacity hover:opacity-100">
    <div class="absolute inset-x-0 -mt-3 border-t-2 border-accent pt-4" />
    <div class="absolute inset-x-0 z-10 mx-auto -mt-6 flex transform justify-center gap-2">
      <button
        type="button"
        class="flex size-6 cursor-pointer items-center justify-center rounded-full bg-accent text-accent-foreground"
        :aria-label="$t('labels.assets.addAsset') as string"
        :title="$t('labels.assets.addAsset') as string"
        @click.stop="$emit('add')"
      >
        <Icon name="lucide:plus" />
      </button>
    </div>
  </div>
</template>
