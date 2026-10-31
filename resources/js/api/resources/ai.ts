import type { AiAttachment } from '~/lib/aiAttachments'
import type { AiDecision, AiDecisionResult, AiEntryScope, AiPreflight } from '~/lib/aiChangesets'
import type { AiQuestionnaireAnswers, AiRunRecord, StartAiRunRequest } from '~/lib/aiRun'
import type { AiThread, AiThreadDetail, AiThreadList, AiThreadListParams, AiThreadPatch } from '~/lib/aiThreads'
import { xhrUpload } from '~/lib/xhr-upload'

import type { ApiClient } from '../client'

export interface AiResolvedSkill {
  name: string
  description: string
  surfaces: string[]
  blocks: string[]
  output: 'text' | 'richtext' | 'json'
  source: AiScope | 'system'
  owner_id: string | null
  skill_id: string | null
  shadows: Array<{ source: AiScope | 'system'; owner_id: string | null }>
}

export type AiScope = 'space' | 'team'

export interface MentionItem {
  type: 'content' | 'block' | 'draft-content' | 'asset' | 'document'
  id: string
  content: Record<string, unknown> | null
  label: string
}

export interface ClassifyAssetsPayload {
  scope: 'selection' | 'all'
  asset_ids?: string[]
  languages?: string[]
  config_id?: string | null
  /** Regenerate every allowed field instead of filling empty ones */
  overwrite?: boolean
  alt_context?: string
  decorative?: boolean
  high_detail?: boolean
}

export interface ClassifyAssetsResult {
  run_id: string
  queued: number
  skipped: number
}

export interface ClassifyAssetsProgress {
  run_id: string
  queued: number
  pending: number
  updated: number
  skipped: number
  failed: number
  complete: boolean
}

export type AiScheduleTargetType = AiEntryScope['type']

export interface AiSchedulePayload {
  name: string
  skill: string
  scope: { type: AiScheduleTargetType; filters: Record<string, string> }
  cron: string
  max_usd: number
  enabled: boolean
}

export interface AiSchedule extends AiSchedulePayload {
  id: string
  paused_reason: 'access_revoked' | 'skill_unavailable' | null
  creator: { id: string; name: string } | null
  last_run_at: string | null
  last_run: { id: string; state: AiRunRecord['state'] } | null
  next_run_at: string | null
}

export interface AiSchedules {
  data: AiSchedule[]
  /** Skills each scope type can run on a schedule */
  skills: Record<AiScheduleTargetType, Array<{ name: string; description: string }>>
}

/** How hard a reasoning model thinks; `off` only where the model can skip it. */
export type AiReasoningEffort = 'off' | 'low' | 'medium' | 'high'

export const AI_REASONING_EFFORTS = ['off', 'low', 'medium', 'high'] as const satisfies readonly AiReasoningEffort[]

/** The level a config uses when it doesn't set one. */
export const DEFAULT_REASONING_EFFORT: AiReasoningEffort = 'medium'

/** What a config's model can do, resolved from the model catalogue. */
export interface AiModelInfo {
  name: string
  supports_vision: boolean
  supports_tools: boolean
  supports_reasoning: boolean
  reasoning_required: boolean
}

export interface SpaceAiConfig {
  id: string
  name: string
  driver: string
  model: string
  system_prompt: string | null
  temperature: number
  max_tokens: number
  reasoning_effort: AiReasoningEffort | null
  /** Null when the model is no longer in the catalogue. */
  model_info: AiModelInfo | null
  is_default: boolean
  created_at: string
  updated_at: string
}

/** What a config is created or updated with; `model_info` is resolved by the server. */
export type SpaceAiConfigPayload = Omit<
  SpaceAiConfig,
  'id' | 'created_at' | 'updated_at' | 'model_info' | 'reasoning_effort'
> & { reasoning_effort?: AiReasoningEffort | null }

export class Ai {
  protected client: ApiClient
  protected spaceId?: string

  constructor(client: ApiClient, spaceId?: string) {
    this.client = client
    this.spaceId = spaceId
  }

  public async getSurfaceSkills(
    surface: string,
    block?: string | null
  ): Promise<{ data: AiResolvedSkill[] }> {
    return this.client.get<{ data: AiResolvedSkill[] }>(
      `/mgmt/v1/spaces/${this.spaceId}/ai/context`,
      { surface, block: block ?? undefined }
    )
  }

  public async getRun(runId: string): Promise<{ data: AiRunRecord }> {
    return this.client.get<{ data: AiRunRecord }>(`/mgmt/v1/spaces/${this.spaceId}/ai/runs/${runId}`)
  }

  public async getRunEvents(
    runId: string,
    after: number
  ): Promise<{ data: unknown[]; run: AiRunRecord }> {
    return this.client.get<{ data: unknown[]; run: AiRunRecord }>(
      `/mgmt/v1/spaces/${this.spaceId}/ai/runs/${runId}/events`,
      { after }
    )
  }

  /** Start a background run; inline runs stream through useAiRun instead. */
  public async startBackgroundRun(payload: StartAiRunRequest): Promise<{ data: AiRunRecord }> {
    return this.client.post<{ data: AiRunRecord }>(`/mgmt/v1/spaces/${this.spaceId}/ai/runs`, {
      idempotency_key: crypto.randomUUID(),
      ...payload,
      background: true,
    })
  }

  /** Answer a background run's questionnaire; it continues on the queue. */
  public async answerRun(runId: string, answers: AiQuestionnaireAnswers): Promise<{ data: AiRunRecord }> {
    return this.client.post<{ data: AiRunRecord }>(
      `/mgmt/v1/spaces/${this.spaceId}/ai/runs/${runId}/answers`,
      { answers }
    )
  }

  public async cancelRun(runId: string): Promise<{ data: AiRunRecord }> {
    return this.client.post<{ data: AiRunRecord }>(
      `/mgmt/v1/spaces/${this.spaceId}/ai/runs/${runId}/cancel`
    )
  }

  public async preflight(payload: {
    surface: string
    scope: AiEntryScope
    skill?: string | null
    config_id?: string | null
  }): Promise<{ data: AiPreflight }> {
    return this.client.post<{ data: AiPreflight }>(`/mgmt/v1/spaces/${this.spaceId}/ai/preflight`, payload)
  }

  /** Upload a file for the next message; progress is 0 to 100. */
  public async uploadAttachment(
    file: File,
    options: { onProgress?: (progress: number) => void; signal?: AbortSignal } = {}
  ): Promise<AiAttachment> {
    const formData = new FormData()
    formData.append('file', file)
    const response = await xhrUpload<{ data: AiAttachment }>(
      `/mgmt/v1/spaces/${this.spaceId}/ai/attachments`,
      formData,
      options
    )
    return response.data
  }

  public async getThreads(params: AiThreadListParams): Promise<AiThreadList> {
    return this.client.get<AiThreadList>(`/mgmt/v1/spaces/${this.spaceId}/ai/threads`, {
      archived: params.archived ? 1 : 0,
      search: params.search || undefined,
    })
  }

  public async getThread(threadId: string): Promise<{ data: AiThreadDetail }> {
    return this.client.get<{ data: AiThreadDetail }>(`/mgmt/v1/spaces/${this.spaceId}/ai/threads/${threadId}`)
  }

  public async updateThread(threadId: string, patch: AiThreadPatch): Promise<{ data: AiThread }> {
    return this.client.patch<{ data: AiThread }>(
      `/mgmt/v1/spaces/${this.spaceId}/ai/threads/${threadId}`,
      patch
    )
  }

  public async acceptChangeset(changesetId: string, decision: AiDecision = {}): Promise<AiDecisionResult> {
    return this.client.post<AiDecisionResult>(
      `/mgmt/v1/spaces/${this.spaceId}/ai/changesets/${changesetId}/accept`,
      decision
    )
  }

  public async rejectChangeset(changesetId: string, decision: AiDecision = {}): Promise<AiDecisionResult> {
    return this.client.post<AiDecisionResult>(
      `/mgmt/v1/spaces/${this.spaceId}/ai/changesets/${changesetId}/reject`,
      decision
    )
  }

  public async acceptSuggestion(suggestionId: string, glossaryId: string): Promise<void> {
    await this.client.post(`/mgmt/v1/spaces/${this.spaceId}/ai/suggestions/${suggestionId}/accept`, {
      glossary_id: glossaryId,
    })
  }

  public async dismissSuggestion(suggestionId: string): Promise<void> {
    await this.client.post(`/mgmt/v1/spaces/${this.spaceId}/ai/suggestions/${suggestionId}/dismiss`)
  }

  public async getSchedules(): Promise<AiSchedules> {
    return this.client.get<AiSchedules>(`/mgmt/v1/spaces/${this.spaceId}/ai/schedules`)
  }

  public async createSchedule(payload: AiSchedulePayload): Promise<{ data: AiSchedule }> {
    return this.client.post<{ data: AiSchedule }>(`/mgmt/v1/spaces/${this.spaceId}/ai/schedules`, payload)
  }

  public async updateSchedule(scheduleId: string, payload: Partial<AiSchedulePayload>): Promise<{ data: AiSchedule }> {
    return this.client.patch<{ data: AiSchedule }>(
      `/mgmt/v1/spaces/${this.spaceId}/ai/schedules/${scheduleId}`,
      payload
    )
  }

  public async deleteSchedule(scheduleId: string): Promise<void> {
    return this.client.delete(`/mgmt/v1/spaces/${this.spaceId}/ai/schedules/${scheduleId}`)
  }

  public async getAiConfigs(): Promise<{ data: SpaceAiConfig[] }> {
    return this.client.get<{ data: SpaceAiConfig[] }>(`/mgmt/v1/spaces/${this.spaceId}/ai-configs`)
  }

  public async getAiConfig(configId: string): Promise<{ data: SpaceAiConfig }> {
    return this.client.get<{ data: SpaceAiConfig }>(
      `/mgmt/v1/spaces/${this.spaceId}/ai-configs/${configId}`
    )
  }

  public async createAiConfig(
    payload: Omit<SpaceAiConfig, 'id' | 'created_at' | 'updated_at'>
  ): Promise<{ data: SpaceAiConfig }> {
    return this.client.post<{ data: SpaceAiConfig }>(
      `/mgmt/v1/spaces/${this.spaceId}/ai-configs`,
      payload
    )
  }

  public async updateAiConfig(
    configId: string,
    payload: Partial<SpaceAiConfig>
  ): Promise<{ data: SpaceAiConfig }> {
    return this.client.patch<{ data: SpaceAiConfig }>(
      `/mgmt/v1/spaces/${this.spaceId}/ai-configs/${configId}`,
      payload
    )
  }

  public async deleteAiConfig(configId: string): Promise<void> {
    return this.client.delete(`/mgmt/v1/spaces/${this.spaceId}/ai-configs/${configId}`)
  }

  public async classifyAssets(payload: ClassifyAssetsPayload): Promise<{ data: ClassifyAssetsResult }> {
    return this.client.post<{ data: ClassifyAssetsResult }>(
      `/mgmt/v1/ai/assets/classify?spaceId=${this.spaceId}`,
      payload
    )
  }

  public async getClassificationRun(runId: string): Promise<{ data: ClassifyAssetsProgress }> {
    return this.client.get<{ data: ClassifyAssetsProgress }>(
      `/mgmt/v1/ai/assets/classify/${runId}?spaceId=${this.spaceId}`
    )
  }
}
