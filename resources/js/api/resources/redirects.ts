import { requestExportBlob, requestImportJson } from '~/lib/import-export'

import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface RedirectsQueryParams extends BaseQueryParams {
  source?: string
  target?: string
  status_code?: number
}

export class Redirects extends BaseResource<
  RedirectResource,
  CreateRedirectPayload,
  UpdateRedirectPayload,
  RedirectsQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/redirects`
  }

  public async reset(id: string): Promise<ApiResponse<RedirectResource>> {
    return this.client.post<ApiResponse<RedirectResource>>(`${this.basePath}/${id}/reset`)
  }

  public async export(params: RedirectsQueryParams & { as: RedirectImportExportFormat }): Promise<Blob> {
    return requestExportBlob({
      client: this.client,
      endpoint: `${this.basePath}/export`,
      payload: params,
    })
  }

  public async importData(file: File, mode: RedirectImportMode = 'addition'): Promise<RedirectDataImportResult> {
    return requestImportJson<RedirectDataImportResult>({
      client: this.client,
      endpoint: `${this.basePath}/import`,
      file,
      extraFields: { import_mode: mode },
    })
  }
}
