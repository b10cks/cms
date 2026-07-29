type TranslateFn = (key: string, params?: Record<string, unknown>) => string

export interface KeyValueRow {
  key: string
  value: string
}

export interface StringValueRow {
  value: string
}

export interface ConditionRow {
  path: string
  operator: AutomationConditionOperator
  value: string
}

export interface TriggerOption {
  value: string
  label: string
  // Index signature so the type satisfies ComboboxField's ComboboxOption.
  [key: string]: unknown
}

export interface AutomationPlaceholderOption {
  value: string
  label: string
  group: 'workflow' | 'record' | 'changes' | 'secrets'
}

export const CONDITION_OPERATOR_OPTIONS: Array<{
  value: AutomationConditionOperator
  labelKey: string
}> = [
  { value: 'eq', labelKey: 'labels.automations.conditionOperators.eq' },
  { value: 'ne', labelKey: 'labels.automations.conditionOperators.ne' },
  { value: 'contains', labelKey: 'labels.automations.conditionOperators.contains' },
  { value: 'gt', labelKey: 'labels.automations.conditionOperators.gt' },
  { value: 'gte', labelKey: 'labels.automations.conditionOperators.gte' },
  { value: 'lt', labelKey: 'labels.automations.conditionOperators.lt' },
  { value: 'lte', labelKey: 'labels.automations.conditionOperators.lte' },
  { value: 'in', labelKey: 'labels.automations.conditionOperators.in' },
  { value: 'nin', labelKey: 'labels.automations.conditionOperators.nin' },
  { value: 'exists', labelKey: 'labels.automations.conditionOperators.exists' },
  { value: 'empty', labelKey: 'labels.automations.conditionOperators.empty' },
]

export function objectToRows(object: Record<string, unknown> | undefined | null): KeyValueRow[] {
  if (!object) {
    return []
  }

  return Object.entries(object).map(([key, value]) => ({
    key,
    value: String(value ?? ''),
  }))
}

export function rowsToObject(rows: KeyValueRow[]): Record<string, string> {
  return rows.reduce<Record<string, string>>((result, row) => {
    const key = row.key.trim()
    const value = row.value.trim()
    if (!key) {
      return result
    }

    result[key] = value
    return result
  }, {})
}

export function valuesToRows(values: string[] | undefined | null): StringValueRow[] {
  return (values || []).map((value) => ({ value }))
}

export function rowsToValues(rows: StringValueRow[]): string[] {
  return rows.map((row) => row.value.trim()).filter((value) => value.length > 0)
}

export function conditionsToRows(
  conditions: AutomationConditionRule[] | undefined | null
): ConditionRow[] {
  return (conditions || []).map((condition) => ({
    path: condition.path,
    operator: condition.operator,
    value: String(condition.value ?? ''),
  }))
}

export function rowsToConditions(rows: ConditionRow[]): AutomationConditionRule[] {
  return rows
    .map((row) => ({
      path: row.path.trim(),
      operator: row.operator,
      value: row.value.trim(),
    }))
    .filter((row) => row.path.length > 0)
}

export function defaultActionConfig(type: AutomationActionType): AutomationActionConfig {
  switch (type) {
    case 'webhook':
      return {
        method: 'POST',
        timeout_seconds: 15,
        headers: {},
        parameters: {},
      }
    case 'email':
      return {
        to: [],
        cc: [],
        bcc: [],
        reply_to: [],
        subject: '',
        body: '',
      }
    case 'void':
      return {
        message: '',
      }
  }
}

export function defaultTriggerConfig(type: AutomationTriggerType): AutomationTriggerConfig {
  switch (type) {
    case 'time_based':
      return {
        schedule: '0 * * * *',
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        payload: {},
        conditions: [],
      }
    case 'manual':
      return {
        payload: {},
        conditions: [],
      }
    case 'content_published':
    case 'content_unpublished':
      return {
        payload: {},
        conditions: [],
      }
    default:
      return {
        table: '',
        watch_columns: [],
        payload: {},
        conditions: [],
      }
  }
}

export function isEventTrigger(type: AutomationTriggerType): boolean {
  return ['on_insert', 'on_update', 'on_delete'].includes(type)
}

export function isContentLifecycleTrigger(type: AutomationTriggerType): boolean {
  return ['content_published', 'content_unpublished'].includes(type)
}

export function getTriggerTable(config?: AutomationTriggerConfig | null): string {
  return String(config?.table || config?.resource || '').trim()
}

export function findTriggerTableDefinition(
  table: string,
  catalog?: AutomationTriggerCatalogResource | null
): AutomationTriggerTableDefinition | null {
  if (!table || !catalog) {
    return null
  }

  return catalog.tables.find((entry) => entry.table === table) || null
}

export function getTriggerTableLabel(
  config?: AutomationTriggerConfig | null,
  catalog?: AutomationTriggerCatalogResource | null
): string {
  const table = getTriggerTable(config)

  if (!table) {
    return ''
  }

  return findTriggerTableDefinition(table, catalog)?.label || table
}

export function buildConditionPathOptions(
  type: AutomationTriggerType,
  tableDefinition?: AutomationTriggerTableDefinition | null
): TriggerOption[] {
  const options: TriggerOption[] = [
    { value: 'source', label: 'Source' },
    { value: 'actor.id', label: 'Actor -> id' },
    { value: 'record_id', label: 'Record -> id' },
  ]

  if (!tableDefinition) {
    return options
  }

  if (tableDefinition.table === 'contents') {
    options.push({ value: 'cache_tags', label: 'Cache tags' })
  }

  for (const column of tableDefinition.columns) {
    options.push({
      value: `record.${column}`,
      label: `Record -> ${column}`,
    })
  }

  if (type === 'on_update') {
    options.push({ value: 'changed_fields', label: 'Changed fields' })

    for (const column of tableDefinition.columns) {
      options.push({
        value: `previous.${column}`,
        label: `Before -> ${column}`,
      })
      options.push({
        value: `changes.${column}.before`,
        label: `Change -> ${column} (before)`,
      })
      options.push({
        value: `changes.${column}.after`,
        label: `Change -> ${column} (after)`,
      })
    }
  }

  return options
}

export function buildWatchColumnOptions(
  tableDefinition?: AutomationTriggerTableDefinition | null
): TriggerOption[] {
  if (!tableDefinition) {
    return []
  }

  return tableDefinition.columns.map((column) => ({
    value: column,
    label: column,
  }))
}

export function defaultPlaceholderTable(catalog?: AutomationTriggerCatalogResource | null): string {
  if (!catalog || catalog.tables.length === 0) {
    return ''
  }

  return (
    catalog.tables.find((table) => table.table === 'contents')?.table || catalog.tables[0].table
  )
}

export function formatAutomationPlaceholderToken(value: string): string {
  return `{{ ${value.trim()} }}`
}

export function buildAutomationPlaceholderOptions(
  tableDefinition?: AutomationTriggerTableDefinition | null,
  secretKeys: string[] = []
): AutomationPlaceholderOption[] {
  const options: AutomationPlaceholderOption[] = [
    { value: 'automation.name', label: 'automation.name', group: 'workflow' },
    { value: 'automation.description', label: 'automation.description', group: 'workflow' },
    { value: 'action.name', label: 'action.name', group: 'workflow' },
    { value: 'action.type', label: 'action.type', group: 'workflow' },
    { value: 'space.name', label: 'space.name', group: 'workflow' },
    { value: 'trigger.type', label: 'trigger.type', group: 'workflow' },
    { value: 'triggered_at', label: 'triggered_at', group: 'workflow' },
    { value: 'actor.id', label: 'actor.id', group: 'workflow' },
    { value: 'record_id', label: 'record_id', group: 'workflow' },
  ]

  const alias = singularizeTableName(tableDefinition?.table || '')

  if (tableDefinition) {
    const recordOptions: AutomationPlaceholderOption[] = [
      { value: 'record.id', label: 'record.id', group: 'record' as const },
      { value: 'record.title', label: 'record.title', group: 'record' as const },
      { value: `${alias}.id`, label: `${alias}.id`, group: 'record' as const },
      { value: `${alias}.title`, label: `${alias}.title`, group: 'record' as const },
    ]

    for (const column of tableDefinition.columns) {
      recordOptions.push({
        value: `record.${column}`,
        label: `record.${column}`,
        group: 'record',
      })
      recordOptions.push({
        value: `${alias}.${column}`,
        label: `${alias}.${column}`,
        group: 'record',
      })
      recordOptions.push({
        value: `previous.${column}`,
        label: `previous.${column}`,
        group: 'changes',
      })
      recordOptions.push({
        value: `changes.${column}.before`,
        label: `changes.${column}.before`,
        group: 'changes',
      })
      recordOptions.push({
        value: `changes.${column}.after`,
        label: `changes.${column}.after`,
        group: 'changes',
      })
    }

    options.push(...recordOptions)
    options.push({ value: 'changed_fields', label: 'changed_fields', group: 'changes' })
    options.push({ value: 'meta.table_label', label: 'meta.table_label', group: 'workflow' })

    if (tableDefinition.table === 'contents') {
      options.push({ value: 'cache_tags', label: 'cache_tags', group: 'record' })
      options.push({ value: 'cache.ttl', label: 'cache.ttl', group: 'record' })
    }
  }

  const normalizedSecretKeys = [...new Set(secretKeys.map((key) => key.trim()).filter(Boolean))]

  if (normalizedSecretKeys.length === 0) {
    options.push({ value: 'secret.api_key', label: 'secret.api_key', group: 'secrets' })
  } else {
    for (const key of normalizedSecretKeys) {
      options.push({
        value: `secret.${key}`,
        label: `secret.${key}`,
        group: 'secrets',
      })
    }
  }

  return dedupePlaceholderOptions(options)
}

function dedupePlaceholderOptions(
  options: AutomationPlaceholderOption[]
): AutomationPlaceholderOption[] {
  const seen = new Set<string>()

  return options.filter((option) => {
    if (!option.value || seen.has(option.value)) {
      return false
    }

    seen.add(option.value)
    return true
  })
}

function singularizeTableName(table: string): string {
  if (!table) {
    return 'record'
  }

  return table
    .split('_')
    .map((segment) => {
      if (segment.endsWith('ies')) {
        return `${segment.slice(0, -3)}y`
      }

      if (segment.endsWith('s')) {
        return segment.slice(0, -1)
      }

      return segment
    })
    .join('_')
}

export function getActionTypeLabel(t: TranslateFn, type: AutomationActionType): string {
  return t(`labels.automationActions.types.${type}`)
}

export function getTriggerTypeLabel(t: TranslateFn, type: AutomationTriggerType): string {
  return t(`labels.automations.triggerTypes.${type}`)
}

export function summarizeAction(action: AutomationActionResource, t: TranslateFn): string {
  switch (action.type) {
    case 'webhook':
      return action.config.url || t('labels.automationActions.summary.webhook')
    case 'email':
      return action.config.subject || t('labels.automationActions.summary.email')
    case 'void':
      return action.config.message || t('labels.automationActions.summary.void')
  }
}

export function summarizeTrigger(
  automation: AutomationResource,
  t: TranslateFn,
  catalog?: AutomationTriggerCatalogResource | null
): string {
  const config = automation.trigger.config || {}

  switch (automation.trigger_type) {
    case 'time_based':
      return config.schedule || t('labels.automations.summary.timeBased')
    case 'manual':
      return t('labels.automations.summary.manual')
    default:
      return getTriggerTableLabel(config, catalog) || t('labels.automations.summary.anyResource')
  }
}
