<script setup lang="ts">
import { SelectItem, SelectTrigger } from 'reka-ui'

import { Select, SelectContent, SelectGroup } from '~/components/ui/select'

import colors from './colors.json'

const selectedColor = defineModel<string | null>()

// The swatch is decorative, so the palette's own copy is what names the trigger.
const selectedLabel = computed(
  () => colors.find(({ value }) => value === (selectedColor.value ?? null))?.label ?? 'None'
)
</script>

<template>
  <Select v-model="selectedColor">
    <SelectTrigger
      class="flex cursor-pointer items-center justify-between gap-2 rounded-md border border-input px-2 py-2 text-start text-sm font-semibold shadow-sm ring-offset-background hover:bg-input focus:ring-1 focus:ring-ring focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 data-[placeholder]:text-muted [&>span]:truncate"
      :aria-label="selectedLabel"
    >
      <span
        class="h-4 w-4 rounded"
        :class="selectedColor ? '' : 'border border-dashed border-input-border'"
        :style="selectedColor ? `background-color: ${selectedColor}` : undefined"
      />
    </SelectTrigger>
    <SelectContent>
      <SelectGroup>
        <div class="grid grid-cols-8">
          <SelectItem
            v-for="{ value, label } in colors"
            :key="String(value)"
            class="relative flex items-center rounded-sm p-2 text-sm outline-none focus:bg-accent focus:text-primary data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
            :value="value"
            :title="label"
          >
            <div
              class="h-4 w-4 rounded"
              :class="value ? '' : 'border border-dashed border-input-border'"
              :style="value ? `background-color: ${value}` : undefined"
            />
            <span class="sr-only">{{ label }}</span>
          </SelectItem>
        </div>
      </SelectGroup>
    </SelectContent>
  </Select>
</template>
