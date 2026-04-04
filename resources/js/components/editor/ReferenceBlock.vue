<script setup lang="ts">
import { useSortable } from '@vueuse/integrations/useSortable'
import { computed, nextTick, ref, watch } from 'vue'

import Icon from '~/components/Icon.vue'
import Label from '~/components/ui/form/Label.vue'
import { useAlertDialog } from '~/composables/useAlertDialog'

import ContentSelect from './ContentSelect.vue'

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
const editingIndex = ref<number | null>(null)
const sortableContainer = ref<HTMLElement | null>(null)

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

const isSingle = computed(() => props.item.type === 'reference' || props.item.max === 1)

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

// Event handlers

/** Handle selection from ContentSelect (add or replace) */
const handleContentSelect = (contentId: string) => {
  if (editingIndex.value !== null) {
    localValue.value[editingIndex.value] = contentId
    editingIndex.value = null
  } else {
    if (!canAddMore.value) return

    localValue.value.push(contentId)
  }

  updateValue()
}

/** Handle ContentSelect update:modelValue when used for single reference */
const handleSingleSelect = (contentId: string) => {
  localValue.value = [contentId]
  updateValue()
}

const handleReferenceEdit = (index: number) => {
  editingIndex.value = index
}

const handleEditSelect = (index: number, contentId: string) => {
  localValue.value[index] = contentId
  editingIndex.value = null
  updateValue()
}

const handleReferenceDelete = async (index: number) => {
  const reference = localValue.value[index]
  if (!reference) return

  const confirmed = await alert.confirm(
    $t('messages.references.confirmDelete', { name: getContentName(reference) }),
    {
      title: $t('labels.references.removeReference'),
      confirmLabel: $t('actions.remove'),
      cancelLabel: $t('actions.cancel'),
    }
  )

  if (confirmed) {
    localValue.value.splice(index, 1)
    if (editingIndex.value === index) editingIndex.value = null
    updateValue()
  }
}

// Drag and drop via sortable
const setupSortable = () => {
  nextTick(() => {
    if (!sortableContainer.value) return
    ;(useSortable as any)(sortableContainer, localValue, {
      handle: '[reference-draggable]',
      onEnd: () => nextTick(() => updateValue()),
    })
  })
}

watch(
  () => localValue.value.length,
  () => setupSortable(),
  { immediate: true }
)
</script>

<template>
  <div class="space-y-4">
    <div class="space-y-1">
      <Label
        :label="item.name || item.key"
        :required="item.required"
      />
      <p
        v-if="item.description"
        class="text-muted-foreground text-xs"
      >
        {{ item.description }}
      </p>
    </div>

    <!-- Single reference: inline select -->
    <ContentSelect
      v-if="isSingle"
      :model-value="localValue[0] ?? null"
      :space-id="spaceId"
      @update:model-value="handleSingleSelect"
    />

    <!-- Multiple references: list + add select -->
    <div
      v-else
      class="space-y-2"
    >
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
        ref="sortableContainer"
        class="space-y-1"
      >
        <div
          v-for="(reference, index) in localValue"
          :key="reference"
        >
          <!-- Edit mode: inline ContentSelect -->
          <ContentSelect
            v-if="editingIndex === index"
            :model-value="reference"
            :space-id="spaceId"
            @update:model-value="handleEditSelect(index, $event)"
            @cancel="editingIndex = null"
          />

          <!-- Display mode -->
          <div
            v-else
            class="group relative overflow-hidden rounded-lg border border-input bg-surface"
          >
            <div class="flex items-center p-2 gap-2">
              <button
                v-if="localValue.length > 1"
                type="button"
                reference-draggable
                class="z-10 cursor-ns-resize absolute text-muted opacity-0 group-hover:opacity-100 hover:text-primary"
                @click.prevent
              >
                <Icon name="lucide:grip-vertical" />
              </button>
              <div class="flex flex-1 items-center gap-2">
                <Icon
                  :name="`lucide:${getContentIcon(reference) || 'file'}`"
                  class="shrink-0 group-hover:opacity-0"
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
      </div>

      <!-- Add new reference -->
      <ContentSelect
        v-if="canAddMore"
        :model-value="null"
        :space-id="spaceId"
        :placeholder="$t('actions.references.add')"
        @update:model-value="handleContentSelect"
      />

      <div
        v-if="item.max && item.max > 0"
        class="text-muted-foreground text-center text-xs"
      >
        {{ $t('labels.references.referencesCount', { current: localValue.length, max: item.max }) }}
      </div>
    </div>
  </div>
</template>
