<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import { useClipboard } from '@vueuse/core'
import { toast } from 'vue-sonner'
import { Alert } from '~/components/ui/alert'
import { Button } from '~/components/ui/button'

const props = defineProps<{
  codes: string[]
  showFullCodes?: boolean
}>()

const { copy } = useClipboard({ source: props.codes.join('\n') })

const emit = defineEmits<{
  copy: []
  download: []
}>()

const handleCopy = () => {
  copy()
  toast.info('Backup codes copied to clipboard')
  emit('copy')
}

const handleDownload = () => {
  const content = props.codes.join('\n')
  const blob = new Blob([content], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'b10cks-backup-codes.txt'
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
  emit('download')
}
</script>

<template>
  <div class="space-y-4">
    <Alert
      variant="modern"
      color="warning"
    >
      <p>{{ $t('labels.twoFactor.backupCodes.saveWarning') }}</p>
    </Alert>
    <div class="grid grid-cols-2 gap-2">
      <span
        v-for="(code, index) in codes"
        :key="index"
        class="rounded border border-input-border bg-surface py-2 text-center font-mono text-sm text-primary"
      >
        {{ code }}&nbsp;</span
      >
    </div>

    <div class="flex gap-2">
      <Button
        class="flex-1"
        @click="handleCopy"
      >
        <Icon name="lucide:copy" />
        <span>{{ $t('labels.twoFactor.backupCodes.copy') }}</span>
      </Button>
      <Button
        class="flex-1"
        @click="handleDownload"
      >
        <Icon name="lucide:download" />
        <span>{{ $t('labels.twoFactor.backupCodes.download') }}</span>
      </Button>
    </div>
  </div>
</template>
