<script setup lang="ts">
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { DateTimeFormField, InputField } from '~/components/ui/form'
import type { ContentResource } from '~/types/contents'

type PublishType = 'now' | 'schedule'

const props = defineProps<{
  open: boolean
  content: ContentResource
  loading?: boolean
  publishType: PublishType
}>()

const emits = defineEmits<{
  (e: 'update:open', value: boolean): void
  (e: 'publish', payload: { message?: string; published_at?: string | null }): void
  (e: 'schedule', payload: { message?: string; scheduled_at?: string | null }): void
}>()

const message = ref('')
const scheduledAt = ref<string | null>(null)

const minDateTime = computed(() => {
  const now = new Date()
  return now.toISOString()
})

const handlePublish = () => {
  if (props.publishType === 'schedule') {
    const payload = {
      message: message.value || undefined,
      scheduled_at: scheduledAt.value,
    }
    emits('schedule', payload)
  } else {
    const payload = {
      message: message.value || undefined,
      scheduled_at: undefined,
    }
    emits('publish', payload)
  }
  resetForm()
}

const handleCancel = () => {
  emits('update:open', false)
}

const isScheduleValid = computed(() => {
  if (props.publishType === 'now') return true
  return !!scheduledAt.value
})

const resetForm = () => {
  message.value = ''
  scheduledAt.value = null
}
</script>

<template>
  <Dialog
    :open="open"
    @update:open="emits('update:open', $event)"
  >
    <DialogContent class="max-w-md">
      <DialogHeaderCombined
        :title="publishType === 'now' ? $t('labels.publishDialog.titleNow') : $t('labels.publishDialog.titleSchedule')"
        :description="
          publishType === 'now'
            ? $t('labels.publishDialog.descNow')
            : $t('labels.publishDialog.descSchedule')
        "
      />

      <div class="space-y-4">
        <InputField
          v-model="message"
          name="message"
          :label="$t('labels.publishDialog.messageLabel')"
          :placeholder="$t('labels.publishDialog.messagePlaceholder')"
          type="text"
        />
        <DateTimeFormField
          v-if="publishType === 'schedule'"
          v-model="scheduledAt"
          name="published_at"
          :label="$t('labels.publishDialog.publishAtLabel')"
          :placeholder="$t('labels.publishDialog.publishAtPlaceholder')"
          :min="minDateTime"
          required
        />
      </div>

      <DialogFooter class="gap-3">
        <Button
          variant="outline"
          @click="handleCancel"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          variant="accent"
          :disabled="loading || !isScheduleValid"
          @click="handlePublish"
        >
          {{ publishType === 'now' ? $t('actions.content.publish') : $t('actions.content.schedule') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
