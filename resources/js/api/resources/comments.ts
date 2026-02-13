import type { ApiResponse, BaseQueryParams } from '~/types'
import type { CommentResource, CreateCommentRequest, UpdateCommentRequest } from '~/types/comments'

import type { ApiClient } from '../client'

import { BaseResource } from './base-resource'

export interface CommentsQueryParams extends BaseQueryParams {
  filter?: {
    content_version_id?: string
    item_id?: string
    field?: string
    is_resolved?: boolean
    parent_id?: string | null
  }
}

export class Comments extends BaseResource<
  CommentResource,
  CreateCommentRequest,
  UpdateCommentRequest,
  CommentsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string, contentId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/contents/${contentId}/comments`
  }

  public async resolve(commentId: string): Promise<ApiResponse<CommentResource>> {
    return this.client.post<ApiResponse<CommentResource>>(`${this.basePath}/${commentId}/resolve`)
  }

  public async unresolve(commentId: string): Promise<ApiResponse<CommentResource>> {
    return this.client.delete<ApiResponse<CommentResource>>(`${this.basePath}/${commentId}/resolve`)
  }

  public async addReaction(
    commentId: string,
    emoji: string
  ): Promise<ApiResponse<CommentResource>> {
    return this.client.post<ApiResponse<CommentResource>>(
      `${this.basePath}/${commentId}/reactions`,
      {
        emoji,
      }
    )
  }

  public async removeReaction(commentId: string, emoji: string): Promise<void> {
    await this.client.request<ApiResponse<CommentResource>>(
      `${this.basePath}/${commentId}/reactions`,
      {
        method: 'DELETE',
        body: { emoji },
      }
    )
  }
}
