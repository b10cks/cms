<script setup lang="ts">
import type { AcceptableValue } from 'reka-ui'

import AutomationActionTypeBadge from '~/components/automation-actions/AutomationActionTypeBadge.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogFooter,
  DialogHeaderCombined,
  DialogScrollContent,
} from '~/components/ui/dialog'
import { ComboboxField, FormField, InputField, TextField } from '~/components/ui/form'
import ArrayInputField from '~/components/ui/form/ArrayInputField.vue'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { Switch } from '~/components/ui/switch'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '~/components/ui/tabs'
import {
  buildConditionPathOptions,
  buildWatchColumnOptions,
  CONDITION_OPERATOR_OPTIONS,
  conditionsToRows,
  defaultTriggerConfig,
  findTriggerTableDefinition,
  getTriggerTable,
  getTriggerTypeLabel,
  isContentLifecycleTrigger,
  isEventTrigger,
  objectToRows,
  rowsToConditions,
  rowsToObject,
  summarizeTrigger,
  type ConditionRow,
  type KeyValueRow,
} from '~/utils/automations'

import AutomationTriggerTypeBadge from './AutomationTriggerTypeBadge.vue'

const props = defineProps<{
  open: boolean
  automation: AutomationResource | null
  actions: AutomationActionResource[]
  triggerCatalog?: AutomationTriggerCatalogResource | null
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  submit: [payload: CreateAutomationPayload | UpdateAutomationPayload]
}>()

const { t } = useI18n()

const triggerTypeOptions: AutomationTriggerType[] = [
  'on_insert',
  'on_update',
  'on_delete',
  'time_based',
  'manual',
  'content_published',
  'content_unpublished',
]

const keyValueColumns = computed(() => [
  {
    key: 'key',
    label: t('labels.automations.fields.key'),
    type: 'text' as const,
    placeholder: 'stage',
    required: true,
    validate: (value: unknown) => String(value ?? '').trim().length > 0,
  },
  {
    key: 'value',
    label: t('labels.automations.fields.value'),
    type: 'text' as const,
    placeholder: t('labels.automations.fields.valuePlaceholder'),
  },
])

const form = ref({
  name: '',
  description: '',
  action_id: '',
  trigger_type: 'manual' as AutomationTriggerType,
  is_active: true,
  execution_limit: '',
  trigger_config: defaultTriggerConfig('manual'),
})

const payloadRows = ref<KeyValueRow[]>([])
const conditionRows = ref<ConditionRow[]>([])
const isHydrating = ref(false)

const isEditing = computed(() => !!props.automation)
const isEventTriggerSelected = computed(() => isEventTrigger(form.value.trigger_type))
const isContentLifecycleTriggerSelected = computed(() =>
  isContentLifecycleTrigger(form.value.trigger_type)
)

const selectedAction = computed(
  () => props.actions.find((action) => action.id === form.value.action_id) || null
)

const tableOptions = computed(() =>
  (props.triggerCatalog?.tables || []).map((table) => ({
    value: table.table,
    label: table.label,
  }))
)

const selectedTableDefinition = computed(() =>
  findTriggerTableDefinition(getTriggerTable(form.value.trigger_config), props.triggerCatalog)
)

const watchColumnOptions = computed(() => buildWatchColumnOptions(selectedTableDefinition.value))

const watchedColumnsModel = computed({
  get: () => form.value.trigger_config.watch_columns || [],
  set: (value: string[]) => {
    form.value.trigger_config.watch_columns = value
  },
})

const conditionColumns = computed(() => {
  const pathOptions = buildConditionPathOptions(
    form.value.trigger_type,
    selectedTableDefinition.value
  )

  return [
    {
      key: 'path',
      label: t('labels.automations.fields.conditionPath'),
      type: selectedTableDefinition.value ? ('select' as const) : ('text' as const),
      placeholder: selectedTableDefinition.value
        ? t('labels.automations.fields.conditionPathPlaceholder')
        : 'record.status',
      options: selectedTableDefinition.value
        ? pathOptions.map((option) => ({
            value: option.value,
            label: option.label,
          }))
        : undefined,
      required: true,
      validate: (value: unknown) => String(value ?? '').trim().length > 0,
    },
    {
      key: 'operator',
      label: t('labels.automations.fields.conditionOperator'),
      type: 'select' as const,
      options: CONDITION_OPERATOR_OPTIONS.map((option) => ({
        value: option.value,
        label: t(option.labelKey),
      })),
      required: true,
      defaultValue: 'eq',
    },
    {
      key: 'value',
      label: t('labels.automations.fields.conditionValue'),
      type: 'text' as const,
      placeholder: 'published',
    },
  ]
})

const applyState = (automation: AutomationResource | null) => {
  isHydrating.value = true

  if (!automation) {
    form.value = {
      name: '',
      description: '',
      action_id: props.actions[0]?.id || '',
      trigger_type: 'manual',
      is_active: true,
      execution_limit: '',
      trigger_config: defaultTriggerConfig('manual'),
    }
    payloadRows.value = []
    conditionRows.value = []
    nextTick(() => {
      isHydrating.value = false
    })

    return
  }

  form.value = {
    name: automation.name,
    description: automation.description || '',
    action_id: automation.action_id,
    trigger_type: automation.trigger_type,
    is_active: automation.is_active,
    execution_limit: automation.execution_limit ? String(automation.execution_limit) : '',
    trigger_config: {
      ...defaultTriggerConfig(automation.trigger_type),
      ...automation.trigger.config,
    },
  }
  payloadRows.value = objectToRows(automation.trigger.config?.payload)
  conditionRows.value = conditionsToRows(automation.trigger.config?.conditions)

  nextTick(() => {
    isHydrating.value = false
  })
}

watch(
  () => [props.automation, props.actions] as const,
  ([automation]) => {
    applyState(automation)
  },
  { immediate: true }
)

watch(
  () => form.value.trigger_type,
  (type, previousType) => {
    if (type === previousType || isHydrating.value) {
      return
    }

    form.value.trigger_config = defaultTriggerConfig(type)
    payloadRows.value = []
    conditionRows.value = []
  }
)

watch(selectedTableDefinition, (tableDefinition) => {
  if (!tableDefinition) {
    watchedColumnsModel.value = []
    return
  }

  watchedColumnsModel.value = watchedColumnsModel.value.filter((column) =>
    tableDefinition.columns.includes(column)
  )
})

const handleOpenChange = (value: boolean) => {
  if (!value) {
    applyState(props.automation)
  }

  emit('update:open', value)
}

const isValid = computed(() => {
  if (!form.value.name.trim() || !form.value.action_id) {
    return false
  }

  if (isEventTriggerSelected.value) {
    return !!getTriggerTable(form.value.trigger_config)
  }

  if (form.value.trigger_type === 'time_based') {
    return Boolean(form.value.trigger_config.schedule?.trim())
  }

  return true
})

const buildTriggerConfig = (): AutomationTriggerConfig => {
  const config: AutomationTriggerConfig = {}

  if (isEventTriggerSelected.value) {
    const table = getTriggerTable(form.value.trigger_config)

    if (table) {
      config.table = table
    }

    if (form.value.trigger_type === 'on_update' && watchedColumnsModel.value.length > 0) {
      config.watch_columns = watchedColumnsModel.value
    }
  }

  if (form.value.trigger_type === 'time_based') {
    config.schedule = form.value.trigger_config.schedule?.trim()

    if (form.value.trigger_config.timezone?.trim()) {
      config.timezone = form.value.trigger_config.timezone.trim()
    }
  }

  const payload = rowsToObject(payloadRows.value)
  if (Object.keys(payload).length > 0) {
    config.payload = payload
  }

  const conditions = rowsToConditions(conditionRows.value)
  if (conditions.length > 0) {
    config.conditions = conditions
  }

  return config
}

const handleSubmit = () => {
  if (!isValid.value) {
    return
  }

  emit('submit', {
    name: form.value.name.trim(),
    description: form.value.description.trim() || undefined,
    action_id: form.value.action_id,
    trigger: {
      type: form.value.trigger_type,
      config: buildTriggerConfig(),
    },
    is_active: form.value.is_active,
    execution_limit: form.value.execution_limit ? Number(form.value.execution_limit) : null,
  })
}

const handleActionChange = (value: AcceptableValue) => {
  if (typeof value === 'string') {
    form.value.action_id = value
  }
}

const handleTriggerTypeChange = (value: AcceptableValue) => {
  if (typeof value === 'string') {
    form.value.trigger_type = value as AutomationTriggerType
  }
}

const handleActiveChange = (value: boolean) => {
  form.value.is_active = value
}

const handleTableChange = (value: AcceptableValue) => {
  if (typeof value === 'string') {
    form.value.trigger_config.table = value
  }
}

const summaryText = computed(() => {
  const automation: AutomationResource = {
    id: props.automation?.id || 'preview',
    space_id: props.automation?.space_id || '',
    action_id: form.value.action_id,
    action: selectedAction.value,
    name: form.value.name || t('labels.automations.previewName'),
    description: form.value.description || null,
    trigger_type: form.value.trigger_type,
    trigger: {
      type: form.value.trigger_type,
      config: buildTriggerConfig(),
    },
    is_active: form.value.is_active,
    execution_count: props.automation?.execution_count || 0,
    execution_limit: form.value.execution_limit ? Number(form.value.execution_limit) : null,
    remaining_executions: null,
    created_at: props.automation?.created_at || new Date().toISOString(),
    updated_at: props.automation?.updated_at || new Date().toISOString(),
  }

  return summarizeTrigger(automation, t, props.triggerCatalog)
})
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogScrollContent class="max-w-5xl!">
      <DialogHeaderCombined
        :title="$t(isEditing ? 'labels.automations.editTitle' : 'labels.automations.createTitle')"
        :description="
          $t(
            isEditing
              ? 'labels.automations.editDescription'
              : 'labels.automations.createDescription'
          )
        "
      />

      <div class="space-y-6">
        <div class="grid gap-4 lg:grid-cols-[1.6fr_1fr]">
          <div class="space-y-4">
            <InputField
              v-model="form.name"
              name="automation-name"
              :label="$t('labels.automations.fields.name')"
              :placeholder="$t('labels.automations.fields.namePlaceholder')"
              :disabled="loading"
            />
            <TextField
              v-model="form.description"
              name="automation-description"
              :label="$t('labels.automations.fields.description')"
              :placeholder="$t('labels.automations.fields.descriptionPlaceholder')"
              :disabled="loading"
            />
          </div>

          <div class="rounded-xl border border-border bg-surface/70 p-4">
            <div class="mb-3 flex items-center justify-between gap-3">
              <span class="text-muted-foreground text-sm font-medium">{{
                $t('labels.automations.previewLabel')
              }}</span>
              <AutomationTriggerTypeBadge :type="form.trigger_type" />
            </div>
            <p class="text-sm">{{ summaryText }}</p>
            <div
              class="mt-4 flex items-center justify-between rounded-lg border border-border bg-background px-3 py-2"
            >
              <div>
                <p class="font-medium text-sm">{{ $t('labels.automations.fields.active') }}</p>
                <p class="text-muted-foreground text-xs">
                  {{ $t('labels.automations.fields.activeDescription') }}
                </p>
              </div>
              <Switch
                :checked="form.is_active"
                :disabled="loading"
                @update:checked="handleActiveChange"
              />
            </div>
          </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
          <FormField
            name="automation-action"
            :label="$t('labels.automations.fields.action')"
            :description="$t('labels.automations.fields.actionDescription')"
          >
            <Select
              :model-value="form.action_id"
              :disabled="loading"
              @update:model-value="handleActionChange"
            >
              <SelectTrigger>
                <SelectValue :placeholder="$t('labels.automations.fields.actionPlaceholder')" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="action in actions"
                  :key="action.id"
                  :value="action.id"
                >
                  {{ action.name }}
                </SelectItem>
              </SelectContent>
            </Select>
          </FormField>

          <FormField
            name="automation-trigger-type"
            :label="$t('labels.automations.fields.triggerType')"
            :description="$t('labels.automations.fields.triggerTypeDescription')"
          >
            <Select
              :model-value="form.trigger_type"
              :disabled="loading"
              @update:model-value="handleTriggerTypeChange"
            >
              <SelectTrigger>
                <SelectValue
                  :placeholder="$t('labels.automations.fields.triggerTypePlaceholder')"
                />
              </SelectTrigger>
              <SelectContent>
                <SelectItem
                  v-for="type in triggerTypeOptions"
                  :key="type"
                  :value="type"
                >
                  {{ getTriggerTypeLabel(t, type) }}
                </SelectItem>
              </SelectContent>
            </Select>
          </FormField>
        </div>

        <div
          v-if="selectedAction"
          class="flex items-center justify-between rounded-xl border border-border bg-surface/70 px-4 py-3"
        >
          <div>
            <p class="font-medium text-sm">{{ selectedAction.name }}</p>
            <p class="text-muted-foreground text-sm">
              {{ selectedAction.description || $t('labels.automations.selectedActionFallback') }}
            </p>
          </div>
          <AutomationActionTypeBadge :type="selectedAction.type" />
        </div>

        <Tabs
          default-value="trigger"
          class="space-y-4"
        >
          <TabsList>
            <TabsTrigger value="trigger">
              {{ $t('labels.automations.tabs.trigger') }}
            </TabsTrigger>
            <TabsTrigger value="execution">
              {{ $t('labels.automations.tabs.execution') }}
            </TabsTrigger>
          </TabsList>

          <TabsContent
            value="trigger"
            class="space-y-4"
          >
            <template v-if="isEventTriggerSelected">
              <FormField
                name="trigger-table"
                :label="$t('labels.automations.fields.table')"
                :description="$t('labels.automations.fields.tableDescription')"
              >
                <Select
                  :model-value="getTriggerTable(form.trigger_config)"
                  :disabled="loading"
                  @update:model-value="handleTableChange"
                >
                  <SelectTrigger>
                    <SelectValue :placeholder="$t('labels.automations.fields.tablePlaceholder')" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem
                      v-for="table in tableOptions"
                      :key="table.value"
                      :value="table.value"
                    >
                      {{ table.label }}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </FormField>

              <div
                v-if="selectedTableDefinition"
                class="rounded-xl border border-border bg-surface/70 px-4 py-3"
              >
                <p class="font-medium text-sm">{{ selectedTableDefinition.label }}</p>
                <p class="text-muted-foreground mt-1 text-sm">
                  {{ selectedTableDefinition.description }}
                </p>
                <p class="text-muted-foreground mt-2 text-xs">
                  {{
                    $t('labels.automations.tableColumnsSummary', {
                      count: selectedTableDefinition.columns.length,
                    })
                  }}
                </p>
              </div>

              <ComboboxField
                v-if="form.trigger_type === 'on_update' && selectedTableDefinition"
                v-model="watchedColumnsModel"
                name="trigger-watch-columns"
                :label="$t('labels.automations.fields.watchColumns')"
                :description="$t('labels.automations.fields.watchColumnsDescription')"
                :placeholder="'labels.automations.fields.watchColumnsPlaceholder'"
                :options="watchColumnOptions"
                multiple
              />
            </template>

            <template v-if="isContentLifecycleTriggerSelected">
              <div class="rounded-xl border border-border bg-surface/70 px-4 py-3">
                <p class="font-medium text-sm">
                  {{ $t(`labels.automations.triggerTypes.${form.trigger_type}`) }}
                </p>
                <p class="text-muted-foreground mt-1 text-sm">
                  {{
                    form.trigger_type === 'content_published'
                      ? $t('labels.automations.contentLifecycle.publishedDescription')
                      : $t('labels.automations.contentLifecycle.unpublishedDescription')
                  }}
                </p>
              </div>
            </template>

            <template v-if="form.trigger_type === 'time_based'">
              <div class="grid gap-4 lg:grid-cols-2">
                <InputField
                  v-model="form.trigger_config.schedule"
                  name="trigger-schedule"
                  :label="$t('labels.automations.fields.schedule')"
                  :description="$t('labels.automations.fields.scheduleDescription')"
                  placeholder="0 * * * *"
                  :disabled="loading"
                />
                <InputField
                  v-model="form.trigger_config.timezone"
                  name="trigger-timezone"
                  :label="$t('labels.automations.fields.timezone')"
                  :description="$t('labels.automations.fields.timezoneDescription')"
                  :placeholder="$t('labels.automations.fields.timezonePlaceholder')"
                  :disabled="loading"
                />
              </div>
            </template>

            <ArrayInputField
              v-model="conditionRows"
              name="trigger-conditions"
              :label="$t('labels.automations.fields.conditions')"
              :description="$t('labels.automations.fields.conditionsDescription')"
              :columns="conditionColumns"
              :disabled="loading"
              :empty-message="$t('labels.automations.emptyConditions')"
              :add-button-text="$t('actions.add')"
              show-empty-placeholder
            />

            <ArrayInputField
              v-model="payloadRows"
              name="trigger-payload"
              :label="$t('labels.automations.fields.payload')"
              :description="$t('labels.automations.fields.payloadDescription')"
              :columns="keyValueColumns"
              :disabled="loading"
              :empty-message="$t('labels.automations.emptyPayload')"
              :add-button-text="$t('actions.add')"
              show-empty-placeholder
            />
          </TabsContent>

          <TabsContent
            value="execution"
            class="space-y-4"
          >
            <InputField
              v-model="form.execution_limit"
              name="execution-limit"
              type="number"
              min="1"
              :label="$t('labels.automations.fields.executionLimit')"
              :description="$t('labels.automations.fields.executionLimitDescription')"
              :placeholder="$t('labels.automations.fields.executionLimitPlaceholder')"
              :disabled="loading"
            />
          </TabsContent>
        </Tabs>
      </div>

      <DialogFooter>
        <Button
          variant="outline"
          :disabled="loading"
          @click="handleOpenChange(false)"
        >
          {{ $t('actions.cancel') }}
        </Button>
        <Button
          :loading="loading"
          :disabled="!isValid"
          @click="handleSubmit"
        >
          {{ isEditing ? $t('actions.automations.save') : $t('actions.automations.create') }}
        </Button>
      </DialogFooter>
    </DialogScrollContent>
  </Dialog>
</template>
