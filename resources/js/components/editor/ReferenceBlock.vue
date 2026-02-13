<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { computed, ref, watch } from 'vue'
import { Button } from '~/components/ui/button'
import { useAlertDialog } from '~/composables/useAlertDialog'
import ContentPicker from './ContentPicker.vue'

const props = defineProps<{
  modelValue?: string[] | null
  item: ReferencesSchema & { key: string }
  spaceId: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string[] | null]
}>()

const { alert } = useAlertDialog()
const { $t } = useI18n()
const { useContentMenuQuery } = useContentMenu(props.spaceId)
const { data: contentMenu } = useContentMenuQuery()

const localValue = ref<string[]>([])
const showContentPicker = ref(false)
const draggedIndex = ref<number | null>(null)
const editingIndex = ref<number | null>(null)

// Sync with props
watch(
  () => props.modelValue,
  (newValue) => {
    localValue.value = newValue ? [...newValue] : []
  },
  { immediate: true, deep: true }
)

// Computed properties
const hasReferences = computed(() => localValue.value.length > 0)

const canAddMore = computed(() => {
  if (props.item.max && props.item.max > 0) {
    return localValue.value.length < props.item.max
  }
  return true
})

const minReferences = computed(() => props.item.min || 0)

// Helper functions
const updateValue = () => {
  const hasMinimumReferences = localValue.value.length >= minReferences.value
  emit(
    'update:modelValue',
    hasMinimumReferences && localValue.value.length > 0 ? localValue.value : null
  )
}

const getContentName = (contentId: string): string => {
  if (!contentMenu.value || !contentId) return $t('labels.references.unknownContent')
  const item = contentMenu.value[contentId]
  return item?.name || $t('labels.references.unknownContent')
}

// Event handlers
const handleContentSelect = (contentId: string) => {
  if (editingIndex.value !== null) {
    // Replace existing reference
    localValue.value[editingIndex.value] = contentId
    editingIndex.value = null
  } else {
    // Add new reference
    if (!canAddMore.value) return

    // Check for duplicates
    if (localValue.value.some((ref) => ref === contentId)) {
      alert.message($t('messages.references.duplicateReference'), {
        title: $t('labels.references.duplicateTitle'),
        okLabel: $t('actions.ok'),
      })
      return
    }

    localValue.value.push(contentId)
  }

  updateValue()
  showContentPicker.value = false
}

const handleContentWithAnchorSelect = (contentId: string, anchorId: string) => {
  if (editingIndex.value !== null) {
    localValue.value[editingIndex.value] = contentId
    editingIndex.value = null
  } else {
    if (!canAddMore.value) return

    if (localValue.value.some((ref) => ref === contentId)) {
      alert.message($t('messages.references.duplicateReference'), {
        title: $t('labels.references.duplicateTitle'),
        okLabel: $t('actions.ok'),
      })
      return
    }

    localValue.value.push(contentId)
  }

  updateValue()
  showContentPicker.value = false
}

const handleReferenceEdit = (index: number) => {
  editingIndex.value = index
  showContentPicker.value = true
}

const handleReferenceDelete = async (index: number) => {
  const reference = localValue.value[index]
  if (!reference) return

  const confirmed = await alert.confirm(
    $t('messages.references.confirmDelete', { name: getContentName(reference.id) }),
    {
      title: $t('labels.references.removeReference'),
      confirmLabel: $t('actions.remove'),
      cancelLabel: $t('actions.cancel'),
    }
  )

  if (confirmed) {
    localValue.value.splice(index, 1)
    updateValue()
  }
}

// Drag and drop handlers
const handleDragStart = (e: DragEvent, index: number) => {
  if (!e.dataTransfer) return
  draggedIndex.value = index
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('text/plain', index.toString())
}

const handleDragOver = (e: DragEvent) => {
  e.preventDefault()
  if (e.dataTransfer) {
    e.dataTransfer.dropEffect = 'move'
  }
}

const handleDrop = (e: DragEvent, targetIndex: number) => {
  e.preventDefault()

  if (draggedIndex.value === null || draggedIndex.value === targetIndex) {
    draggedIndex.value = null
    return
  }

  const draggedItem = localValue.value[draggedIndex.value]
  localValue.value.splice(draggedIndex.value, 1)
  localValue.value.splice(targetIndex, 0, draggedItem)

  updateValue()
  draggedIndex.value = null
}

const getContentIcon = (contentId: string): string => {
  if (!contentMenu.value || !contentId) return 'file'
  const item = contentMenu.value[contentId]
  return item?.icon || 'file'
}

const getContentColor = (contentId: string): string => {
  if (!contentMenu.value || !contentId) return '#64748b'
  const item = contentMenu.value[contentId]
  return item?.color || '#64748b'
}
</script>

<template>
  <div class="space-y-4">
    <div class="space-y-1">
      <label class="text-sm font-medium text-primary">
        {{ item.name || item.key }}
      </label>
      <p
        v-if="item.description"
        class="text-muted-foreground text-xs"
      >
        {{ item.description }}
      </p>
    </div>
    <div class="space-y-2">
      <div
        v-if="!hasReferences"
        class="flex flex-col items-center justify-center gap-2 py-2"
      >
        <Icon
          name="lucide:link-2"
          class="text-muted"
        />
        <p class="text-sm text-muted">
          {{ $t('labels.references.noReferences') }}
        </p>
      </div>
      <div
        v-else
        class="space-y-2"
      >
        <div
          v-for="(reference, index) in localValue"
          :key="reference"
          class="group relative overflow-hidden rounded-lg border border-input bg-surface"
          :draggable="localValue.length > 1"
          @dragstart="handleDragStart($event, index)"
          @dragover="handleDragOver"
          @drop="handleDrop($event, index)"
        >
          <div class="flex items-center p-2">
            <Icon
              v-if="localValue.length > 1"
              name="lucide:grip-vertical"
              class="cursor-ns-resize text-muted opacity-0 group-hover:opacity-100 hover:text-primary"
            />
            <div
              v-else
              class="w-2"
            />
            <div class="flex flex-1 items-center gap-2">
              <Icon
                :name="`lucide:${getContentIcon(reference) || 'file'}`"
                class="shrink-0"
                :style="{ color: getContentColor(reference) }"
              />
              <div class="min-w-0 flex-1">
                {{ getContentName(reference) }}
              </div>
            </div>
            <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100">
              <button
                class="flex transform cursor-pointer items-center hover:text-primary"
                @click="handleReferenceEdit(index)"
              >
                <Icon name="lucide:pencil" />
              </button>
              <button
                class="flex transform cursor-pointer items-center hover:text-red-500"
                @click="handleReferenceDelete(index)"
              >
                <Icon name="lucide:trash-2" />
              </button>
            </div>
          </div>
        </div>
      </div>

      <Button
        v-if="canAddMore"
        class="w-full"
        @click="showContentPicker = true"
      >
        <Icon name="lucide:plus" />
        {{ $t('actions.references.add') }}
      </Button>

      <div
        v-if="item.max && item.max > 0"
        class="text-muted-foreground text-center text-xs"
      >
        {{ $t('labels.references.referencesCount', { current: localValue.length, max: item.max }) }}
      </div>
    </div>

    <!-- Content Picker Dialog -->
    <ContentPicker
      v-model:open="showContentPicker"
      :space-id="spaceId"
      :title="$t('labels.references.selectContent')"
      :show-elements="true"
      @content-select="handleContentSelect"
      @content-with-anchor-select="handleContentWithAnchorSelect"
    />
  </div>
</template>
