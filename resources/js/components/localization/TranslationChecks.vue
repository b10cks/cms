<script setup lang="ts">
import Icon from '~/components/Icon.vue'

/** A translation over the schema's maximum length, as a warning line. It never blocks a save. */
defineProps<{
  length: { count: number; max: number } | null
}>()

const { t } = useI18n()
</script>

<template>
  <div
    v-if="length"
    class="flex items-center justify-between gap-4 text-sm text-warning-ink"
  >
    <span class="flex items-center gap-1.5">
      <Icon
        name="lucide:triangle-alert"
        class="size-3.5 shrink-0"
      />
      {{ t('components.translationAssist.checkTooLong', { max: length.max }) }}
    </span>
    <span class="tabular-nums">{{ length.count }}/{{ length.max }}</span>
  </div>
</template>
