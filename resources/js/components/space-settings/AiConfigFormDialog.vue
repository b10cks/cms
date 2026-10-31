<script setup lang="ts">
import { toast } from 'vue-sonner'

import {
  type AiReasoningEffort,
  DEFAULT_REASONING_EFFORT,
  type SpaceAiConfig,
  type SpaceAiConfigPayload,
} from '~/api/resources/ai'
import AiCapabilityIcons from '~/components/ai/AiCapabilityIcons.vue'
import AiModelPickerDialog from '~/components/ai/AiModelPickerDialog.vue'
import AiReasoningPicker from '~/components/ai/AiReasoningPicker.vue'
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
import { FormField, InputField, TextField } from '~/components/ui/form'
import { Switch } from '~/components/ui/switch'
import type { AiModel } from '~/composables/useAiModels'
import { modelDisplayName, modelIcon } from '~/lib/modelIcons'

const open = defineModel<boolean>('open', { required: true })

const props = defineProps<{
  config: SpaceAiConfig | null
  spaceId: string
  groupedModels: Record<string, AiModel[]>
  isSubmitting: boolean
  canSubmit?: boolean
}>()

const emit = defineEmits<{
  submit: [payload: SpaceAiConfigPayload]
}>()

const { t } = useI18n()

const emptyForm = () => ({
  name: '',
  driver: '',
  model: '',
  system_prompt: '',
  temperature: 0.7,
  max_tokens: 32768,
  reasoning_effort: DEFAULT_REASONING_EFFORT as AiReasoningEffort,
  is_default: false,
})

const formData = ref(emptyForm())
const pickerOpen = ref(false)

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

const handleModelSelect = (model: AiModel) => {
  formData.value.driver = model.driver
  formData.value.model = model.id
  if (model.reasoning_required && formData.value.reasoning_effort === 'off') formData.value.reasoning_effort = 'low'
}

const handleSubmit = () => {
  if (!isFormValid.value) return

  const payload: SpaceAiConfigPayload = {
    ...formData.value,
    temperature: Number(formData.value.temperature),
    max_tokens: Number(formData.value.max_tokens),
    reasoning_effort: selectedModel.value?.supports_reasoning ? formData.value.reasoning_effort : null,
  }

  emit('submit', payload)
}

watch(
  () => props.config,
  (config) => {
    formData.value = config
      ? {
          name: config.name,
          driver: config.driver,
          model: config.model,
          system_prompt: config.system_prompt ?? '',
          temperature: config.temperature,
          max_tokens: config.max_tokens,
          reasoning_effort: config.reasoning_effort ?? DEFAULT_REASONING_EFFORT,
          is_default: config.is_default,
        }
      : emptyForm()
  },
  { immediate: true }
)
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-w-2xl!">
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
          :label="$t('labels.settings.ai.configName')"
          :placeholder="$t('labels.settings.ai.configNamePlaceholder')"
          required
        />

        <FormField
          name="model"
          :label="$t('labels.settings.ai.selectModel')"
          required
        >
          <button
            id="model"
            type="button"
            class="flex h-10 w-full cursor-pointer items-center gap-2.5 rounded-lg bg-input px-3 text-left text-sm shadow-sm transition-colors hover:bg-input/80 focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
            @click="pickerOpen = true"
          >
            <template v-if="formData.model">
              <Icon
                :name="modelIcon(formData.driver, formData.model, 'color')"
                size="1.125rem"
                class="shrink-0"
              />
              <span class="min-w-0 truncate font-medium">{{ selectedModel ? modelDisplayName(selectedModel.name) : formData.model }}</span>
              <AiCapabilityIcons
                v-if="selectedModel"
                :capabilities="selectedModel"
              />
            </template>
            <span
              v-else
              class="text-muted"
            >
              {{ $t('components.aiModelSelector.selectModel') }}
            </span>
            <Icon
              name="lucide:chevron-down"
              class="ml-auto shrink-0 text-muted"
            />
          </button>
        </FormField>

        <FormField
          v-if="selectedModel?.supports_reasoning"
          name="reasoning"
          :label="$t('components.aiModelSelector.reasoning.label')"
          :description="$t('components.aiModelSelector.reasoning.description')"
        >
          <AiReasoningPicker
            v-model="formData.reasoning_effort"
            variant="segmented"
            :required="selectedModel.reasoning_required"
          />
        </FormField>

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
          v-if="canSubmit ?? true"
          variant="primary"
          :loading="isSubmitting"
          :disabled="!isFormValid"
          @click="handleSubmit"
        >
          {{ isSubmitting ? $t('actions.saving') : $t('actions.save') }}
        </Button>
      </DialogFooter>
      <AiModelPickerDialog
        v-model:open="pickerOpen"
        :space-id="spaceId"
        :selected="formData.model ? `${formData.driver}:${formData.model}` : null"
        @select="handleModelSelect"
      />
    </DialogContent>
  </Dialog>
</template>
