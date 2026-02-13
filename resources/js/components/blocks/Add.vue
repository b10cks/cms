<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import BlockTypeSelect from '~/components/ui/BlockTypeSelect.vue'
import { Button } from '~/components/ui/button'
import { Input } from '~/components/ui/input'

const types = [
  'text',
  'date',
  'number',
  'textarea',
  'markdown',
  'richtext',
  'blocks',
  'option',
  'options',
  'link',
  'reference',
  'boolean',
  'asset',
  'multiAsset',
  'meta',
]

const slugBlacklist = ['key', 'block']

interface AddBlockProps {
  type: string
  key: string
}

const value = ref<AddBlockProps>({
  type: 'text',
  key: '',
})

const emit = defineEmits<{
  (e: 'add', payload: AddBlockProps & { resolve: (value: boolean) => void }): void
}>()

async function add() {
  if (value.value.key.trim() === '') return
  if (slugBlacklist.includes(value.value.key)) {
    return
  }
  const result = await new Promise<boolean>((resolve) => emit('add', { ...value.value, resolve }))
  if (result) {
    value.value.key = ''
  }
}

// Combined function to handle all key events
function handleKeydown(event: KeyboardEvent) {
  if (event.key === 'Enter') {
    event.preventDefault()
    add()
  } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
    event.preventDefault()

    const currentIndex = types.findIndex((type) => type === value.value.type)
    let newIndex: number

    if (event.key === 'ArrowUp') {
      // Move up in the list (or wrap to the bottom)
      newIndex = currentIndex <= 0 ? types.length - 1 : currentIndex - 1
    } else {
      // Move down in the list (or wrap to the top)
      newIndex = currentIndex >= types.length - 1 ? 0 : currentIndex + 1
    }

    value.value.type = types[newIndex]

    // Announce type change for screen readers
    announceTypeChange(types[newIndex])
  }
}

// Function to announce type changes to screen readers
function announceTypeChange(type: string) {
  const announcement = `Block type changed to ${type}`
  const ariaLive = document.getElementById('type-change-announcer')

  if (ariaLive) {
    ariaLive.textContent = announcement
  }
}

// Lifecycle hook to ensure announcer element exists
onMounted(() => {
  if (!document.getElementById('type-change-announcer')) {
    const announcer = document.createElement('div')
    announcer.id = 'type-change-announcer'
    announcer.setAttribute('aria-live', 'polite')
    announcer.setAttribute('class', 'sr-only')
    document.body.appendChild(announcer)
  }
})

// Clean up on component unmount
onUnmounted(() => {
  const announcer = document.getElementById('type-change-announcer')
  if (announcer && !document.querySelectorAll('[aria-live]').length) {
    announcer.remove()
  }
})
</script>

<template>
  <div
    class="flex gap-2"
    role="group"
    aria-labelledby="add-block-label"
  >
    <span
      id="add-block-label"
      class="sr-only"
      >Add new Field</span
    >
    <BlockTypeSelect
      v-model="value.type"
      :types="types"
      aria-label="Block type"
    />
    <Input
      v-model="value.key"
      :placeholder="
        String(
          $t(`labels.blocks.fields.slugOf`, {
            name: String($t(`labels.blocks.fieldTypes.${value.type}.label`)),
          })
        )
      "
      aria-label="Block slug"
      aria-required="true"
      @keydown="handleKeydown"
    />
    <Button
      :disabled="value.key.trim() === ''"
      type="button"
      aria-label="Add block"
      :aria-disabled="value.key.trim() === ''"
      @click="add"
    >
      <Icon name="lucide:plus" />
      <span class="sr-only">Add block</span>
    </Button>
  </div>
</template>
