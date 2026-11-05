<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'

/** Previous and next for the dock's paged lists, with the page in words. */
const page = defineModel<number>('page', { required: true })

defineProps<{
  lastPage: number
  busy?: boolean
}>()
</script>

<template>
  <nav
    v-if="lastPage > 1"
    class="flex items-center justify-end gap-1 text-sm text-muted tabular-nums"
    :aria-label="$t('components.aiOperations.pager.label')"
  >
    <Button
      size="toolbar"
      variant="ghost"
      :disabled="page <= 1 || busy"
      :aria-label="$t('components.aiOperations.pager.previous')"
      @click="page = page - 1"
    >
      <Icon name="lucide:chevron-left" />
    </Button>
    <span>{{ $t('components.aiOperations.pager.position', { page, last: lastPage }) }}</span>
    <Button
      size="toolbar"
      variant="ghost"
      :disabled="page >= lastPage || busy"
      :aria-label="$t('components.aiOperations.pager.next')"
      @click="page = page + 1"
    >
      <Icon name="lucide:chevron-right" />
    </Button>
  </nav>
</template>
