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
  }>(),
  {
    inputClass:
      'rounded-md w-full px-1 flex shadow-sm transition-colors placeholder:text-muted focus-visible:outline-none focus-visible:ring-ring focus-visible:ring-1 disabled:cursor-not-allowed disabled:opacity-50',
    disabled: false,
    fallback: undefined,
  }
)

const emit = defineEmits<{
  (e: 'update', newName: string, itemId?: string | number): void
  (e: 'cancel' | 'edit-start', itemId?: string | number): void
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
  emit('cancel', props)
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

watch(
  () => props.name,
  (newValue) => {
    if (!isEditing.value) {
      inputValue.value = newValue
    }
  }
)
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
    @click.stop
  />
  <span
    v-else
    type="button"
    :class="props.class"
    @dblclick.stop="startEdit"
  >
    <slot :name="name">{{ name ?? fallback }}</slot>
  </span>
</template>
