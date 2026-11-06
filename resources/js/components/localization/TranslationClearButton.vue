<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { SimpleTooltip } from '~/components/ui/tooltip'

/**
 * Empties a translation so it reads as missing again. Shown while its
 * translation row (a `group/row` ancestor) is hovered or focused; the caller
 * positions it.
 */
defineEmits<{ clear: [] }>()

const { t } = useI18n()
</script>

<template>
  <SimpleTooltip
    :tooltip="t('components.flattenedLocalization.clearTranslation')"
    as-child
  >
    <Button
      type="button"
      variant="ghost"
      size="toolbar"
      class="text-muted opacity-0 transition-opacity group-hover/row:opacity-100 group-focus-within/row:opacity-100 hover:text-primary"
      :aria-label="t('components.flattenedLocalization.clearTranslation')"
      tabindex="-1"
      @click="$emit('clear')"
    >
      <Icon name="lucide:eraser" />
    </Button>
  </SimpleTooltip>
</template>
