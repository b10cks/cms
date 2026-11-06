<script setup lang="ts">
import Icon from '~/components/Icon.vue'
</script>

<template>
  <span
    class="inline-flex shrink-0 items-center gap-1 text-sm font-normal text-primary"
    :title="$t('glossaryImport.editor.fields.ban')"
  >
    <Icon
      name="lucide:ban"
      class="text-destructive"
    />
    <span class="sr-only @3xl:not-sr-only">{{ $t('glossaryImport.editor.cell.ban') }}</span>
  </span>
</template>
