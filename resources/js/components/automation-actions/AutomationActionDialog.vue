<script lang="ts">
export default {
  name: 'AutomationActionDialog',
}
</script>

<script setup lang="ts">
import type { AcceptableValue } from 'reka-ui'

import AutomationPlaceholderEditor from '~/components/automations/AutomationPlaceholderEditor.vue'
import Icon from '~/components/Icon.vue'
import { Button } from '~/components/ui/button'
import {
  Dialog,
  DialogFooter,
  DialogHeaderCombined,
  DialogScrollContent,
} from '~/components/ui/dialog'
import { FormField, InputField, TextField } from '~/components/ui/form'
import ArrayInputField from '~/components/ui/form/ArrayInputField.vue'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '~/components/ui/select'
import { Switch } from '~/components/ui/switch'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '~/components/ui/tabs'
import {
  buildAutomationPlaceholderOptions,
  defaultActionConfig,
  defaultPlaceholderTable,
  findTriggerTableDefinition,
  formatAutomationPlaceholderToken,
  getActionTypeLabel,
  objectToRows,
  rowsToObject,
  rowsToValues,
  summarizeAction,
  valuesToRows,
  type AutomationPlaceholderOption,
  type KeyValueRow,
  type StringValueRow,
} from '~/utils/automations'

import AutomationActionTypeBadge from './AutomationActionTypeBadge.vue'

const props = defineProps<{
  open: boolean
  action: AutomationActionResource | null
  triggerCatalog?: AutomationTriggerCatalogResource | null
  loading?: boolean
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  submit: [payload: CreateAutomationActionPayload | UpdateAutomationActionPayload]
}>()

const { t } = useI18n()

const actionTypeOptions: AutomationActionType[] = ['webhook', 'email', 'void']

const recipientColumns = computed(() => [
  {
    key: 'value',
    label: t('labels.automationActions.fields.emailAddress'),
    type: 'email' as const,
    placeholder: 'ops@example.com',
    required: true,
    validate: (value: unknown) => String(value ?? '').trim().length > 0,
  },
])

const keyValueColumns = computed(() => [
  {
    key: 'key',
    label: t('labels.automationActions.fields.key'),
    type: 'text' as const,
    placeholder: 'x-api-key',
    required: true,
    validate: (value: unknown) => String(value ?? '').trim().length > 0,
  },
  {
    key: 'value',
    label: t('labels.automationActions.fields.value'),
    type: 'text' as const,
    placeholder: t('labels.automationActions.fields.valuePlaceholder'),
    required: false,
  },
])

const isEditing = computed(() => !!props.action)

const form = ref({
  name: '',
  description: '',
  type: 'webhook' as AutomationActionType,
  is_active: true,
  config: defaultActionConfig('webhook'),
})

const toRows = ref<StringValueRow[]>([])
const ccRows = ref<StringValueRow[]>([])
const bccRows = ref<StringValueRow[]>([])
const replyToRows = ref<StringValueRow[]>([])
const headerRows = ref<KeyValueRow[]>([])
const parameterRows = ref<KeyValueRow[]>([])
const secretRows = ref<KeyValueRow[]>([])

const existingSecretKeys = ref<string[]>([])
const isHydrating = ref(false)
const placeholderTable = ref('')

const applyState = (action: AutomationActionResource | null) => {
  isHydrating.value = true
  placeholderTable.value = defaultPlaceholderTable(props.triggerCatalog)

  if (!action) {
    form.value = {
      name: '',
      description: '',
      type: 'webhook',
      is_active: true,
      config: defaultActionConfig('webhook'),
    }
    toRows.value = []
    ccRows.value = []
    bccRows.value = []
    replyToRows.value = []
    headerRows.value = []
    parameterRows.value = []
    secretRows.value = []
    existingSecretKeys.value = []
    nextTick(() => {
      isHydrating.value = false
    })
    return
  }

  form.value = {
    name: action.name,
    description: action.description || '',
    type: action.type,
    is_active: action.is_active,
    config: {
      ...defaultActionConfig(action.type),
      ...action.config,
    },
  }

  toRows.value = valuesToRows(action.config.to)
  ccRows.value = valuesToRows(action.config.cc)
  bccRows.value = valuesToRows(action.config.bcc)
  replyToRows.value = valuesToRows(action.config.reply_to)
  headerRows.value = objectToRows(action.config.headers)
  parameterRows.value = objectToRows(action.config.parameters)
  existingSecretKeys.value = [...action.secret_keys]
  secretRows.value = action.secret_keys.map((key) => ({ key, value: '' }))

  nextTick(() => {
    isHydrating.value = false
  })
}

watch(
  () => props.action,
  (action) => {
    applyState(action)
  },
  { immediate: true }
)

watch(
  () => props.triggerCatalog,
  (catalog) => {
    if (!placeholderTable.value) {
      placeholderTable.value = defaultPlaceholderTable(catalog)
    }
  },
  { immediate: true }
)

watch(
  () => form.value.type,
  (type, previousType) => {
    if (type === previousType || isHydrating.value) {
      return
    }

    form.value.config = defaultActionConfig(type)
    toRows.value = []
    ccRows.value = []
    bccRows.value = []
    replyToRows.value = []
    headerRows.value = []
    parameterRows.value = []
  }
)

const handleOpenChange = (value: boolean) => {
  if (!value) {
    applyState(props.action)
  }
  emit('update:open', value)
}

const webhookConfig = computed(() => form.value.config)
const emailConfig = computed(() => form.value.config)
const voidConfig = computed(() => form.value.config)
const placeholderTableOptions = computed(() => props.triggerCatalog?.tables || [])
const selectedPlaceholderTable = computed(() =>
  findTriggerTableDefinition(placeholderTable.value, props.triggerCatalog)
)
const availableSecretKeys = computed(() => {
  return [...new Set([...existingSecretKeys.value, ...secretRows.value.map((row) => row.key)])]
})
const placeholderOptions = computed(() =>
  buildAutomationPlaceholderOptions(selectedPlaceholderTable.value, availableSecretKeys.value)
)
const groupedPlaceholderOptions = computed(() => {
  const groups: Array<{
    key: AutomationPlaceholderOption['group']
    items: AutomationPlaceholderOption[]
  }> = []
  const order: AutomationPlaceholderOption['group'][] = ['record', 'changes', 'workflow', 'secrets']

  for (const key of order) {
    const items = placeholderOptions.value.filter((option) => option.group === key)

    if (items.length > 0) {
      groups.push({ key, items })
    }
  }

  return groups
})

const clearSecretKeys = computed(() => {
  const currentKeys = new Set(secretRows.value.map((row) => row.key.trim()).filter(Boolean))
  return existingSecretKeys.value.filter((key) => !currentKeys.has(key))
})

const subjectPlaceholderText = computed(() =>
  [
    t('labels.automationActions.fields.subjectPlaceholder'),
    formatAutomationPlaceholderToken('content.title'),
  ]
    .filter(Boolean)
    .join(' ')
)

const voidMessagePlaceholderText = computed(() =>
  [
    t('labels.automationActions.fields.voidMessagePlaceholderPrefix'),
    formatAutomationPlaceholderToken('automation.name'),
    t('labels.automationActions.fields.voidMessagePlaceholderSuffix'),
  ]
    .filter(Boolean)
    .join(' ')
)

const secretsPayload = computed<Record<string, string>>(() => {
  return secretRows.value.reduce<Record<string, string>>((result, row) => {
    const key = row.key.trim()
    const value = row.value.trim()
    if (!key || !value) {
      return result
    }

    result[key] = value
    return result
  }, {})
})

const isValid = computed(() => {
  if (!form.value.name.trim()) {
    return false
  }

  switch (form.value.type) {
    case 'webhook':
      return Boolean(webhookConfig.value.url?.trim() && webhookConfig.value.method)
    case 'email':
      return (
        rowsToValues(toRows.value).length > 0 &&
        Boolean(emailConfig.value.subject?.trim()) &&
        Boolean(emailConfig.value.body?.trim())
      )
    case 'void':
      return true
  }
})

const buildConfig = (): AutomationActionConfig => {
  switch (form.value.type) {
    case 'webhook':
      return {
        method: webhookConfig.value.method,
        url: webhookConfig.value.url?.trim(),
        timeout_seconds: Number(webhookConfig.value.timeout_seconds || 15),
        headers: rowsToObject(headerRows.value),
        parameters: rowsToObject(parameterRows.value),
      }
    case 'email':
      return {
        to: rowsToValues(toRows.value),
        cc: rowsToValues(ccRows.value),
        bcc: rowsToValues(bccRows.value),
        reply_to: rowsToValues(replyToRows.value),
        subject: emailConfig.value.subject?.trim(),
        body: emailConfig.value.body?.trim(),
      }
    case 'void':
      return {
        message: voidConfig.value.message?.trim(),
      }
  }
}

const handleSubmit = () => {
  if (!isValid.value) {
    return
  }

  const payload: UpdateAutomationActionPayload = {
    name: form.value.name.trim(),
    description: form.value.description.trim() || undefined,
    type: form.value.type,
    config: buildConfig(),
    is_active: form.value.is_active,
  }

  if (Object.keys(secretsPayload.value).length > 0) {
    payload.secrets = secretsPayload.value
  }

  if (isEditing.value && clearSecretKeys.value.length > 0) {
    payload.clear_secret_keys = clearSecretKeys.value
  }

  emit('submit', payload)
}

const handleTypeChange = (value: AcceptableValue) => {
  if (typeof value === 'string') {
    form.value.type = value as AutomationActionType
  }
}

const handlePlaceholderTableChange = (value: AcceptableValue) => {
  if (typeof value === 'string') {
    placeholderTable.value = value
  }
}

const handleActiveChange = (value: boolean) => {
  form.value.is_active = value
}

const summaryText = computed(() => {
  const action: AutomationActionResource = {
    id: props.action?.id || 'preview',
    space_id: props.action?.space_id || '',
    name: form.value.name || t('labels.automationActions.previewName'),
    description: form.value.description || null,
    type: form.value.type,
    config: buildConfig(),
    is_active: form.value.is_active,
    has_secrets: secretRows.value.length > 0,
    secret_keys: secretRows.value.map((row) => row.key).filter(Boolean),
    created_at: props.action?.created_at || new Date().toISOString(),
    updated_at: props.action?.updated_at || new Date().toISOString(),
  }

  return summarizeAction(action, t)
})
</script>

<template>
  <Dialog
    :open="open"
    @update:open="handleOpenChange"
  >
    <DialogScrollContent class="max-w-5xl!">
      <DialogHeaderCombined
        :title="
          $t(
            isEditing
              ? 'labels.automationActions.editTitle'
              : 'labels.automationActions.createTitle'
          )
        "
        :description="
          $t(
            isEditing
              ? 'labels.automationActions.editDescription'
              : 'labels.automationActions.createDescription'
          )
        "
      />

      <div class="space-y-6">
        <div class="grid gap-4 lg:grid-cols-[1.6fr_1fr]">
          <div class="space-y-4">
            <InputField
              v-model="form.name"
              name="action-name"
              :label="$t('labels.automationActions.fields.name')"
              :placeholder="$t('labels.automationActions.fields.namePlaceholder')"
              :disabled="loading"
            />
            <TextField
              v-model="form.description"
              name="action-description"
              :label="$t('labels.automationActions.fields.description')"
              :placeholder="$t('labels.automationActions.fields.descriptionPlaceholder')"
              :disabled="loading"
            />
          </div>

          <div class="rounded-xl border border-border bg-surface/70 p-4">
            <div class="mb-3 flex items-center justify-between gap-3">
              <span class="text-muted-foreground text-sm font-medium">{{
                $t('labels.automationActions.previewLabel')
              }}</span>
              <AutomationActionTypeBadge :type="form.type" />
            </div>
            <p class="text-sm">{{ summaryText }}</p>
            <div
              class="mt-4 flex items-center justify-between rounded-lg border border-border bg-background px-3 py-2"
            >
              <div>
                <p class="font-medium text-sm">
                  {{ $t('labels.automationActions.fields.active') }}
                </p>
                <p class="text-muted-foreground text-xs">
                  {{ $t('labels.automationActions.fields.activeDescription') }}
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

        <FormField
          name="action-type"
          :label="$t('labels.automationActions.fields.type')"
          :description="$t('labels.automationActions.fields.typeDescription')"
        >
          <Select
            :model-value="form.type"
            :disabled="loading"
            @update:model-value="handleTypeChange"
          >
            <SelectTrigger>
              <SelectValue :placeholder="$t('labels.automationActions.fields.typePlaceholder')" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem
                v-for="type in actionTypeOptions"
                :key="type"
                :value="type"
              >
                {{ getActionTypeLabel(t, type) }}
              </SelectItem>
            </SelectContent>
          </Select>
        </FormField>

        <Tabs
          default-value="configuration"
          class="space-y-4"
        >
          <TabsList>
            <TabsTrigger value="configuration">
              {{ $t('labels.automationActions.tabs.configuration') }}
            </TabsTrigger>
            <TabsTrigger value="secrets">
              {{ $t('labels.automationActions.tabs.secrets') }}
            </TabsTrigger>
          </TabsList>

          <TabsContent
            value="configuration"
            class="space-y-4"
          >
            <div
              v-if="form.type === 'email' || form.type === 'void'"
              class="rounded-xl border border-border bg-surface/70 p-4"
            >
              <div class="grid gap-4 lg:grid-cols-[minmax(0,240px)_1fr]">
                <FormField
                  name="placeholder-table"
                  :label="$t('labels.automationActions.fields.placeholderTable')"
                  :description="$t('labels.automationActions.fields.placeholderTableDescription')"
                >
                  <Select
                    :model-value="placeholderTable"
                    :disabled="loading || placeholderTableOptions.length === 0"
                    @update:model-value="handlePlaceholderTableChange"
                  >
                    <SelectTrigger>
                      <SelectValue
                        :placeholder="
                          $t('labels.automationActions.fields.placeholderTablePlaceholder')
                        "
                      />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectGroup v-if="placeholderTableOptions.length > 0">
                        <SelectLabel>{{
                          $t('labels.automationActions.fields.availablePlaceholders')
                        }}</SelectLabel>
                        <SelectItem
                          v-for="table in placeholderTableOptions"
                          :key="table.table"
                          :value="table.table"
                        >
                          {{ table.label }}
                        </SelectItem>
                      </SelectGroup>
                    </SelectContent>
                  </Select>
                </FormField>

                <div class="space-y-3">
                  <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-sm">
                      {{ $t('labels.automationActions.fields.availablePlaceholders') }}
                    </span>
                    <span
                      v-if="selectedPlaceholderTable"
                      class="rounded-md border border-border bg-background px-2 py-0.5 text-xs text-muted-foreground"
                    >
                      {{ selectedPlaceholderTable.label }}
                    </span>
                  </div>

                  <div class="space-y-3">
                    <div
                      v-for="group in groupedPlaceholderOptions"
                      :key="group.key"
                      class="space-y-1.5"
                    >
                      <p class="text-muted-foreground text-xs font-medium uppercase tracking-wide">
                        {{ $t(`labels.automationActions.placeholderGroups.${group.key}`) }}
                      </p>
                      <div class="flex flex-wrap gap-2">
                        <span
                          v-for="option in group.items.slice(0, 6)"
                          :key="option.value"
                          class="rounded-md border border-border bg-background px-2 py-1 font-mono text-[11px] text-foreground"
                        >
                          {{ option.label }}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <template v-if="form.type === 'webhook'">
              <div class="grid gap-4 lg:grid-cols-12">
                <FormField
                  name="webhook-method"
                  class="lg:col-span-3"
                  :label="$t('labels.automationActions.fields.webhookMethod')"
                >
                  <Select
                    :model-value="webhookConfig.method || 'POST'"
                    :disabled="loading"
                    @update:model-value="
                      (value) => (webhookConfig.method = value as AutomationActionConfig['method'])
                    "
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="POST" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="GET">GET</SelectItem>
                      <SelectItem value="POST">POST</SelectItem>
                      <SelectItem value="PUT">PUT</SelectItem>
                      <SelectItem value="PATCH">PATCH</SelectItem>
                      <SelectItem value="DELETE">DELETE</SelectItem>
                      <SelectItem value="HEAD">HEAD</SelectItem>
                    </SelectContent>
                  </Select>
                </FormField>
                <InputField
                  class="lg:col-span-9"
                  v-model="webhookConfig.url"
                  name="webhook-url"
                  :label="$t('labels.automationActions.fields.webhookUrl')"
                  placeholder="https://example.com/webhooks/cms"
                  :disabled="loading"
                />
              </div>

              <InputField
                v-model="webhookConfig.timeout_seconds"
                name="webhook-timeout"
                type="number"
                min="1"
                max="120"
                :label="$t('labels.automationActions.fields.timeout')"
                :description="$t('labels.automationActions.fields.timeoutDescription')"
                :disabled="loading"
              />

              <ArrayInputField
                v-model="headerRows"
                name="webhook-headers"
                :label="$t('labels.automationActions.fields.headers')"
                :description="$t('labels.automationActions.fields.headersDescription')"
                :columns="keyValueColumns"
                :disabled="loading"
                :empty-message="$t('labels.automationActions.emptyHeaders')"
                :add-button-text="$t('actions.add')"
                show-empty-placeholder
              />

              <ArrayInputField
                v-model="parameterRows"
                name="webhook-parameters"
                :label="$t('labels.automationActions.fields.parameters')"
                :description="$t('labels.automationActions.fields.parametersDescription')"
                :columns="keyValueColumns"
                :disabled="loading"
                :empty-message="$t('labels.automationActions.emptyParameters')"
                :add-button-text="$t('actions.add')"
                show-empty-placeholder
              />
            </template>

            <template v-else-if="form.type === 'email'">
              <ArrayInputField
                v-model="toRows"
                name="email-to"
                :label="$t('labels.automationActions.fields.to')"
                :description="$t('labels.automationActions.fields.toDescription')"
                :columns="recipientColumns"
                :disabled="loading"
                :empty-message="$t('labels.automationActions.emptyRecipients')"
                :add-button-text="$t('actions.add')"
                show-empty-placeholder
              />

              <div class="grid gap-4 lg:grid-cols-2">
                <ArrayInputField
                  v-model="ccRows"
                  name="email-cc"
                  :label="$t('labels.automationActions.fields.cc')"
                  :columns="recipientColumns"
                  :disabled="loading"
                  :empty-message="$t('labels.automationActions.emptyRecipients')"
                  :add-button-text="$t('actions.add')"
                  show-empty-placeholder
                />

                <ArrayInputField
                  v-model="bccRows"
                  name="email-bcc"
                  :label="$t('labels.automationActions.fields.bcc')"
                  :columns="recipientColumns"
                  :disabled="loading"
                  :empty-message="$t('labels.automationActions.emptyRecipients')"
                  :add-button-text="$t('actions.add')"
                  show-empty-placeholder
                />
              </div>

              <ArrayInputField
                v-model="replyToRows"
                name="email-reply-to"
                :label="$t('labels.automationActions.fields.replyTo')"
                :columns="recipientColumns"
                :disabled="loading"
                :empty-message="$t('labels.automationActions.emptyRecipients')"
                :add-button-text="$t('actions.add')"
                show-empty-placeholder
              />

              <FormField
                name="email-subject"
                :label="$t('labels.automationActions.fields.subject')"
                :description="$t('labels.automationActions.fields.subjectDescription')"
              >
                <AutomationPlaceholderEditor
                  v-model="emailConfig.subject"
                  :placeholder="subjectPlaceholderText"
                  :options="placeholderOptions"
                  :disabled="loading"
                  single-line
                  min-height-class="min-h-12"
                />
              </FormField>

              <FormField
                name="email-body"
                :label="$t('labels.automationActions.fields.body')"
                :description="$t('labels.automationActions.fields.bodyDescription')"
              >
                <AutomationPlaceholderEditor
                  v-model="emailConfig.body"
                  :placeholder="$t('labels.automationActions.fields.bodyPlaceholder')"
                  :options="placeholderOptions"
                  :disabled="loading"
                  min-height-class="min-h-40"
                />
              </FormField>
            </template>

            <template v-else>
              <FormField
                name="void-message"
                :label="$t('labels.automationActions.fields.voidMessage')"
                :description="$t('labels.automationActions.fields.voidMessageDescription')"
              >
                <AutomationPlaceholderEditor
                  v-model="voidConfig.message"
                  :placeholder="voidMessagePlaceholderText"
                  :options="placeholderOptions"
                  :disabled="loading"
                  min-height-class="min-h-28"
                />
              </FormField>
            </template>
          </TabsContent>

          <TabsContent
            value="secrets"
            class="space-y-4"
          >
            <div class="rounded-xl border border-dashed border-border bg-surface/70 p-4">
              <div class="flex items-start gap-3">
                <Icon
                  name="lucide:key-round"
                  class="mt-0.5"
                />
                <div>
                  <p class="font-medium text-sm">
                    {{ $t('labels.automationActions.secretsTitle') }}
                  </p>
                  <p class="text-muted-foreground text-sm">
                    {{ $t('labels.automationActions.secretsDescription') }}
                  </p>
                </div>
              </div>
            </div>

            <ArrayInputField
              v-model="secretRows"
              name="action-secrets"
              :label="$t('labels.automationActions.fields.secrets')"
              :description="$t('labels.automationActions.fields.secretsHint')"
              :columns="keyValueColumns"
              :disabled="loading"
              :empty-message="$t('labels.automationActions.emptySecrets')"
              :add-button-text="$t('actions.add')"
              show-empty-placeholder
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
          {{
            isEditing
              ? $t('actions.automationActions.save')
              : $t('actions.automationActions.create')
          }}
        </Button>
      </DialogFooter>
    </DialogScrollContent>
  </Dialog>
</template>
