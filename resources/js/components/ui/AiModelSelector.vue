<script setup lang="ts">
import { toast } from 'vue-sonner'

import Icon from '~/components/Icon.vue'
import { Badge } from '~/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectScrollDownButton,
  SelectScrollUpButton,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { Skeleton } from '~/components/ui/skeleton'
import type { AiModel } from '~/composables/useAiModels'
import { useAiModels, useAiSettings } from '~/composables/useAiModels'

const modelValue = defineModel<string | null>()

const props = withDefaults(
  defineProps<{
    spaceId: string
    placeholder?: string
    showFavourites?: boolean
    showCosts?: boolean
    compact?: boolean
    autoSave?: boolean
  }>(),
  {
    placeholder: 'Select model',
    showFavourites: false,
    showCosts: true,
    compact: false,
    autoSave: false,
  }
)

const { t } = useI18n()
const { useModelsQuery } = useAiModels(toRef(props, 'spaceId'))
const { setModel } = useAiSettings(toRef(props, 'spaceId'))

const { data: groupedModels, isLoading, error, refetch } = useModelsQuery()

const flatModels = computed<AiModel[]>(() => {
  if (!groupedModels.value) return []
  return Object.values(groupedModels.value).flat()
})

const selectedModel = computed<AiModel | null>(() => {
  if (!modelValue.value) return null
  return flatModels.value.find((m) => m.full_id === modelValue.value) ?? null
})

const handleSelect = async (modelId: string) => {
  modelValue.value = modelId

  if (props.autoSave) {
    try {
      await setModel(modelId)
    } catch {
      toast.error(t('components.aiModelSelector.setModelError'))
    }
  }
}
const getDriverLabel = (driver: string): string => {
  return t(`components.aiModelSelector.drivers.${driver}`, driver)
}

const formatContextWindow = (input: number): string => {
  if (input >= 1000000) {
    return `${(input / 1000000).toFixed(1)}M`
  }
  if (input >= 1000) {
    return `${(input / 1000).toFixed(0)}k`
  }
  return input.toString()
}

const formatCost = (cost: number): string => {
  if (cost === 0) return 'Free'
  if (cost < 0.01) return `$${cost.toFixed(4)}`
  if (cost < 1) return `$${cost.toFixed(2)}`
  return `$${cost.toFixed(1)}`
}

const getModelCostLabel = (model: AiModel): string => {
  if (model.input_cost === 0 && model.output_cost === 0) return 'Free'
  return `${formatCost(model.input_cost)} / ${formatCost(model.output_cost)}`
}
</script>

<template>
  <Select
    :model-value="modelValue"
    @update:model-value="handleSelect"
  >
    <SelectTrigger>
      <SelectValue :placeholder="placeholder">
        <div
          v-if="modelValue"
          class="flex items-center gap-2"
        >
          <span class="truncate">{{ modelValue }}</span>
        </div>
      </SelectValue>
    </SelectTrigger>
    <SelectContent>
      <SelectScrollUpButton />
      <div v-if="isLoading">
        <div class="space-y-2 p-2">
          <Skeleton class="h-6 w-full" />
          <Skeleton class="h-6 w-full" />
          <Skeleton class="h-6 w-full" />
        </div>
      </div>

      <div
        v-else-if="error"
        class="p-4 text-center text-sm text-destructive"
      >
        {{ t('components.aiModelSelector.loadError') }}
        <button
          type="button"
          class="mt-2 block w-full text-primary underline"
          @click="refetch()"
        >
          {{ t('actions.retry') }}
        </button>
      </div>

      <template v-else-if="groupedModels">
        <SelectGroup
          v-for="(models, driver) in groupedModels"
          :key="driver"
        >
          <SelectLabel class="px-2 py-1.5 text-xs font-medium text-muted">
            {{ getDriverLabel(driver as string) }}
          </SelectLabel>
          <SelectItem
            v-for="model in models"
            :key="model.full_id"
            :value="model.full_id"
          >
            <div class="flex flex-col w-full gap-0.5">
              <div class="text-primary flex-1 truncate font-medium">{{ model.name }}</div>
              <div
                v-if="!compact"
                class="flex items-center gap-2 text-xs font-medium"
              >
                <span
                  v-if="showCosts"
                  class="flex items-center gap-1 opacity-60"
                >
                  {{ getModelCostLabel(model) }}
                </span>
              </div>
            </div>
          </SelectItem>
        </SelectGroup>
      </template>

      <div
        v-else
        class="p-4 text-center text-sm text-muted-foreground"
      >
        {{ t('components.aiModelSelector.noModels') }}
      </div>
      <SelectScrollDownButton />
    </SelectContent>
  </Select>
</template>
