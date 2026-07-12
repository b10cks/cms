<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'

const props = defineProps<{
  text: string
}>()

const { $t } = useI18n()

const copy = () => {
  navigator.clipboard.writeText(props.text)
  toast.info($t('notifications.inputField.copied'))
}
</script>

<template>
  <Button
    size="xs"
    class="absolute top-1 right-1"
    aria-label="copy"
    tabindex="-1"
    @click="copy"
  >
    <Icon name="lucide:copy" />
  </Button>
</template>
