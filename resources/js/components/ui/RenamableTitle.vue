<script setup lang="ts">
import { onClickOutside } from '@vueuse/core'
import type { HTMLAttributes } from 'vue'

import HighlightedText from '~/components/ui/HighlightedText.vue'
import { errorMessage } from '~/lib/toast-error'

const props = withDefaults(
  defineProps<{
    name: string
    fallback?: string
    disabled?: boolean
    inputClass?: string
    class?: HTMLAttributes['class']
    highlight?: number[]
    /**
     * Persists the new name. While it runs the field stays open and read-only;
     * a rejection keeps the typed text and shows the error under the field.
     * Without it the new name is only announced through `update`.
     */
    save?: (newName: string) => Promise<unknown>
  }>(),
  {
    inputClass:
      'rounded-md w-full px-1 flex shadow-sm transition-colors placeholder:text-muted focus-visible:outline-none focus-visible:ring-ring focus-visible:ring-1 disabled:cursor-not-allowed disabled:opacity-50',
    disabled: false,
    fallback: undefined,
    save: undefined,
  }
)

const emit = defineEmits<{
  (e: 'update', newName: string): void
  (e: 'cancel' | 'edit-start'): void
}>()

const isEditing = ref(false)
const inputValue = ref(props.name)
const inputRef = ref<HTMLInputElement | null>(null)
const isSaving = ref(false)
const saveError = ref<string | null>(null)
const errorId = useId()

// After a failed save only Enter retries and Escape discards, so a stray click
// does not resubmit the same failing name.
const canAutoSubmit = () => isEditing.value && !isSaving.value && !saveError.value

onClickOutside(inputRef, () => {
  if (canAutoSubmit()) {
    submitRename()
  }
})

// Start editing
function startEdit() {
  if (props.disabled) return

  isEditing.value = true
  saveError.value = null
  inputValue.value = props.name
  emit('edit-start')

  setTimeout(() => {
    if (inputRef.value) {
      inputRef.value.focus()
      inputRef.value.select()
    }
  }, 0)
}

// Deactivating the window blurs the input while it keeps DOM focus, so only a
// real focus move inside the page ends the rename — an alt-tab does not.
function handleBlur(event: FocusEvent) {
  const input = inputRef.value
  const next = event.relatedTarget as HTMLElement | null

  if (document.activeElement === input || isSaving.value) return

  // Firefox hands focus to the enclosing row when the field is clicked, since
  // the row is a link. Take it back instead of ending the rename.
  if (input && next?.contains(input)) {
    const start = input.selectionStart
    const end = input.selectionEnd
    input.focus()
    if (start !== null && end !== null) input.setSelectionRange(start, end)
    return
  }

  if (canAutoSubmit()) submitRename()
}

async function submitRename() {
  if (isSaving.value) return

  const name = inputValue.value?.trim()

  if (!name || name === props.name) {
    cancelRename()
    return
  }

  if (props.save) {
    isSaving.value = true
    saveError.value = null

    try {
      await props.save(name)
    } catch (error) {
      saveError.value = errorMessage(error)
      isSaving.value = false
      return
    }

    isSaving.value = false
  }

  emit('update', name)
  isEditing.value = false
}

function cancelRename() {
  if (isSaving.value) return

  isEditing.value = false
  saveError.value = null
  emit('cancel')
}

function handleKeyDown(event: KeyboardEvent) {
  // Nothing typed into the field may reach the list behind it: reka-ui's tree
  // rows act on ArrowLeft/ArrowRight (expand, collapse, focus the parent row),
  // and moving focus off the input ends the rename mid-edit.
  event.stopPropagation()

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

defineExpose({
  startEdit,
})
</script>

<template>
  <div
    v-if="isEditing"
    class="relative w-full min-w-0"
  >
    <input
      ref="inputRef"
      v-model="inputValue"
      type="text"
      :class="[inputClass, isSaving && 'opacity-50']"
      :disabled="disabled"
      :readonly="isSaving"
      :aria-busy="isSaving || undefined"
      :aria-invalid="saveError ? true : undefined"
      :aria-describedby="saveError ? errorId : undefined"
      draggable="false"
      @keydown="handleKeyDown"
      @blur="handleBlur"
      @mousedown.stop
      @click.stop.prevent
      @dragstart.stop.prevent
    />
    <span
      v-if="saveError"
      :id="errorId"
      role="alert"
      class="absolute top-full left-0 z-10 mt-1 max-w-64 rounded-md bg-popover px-2 py-1 text-xs font-normal text-destructive shadow-md"
    >{{ saveError }}</span>
  </div>
  <span
    v-else
    :class="props.class"
    :role="disabled ? undefined : 'button'"
    :tabindex="disabled ? undefined : 0"
    @dblclick.stop.prevent="startEdit"
    @keydown.enter.stop.prevent="startEdit"
    @keydown.space.stop.prevent="startEdit"
  >
    <HighlightedText
      v-if="highlight?.length && name"
      :text="name"
      :highlight="highlight"
    />
    <slot
      v-else
      :value="name"
    >{{ name || fallback }}</slot>
  </span>
</template>
