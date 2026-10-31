<script setup lang="ts">
import { Button } from '~/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeaderCombined } from '~/components/ui/dialog'
import { SelectField, TextField } from '~/components/ui/form'
import { useRootAccess } from '~/composables/useRootAccess'
import { ELEVATION_DURATIONS, type ElevationDuration } from '~/types/provider-console'

const open = defineModel<boolean>('open', { required: true })

const { t } = useI18n()
const { useElevateMutation } = useRootAccess()
const elevate = useElevateMutation()

const reason = ref('')
const duration = ref<ElevationDuration>(30)

const durationOptions = computed(() =>
  ELEVATION_DURATIONS.map((minutes) => ({
    value: minutes,
    label: t('rootAccess.elevate.minutes', { count: minutes }),
  }))
)

const canSubmit = computed(() => reason.value.trim().length >= 3 && !elevate.isPending.value)

watch(open, (isOpen) => {
  if (isOpen) {
    reason.value = ''
    duration.value = 30
  }
})

const submit = () => {
  if (!canSubmit.value) return

  elevate.mutate(
    { reason: reason.value.trim(), duration: duration.value },
    { onSuccess: () => (open.value = false) }
  )
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-md">
      <DialogHeaderCombined
        :title="$t('rootAccess.elevate.title')"
        :description="$t('rootAccess.elevate.description')"
      />
      <form
        class="space-y-4"
        @submit.prevent="submit"
      >
        <TextField
          v-model="reason"
          name="reason"
          :label="$t('rootAccess.elevate.reason')"
          :placeholder="$t('rootAccess.elevate.reasonPlaceholder')"
          :rows="2"
          required
        />
        <SelectField
          v-model="duration"
          name="duration"
          :label="$t('rootAccess.elevate.duration')"
          :options="durationOptions"
        />
        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            @click="open = false"
          >
            {{ $t('actions.cancel') }}
          </Button>
          <Button
            type="submit"
            variant="warning"
            :loading="elevate.isPending.value"
            :disabled="!canSubmit"
          >
            {{ $t('rootAccess.elevate.submit') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
