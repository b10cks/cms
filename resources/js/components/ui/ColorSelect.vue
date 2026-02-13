<script setup lang="ts">
import { Select, SelectContent, SelectGroup } from '~/components/ui/select'
import { SelectItem, SelectTrigger } from 'reka-ui'
import colors from './colors.json'

const selectedColor = defineModel<string | null>()
</script>

<template>
  <Select v-model="selectedColor">
    <SelectTrigger
      class="flex cursor-pointer items-center justify-between gap-2 rounded-md border border-input px-3 py-2 text-start text-sm font-semibold shadow-sm ring-offset-background hover:bg-input focus:ring-1 focus:ring-ring focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 data-[placeholder]:text-muted [&>span]:truncate"
    >
      <span
        class="h-4 w-4 rounded"
        :style="`background-color: ${selectedColor}`"
      />
    </SelectTrigger>
    <SelectContent>
      <SelectGroup>
        <div class="grid grid-cols-8">
          <SelectItem
            v-for="{ value, label } in colors"
            :key="value"
            class="relative flex items-center rounded-sm p-2 text-sm outline-none focus:bg-accent focus:text-primary data-[disabled]:pointer-events-none data-[disabled]:opacity-50"
            :value="value"
            :title="label"
          >
            <div
              class="h-4 w-4 rounded"
              :style="`background-color: ${value}`"
            />
          </SelectItem>
        </div>
      </SelectGroup>
    </SelectContent>
  </Select>
</template>
