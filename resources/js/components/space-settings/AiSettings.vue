<script setup lang="ts">
import { toast } from 'vue-sonner'

import type { SpaceAiConfig } from '~/api/resources/ai'
import Icon from '~/components/Icon.vue'
import AiConfigFormDialog from '~/components/space-settings/AiConfigFormDialog.vue'
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
import SimpleTooltip from '~/components/ui/tooltip/SimpleTooltip.vue'
import { useAiConfigs, useAiModels, useAiSettings } from '~/composables/useAiModels'
import { useAlertDialog } from '~/composables/useAlertDialog'

const props = defineProps<{ space: SpaceResource }>()

interface SpaceAiUsageResource {
  used_tokens: number
  max_tokens: number
  valid_to: string | null
}

const { t } = useI18n()
const { alert } = useAlertDialog()
const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace, isPending: isUpdating } = useUpdateSpaceMutation()
const { client: apiClient } = useApiClient()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.space.id })))
const canManageAi = computed(() => access.hasAbility('ai.manage'))

const { useModelsQuery } = useAiModels(computed(() => props.space.id))
const { useAiSettingsQuery } = useAiSettings(computed(() => props.space.id))
const {
  useAiConfigsQuery,
  useCreateAiConfigMutation,
  useUpdateAiConfigMutation,
  useDeleteAiConfigMutation,
} = useAiConfigs(computed(() => props.space.id))

const { data: groupedModels } = useModelsQuery()
const { data: aiSettings } = useAiSettingsQuery()
const { data: aiConfigs, isLoading: isLoadingConfigs } = useAiConfigsQuery()
const { mutate: createConfig, isPending: isCreating } = useCreateAiConfigMutation()
const { mutate: updateConfig, isPending: isUpdatingConfig } = useUpdateAiConfigMutation()
const { mutate: deleteConfig, isPending: isDeleting } = useDeleteAiConfigMutation()

const enableAI = ref(aiSettings.value?.enabled ?? true)

const aiUsage = ref<SpaceAiUsageResource | null>(null)
const isLoadingUsage = ref(false)
const usageError = ref<string | null>(null)

const isDialogOpen = ref(false)
const editingConfig = ref<SpaceAiConfig | null>(null)

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
    }
  },
  { immediate: true }
)

watch(isDialogOpen, (isOpen) => {
  if (!isOpen) {
    editingConfig.value = null
  }
})

const saveSettings = async () => {
  try {
    await updateSpace({
      id: props.space.id,
      payload: {
        settings: {
          ...(props.space.settings as Record<string, unknown>),
          ai: {
            ...(props.space.settings as Record<string, any>)?.ai,
            enabled: enableAI.value,
          },
        } as any,
      },
    })
    toast.success(t('components.aiSettings.saveSuccess'))
  } catch (error: any) {
    toast.error(t('components.aiSettings.saveError'))
  }
}

const openCreateDialog = () => {
  editingConfig.value = null
  isDialogOpen.value = true
}

const openEditDialog = (config: SpaceAiConfig) => {
  editingConfig.value = config
  isDialogOpen.value = true
}

const handleConfigSubmit = (payload: Partial<SpaceAiConfig>) => {
  if (editingConfig.value) {
    updateConfig(
      {
        configId: editingConfig.value.id,
        payload,
      },
      {
        onSuccess: () => {
          toast.success(t('components.aiSettings.configUpdateSuccess'))
          isDialogOpen.value = false
        },
        onError: () => {
          toast.error(t('components.aiSettings.configUpdateError'))
        },
      }
    )
  } else {
    createConfig(payload as Omit<SpaceAiConfig, 'id' | 'created_at' | 'updated_at'>, {
      onSuccess: () => {
        toast.success(t('components.aiSettings.configCreateSuccess'))
        isDialogOpen.value = false
      },
      onError: () => {
        toast.error(t('components.aiSettings.configCreateError'))
      },
    })
  }
}

const handleDeleteConfig = async (config: SpaceAiConfig) => {
  if (aiConfigs.value && aiConfigs.value.length === 1) {
    return
  }

  const confirmed = await alert.confirm(
    t('components.aiSettings.deleteDialogDescription', { name: config.name }),
    {
      title: t('components.aiSettings.deleteDialogTitle'),
      confirmLabel: t('actions.delete'),
      variant: 'destructive',
    }
  )

  if (confirmed) {
    deleteConfig(config.id, {
      onSuccess: () => {
        toast.success(t('components.aiSettings.configDeleteSuccess'))
      },
      onError: () => {
        toast.error(t('components.aiSettings.configDeleteError'))
      },
    })
  }
}
</script>

<template>
  <div class="pt-6 space-y-12 divide-y divide-border">
    <Card variant="none">
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
              v-model="enableAI"
              :disabled="!canManageAi"
              :aria-label="$t('labels.settings.ai.enableAIFeatures')"
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
          <div class="space-y-4">
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
                  {{ aiUsage.max_tokens.toLocaleString() }}
                  {{ $t('labels.settings.ai.tokensUsed') }}
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
        </div>
      </CardContent>
      <CardFooter>
        <Button
          v-if="canManageAi"
          variant="primary"
          :disabled="isUpdating"
          @click="saveSettings"
        >
          {{ isUpdating ? $t('actions.saving') : $t('actions.saveChanges') }}
        </Button>
      </CardFooter>
    </Card>

    <Card
      v-if="enableAI"
      variant="none"
    >
      <CardHeader>
        <div class="flex items-center justify-between gap-6">
          <div>
            <CardTitle>{{ $t('labels.settings.ai.configurations') }}</CardTitle>
            <CardDescription>{{
              $t('labels.settings.ai.configurationsDescription')
            }}</CardDescription>
          </div>
          <Button
            v-if="canManageAi"
            variant="primary"
            size="sm"
            @click="openCreateDialog"
          >
            <Icon
              name="lucide:plus"
              class="h-4 w-4"
            />
            {{ $t('labels.settings.ai.createConfig') }}
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        <div
          v-if="isLoadingConfigs"
          class="flex items-center justify-center py-8"
        >
          <div
            class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent"
          />
        </div>

        <div
          v-else-if="!aiConfigs || aiConfigs.length === 0"
          class="flex flex-col items-center justify-center py-12 text-center text-muted-foreground"
        >
          <Icon
            name="lucide:sparkles"
            class="mb-4 h-12 w-12 opacity-20"
          />
          <p class="font-medium">{{ $t('labels.settings.ai.noConfigs') }}</p>
          <p class="mt-1 text-sm">{{ $t('labels.settings.ai.noConfigsDescription') }}</p>
        </div>

        <div
          v-else
          class="space-y-3"
        >
          <div
            v-for="config in aiConfigs"
            :key="config.id"
            class="flex items-center justify-between rounded-lg p-4 transition-colors bg-surface"
            :class="config.is_default ? 'border border-border' : ''"
          >
            <div class="flex-1 space-y-2">
              <div class="flex items-center gap-2">
                <h4 class="font-semibold text-primary">{{ config.name }}</h4>
                <Badge
                  v-if="config.is_default"
                  size="xs"
                >
                  {{ $t('labels.settings.ai.default') }}
                </Badge>
              </div>
              <div class="flex flex-wrap gap-3 text-sm text-muted">
                <span class="flex items-center gap-1">
                  <Icon name="lucide:server" />
                  {{ config.driver }}:{{ config.model }}
                </span>
                <span class="flex items-center gap-1">
                  <Icon name="lucide:thermometer" />
                  {{ config.temperature }}
                </span>
                <span class="flex items-center gap-1">
                  <Icon name="lucide:hash" />
                  {{ config.max_tokens.toLocaleString() }}
                </span>
              </div>
              <p
                v-if="config.system_prompt"
                class="line-clamp-1 text-sm text-muted-foreground"
              >
                {{ config.system_prompt }}
              </p>
            </div>
            <div class="flex gap-1">
              <SimpleTooltip
                v-if="canManageAi"
                :tooltip="$t('actions.edit')"
              >
                <Button
                  variant="ghost"
                  size="icon"
                  @click="openEditDialog(config)"
                >
                  <Icon name="lucide:pencil" />
                  <span class="sr-only">{{ $t('actions.edit') }}</span>
                </Button>
              </SimpleTooltip>

              <SimpleTooltip
                v-if="canManageAi"
                :tooltip="
                  aiConfigs && aiConfigs.length === 1
                    ? $t('components.aiSettings.cannotDeleteLast')
                    : $t('actions.delete')
                "
              >
                <Button
                  variant="ghost"
                  size="icon"
                  :disabled="isDeleting || (aiConfigs && aiConfigs.length === 1)"
                  @click="handleDeleteConfig(config)"
                >
                  <Icon
                    name="lucide:trash-2"
                    class="h-4 w-4"
                  />
                  <span class="sr-only">{{ $t('actions.delete') }}</span>
                </Button>
              </SimpleTooltip>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>

    <AiConfigFormDialog
      v-model:open="isDialogOpen"
      :config="editingConfig"
      :space-id="space.id"
      :grouped-models="groupedModels ?? {}"
      :is-submitting="isCreating || isUpdatingConfig"
      :can-submit="canManageAi"
      @submit="handleConfigSubmit"
    />
  </div>
</template>
