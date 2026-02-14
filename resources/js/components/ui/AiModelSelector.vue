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
const { toggleFavourite, setModel } = useAiSettings(toRef(props, 'spaceId'))

const { data: groupedModels, isLoading, error, refetch } = useModelsQuery()

const flatModels = computed<AiModel[]>(() => {
  if (!groupedModels.value) return []
  return Object.values(groupedModels.value).flat()
})

const favouriteModels = computed<AiModel[]>(() => {
  return flatModels.value.filter((m) => m.is_favourite)
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

const handleToggleFavourite = async (model: AiModel, event: Event) => {
  event.stopPropagation()
  event.preventDefault()
  try {
    await toggleFavourite(model.full_id)
  } catch {
    toast.error(t('components.aiModelSelector.favouriteError'))
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
          v-if="selectedModel"
          class="flex items-center gap-2"
        >
          <span class="truncate">{{ selectedModel.name }}</span>
          <Badge
            v-if="selectedModel.is_favourite"
            variant="secondary"
            size="xs"
            class="shrink-0"
          >
            <Icon
              name="lucide:star"
              class="h-3 w-3 fill-yellow-400 text-yellow-400"
            />
          </Badge>
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
          v-if="showFavourites && favouriteModels.length > 0"
        >
          <SelectLabel class="px-2 py-1.5 text-xs font-medium text-muted">
            {{ t('components.aiModelSelector.favourites') }}
          </SelectLabel>
          <SelectItem
            v-for="model in favouriteModels"
            :key="model.full_id"
            :value="model.full_id"
          >
            <div class="flex w-full items-center gap-2">
              <span class="flex-1 truncate font-medium">{{ model.name }}</span>
              <Badge
                variant="outline"
                size="xs"
                class="shrink-0"
              >
                {{ formatContextWindow(model.context_window.input) }}
              </Badge>
            </div>
          </SelectItem>
        </SelectGroup>

        <SelectGroup
          v-for="(models, driver) in groupedModels" :key="driver"
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
                <div class="flex gap-2">
                    <Icon
                    v-if="model.supports_vision"
                    name="lucide:eye" class="h-3 w-3" />
                    <Icon
                    v-if="model.supports_tools"
                    name="lucide:wrench" class="h-3 w-3" />
                </div>
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
