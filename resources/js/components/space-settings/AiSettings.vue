<script setup lang="ts">
import { toast } from 'vue-sonner'
import { Badge } from '~/components/ui/badge'
import { Button } from '~/components/ui/button'
import {
  Card,
  CardContent,
  CardDescription,
  CardFooter,
  CardHeader,
  CardTitle,
} from '~/components/ui/card'
import { Progress } from '~/components/ui/progress'
import { Switch } from '~/components/ui/switch'

import AiModelSelector from '~/components/ui/AiModelSelector.vue'
import { useAiModels, useAiSettings } from '~/composables/useAiModels'

const props = defineProps<{ space: SpaceResource }>()

const { t } = useI18n()
const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace, isPending: isUpdating } = useUpdateSpaceMutation()
const { client: apiClient } = useApiClient()

const { useModelsQuery } = useAiModels(computed(() => props.space.id))
const { useAiSettingsQuery } = useAiSettings(computed(() => props.space.id))

const { data: groupedModels, isLoading: isLoadingModels } = useModelsQuery()
const { data: aiSettings } = useAiSettingsQuery()

const enableAI = ref(aiSettings.value?.enabled ?? true)
const selectedModelId = ref<string | null>(aiSettings.value?.model ?? null)

const aiUsage = ref<SpaceAiUsageResource | null>(null)
const isLoadingUsage = ref(false)
const usageError = ref<string | null>(null)

const usagePercentage = computed(() => {
  if (!aiUsage.value || aiUsage.value.max_tokens === 0) return 0
  return Math.round((aiUsage.value.used_tokens / aiUsage.value.max_tokens) * 100)
})

const resetDate = computed(() => {
  if (!aiUsage.value?.valid_to) return null
  try {
    return new Date(aiUsage.value.valid_to).toLocaleDateString()
  } catch {
    return null
  }
})

const flatModels = computed(() => {
  if (!groupedModels.value) return []
  return Object.values(groupedModels.value).flat()
})

const selectedModel = computed(() => {
  return flatModels.value.find((m) => m.full_id === selectedModelId.value) ?? null
})

const fetchAiUsage = async () => {
  isLoadingUsage.value = true
  usageError.value = null

  try {
    const response = await apiClient.get<{ data: SpaceAiUsageResource }>(
      `/mgmt/v1/spaces/${props.space.id}/ai-usage`
    )
    aiUsage.value = response.data
  } catch (error: any) {
    usageError.value = error.message || 'Failed to load AI usage data'
  } finally {
    isLoadingUsage.value = false
  }
}

onMounted(() => {
  fetchAiUsage()
})

watch(
  () => aiSettings.value,
  (newSettings) => {
    if (newSettings) {
      enableAI.value = newSettings.enabled
      selectedModelId.value = newSettings.model
    }
  },
  { immediate: true }
)

const saveSettings = async () => {
  try {
    await updateSpace({
      id: props.space.id,
      payload: {
        settings: {
          ...props.space.settings,
          ai: {
            ...props.space.settings?.ai,
            enabled: enableAI.value,
            model: selectedModelId.value,
          },
        },
      },
    })
    toast.success(t('components.aiSettings.saveSuccess'))
  } catch (error: any) {
    toast.error(t('components.aiSettings.saveError'))
  }
}

const handleModelSelect = (modelId: string | null) => {
  selectedModelId.value = modelId
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
</script>

<template>
  <Card variant="outline">
    <CardHeader>
      <CardTitle>{{ $t('labels.settings.ai.title') }}</CardTitle>
      <CardDescription>{{ $t('labels.settings.ai.description') }}</CardDescription>
    </CardHeader>
    <CardContent class="space-y-6">
      <div class="space-y-2">
        <div class="flex items-center justify-between">
          <label
            for="enable-ai"
            class="text-sm font-medium"
          >
            {{ $t('labels.settings.ai.enableAIFeatures') }}
          </label>
          <Switch
            id="enable-ai"
            v-model:checked="enableAI"
            aria-label="Enable AI Features"
          />
        </div>
        <p class="text-xs text-muted">
          {{ $t('labels.settings.ai.featuresDescription') }}
        </p>
      </div>

      <div
        v-if="enableAI"
        class="space-y-4"
      >
        <div class="space-y-2">
          <label class="text-sm font-medium">
            {{ $t('labels.settings.ai.modelSelection') }}
          </label>
          <p class="text-xs text-muted">
            {{ $t('labels.settings.ai.modelSelectionDescription') }}
          </p>
          <AiModelSelector
            v-model="selectedModelId"
            :space-id="space.id"
            :show-favourites="true"
            :show-costs="true"
            @update:model-value="handleModelSelect"
          />
        </div>

        <div
          v-if="selectedModel"
          class="rounded-lg bg-surface p-4"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="space-y-2">
              <div class="flex items-center gap-2">
                <h4 class="text-sm font-semibold">{{ selectedModel.name }}</h4>
                <Badge
                  v-if="selectedModel.is_favourite"
                  variant="secondary"
                  size="xs"
                >
                  <Icon
                    name="lucide:star"
                    class="h-3 w-3 fill-yellow-400 text-yellow-400"
                  />
                </Badge>
              </div>
              <p
                v-if="selectedModel.description"
                class="text-muted-foreground text-xs"
              >
                {{ selectedModel.description }}
              </p>
              <div class="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                <span class="flex items-center gap-1">
                  <Icon
                    name="lucide:gauge"
                    class="h-3 w-3"
                  />
                  {{ $t('labels.settings.ai.contextWindow') }}:
                  {{ formatContextWindow(selectedModel.context_window.input) }}
                </span>
                <span class="flex items-center gap-1">
                  <Icon
                    name="lucide:server"
                    class="h-3 w-3"
                  />
                  {{ $t('labels.settings.ai.provider') }}:
                  {{ selectedModel.driver }}
                </span>
              </div>
              <div class="flex gap-1">
                <Badge
                  v-for="cap in selectedModel.capabilities"
                  :key="cap"
                  variant="outline"
                  size="xs"
                >
                  {{ cap }}
                </Badge>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div
        v-if="enableAI"
        class="space-y-4"
      >
        <div>
          <h4 class="font-medium">{{ $t('labels.settings.ai.usage') }}</h4>
          <p class="text-sm text-muted">{{ $t('labels.settings.ai.usageDescription') }}</p>
        </div>

        <div
          v-if="isLoadingUsage"
          class="text-muted-foreground flex items-center gap-2 text-sm"
        >
          <div
            class="h-4 w-4 animate-spin rounded-full border-2 border-primary border-t-transparent"
          />
          {{ $t('labels.settings.ai.loadingUsage') }}
        </div>

        <div
          v-else-if="usageError"
          class="text-sm text-destructive"
        >
          {{ usageError }}
          <Button
            variant="link"
            size="sm"
            class="ml-2 h-auto p-0"
            @click="fetchAiUsage"
          >
            {{ $t('actions.retry') }}
          </Button>
        </div>

        <div
          v-else-if="aiUsage"
          class="space-y-2"
        >
          <div class="flex items-center justify-between">
            <span class="text-sm">
              {{ aiUsage.used_tokens.toLocaleString() }} {{ $t('labels.settings.ai.of') }}
              {{ aiUsage.max_tokens.toLocaleString() }} {{ $t('labels.settings.ai.tokensUsed') }}
            </span>
            <span class="text-sm text-muted">{{ usagePercentage }}%</span>
          </div>
          <Progress
            :model-value="usagePercentage"
            class="h-2"
          />
          <p
            v-if="resetDate"
            class="text-sm text-muted"
          >
            {{ $t('labels.settings.ai.resetInfo', { date: resetDate }) }}
          </p>
        </div>

        <div
          v-else
          class="text-muted-foreground text-sm"
        >
          {{ $t('labels.settings.ai.noUsageData') }}
        </div>
      </div>
    </CardContent>
    <CardFooter>
      <Button
        variant="primary"
        :disabled="isUpdating"
        @click="saveSettings"
      >
        {{ isUpdating ? $t('actions.saving') : $t('actions.saveChanges') }}
      </Button>
    </CardFooter>
  </Card>
</template>
