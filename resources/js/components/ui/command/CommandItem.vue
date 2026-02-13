<script setup lang="ts">
import type { ListboxItemEmits, ListboxItemProps } from 'reka-ui'
import { ListboxItem, useForwardPropsEmits, useId } from 'reka-ui'
import { cn } from '@/lib/utils'
import { useCurrentElement } from '@vueuse/core'
import { computed, type HTMLAttributes, onMounted, onUnmounted, ref } from 'vue'
import { useCommand, useCommandGroup } from '.'

const props = defineProps<ListboxItemProps & { class?: HTMLAttributes['class'] }>()
const emits = defineEmits<ListboxItemEmits>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)

const id = useId()
const { filterState, allItems, allGroups } = useCommand()
const groupContext = useCommandGroup()

const isRender = computed(() => {
  if (!filterState.search) {
    return true
  }

  const filteredCurrentItem = filterState.filtered.items.get(id)
  if (filteredCurrentItem === undefined) {
    return true
  }

  return filteredCurrentItem > 0
})

const itemRef = ref()
const currentElement = useCurrentElement(itemRef)
onMounted(() => {
  if (!(currentElement.value instanceof HTMLElement)) return

  allItems.value.set(id, currentElement.value.textContent ?? props.value.toString())

  const groupId = groupContext?.id
  if (groupId) {
    if (allGroups.value.has(groupId)) {
      allGroups.value.get(groupId)?.add(id)
    } else {
      allGroups.value.set(groupId, new Set([id]))
    }
  }
})
onUnmounted(() => {
  allItems.value.delete(id)
})
</script>

<template>
  <ListboxItem
    v-if="isRender"
    v-bind="forwarded"
    :id="id"
    ref="itemRef"
    :class="
      cn(
        'relative flex cursor-default items-center gap-2 rounded-sm px-2 py-1.5 text-sm outline-none select-none data-[disabled]:pointer-events-none data-[disabled]:opacity-50 data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground [&_svg]:size-4 [&_svg]:shrink-0',
        props.class
      )
    "
    @select="
      () => {
        filterState.search = ''
      }
    "
  >
    <slot />
  </ListboxItem>
</template>
