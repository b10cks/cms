<script lang="ts" setup>
import { SelectItem, SelectTrigger } from 'reka-ui'

import Icon from '~/components/Icon.vue'
import { Select, SelectContent, SelectGroup, SelectLabel } from '~/components/ui/select'

import iconList from './iconlist.json'

defineProps<{
  color?: string
}>()

const selectedIcon = defineModel<string | null>()
</script>

<template>
  <Select v-model="selectedIcon">
    <SelectTrigger
      class="flex cursor-pointer items-center justify-between rounded-md border border-input px-2 py-1 font-semibold shadow-sm ring-offset-background data-placeholder:text-muted focus:outline-none focus:ring-1 focus:ring-ring hover:bg-input disabled:cursor-not-allowed disabled:opacity-50 [&>span]:truncate text-start gap-2"
    >
      <Icon
        v-if="selectedIcon"
        :name="`lucide:${selectedIcon}`"
        :style="{ color: color || 'inherit' }"
      />
      <span
        v-else
        class="h-4 w-4 rounded bg-input"
      />
    </SelectTrigger>
    <SelectContent class="sm:max-w-md max-h-80 overflow-y-auto">
      <SelectGroup
        v-for="(group, i) in iconList"
        :key="i"
      >
        <SelectLabel
          class="py-1.5 text-xs tracking-widest text-muted uppercase select-none font-semibold"
          >{{ group.title }}
        </SelectLabel>
        <div
          :style="{ color: color || 'inherit' }"
          class="grid grid-cols-8"
        >
          <SelectItem
            v-for="option in group.items"
            :key="`${i}-${option}`"
            :title="option"
            :value="option"
            class="p-1.5 hover:bg-background rounded-md"
          >
            <Icon :name="`lucide:${option}`" />
          </SelectItem>
        </div>
      </SelectGroup>
    </SelectContent>
  </Select>
</template>
