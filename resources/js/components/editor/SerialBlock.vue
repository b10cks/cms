<script setup lang="ts">
import { computed, ref } from 'vue'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { FormField, InputField } from '~/components/ui/form'
import { SimpleTooltip } from '~/components/ui/tooltip'

const value = defineModel<string>()

const props = defineProps<{
  item: SerialSchema & { key: string }
  readOnly?: boolean
}>()

const { $t } = useI18n()

const copied = ref(false)

// The value is written by the server when the entry is created. On a brand-new
// entry there is nothing to show yet, which is worth saying out loud rather
// than rendering a blank field that looks broken.
const isPending = computed(() => !value.value)

const canEdit = computed(() => props.item.editable && !props.readOnly)

const copy = async () => {
  if (!value.value) return

  await navigator.clipboard.writeText(value.value)
  copied.value = true
  window.setTimeout(() => (copied.value = false), 1500)
}
</script>

<template>
  <InputField
    v-if="canEdit"
    v-model="value"
    :name="item.key"
    :label="item.name || item.key"
    :description="item.description || $t('labels.blocks.fields.serial.editableHint')"
    class="font-mono"
  />

  <FormField
    v-else
    :name="item.key"
    :label="item.name || item.key"
    :description="item.description || undefined"
  >
    <div class="flex items-center gap-2">
      <span
        v-if="!isPending"
        class="inline-flex items-center rounded-md border border-input bg-elevated px-2 py-1 font-mono text-sm"
      >
        {{ value }}
      </span>
      <span
        v-else
        class="text-sm text-muted"
      >
        {{ $t('labels.blocks.fields.serial.assignedOnSave') }}
      </span>

      <SimpleTooltip
        v-if="!isPending"
        :tooltip="String(copied ? $t('actions.copied') : $t('actions.copy'))"
      >
        <Button
          type="button"
          size="sm"
          variant="ghost"
          :aria-label="String(copied ? $t('actions.copied') : $t('actions.copy'))"
          @click="copy"
        >
          <Icon :name="copied ? 'lucide:check' : 'lucide:copy'" />
        </Button>
      </SimpleTooltip>
    </div>
  </FormField>
</template>
