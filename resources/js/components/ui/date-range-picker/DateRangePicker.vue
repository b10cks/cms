<script setup lang="ts">
import { CalendarDate, Time } from '@internationalized/date'
import dayjs, { type Dayjs } from 'dayjs'
import type { DateRange, DateValue, TimeValue } from 'reka-ui'
import { computed, ref, type Ref } from 'vue'

import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import { RangeCalendar } from '~/components/ui/calendar'
import { DateField, TimeField } from '~/components/ui/date-field'
import { Popover, PopoverContent, PopoverTrigger } from '~/components/ui/popover'
import { getLocale } from '~/plugins/i18n'

export interface DateRangeValue {
  start: string | null
  end: string | null
  preset?: string | null
}

const props = defineProps<{
  modelValue?: DateRangeValue
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: DateRangeValue): void
}>()

const { t } = useI18n()

const locale = computed(() => getLocale())

const presets = [
  { key: 'last15m', minutes: 15 },
  { key: 'last1h', minutes: 60 },
  { key: 'last3h', minutes: 180 },
  { key: 'last24h', minutes: 60 * 24 },
  { key: 'last3d', minutes: 60 * 24 * 3 },
  { key: 'last7d', minutes: 60 * 24 * 7 },
  { key: 'last30d', minutes: 60 * 24 * 30 },
] as const

const open = ref(false)
// Cast past Vue's UnwrapRef, which strips the @internationalized/date class brand.
const range = ref({ start: undefined, end: undefined }) as Ref<DateRange>
const startTime = ref(new Time(0, 0)) as Ref<TimeValue>
const endTime = ref(new Time(23, 59)) as Ref<TimeValue>
const activePreset = ref<string | null>(null)

const startDate = computed<DateValue | undefined>({
  get: () => range.value.start ?? undefined,
  set: (value) => {
    range.value = { ...range.value, start: value }
    activePreset.value = null
  },
})

const endDate = computed<DateValue | undefined>({
  get: () => range.value.end ?? undefined,
  set: (value) => {
    range.value = { ...range.value, end: value }
    activePreset.value = null
  },
})

const hasValue = computed(() => !!(props.modelValue?.start || props.modelValue?.end))

const triggerLabel = computed(() => {
  const value = props.modelValue
  if (value?.preset) {
    return t(`labels.dateRange.presets.${value.preset}`)
  }
  if (value?.start || value?.end) {
    const fmt = 'MMM D, YYYY, h:mm A'
    const start = value.start ? dayjs(value.start).format(fmt) : '…'
    const end = value.end ? dayjs(value.end).format(fmt) : '…'
    return `${start} – ${end}`
  }
  return t('labels.dateRange.placeholder')
})

function toCalendarDate(value: Dayjs): CalendarDate {
  return new CalendarDate(value.year(), value.month() + 1, value.date())
}

function toTime(value: Dayjs): Time {
  return new Time(value.hour(), value.minute())
}

function combine(date: DateValue | undefined, time: TimeValue, inclusiveEnd: boolean): string | null {
  if (!date) {
    return null
  }
  return dayjs()
    .set('year', date.year)
    .set('month', date.month - 1)
    .set('date', date.day)
    .set('hour', time.hour)
    .set('minute', time.minute)
    .set('second', inclusiveEnd ? 59 : 0)
    .set('millisecond', 0)
    .format('YYYY-MM-DD HH:mm:ss')
}

function syncFromModel(): void {
  const value = props.modelValue
  const start = value?.start ? dayjs(value.start) : null
  const end = value?.end ? dayjs(value.end) : null

  range.value = {
    start: start?.isValid() ? toCalendarDate(start) : undefined,
    end: end?.isValid() ? toCalendarDate(end) : undefined,
  }
  startTime.value = start?.isValid() ? toTime(start) : new Time(0, 0)
  endTime.value = end?.isValid() ? toTime(end) : new Time(23, 59)
  activePreset.value = value?.preset ?? null
}

function onCalendarUpdate(value: DateRange): void {
  range.value = value
  activePreset.value = null
}

function applyPreset(preset: (typeof presets)[number]): void {
  const end = dayjs()
  const start = end.subtract(preset.minutes, 'minute')

  range.value = { start: toCalendarDate(start), end: toCalendarDate(end) }
  startTime.value = toTime(start)
  endTime.value = toTime(end)
  activePreset.value = preset.key
}

function clearSelection(): void {
  range.value = { start: undefined, end: undefined }
  startTime.value = new Time(0, 0)
  endTime.value = new Time(23, 59)
  activePreset.value = null
}

function onOpenChange(value: boolean): void {
  if (value) {
    syncFromModel()
  }
  open.value = value
}

function apply(): void {
  emit('update:modelValue', {
    start: combine(range.value.start, startTime.value, false),
    end: combine(range.value.end, endTime.value, true),
    preset: activePreset.value,
  })
  open.value = false
}

function clearValue(): void {
  emit('update:modelValue', { start: null, end: null, preset: null })
}
</script>

<template>
  <Popover
    :open="open"
    @update:open="onOpenChange"
  >
    <PopoverTrigger as-child>
      <button
        type="button"
        class="group flex h-9 cursor-pointer items-center gap-2 rounded-md border border-input-border bg-input px-3 text-sm font-semibold whitespace-nowrap text-primary shadow-sm transition-colors hover:bg-input/70 focus:ring-1 focus:ring-ring focus:outline-none data-[state=open]:ring-1 data-[state=open]:ring-ring"
        :aria-label="$t('labels.dateRange.label')"
      >
        <Icon
          name="lucide:calendar"
          class="shrink-0 text-muted"
        />
        <span :class="hasValue ? 'text-primary' : 'text-muted'">{{ triggerLabel }}</span>
        <span
          v-if="hasValue"
          role="button"
          tabindex="0"
          class="-mr-1 ml-1 flex size-5 items-center justify-center rounded-full p-0.5 text-muted hover:bg-elevated hover:text-primary"
          :aria-label="$t('labels.dateRange.clear')"
          @click.stop="clearValue"
          @keydown.enter.stop.prevent="clearValue"
          @keydown.space.stop.prevent="clearValue"
        >
          <Icon name="lucide:x" />
        </span>
        <Icon
          v-else
          name="lucide:chevron-down"
          class="shrink-0 text-muted transition-transform group-data-[state=open]:rotate-180"
        />
      </button>
    </PopoverTrigger>

    <PopoverContent
      align="end"
      class="w-auto overflow-hidden !max-h-none !p-0"
    >
      <div class="flex flex-col sm:flex-row">
        <!-- Presets -->
        <div class="flex shrink-0 flex-col gap-0.5 border-b border-popover-border p-2 sm:w-44 sm:border-r sm:border-b-0">
          <button
            v-for="preset in presets"
            :key="preset.key"
            type="button"
            class="flex cursor-pointer items-center justify-between rounded-md px-3 py-1.5 text-start text-sm font-semibold transition-colors hover:bg-elevated"
            :class="
              activePreset === preset.key ? 'bg-elevated text-accent' : 'text-primary'
            "
            @click="applyPreset(preset)"
          >
            <span>{{ $t(`labels.dateRange.presets.${preset.key}`) }}</span>
            <Icon
              v-if="activePreset === preset.key"
              name="lucide:check"
              class="shrink-0"
            />
          </button>
        </div>

        <!-- Calendar + fields -->
        <div class="p-3">
          <div class="space-y-2 pb-3">
            <div class="flex items-center gap-2">
              <span class="w-9 text-sm font-semibold text-muted">{{ $t('labels.dateRange.from') }}</span>
              <DateField
                v-model="startDate"
                :locale="locale"
                :aria-label="$t('labels.dateRange.fromDate')"
              />
              <TimeField
                v-model="startTime"
                :locale="locale"
                :aria-label="$t('labels.dateRange.fromTime')"
              />
            </div>
            <div class="flex items-center gap-2">
              <span class="w-9 text-sm font-semibold text-muted">{{ $t('labels.dateRange.to') }}</span>
              <DateField
                v-model="endDate"
                :locale="locale"
                :aria-label="$t('labels.dateRange.toDate')"
              />
              <TimeField
                v-model="endTime"
                :locale="locale"
                :aria-label="$t('labels.dateRange.toTime')"
              />
            </div>
          </div>

          <RangeCalendar
            :model-value="range"
            :locale="locale"
            @update:model-value="onCalendarUpdate"
          />

          <div class="mt-1 flex items-center justify-between border-t border-popover-border pt-3">
            <Button
              variant="link"
              size="sm"
              @click="clearSelection"
            >
              {{ $t('labels.dateRange.clear') }}
            </Button>
            <div class="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                @click="onOpenChange(false)"
              >
                {{ $t('labels.dateRange.cancel') }}
              </Button>
              <Button
                variant="primary"
                size="sm"
                @click="apply"
              >
                {{ $t('labels.dateRange.apply') }}
              </Button>
            </div>
          </div>
        </div>
      </div>
    </PopoverContent>
  </Popover>
</template>
