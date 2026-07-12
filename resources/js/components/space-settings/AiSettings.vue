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
import { Skeleton } from '~/components/ui/skeleton'
import { Switch } from '~/components/ui/switch'
import SimpleTooltip from '~/components/ui/tooltip/SimpleTooltip.vue'
import { useAiConfigs, useAiModels, useAiSettings, useAiUsage } from '~/composables/useAiModels'
import { useAlertDialog } from '~/composables/useAlertDialog'

const props = defineProps<{ space: SpaceResource }>()

const { t } = useI18n()
const { alert } = useAlertDialog()
const { useUpdateSpaceMutation } = useSpaces()
const { mutate: updateSpace, isPending: isUpdating } = useUpdateSpaceMutation()
const { useAccessControl } = useAuthorization()
const access = useAccessControl(computed(() => ({ space_id: props.space.id })))
const canManageAi = computed(() => access.hasAbility('ai.manage'))

const { useModelsQuery } = useAiModels(computed(() => props.space.id))
const { useAiSettingsQuery } = useAiSettings(computed(() => props.space.id))
const { useAiUsageQuery, forceRefresh } = useAiUsage(computed(() => props.space.id))
const {
  useAiConfigsQuery,
  useCreateAiConfigMutation,
  useUpdateAiConfigMutation,
  useDeleteAiConfigMutation,
} = useAiConfigs(computed(() => props.space.id))

const { data: groupedModels } = useModelsQuery()
const { data: aiSettings } = useAiSettingsQuery()
const { data: aiConfigs, isLoading: isLoadingConfigs } = useAiConfigsQuery()
const {
  data: aiUsage,
  isLoading: isLoadingUsage,
  isFetching: isFetchingUsage,
  isError: isUsageError,
  refetch: refetchUsage,
} = useAiUsageQuery()
const { mutate: createConfig, isPending: isCreating } = useCreateAiConfigMutation()
const { mutate: updateConfig, isPending: isUpdatingConfig } = useUpdateAiConfigMutation()
const { mutate: deleteConfig, isPending: isDeleting } = useDeleteAiConfigMutation()

const enableAI = ref(aiSettings.value?.enabled ?? true)

const isDialogOpen = ref(false)
const editingConfig = ref<SpaceAiConfig | null>(null)

const formatAmount = (value: number | null): string => {
  if (value === null) return '—'

  if (aiUsage.value?.unit === 'usd') {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: 'USD',
      minimumFractionDigits: 2,
      maximumFractionDigits: value > 0 && value < 1 ? 4 : 2,
    }).format(value)
  }

  return value.toLocaleString()
}

const resetDate = computed(() => {
  if (!aiUsage.value?.resets_at) return null
  try {
    return new Date(aiUsage.value.resets_at).toLocaleDateString()
  } catch {
    return null
  }
})

const refreshUsage = () => {
  forceRefresh.value = true
  refetchUsage()
}

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
            <div class="flex items-start justify-between gap-4">
              <div>
                <h4 class="font-medium">{{ $t('labels.settings.ai.usage') }}</h4>
                <p class="text-sm text-muted">{{ $t('labels.settings.ai.usageDescription') }}</p>
              </div>
              <SimpleTooltip
                v-if="aiUsage?.available"
                :tooltip="$t('actions.refresh')"
              >
                <Button
                  variant="ghost"
                  size="icon"
                  :disabled="isFetchingUsage"
                  @click="refreshUsage"
                >
                  <Icon
                    name="lucide:refresh-cw"
                    class="h-4 w-4"
                    :class="{ 'animate-spin': isFetchingUsage }"
                  />
                  <span class="sr-only">{{ $t('actions.refresh') }}</span>
                </Button>
              </SimpleTooltip>
            </div>

            <div
              v-if="isLoadingUsage"
              class="space-y-2"
            >
              <div class="flex items-center justify-between gap-2">
                <Skeleton class="h-4 w-40" />
                <Skeleton class="h-4 w-10" />
              </div>
              <Skeleton class="h-2 w-full rounded-full" />
              <div class="flex items-center justify-between gap-2">
                <Skeleton class="h-3 w-24" />
                <Skeleton class="h-3 w-32" />
              </div>
            </div>

            <div
              v-else-if="isUsageError"
              class="text-sm text-destructive"
            >
              {{ $t('labels.settings.ai.usageError') }}
              <Button
                variant="link"
                size="sm"
                class="ml-2 h-auto p-0"
                @click="refreshUsage"
              >
                {{ $t('actions.retry') }}
              </Button>
            </div>

            <div
              v-else-if="aiUsage && !aiUsage.available"
              class="text-muted-foreground text-sm"
            >
              {{ $t('labels.settings.ai.notAvailable') }}
            </div>

            <div
              v-else-if="aiUsage && aiUsage.unlimited"
              class="space-y-2"
            >
              <div class="flex items-center gap-2">
                <span class="text-sm">
                  {{ formatAmount(aiUsage.used) }} {{ $t('labels.settings.ai.spent') }}
                </span>
                <Badge size="xs">{{ $t('labels.settings.ai.unlimited') }}</Badge>
                <Badge
                  v-if="aiUsage.live"
                  size="xs"
                  variant="success"
                >
                  {{ $t('labels.settings.ai.live') }}
                </Badge>
              </div>
            </div>

            <div
              v-else-if="aiUsage"
              class="space-y-2"
            >
              <div class="flex items-center justify-between gap-2">
                <span class="flex items-center gap-2 text-sm">
                  <span>
                    {{ formatAmount(aiUsage.used) }} {{ $t('labels.settings.ai.of') }}
                    {{ formatAmount(aiUsage.limit) }} {{ $t('labels.settings.ai.spent') }}
                  </span>
                  <Badge
                    v-if="aiUsage.live"
                    size="xs"
                    variant="success"
                  >
                    {{ $t('labels.settings.ai.live') }}
                  </Badge>
                </span>
                <span class="text-sm text-muted">{{ aiUsage.percentage }}%</span>
              </div>
              <Progress
                :model-value="aiUsage.percentage"
                class="h-2"
              />
              <div class="flex items-center justify-between gap-2">
                <p
                  v-if="aiUsage.remaining !== null"
                  class="text-sm text-muted"
                >
                  {{ formatAmount(aiUsage.remaining) }} {{ $t('labels.settings.ai.remaining') }}
                </p>
                <p
                  v-if="resetDate"
                  class="text-sm text-muted"
                >
                  {{ $t('labels.settings.ai.resetInfo', { date: resetDate }) }}
                </p>
              </div>
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
          class="space-y-3"
        >
          <div
            v-for="i in 2"
            :key="i"
            class="flex items-center justify-between rounded-lg bg-surface p-4"
          >
            <div class="flex-1 space-y-2">
              <Skeleton class="h-5 w-40" />
              <div class="flex flex-wrap gap-3">
                <Skeleton class="h-4 w-32" />
                <Skeleton class="h-4 w-16" />
                <Skeleton class="h-4 w-20" />
              </div>
            </div>
          </div>
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
