<script setup lang="ts">
import { Badge } from '~/components/ui/badge'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { Spinner } from '~/components/ui/spinner'
import { useAiConfigs } from '~/composables/useAiModels'

const modelValue = defineModel<string | null>()

const props = defineProps<{
  spaceId: string
  error?: string
}>()

const { useAiConfigsQuery } = useAiConfigs(toRef(props, 'spaceId'))
const { data: configs, isLoading } = useAiConfigsQuery()

const selectedConfig = computed(() => {
  if (!modelValue.value || !configs.value) return null
  return configs.value.find((c) => c.id === modelValue.value)
})

const defaultConfig = computed(() => {
  return configs.value?.find((c) => c.is_default) ?? null
})

watch(
  () => defaultConfig.value,
  (config) => {
    if (config && !modelValue.value) {
      modelValue.value = config.id
    }
  },
  { immediate: true }
)
</script>

<template>
  <Select v-model="modelValue">
    <SelectTrigger :class="{ 'border-warning': error }">
      <SelectValue>
        <div
          v-if="selectedConfig"
          class="flex items-center gap-2"
        >
          <span class="font-medium">{{ selectedConfig.name }}</span>
        </div>
        <span
          v-else
          class="text-muted-foreground"
        >
          {{ $t('components.aiConfigSelector.selectConfig') }}
        </span>
      </SelectValue>
    </SelectTrigger>
    <SelectContent>
      <SelectGroup v-if="!isLoading && configs && configs.length > 0">
        <SelectLabel>{{ $t('components.aiConfigSelector.availableConfigs') }}</SelectLabel>
        <SelectItem
          v-for="config in configs"
          :key="config.id"
          :value="config.id"
          class="cursor-pointer"
        >
          <div class="flex flex-col gap-0.5">
            <div class="flex items-center">
              <span class="font-medium">{{ config.name }}</span>
              <Badge
                v-if="config.is_default"
                class="ml-auto"
                size="xs"
              >
                {{ $t('components.aiConfigSelector.default') }}
              </Badge>
            </div>
            <div class="flex items-center gap-2 text-xs text-muted">
              <span>{{ config.driver }}:{{ config.model }}</span>
              <span>•</span>
              <span>T: {{ config.temperature }}</span>
              <span>•</span>
              <span>{{ (config.max_tokens / 1000).toFixed(0) }}k</span>
            </div>
          </div>
        </SelectItem>
      </SelectGroup>
      <div
        v-else-if="isLoading"
        class="flex items-center justify-center gap-2 p-4 text-sm text-muted"
      >
        <Spinner />
        {{ $t('components.aiConfigSelector.loading') }}
      </div>
      <div
        v-else
        class="p-4 text-center text-sm text-muted-foreground"
      >
        <p>{{ $t('components.aiConfigSelector.noConfigs') }}</p>
      </div>
    </SelectContent>
  </Select>
</template>
