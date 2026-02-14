import type { ApiClient } from '../client'

export interface ContentInteractionPayload {
  prompt: string
  content: object | null
  content_id: string | null
  files?: Array<{ name: string; url: string; type: string }>
  model?: string | null
  mentions?: MentionItem[]
}

export interface ContentInteractionResponse {
  data: object
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

export class Ai {
  protected client: ApiClient
  protected spaceId?: string

  constructor(client: ApiClient, spaceId?: string) {
    this.client = client
    this.spaceId = spaceId
  }

  public async interactWithContent(
    payload: ContentInteractionPayload
  ): Promise<ContentInteractionResponse> {
    const query = this.spaceId ? { spaceId: this.spaceId } : {}
    return this.client.post<ContentInteractionResponse>(
      '/mgmt/v1/ai/content-interaction',
      payload,
      { query }
    )
  }

  public getStreamUrl(): string {
    const baseUrl = this.client.getBaseUrl()
    const query = this.spaceId ? `?spaceId=${this.spaceId}` : ''
    return `${baseUrl}/mgmt/v1/ai/content-interaction/stream${query}`
  }
}
