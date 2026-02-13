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
import { FormField } from '~/components/ui/form'
import { Progress } from '~/components/ui/progress'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { Switch } from '~/components/ui/switch'

// Types based on the Laravel resource
interface AiModelResource {
  id: string
  name: string
  model: string
  tags: string[]
  token_multiplier: number
  is_free: boolean
  is_active: boolean
  description: string
  provider: string
  created_at: string
  updated_at: string
}

interface AiModelsResponse {
  data: AiModelResource[]
}

interface SpaceAiUsageResource {
  id: string
  max_tokens: number
  used_tokens: number
  valid_to: string
  created_at: string
  updated_at: string
}

interface AiUsageResponse {
  data: SpaceAiUsageResource
}

const props = defineProps<{ space: SpaceResource }>()

const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace, isPending: isUpdating } = useUpdateSpaceMutation()
const { client: apiClient } = useApiClient()

// State management
const enableAI = ref(props.space.settings?.ai?.enabled ?? true)
const selectedModelId = ref(props.space.settings?.ai?.model ?? null)
const availableModels = ref<AiModelResource[]>([])
const isLoadingModels = ref(false)
const modelError = ref<string | null>(null)

// Usage tracking with real data
const aiUsage = ref<SpaceAiUsageResource | null>(null)
const isLoadingUsage = ref(false)
const usageError = ref<string | null>(null)
const usagePercentage = computed(() => {
  if (!aiUsage.value || aiUsage.value.max_tokens === 0) return 0
  return Math.round((aiUsage.value.used_tokens / aiUsage.value.max_tokens) * 100)
})

// Format valid_to date for display
const resetDate = computed(() => {
  if (!aiUsage.value?.valid_to) return null
  try {
    return new Date(aiUsage.value.valid_to).toLocaleDateString()
  } catch {
    return null
  }
})

// Computed properties
const selectedModel = computed(() => {
  return availableModels.value.find((model) => model.id === selectedModelId.value) || null
})

const activeModels = computed(() => {
  return availableModels.value.filter((model) => model.is_active)
})

// Fetch AI usage data
const fetchAiUsage = async () => {
  isLoadingUsage.value = true
  usageError.value = null

  try {
    const response = await apiClient.get<AiUsageResponse>(
      `/mgmt/v1/spaces/${props.space.id}/ai-usage`
    )
    aiUsage.value = response.data
  } catch (error: any) {
    usageError.value = error.message || 'Failed to load AI usage data'
    console.error('Failed to fetch AI usage:', error)
  } finally {
    isLoadingUsage.value = false
  }
}

// Fetch available AI models
const fetchAiModels = async () => {
  isLoadingModels.value = true
  modelError.value = null

  try {
    const response = await apiClient.get<AiModelsResponse>('/mgmt/v1/ai/available-models')
    availableModels.value = response.data || []

    // If no model is selected but we have available models, select the first active one
    if (!selectedModelId.value && activeModels.value.length > 0) {
      selectedModelId.value = activeModels.value[0].id
    }
  } catch (error: any) {
    modelError.value = error.message || 'Failed to load AI models'
    toast.error('Failed to load AI models')
  } finally {
    isLoadingModels.value = false
  }
}

// Lifecycle - fetch models on component mount
onMounted(() => {
  fetchAiModels()
  fetchAiUsage()
})

// Watch for space settings changes to sync local state
watch(
  () => props.space.settings?.ai,
  (newAiSettings) => {
    if (newAiSettings) {
      enableAI.value = newAiSettings.enabled ?? true
      selectedModelId.value = newAiSettings.model ?? null
    }
  },
  { immediate: true, deep: true }
)

// Save settings function
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
    toast.success('AI settings saved successfully')
  } catch (error: any) {
    toast.error('Failed to save AI settings')
  }
}

const handleModelSelect = (modelId: string) => {
  selectedModelId.value = modelId
}

const getModelDisplayValue = () => {
  if (!selectedModel.value) return 'Select AI model'
  return selectedModel.value.name
}

const formatTags = (tags: string[]) => {
  if (!tags || tags.length === 0) return ''
  return `[${tags.join(', ')}]`
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

      <!-- AI Model Selection -->
      <div
        v-if="enableAI"
        class="space-y-4"
      >
        <FormField
          name="aiModel"
          :label="$t('labels.settings.ai.modelSelection')"
          :description="$t('labels.settings.ai.modelSelectionDescription')"
          :error="modelError"
        >
          <Select
            :model-value="selectedModelId"
            :disabled="isLoadingModels || activeModels.length === 0"
            @update:model-value="handleModelSelect"
          >
            <SelectTrigger>
              <SelectValue :placeholder="$t('labels.settings.ai.selectModel')">
                {{ getModelDisplayValue() }}
              </SelectValue>
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="model in activeModels"
                :key="model.id"
                :value="model.id"
              >
                <div class="flex w-full flex-col gap-1 py-1">
                  <div class="flex items-center gap-2">
                    <span class="font-medium">{{ model.name }}</span>
                    <Badge
                      v-for="tag in model.tags"
                      :key="tag"
                      variant="secondary"
                      size="xs"
                    >
                      {{ $t(`labels.settings.ai.tags.${tag}`) }}
                    </Badge>
                  </div>
                  <div class="text-muted-foreground flex items-center gap-2 text-xs">
                    <span class="font-mono">{{ model.model }}</span>
                    <span class="ml-auto">{{ model.token_multiplier }}x</span>
                  </div>
                </div>
              </SelectItem>
            </SelectContent>
          </Select>
        </FormField>

        <div
          v-if="selectedModel"
          class="rounded-lg bg-surface p-3"
        >
          <div class="flex items-start justify-between gap-3">
            <div class="space-y-1">
              <div class="flex items-center gap-2">
                <h4 class="text-sm font-medium">{{ selectedModel.name }}</h4>
                <Badge
                  v-for="tag in selectedModel.tags"
                  :key="tag"
                  variant="secondary"
                  size="xs"
                >
                  {{ $t(`labels.settings.ai.tags.${tag}`) }}
                </Badge>
              </div>
              <p class="text-muted-foreground text-xs">{{ selectedModel.description }}</p>
              <div class="text-muted-foreground flex items-center gap-3 text-xs">
                <span class="font-mono">{{ selectedModel.model }}</span>
                <span>{{ $t('labels.settings.ai.provider') }}: {{ selectedModel.provider }}</span>
                <span
                  >{{ $t('labels.settings.ai.tokenMultiplier') }}:
                  {{ selectedModel.token_multiplier }}x</span
                >
              </div>
            </div>
          </div>
        </div>

        <!-- Loading State -->
        <div
          v-if="isLoadingModels"
          class="text-muted-foreground flex items-center gap-2 text-sm"
        >
          <div
            class="h-4 w-4 animate-spin rounded-full border-2 border-primary border-t-transparent"
          />
          {{ $t('labels.settings.ai.loadingModels') }}
        </div>

        <!-- Error State -->
        <div
          v-if="modelError && !isLoadingModels"
          class="text-sm text-destructive"
        >
          {{ modelError }}
          <Button
            variant="link"
            size="sm"
            class="ml-2 h-auto p-0"
            @click="fetchAiModels"
          >
            {{ $t('actions.retry') }}
          </Button>
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
            <Button
              variant="link"
              class="h-auto p-0 text-xs"
            >
              {{ $t('labels.settings.ai.upgradePlan') }}
            </Button>
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
