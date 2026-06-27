<script setup lang="ts">
import type { RangeCalendarRootEmits, RangeCalendarRootProps } from 'reka-ui'
import {
  RangeCalendarCell,
  RangeCalendarCellTrigger,
  RangeCalendarGrid,
  RangeCalendarGridBody,
  RangeCalendarGridHead,
  RangeCalendarGridRow,
  RangeCalendarHeadCell,
  RangeCalendarHeader,
  RangeCalendarHeading,
  RangeCalendarNext,
  RangeCalendarPrev,
  RangeCalendarRoot,
  useForwardPropsEmits,
} from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'
import Icon from '~/components/Icon.vue'

const props = withDefaults(
  defineProps<RangeCalendarRootProps & { class?: HTMLAttributes['class'] }>(),
  {
    fixedWeeks: true,
    weekdayFormat: 'short',
  }
)
const emits = defineEmits<RangeCalendarRootEmits>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <RangeCalendarRoot
    v-slot="{ grid, weekDays }"
    v-bind="forwarded"
    :class="cn('select-none', props.class)"
  >
    <RangeCalendarHeader class="flex items-center justify-between pb-3">
      <RangeCalendarPrev
        class="inline-flex size-7 items-center justify-center rounded-md text-muted transition-colors hover:bg-elevated hover:text-primary focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
      >
        <Icon name="lucide:chevron-left" />
      </RangeCalendarPrev>
      <RangeCalendarHeading class="text-sm font-semibold text-primary" />
      <RangeCalendarNext
        class="inline-flex size-7 items-center justify-center rounded-md text-muted transition-colors hover:bg-elevated hover:text-primary focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
      >
        <Icon name="lucide:chevron-right" />
      </RangeCalendarNext>
    </RangeCalendarHeader>

    <div class="flex flex-col gap-4 sm:flex-row">
      <RangeCalendarGrid
        v-for="month in grid"
        :key="month.value.toString()"
        class="w-full border-collapse"
      >
        <RangeCalendarGridHead>
          <RangeCalendarGridRow class="flex w-full">
            <RangeCalendarHeadCell
              v-for="day in weekDays"
              :key="day"
              class="w-9 text-xs font-normal text-muted"
            >
              {{ day }}
            </RangeCalendarHeadCell>
          </RangeCalendarGridRow>
        </RangeCalendarGridHead>
        <RangeCalendarGridBody>
          <RangeCalendarGridRow
            v-for="(weekDates, index) in month.rows"
            :key="`weekDate-${index}`"
            class="flex w-full"
          >
            <RangeCalendarCell
              v-for="weekDate in weekDates"
              :key="weekDate.toString()"
              :date="weekDate"
              class="relative size-9 p-0 text-center text-sm focus-within:relative focus-within:z-20 [&:has([data-selected])]:bg-accent/15 [&:has([data-selection-end])]:rounded-r-md [&:has([data-selection-start])]:rounded-l-md [&:has([data-highlighted])]:bg-accent/15"
            >
              <RangeCalendarCellTrigger
                :day="weekDate"
                :month="month.value"
                class="relative inline-flex size-9 items-center justify-center rounded-md p-0 text-sm font-normal text-primary outline-none transition-colors hover:bg-elevated focus-visible:ring-1 focus-visible:ring-ring data-[outside-view]:text-muted/40 data-[disabled]:pointer-events-none data-[disabled]:text-muted/30 data-[unavailable]:pointer-events-none data-[unavailable]:text-muted/30 data-[unavailable]:line-through data-[selected]:rounded-none data-[today]:font-semibold data-[today]:text-accent data-[selection-start]:rounded-md data-[selection-start]:bg-accent data-[selection-start]:text-white data-[selection-start]:hover:bg-accent-hover data-[selection-end]:rounded-md data-[selection-end]:bg-accent data-[selection-end]:text-white data-[selection-end]:hover:bg-accent-hover data-[selection-start]:data-[today]:text-white data-[selection-end]:data-[today]:text-white"
              />
            </RangeCalendarCell>
          </RangeCalendarGridRow>
        </RangeCalendarGridBody>
      </RangeCalendarGrid>
    </div>
  </RangeCalendarRoot>
</template>
