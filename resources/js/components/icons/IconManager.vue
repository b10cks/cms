<script setup lang="ts">
import Icon from '~/components/Icon.vue'
import IconDetailsDialog from '~/components/icons/IconDetailsDialog.vue'
import IconGrid from '~/components/icons/IconGrid.vue'
import IconUploadDialog from '~/components/icons/IconUploadDialog.vue'
import ImportIconsDialog from '~/components/icons/ImportIconsDialog.vue'
import { Button } from '~/components/ui/button'
import ContentHeader from '~/components/ui/ContentHeader.vue'
import type { IconResource } from '~/types/icons'

const props = defineProps<{
  spaceId: string
}>()

const { t } = useI18n()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.spaceId })))
const canManage = computed(() => access.hasAbility('icons.manage'))

const uploadOpen = ref(false)
const importOpen = ref(false)
const detailsOpen = ref(false)
const selectedIcon = ref<IconResource | null>(null)
const droppedFiles = ref<File[]>([])
const isDraggingOver = ref(false)

const handleEdit = (icon: IconResource) => {
  selectedIcon.value = icon
  detailsOpen.value = true
}

const handleDocumentDragOver = (event: DragEvent) => {
  if (!canManage.value || !event.dataTransfer?.types.includes('Files')) {
    return
  }

  event.preventDefault()
  isDraggingOver.value = true
}

const handleDocumentDragLeave = (event: DragEvent) => {
  if (!event.dataTransfer?.types.includes('Files')) {
    return
  }

  if (!event.relatedTarget || event.relatedTarget === document.body) {
    isDraggingOver.value = false
  }
}

const handleDocumentDrop = (event: DragEvent) => {
  if (!canManage.value || !event.dataTransfer?.files?.length) {
    return
  }

  event.preventDefault()
  isDraggingOver.value = false
  droppedFiles.value = Array.from(event.dataTransfer.files)
  uploadOpen.value = true
}

onMounted(() => {
  document.addEventListener('dragover', handleDocumentDragOver)
  document.addEventListener('dragleave', handleDocumentDragLeave)
  document.addEventListener('drop', handleDocumentDrop)
})

onUnmounted(() => {
  document.removeEventListener('dragover', handleDocumentDragOver)
  document.removeEventListener('dragleave', handleDocumentDragLeave)
  document.removeEventListener('drop', handleDocumentDrop)
  isDraggingOver.value = false
})
</script>

<template>
  <div class="flex h-full w-full flex-col gap-4">
    <ContentHeader
      :header="t('labels.icons.pageTitle')"
      :description="t('labels.icons.pageDescription')"
    >
      <template #actions>
        <div class="ml-auto flex items-center gap-2">
          <Button
            v-if="canManage"
            variant="outline"
            @click="importOpen = true"
          >
            <Icon name="lucide:file-json" />
            {{ t('actions.icons.import') }}
          </Button>
          <Button
            v-if="canManage"
            variant="primary"
            @click="uploadOpen = true"
          >
            <Icon name="lucide:upload" />
            {{ t('actions.icons.uploadNew') }}
          </Button>
        </div>
      </template>
    </ContentHeader>

    <div class="min-h-0 flex-1">
      <IconGrid
        :space-id="spaceId"
        mode="manage"
        @icon-edit="handleEdit"
      />
    </div>

    <Transition name="fade">
      <div
        v-if="isDraggingOver"
        class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-3 bg-background/80 backdrop-blur-sm ring-4 ring-inset ring-primary pointer-events-none"
      >
        <Icon
          name="lucide:upload"
          size="48"
          class="text-primary"
        />
        <p class="text-xl font-semibold text-primary">
          {{ t('labels.icons.dragDropOverlay') }}
        </p>
        <p class="text-sm text-muted">
          {{ t('labels.icons.dropSubHint') }}
        </p>
      </div>
    </Transition>

    <IconUploadDialog
      v-model:open="uploadOpen"
      :space-id="spaceId"
      :initial-files="droppedFiles"
      @update:open="
        (open) => {
          if (!open) droppedFiles = []
        }
      "
    />

    <ImportIconsDialog
      v-model:open="importOpen"
      :space-id="spaceId"
    />

    <IconDetailsDialog
      v-model:open="detailsOpen"
      :space-id="spaceId"
      :icon="selectedIcon"
    />
  </div>
</template>
