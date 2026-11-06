<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'

/**
 * Copies a read-only source value as plain text. Shown while its translation
 * row (a `group/row` ancestor) is hovered or focused; the caller positions it.
 */
const props = defineProps<{
  text: string
}>()

const { t } = useI18n()

const copy = async () => {
  await navigator.clipboard.writeText(props.text)
  toast.info(t('notifications.inputField.copied'))
}
</script>

<template>
  <Button
    type="button"
    variant="ghost"
    size="toolbar"
    class="text-muted opacity-0 transition-opacity group-hover/row:opacity-100 group-focus-within/row:opacity-100 hover:text-primary"
    :aria-label="t('components.flattenedLocalization.copySource')"
    :title="t('components.flattenedLocalization.copySource')"
    tabindex="-1"
    @click="copy"
  >
    <Icon name="lucide:copy" />
  </Button>
</template>
