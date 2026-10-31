<script setup lang="ts">
import { CalendarDate, getLocalTimeZone, parseDate, today } from '@internationalized/date'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { DateField } from '~/components/ui/date-field'
import { FormField } from '~/components/ui/form'
import { getLocale } from '~/plugins/i18n'

/** "Access until" day picker; the model is a `YYYY-MM-DD` string or `null` for no end. */
defineProps<{
  label: string
  description?: string
  error?: string | null
}>()

const model = defineModel<string | null>({ default: null })
const { t } = useI18n()

const value = computed(() => (model.value ? parseDate(model.value) : undefined))
const minimum = today(getLocalTimeZone())

const update = (date: { year: number; month: number; day: number } | undefined) => {
  if (!date) {
    model.value = null
    return
  }
  const picked = new CalendarDate(date.year, date.month, date.day)
  model.value = (picked.compare(minimum) < 0 ? minimum : picked).toString()
}
</script>

<template>
  <FormField
    name="access_until"
    :label="label"
    :description="description"
    :error="error"
  >
    <template #default="{ id }">
      <div class="flex items-center gap-2">
        <DateField
          :id="id"
          :model-value="value"
          :locale="getLocale()"
          :aria-label="label"
          class="h-9"
          @update:model-value="update"
        />
        <Button
          v-if="model"
          type="button"
          size="sm"
          variant="ghost"
          @click="model = null"
        >
          <Icon name="lucide:x" />
          {{ t('labels.teamWorkspace.expiry.clear') }}
        </Button>
      </div>
    </template>
  </FormField>
</template>
