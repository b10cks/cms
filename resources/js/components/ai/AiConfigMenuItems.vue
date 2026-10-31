<script setup lang="ts">
import type { SpaceAiConfig } from '~/api/resources/ai'
import Icon from '~/components/Icon.vue'
import { DropdownMenuItem } from '~/components/ui/dropdown-menu'
import { modelDisplayName, modelIcon } from '~/lib/modelIcons'

/**
 * AI configs as dropdown rows: mono model logo, name, the model in muted text
 * and a check on `selectedId`. Shared by the config picker and the split
 * buttons that run a feature with a chosen config.
 */
defineProps<{
  configs: SpaceAiConfig[]
  selectedId?: string | null
  disabled?: boolean
}>()

const emit = defineEmits<{ select: [configId: string] }>()
</script>

<template>
  <DropdownMenuItem
    v-for="config in configs"
    :key="config.id"
    :disabled="disabled"
    class="cursor-pointer gap-2.5 rounded-md px-2 py-1.5 font-normal focus:bg-muted-background! focus:text-foreground!"
    @select="emit('select', config.id)"
  >
    <Icon
      :name="modelIcon(config.driver, config.model)"
      class="shrink-0 text-muted"
    />
    <span class="min-w-0 flex-1">
      <span class="block truncate text-sm">{{ config.name }}</span>
      <span class="mt-0.5 block truncate text-sm text-muted">{{ config.model_info ? modelDisplayName(config.model_info.name) : config.model }}</span>
    </span>
    <Icon
      name="lucide:check"
      class="size-3.5 shrink-0"
      :class="config.id !== selectedId && 'invisible'"
    />
  </DropdownMenuItem>
  <p
    v-if="configs.length === 0"
    class="px-2 py-3 text-center text-sm text-muted"
  >
    {{ $t('components.aiConfigSelector.noConfigs') }}
  </p>
</template>
