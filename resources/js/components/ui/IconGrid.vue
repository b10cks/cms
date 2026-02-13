<script setup lang="ts">
import Icon from '~/components/Icon.vue'

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '~/components/ui/dropdown-menu'
import { ListboxContent, ListboxGroup, ListboxGroupLabel, ListboxItem, ListboxRoot } from 'reka-ui'

import { Button } from '~/components/ui/button'
import iconList from './iconlist.json'

defineProps<{
  disabled?: boolean
}>()

const selectedIcon = defineModel<string | null>()
const isDialogOpen = ref(false)

function selectIcon(icon: string) {
  selectedIcon.value = icon
  isDialogOpen.value = false
}
</script>

<template>
  <DropdownMenu v-model="isDialogOpen">
    <DropdownMenuTrigger as-child>
      <Button
        variant="outline"
        type="button"
        class="px-3!"
        :disabled="disabled"
      >
        <Icon
          v-if="selectedIcon"
          :name="`lucide:${selectedIcon}`"
        />
        <span
          v-else
          class="h-4 w-4 rounded bg-input"
        />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent class="max-h-80 overflow-y-auto sm:max-w-[700px]">
      <ListboxRoot
        :model-value="selectedIcon"
        @update:model-value="selectIcon"
      >
        <ListboxContent class="">
          <ListboxGroup
            v-for="(group, i) in iconList"
            :key="i"
          >
            <ListboxGroupLabel
              class="py-1.5 text-xs font-semibold tracking-widest text-muted uppercase select-none"
              >{{ group.title }}
            </ListboxGroupLabel>
            <div class="grid grid-cols-9">
              <ListboxItem
                v-for="option in group.items"
                :key="option"
                :value="option"
                class="rounded-md p-1.5 hover:bg-background"
                :title="option"
              >
                <Icon :name="`lucide:${option}`" />
              </ListboxItem>
            </div>
          </ListboxGroup>
        </ListboxContent>
      </ListboxRoot>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
