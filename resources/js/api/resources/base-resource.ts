import type { ApiResponse, ApiCollectionResponse, BaseQueryParams } from '~/types'

import type { ApiClient } from '../client'

export abstract class BaseResource<
  T,
  CreatePayload = unknown,
  UpdatePayload = unknown,
  QueryParams extends BaseQueryParams = BaseQueryParams,
> {
  protected client: ApiClient
  protected abstract basePath: string

  constructor(client: ApiClient) {
    this.client = client
  }

  protected getPath(endpoint: string = ''): string {
    if (endpoint.startsWith('/')) {
      return `${this.basePath}${endpoint}`
    }
    return endpoint ? `${this.basePath}/${endpoint}` : this.basePath
  }

  public async index(query: QueryParams = {} as QueryParams): Promise<ApiCollectionResponse<T>> {
    return this.client.get<ApiCollectionResponse<T>>(
      this.basePath,
      query as Record<string, unknown>
    )
  }

  public async get(id: string, query: QueryParams = {} as QueryParams): Promise<ApiResponse<T>> {
    return this.client.get<ApiResponse<T>>(
      `${this.basePath}/${id}`,
      query as Record<string, unknown>
    )
  }

  public async create(payload: CreatePayload): Promise<ApiResponse<T>> {
    return this.client.post<ApiResponse<T>>(this.basePath, payload)
  }

  public async update(id: string, payload: UpdatePayload): Promise<ApiResponse<T>> {
    return this.client.patch<ApiResponse<T>>(`${this.basePath}/${id}`, payload)
  }

  public async replace(id: string, payload: CreatePayload): Promise<ApiResponse<T>> {
    return this.client.put<ApiResponse<T>>(`${this.basePath}/${id}`, payload)
  }

  public async delete(id: string): Promise<void> {
    return this.client.delete(`${this.basePath}/${id}`)
  }

  public async custom<R = unknown>(
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    endpoint: string,
    payload?: unknown
  ): Promise<R> {
    const path = this.getPath(endpoint)

    switch (method) {
      case 'GET':
        return this.client.get<R>(path)
      case 'POST':
        return this.client.post<R>(path, payload)
      case 'PUT':
        return this.client.put<R>(path, payload)
      case 'PATCH':
        return this.client.patch<R>(path, payload)
      case 'DELETE':
        return this.client.delete<R>(path)
    }
  }
}
