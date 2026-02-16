<script setup lang="ts">
import { toast } from 'vue-sonner'
import type { SpaceAiConfig } from '~/api/resources/ai'
import type { AiModel } from '~/composables/useAiModels'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '~/components/ui/dialog'
import InputField from '~/components/ui/form/InputField.vue'
import TextField from '~/components/ui/form/TextField.vue'
import { Switch } from '~/components/ui/switch'
import AiModelSelector from '~/components/ui/AiModelSelector.vue'

const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{
  config: SpaceAiConfig | null
  spaceId: string
  groupedModels: Record<string, AiModel[]>
  isSubmitting: boolean
}>()

const emit = defineEmits<{
  submit: [payload: Partial<SpaceAiConfig>]
}>()

const { t } = useI18n()

const formData = ref({
  name: '',
  driver: '',
  model: '',
  system_prompt: '',
  temperature: 0.7,
  max_tokens: 32768,
  is_default: false,
})

const flatModels = computed(() => {
  if (!props.groupedModels) return []
  return Object.values(props.groupedModels).flat()
})

const selectedModel = computed(() => {
  if (!formData.value.driver || !formData.value.model) return null
  const fullId = `${formData.value.driver}:${formData.value.model}`
  return flatModels.value.find((m) => m.full_id === fullId) ?? null
})

const isFormValid = computed(() => {
  return (
    formData.value.name.trim().length > 0 &&
    formData.value.driver.trim().length > 0 &&
    formData.value.model.trim().length > 0
  )
})

const formatContextWindow = (input: number): string => {
  if (input >= 1000000) return `${(input / 1000000).toFixed(1)}M`
  if (input >= 1000) return `${(input / 1000).toFixed(0)}k`
  return input.toString()
}

const handleModelSelect = (modelId: string | null | undefined) => {
  if (!modelId) {
    formData.value.driver = ''
    formData.value.model = ''
    return
  }

  const [driver, model] = modelId.split(':')
  formData.value.driver = driver
  formData.value.model = model
}

const handleSubmit = () => {
  if (!isFormValid.value) return

  const payload = {
    ...formData.value,
    temperature: Number(formData.value.temperature),
    max_tokens: Number(formData.value.max_tokens),
  }

  emit('submit', payload)
}

watch(
  () => props.config,
  (config) => {
    if (config) {
      formData.value = {
        name: config.name,
        driver: config.driver,
        model: config.model,
        system_prompt: config.system_prompt ?? '',
        temperature: config.temperature,
        max_tokens: config.max_tokens,
        is_default: config.is_default,
      }
    } else {
      formData.value = {
        name: '',
        driver: '',
        model: '',
        system_prompt: '',
        temperature: 0.7,
        max_tokens: 32768,
        is_default: false,
      }
    }
  },
  { immediate: true }
)
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-2xl">
      <DialogHeader>
        <DialogTitle>
          <template v-if="config">
            {{ $t('labels.settings.ai.editConfig') }}: {{ config.name }}
          </template>
          <template v-else>
            {{ $t('labels.settings.ai.createConfig') }}
          </template>
        </DialogTitle>
        <DialogDescription>
          {{ $t('labels.settings.ai.configDialogDescription') }}
        </DialogDescription>
      </DialogHeader>

      <div class="space-y-4 py-4">
        <InputField
          v-model="formData.name"
          name="name"
          :label="$t('labels.settings.ai.configName') + ' *'"
          :placeholder="$t('labels.settings.ai.configNamePlaceholder')"
          required
        />

        <div class="space-y-2">
          <label class="text-sm font-medium">
            {{ $t('labels.settings.ai.selectModel') }} *
          </label>
          <AiModelSelector
            :key="`${formData.driver}:${formData.model}`"
            :model-value="
              formData.driver && formData.model ? `${formData.driver}:${formData.model}` : null
            "
            :space-id="spaceId"
            :show-favourites="true"
            :show-costs="true"
            @update:model-value="handleModelSelect"
          />
        </div>

        <div
          v-if="selectedModel"
          class="rounded-lg bg-surface p-3"
        >
          <div class="space-y-1">
            <div class="flex items-center gap-2">
              <h4 class="text-sm font-semibold">{{ selectedModel.name }}</h4>
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
                {{ formatContextWindow(selectedModel.context_window.input) }}
              </span>
              <span class="flex items-center gap-1">
                <Icon
                  name="lucide:server"
                  class="h-3 w-3"
                />
                {{ selectedModel.driver }}
              </span>
            </div>
          </div>
        </div>

        <TextField
          v-model="formData.system_prompt"
          name="system-prompt"
          :label="$t('labels.settings.ai.systemPrompt')"
          :description="$t('labels.settings.ai.systemPromptDescription')"
          :placeholder="$t('labels.settings.ai.systemPromptPlaceholder')"
          :rows="4"
        />

        <div class="grid w-full items-center gap-2">
          <div class="flex items-center justify-between">
            <label class="text-sm font-medium">
              {{ $t('labels.settings.ai.temperature') }}
            </label>
            <span class="text-sm text-muted-foreground">{{ formData.temperature.toFixed(2) }}</span>
          </div>
          <input
            v-model="formData.temperature"
            type="range"
            :min="0"
            :max="2"
            :step="0.05"
            class="h-2 w-full cursor-pointer appearance-none rounded-lg bg-surface accent-primary"
          />
          <div class="flex justify-between text-xs text-muted-foreground">
            <span>{{ $t('labels.settings.ai.precise') }}</span>
            <span>{{ $t('labels.settings.ai.creative') }}</span>
          </div>
        </div>

        <InputField
          v-model="formData.max_tokens"
          name="max-tokens"
          type="number"
          :label="$t('labels.settings.ai.maxTokens')"
          :description="$t('labels.settings.ai.maxTokensDescription')"
        />

        <div class="flex items-center gap-2">
          <Switch
            v-model="formData.is_default"
            id="is-default"
          />
          <label
            for="is-default"
            class="text-sm font-medium"
          >
            {{ $t('labels.settings.ai.setAsDefault') }}
          </label>
        </div>
      </div>

      <DialogFooter>
        <DialogClose as-child>
          <Button
            variant="ghost"
            :disabled="isSubmitting"
          >
            {{ $t('actions.cancel') }}
          </Button>
        </DialogClose>
        <Button
          variant="primary"
          :disabled="!isFormValid || isSubmitting"
          @click="handleSubmit"
        >
          <Icon
            v-if="isSubmitting"
            name="lucide:loader"
            class="h-4 w-4 animate-spin"
          />
          {{ isSubmitting ? $t('actions.saving') : $t('actions.save') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
