<script setup lang="ts">
import type { HTMLAttributes } from 'vue'
import { cn } from '@/lib/utils'
import { useVModel } from '@vueuse/core'

const props = defineProps<{
  class?: HTMLAttributes['class']
  defaultValue?: string | number
  modelValue?: string | number
  autoSize?: boolean | number
}>()

const emits = defineEmits<{
  (e: 'update:modelValue', payload: string | number): void
}>()

const textareaRef = ref<HTMLTextAreaElement | null>(null)

const modelValue = useVModel(props, 'modelValue', emits, {
  passive: true,
  defaultValue: props.defaultValue,
})
const resizeTextarea = () => {
  if (props.autoSize && textareaRef.value) {
    textareaRef.value.style.height = 'auto'
    const maxHeight =
      typeof props.autoSize === 'number'
        ? Math.min(textareaRef.value.scrollHeight, props.autoSize)
        : textareaRef.value.scrollHeight

    textareaRef.value.style.height = `${maxHeight}px`
  }
}

onMounted(() => nextTick(resizeTextarea))
</script>

<template>
  <textarea
    ref="textareaRef"
    v-model="modelValue"
    :class="
      cn(
        'flex min-h-[60px] w-full rounded-md border border-input-border bg-input px-3 py-2 text-sm text-primary shadow-sm placeholder:text-muted focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50',
        props.class
      )
    "
  />
</template>
