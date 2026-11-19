<script setup lang="ts">
import { DateFormatter, type CalendarDate, Time, toCalendarDate } from '@internationalized/date'
import type { DateValue, TimeValue } from 'reka-ui'
import { computed, ref, watch, type HTMLAttributes, type Ref } from 'vue'

import { cn } from '@/lib/utils'
import Icon from '~/components/Icon.vue'
import { Calendar } from '~/components/ui/calendar'
import { TimeField } from '~/components/ui/date-field'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import {
  boundTimeOn,
  type DateFieldFormat,
  parseDateFieldValue,
  serializeDateFieldValue,
} from '~/lib/dateFieldValue'
import { getLocale } from '~/plugins/i18n'

import FormField from './FormField.vue'

/**
 * Date, time or date-and-time picker for schema date fields. The model is the stored
 * wall-clock string (`YYYY-MM-DD`, `HH:mm`, `YYYY-MM-DDTHH:mm`), never shifted by a time zone.
 */
const props = withDefaults(
  defineProps<{
    id?: string
    name: string
    label?: string
    required?: boolean
    tooltip?: string
    description?: string
    error?: string | null
    class?: HTMLAttributes['class']
    inputClass?: HTMLAttributes['class']
    format?: DateFieldFormat
    min?: string | null
    max?: string | null
    disabled?: boolean
    readonly?: boolean
  }>(),
  { format: 'date' }
)

const modelValue = defineModel<string | null>()

const { t } = useI18n()

const locale = computed(() => getLocale())
const hasDate = computed(() => props.format !== 'time')
const hasTime = computed(() => props.format !== 'date')
const editable = computed(() => !props.disabled && !props.readonly)

// Cast past Vue's UnwrapRef, which strips the @internationalized/date class brand.
const date = ref<CalendarDate>() as Ref<CalendarDate | undefined>
const time = ref<Time>() as Ref<Time | undefined>
const open = ref(false)

watch(
  modelValue,
  (value) => {
    const parts = parseDateFieldValue(value)
    date.value = parts.date
    time.value = parts.time
  },
  { immediate: true }
)

const minParts = computed(() => parseDateFieldValue(props.min))
const maxParts = computed(() => parseDateFieldValue(props.max))

const minTime = computed(() => boundTimeOn(props.format, date.value, minParts.value))
const maxTime = computed(() => boundTimeOn(props.format, date.value, maxParts.value))

const hourCycle = computed(() => {
  const cycle = new Intl.DateTimeFormat(locale.value, { hour: 'numeric' }).resolvedOptions()
    .hourCycle
  return cycle === 'h11' || cycle === 'h12' ? 12 : 24
})

const dateLabel = computed(() => {
  if (!date.value) return null
  return new DateFormatter(locale.value, { dateStyle: 'medium', timeZone: 'UTC' }).format(
    date.value.toDate('UTC')
  )
})

// reka puts an `id` on its hidden form input, so the visible time group is named directly.
const timeLabel = computed(() =>
  hasDate.value
    ? [props.label, t('labels.datePicker.time')].filter(Boolean).join(', ')
    : props.label
)

const hasValue = computed(() => Boolean(date.value || time.value))

function commit(): void {
  const value = serializeDateFieldValue(props.format, { date: date.value, time: time.value })
  if (value !== null && value !== (modelValue.value ?? '')) modelValue.value = value
}

function selectDate(value: DateValue | undefined): void {
  if (!value) return
  date.value = toCalendarDate(value)
  if (hasTime.value && !time.value) time.value = new Time(0, 0)
  open.value = false
  commit()
}

function selectTime(value: TimeValue | undefined): void {
  time.value = value ? new Time(value.hour, value.minute) : undefined
  commit()
}

function clear(): void {
  date.value = undefined
  time.value = undefined
  commit()
}
</script>

<template>
  <FormField
    :id="id"
    :label="label"
    :name="name"
    :required="required"
    :tooltip="tooltip"
    :description="description"
    :error="error"
    :class="props.class"
  >
    <template #default="{ id: fieldId, 'aria-describedby': describedBy, 'aria-invalid': invalid }">
      <div :class="cn('flex w-full flex-wrap items-center gap-2', inputClass)">
        <Popover
          v-if="hasDate"
          v-model:open="open"
        >
          <PopoverTrigger as-child>
            <button
              :id="fieldId"
              type="button"
              :disabled="!editable"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
              :aria-required="required || undefined"
              class="flex h-9 min-w-40 flex-1 cursor-pointer items-center gap-2 rounded-lg border border-input-border bg-input px-3 text-start text-sm text-primary shadow-sm transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:text-input-muted aria-invalid:border-destructive data-[state=open]:ring-2 data-[state=open]:ring-ring"
            >
              <Icon
                name="lucide:calendar"
                class="shrink-0 text-muted"
              />
              <span
                class="truncate"
                :class="!dateLabel && 'text-input-muted'"
              >
                {{ dateLabel ?? $t('labels.datePicker.placeholder') }}
              </span>
            </button>
          </PopoverTrigger>
          <PopoverContent
            align="start"
            class="w-auto !max-h-none"
          >
            <Calendar
              :model-value="date"
              :min-value="minParts.date"
              :max-value="maxParts.date"
              :locale="locale"
              prevent-deselect
              initial-focus
              @update:model-value="selectDate"
            />
          </PopoverContent>
        </Popover>

        <TimeField
          v-if="hasTime"
          :model-value="time"
          :locale="locale"
          :hour-cycle="hourCycle"
          :min-value="minTime"
          :max-value="maxTime"
          :disabled="disabled"
          :readonly="readonly"
          :aria-label="timeLabel"
          :aria-describedby="describedBy"
          :aria-invalid="invalid"
          :class="cn('!h-9 !rounded-lg !px-3', hasDate ? 'w-30 shrink-0' : 'flex-1')"
          @update:model-value="selectTime"
        />

        <button
          v-if="hasValue && editable"
          type="button"
          class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg text-muted transition-colors duration-100 hover:bg-state-hover hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
          :aria-label="$t('labels.datePicker.clear')"
          @click="clear"
        >
          <Icon name="lucide:x" />
        </button>
      </div>
    </template>
  </FormField>
</template>
