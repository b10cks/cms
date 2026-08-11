type AutomationActionType = 'webhook' | 'email' | 'void'
type AutomationTriggerType =
  | 'on_insert'
  | 'on_update'
  | 'on_delete'
  | 'time_based'
  | 'manual'
  | 'content_published'
  | 'content_unpublished'
type AutomationExecutionStatus = 'queued' | 'completed' | 'failed' | 'running'
type AutomationConditionOperator =
  | 'eq'
  | 'ne'
  | 'contains'
  | 'gt'
  | 'gte'
  | 'lt'
  | 'lte'
  | 'in'
  | 'nin'
  | 'exists'
  | 'empty'

interface AutomationConditionRule {
  path: string
  operator: AutomationConditionOperator
  value?: string | number | boolean | null
}

interface AutomationTriggerConfig {
  resource?: string
  table?: string
  schedule?: string
  timezone?: string
  watch_columns?: string[]
  block_ids?: string[]
  payload?: Record<string, string>
  conditions?: AutomationConditionRule[]
}

interface AutomationTrigger {
  type: AutomationTriggerType
  config?: AutomationTriggerConfig
}

interface AutomationActionConfig {
  url?: string
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' | 'HEAD'
  timeout_seconds?: number
  headers?: Record<string, string>
  parameters?: Record<string, string>
  to?: string[]
  cc?: string[]
  bcc?: string[]
  reply_to?: string[]
  subject?: string
  body?: string
  message?: string
}

interface AutomationActionResource {
  id: string
  space_id: string
  name: string
  description?: string | null
  type: AutomationActionType
  config: AutomationActionConfig
  is_active: boolean
  has_secrets: boolean
  secret_keys: string[]
  automations_count?: number
  last_executed_at?: string | null
  last_execution_status?: AutomationExecutionStatus | null
  last_execution_error?: string | null
  created_at: string
  updated_at: string
}

interface AutomationResource {
  id: string
  space_id: string
  action_id: string
  action?: AutomationActionResource | null
  name: string
  description?: string | null
  trigger_type: AutomationTriggerType
  trigger: AutomationTrigger
  is_active: boolean
  execution_count: number
  execution_limit?: number | null
  remaining_executions?: number | null
  last_triggered_at?: string | null
  created_at: string
  updated_at: string
}

interface AutomationExecutionResource {
  id: string
  automation_id: string
  automation?: AutomationResource | null
  status: AutomationExecutionStatus
  context?: Record<string, unknown> | null
  result?: Record<string, unknown> | null
  error?: string | null
  duration?: number | null
  started_at?: string | null
  completed_at?: string | null
  created_at: string
}

interface CreateAutomationActionPayload {
  name: string
  description?: string
  type: AutomationActionType
  config: AutomationActionConfig
  secrets?: Record<string, string>
  is_active?: boolean
}

interface UpdateAutomationActionPayload {
  name?: string
  description?: string
  type?: AutomationActionType
  config?: AutomationActionConfig
  secrets?: Record<string, string>
  clear_secret_keys?: string[]
  is_active?: boolean
}

interface CreateAutomationPayload {
  name: string
  description?: string
  action_id: string
  trigger: AutomationTrigger
  is_active?: boolean
  execution_limit?: number | null
}

interface UpdateAutomationPayload {
  name?: string
  description?: string
  action_id?: string
  trigger?: AutomationTrigger
  is_active?: boolean
  execution_limit?: number | null
}

interface TriggerAutomationPayload {
  payload?: Record<string, string>
  content_id?: string
}

interface AutomationTriggerTableDefinition {
  table: string
  label: string
  description: string
  columns: string[]
}

interface AutomationContentLifecycleTrigger {
  trigger_type: 'content_published' | 'content_unpublished'
  table: 'contents'
  label: string
  description: string
}

interface AutomationTriggerCatalogResource {
  tables: AutomationTriggerTableDefinition[]
  content_lifecycle: AutomationContentLifecycleTrigger[]
}
