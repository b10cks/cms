<script setup lang="ts">
import Icon from '~/components/Icon.vue'

/**
 * The grip of a reorderable row (see lib/sortable.ts). Dragging it moves the row; arrow keys
 * along the list's direction emit `move`. Add the list's handle attribute to it.
 */
const props = withDefaults(
  defineProps<{ label?: string; orientation?: 'vertical' | 'horizontal'; disabled?: boolean }>(),
  { label: undefined, orientation: 'vertical', disabled: false }
)

const emit = defineEmits<{ move: [delta: -1 | 1] }>()

const { t } = useI18n()

const onKeydown = (event: KeyboardEvent) => {
  const [back, forward] =
    props.orientation === 'vertical' ? ['ArrowUp', 'ArrowDown'] : ['ArrowLeft', 'ArrowRight']
  if (props.disabled || (event.key !== back && event.key !== forward)) return

  event.preventDefault()
  event.stopPropagation()
  emit('move', event.key === back ? -1 : 1)
}
</script>

<template>
  <div
    :role="disabled ? undefined : 'button'"
    :tabindex="disabled ? undefined : 0"
    :aria-label="disabled ? undefined : (label ?? t('actions.blocks.tooltips.reorder'))"
    :title="disabled ? undefined : (label ?? t('actions.blocks.tooltips.reorder'))"
    :aria-hidden="disabled || undefined"
    :class="[
      '-m-1 flex shrink-0 touch-none items-center rounded-sm p-1 text-muted select-none',
      disabled
        ? 'opacity-50'
        : 'cursor-grab hover:text-foreground focus-visible:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing',
    ]"
    @keydown="onKeydown"
  >
    <Icon name="lucide:grip-vertical" />
  </div>
</template>
