<script setup lang="ts">
import type { CalendarRootEmits, CalendarRootProps } from 'reka-ui'
import {
  CalendarCell,
  CalendarCellTrigger,
  CalendarGrid,
  CalendarGridBody,
  CalendarGridHead,
  CalendarGridRow,
  CalendarHeadCell,
  CalendarHeader,
  CalendarHeading,
  CalendarNext,
  CalendarPrev,
  CalendarRoot,
  useForwardPropsEmits,
} from 'reka-ui'
import { computed, type HTMLAttributes } from 'vue'

import { cn } from '@/lib/utils'
import Icon from '~/components/Icon.vue'

const props = withDefaults(defineProps<CalendarRootProps & { class?: HTMLAttributes['class'] }>(), {
  fixedWeeks: true,
  weekdayFormat: 'short',
})
const emits = defineEmits<CalendarRootEmits>()

const delegatedProps = computed(() => {
  const { class: _, ...delegated } = props

  return delegated
})

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <CalendarRoot
    v-slot="{ grid, weekDays }"
    v-bind="forwarded"
    :class="cn('select-none', props.class)"
  >
    <CalendarHeader class="flex items-center justify-between pb-3">
      <CalendarPrev
        class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-muted transition-colors duration-100 hover:bg-state-hover hover:text-primary active:bg-state-selected focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
      >
        <Icon name="lucide:chevron-left" />
      </CalendarPrev>
      <CalendarHeading class="text-sm font-semibold text-primary" />
      <CalendarNext
        class="inline-flex size-7 cursor-pointer items-center justify-center rounded-md text-muted transition-colors duration-100 hover:bg-state-hover hover:text-primary active:bg-state-selected focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
      >
        <Icon name="lucide:chevron-right" />
      </CalendarNext>
    </CalendarHeader>

    <div class="flex flex-col gap-4 sm:flex-row">
      <CalendarGrid
        v-for="month in grid"
        :key="month.value.toString()"
        class="w-full border-collapse"
      >
        <CalendarGridHead>
          <CalendarGridRow class="flex w-full">
            <CalendarHeadCell
              v-for="day in weekDays"
              :key="day"
              class="w-9 text-sm font-normal text-muted"
            >
              {{ day }}
            </CalendarHeadCell>
          </CalendarGridRow>
        </CalendarGridHead>
        <CalendarGridBody>
          <CalendarGridRow
            v-for="(weekDates, index) in month.rows"
            :key="`weekDate-${index}`"
            class="flex w-full"
          >
            <CalendarCell
              v-for="weekDate in weekDates"
              :key="weekDate.toString()"
              :date="weekDate"
              class="relative size-9 p-0 text-center text-sm focus-within:relative focus-within:z-20"
            >
              <CalendarCellTrigger
                :day="weekDate"
                :month="month.value"
                class="relative inline-flex size-9 items-center justify-center cursor-pointer rounded-md p-0 text-sm font-normal text-primary outline-none transition-colors duration-100 hover:bg-state-hover focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring data-[outside-view]:text-muted data-[disabled]:pointer-events-none data-[disabled]:text-muted/30 data-[unavailable]:pointer-events-none data-[unavailable]:text-muted/30 data-[unavailable]:line-through data-[today]:font-semibold data-[today]:text-info-ink data-[selected]:bg-accent-fill data-[selected]:text-white data-[selected]:hover:bg-accent-fill-hover data-[selected]:data-[today]:text-white"
              />
            </CalendarCell>
          </CalendarGridRow>
        </CalendarGridBody>
      </CalendarGrid>
    </div>
  </CalendarRoot>
</template>
