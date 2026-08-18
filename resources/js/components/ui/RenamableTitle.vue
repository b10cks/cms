<script setup lang="ts">
import { onClickOutside } from '@vueuse/core'
import type { HTMLAttributes } from 'vue'

const props = withDefaults(
  defineProps<{
    name: string
    fallback?: string
    disabled?: boolean
    inputClass?: string
    class?: HTMLAttributes['class']
    highlight?: number[]
  }>(),
  {
    inputClass:
      'rounded-md w-full px-1 flex shadow-sm transition-colors placeholder:text-muted focus-visible:outline-none focus-visible:ring-ring focus-visible:ring-1 disabled:cursor-not-allowed disabled:opacity-50',
    disabled: false,
    fallback: undefined,
  }
)

const emit = defineEmits<{
  (e: 'update', newName: string): void
  (e: 'cancel' | 'edit-start'): void
}>()

const isEditing = ref(false)
const inputValue = ref(props.name)
const inputRef = ref<HTMLInputElement | null>(null)

onClickOutside(inputRef, () => {
  if (isEditing.value) {
    submitRename()
  }
})

// Start editing
function startEdit() {
  if (props.disabled) return

  isEditing.value = true
  inputValue.value = props.name
  emit('edit-start')

  setTimeout(() => {
    if (inputRef.value) {
      inputRef.value.focus()
      inputRef.value.select()
    }
  }, 0)
}

function submitRename() {
  if (inputValue.value?.trim() && inputValue.value.trim() !== props.name) {
    emit('update', inputValue.value.trim())
  } else {
    emit('cancel')
  }

  isEditing.value = false
}

// Cancel rename
function cancelRename() {
  isEditing.value = false
  emit('cancel')
}

function handleKeyDown(event: KeyboardEvent) {
  if (event.key === 'Enter') {
    event.preventDefault()
    submitRename()
  } else if (event.key === 'Escape') {
    event.preventDefault()
    cancelRename()
  }
}

// Contiguous runs of highlighted / plain characters, so matched characters can
// be emphasized without one span per character.
const highlightSegments = computed(() => {
  if (!props.highlight?.length || !props.name) {
    return null
  }

  const hits = new Set(props.highlight)
  const segments: { text: string; hit: boolean }[] = []

  for (let index = 0; index < props.name.length; index++) {
    const hit = hits.has(index)
    const last = segments[segments.length - 1]

    if (last && last.hit === hit) {
      last.text += props.name[index]
    } else {
      segments.push({ text: props.name[index], hit })
    }
  }

  return segments
})

watch(
  () => props.name,
  (newValue) => {
    if (!isEditing.value) {
      inputValue.value = newValue
    }
  }
)

defineExpose({
  startEdit,
})
</script>

<template>
  <input
    v-if="isEditing"
    ref="inputRef"
    v-model="inputValue"
    type="text"
    :class="inputClass"
    :disabled="disabled"
    @keydown="handleKeyDown"
    @blur="submitRename"
    @mousedown.stop
    @click.stop.prevent
  />
  <span
    v-else
    :class="props.class"
    :role="disabled ? undefined : 'button'"
    :tabindex="disabled ? undefined : 0"
    @dblclick.stop.prevent="startEdit"
    @keydown.enter.stop.prevent="startEdit"
    @keydown.space.stop.prevent="startEdit"
  >
    <template v-if="highlightSegments">
      <span
        v-for="(segment, index) in highlightSegments"
        :key="index"
        :class="segment.hit ? 'font-bold text-info' : ''"
      >{{ segment.text }}</span>
    </template>
    <slot
      v-else
      :value="name"
    >{{ name || fallback }}</slot>
  </span>
</template>
