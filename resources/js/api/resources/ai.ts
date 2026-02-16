import type { ApiClient } from '../client'

export interface ContentInteractionPayload {
  prompt: string
  content: object | null
  content_id: string | null
  files?: Array<{ name: string; url: string; type: string }>
  config_id?: string | null
  model?: string | null // Deprecated: kept for backward compatibility
  mentions?: MentionItem[]
}

export interface AiStreamEvent {
  type: 'status' | 'delta' | 'done' | 'error'
  message?: string
  content?: string
  data?: Record<string, unknown>
}

export interface MentionItem {
  type: 'content' | 'block'
  id: string
  content: Record<string, unknown> | null
  label: string
}

export interface SpaceAiConfig {
  id: string
  name: string
  driver: string
  model: string
  system_prompt: string | null
  temperature: number
  max_tokens: number
  is_default: boolean
  created_at: string
  updated_at: string
}

export class Ai {
  protected client: ApiClient
  protected spaceId?: string

  constructor(client: ApiClient, spaceId?: string) {
    this.client = client
    this.spaceId = spaceId
  }

  public getStreamUrl(): string {
    const baseUrl = this.client.getBaseUrl()
    const query = this.spaceId ? `?spaceId=${this.spaceId}` : ''
    return `${baseUrl}/mgmt/v1/ai/content-interaction/stream${query}`
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
}
