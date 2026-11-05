<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'

/** Shown when a read failed, so a page never presents missing data as an empty list or a zero. */
defineProps<{
  title?: string
  retrying?: boolean
  /** Inside a panel or table, which already draws the surface. */
  flush?: boolean
}>()

const emit = defineEmits<{ retry: [] }>()

const { t } = useI18n()
</script>

<template>
  <div
    role="alert"
    class="flex flex-wrap items-center gap-3"
    :class="!flush && 'rounded-xl border border-border bg-card px-5 py-4 shadow-soft-sm'"
  >
    <Icon
      name="lucide:cloud-off"
      class="shrink-0 text-destructive"
    />
    <p class="min-w-0 flex-1 text-primary">{{ title ?? t('labels.queryError') }}</p>
    <Button
      size="sm"
      variant="outline"
      :loading="retrying"
      @click="emit('retry')"
    >
      {{ t('actions.retry') }}
    </Button>
  </div>
</template>
