import { requestExportBlob, requestImportJson } from '~/lib/import-export'
import type { ApiCollectionResponse, ApiResponse, BaseQueryParams } from '~/types'
import type {
  CreateDataEntryPayload,
  CreateDataSourcePayload,
  DataEntryImportExportFormat,
  DataEntryImportMode,
  DataEntryImportResult,
  DataEntryQueryParams,
  DataEntryResource,
  DataSourceResource,
  UpdateDataEntryPayload,
  UpdateDataSourcePayload,
} from '~/types/data-sources'

import type { ApiClient } from '../client'
import { BaseResource } from './base-resource'

export interface DataSourcesQueryParams extends BaseQueryParams {
  name?: string
  slug?: string
  is_active?: boolean
}

export class DataSources extends BaseResource<
  DataSourceResource,
  CreateDataSourcePayload,
  UpdateDataSourcePayload,
  DataSourcesQueryParams
> {
  protected basePath: string

  constructor(client: ApiClient, spaceId: string) {
    super(client)
    this.basePath = `/mgmt/v1/spaces/${spaceId}/data-sources`
  }

  public async getEntries(dataSourceId: string, params: DataEntryQueryParams = {}) {
    return this.client.get<ApiCollectionResponse<DataEntryResource>>(
      `${this.basePath}/${dataSourceId}/entries`,
      params as Record<string, unknown>
    )
  }

  public async getEntry(dataSourceId: string, entryId: string) {
    return this.client.get<ApiResponse<DataEntryResource>>(
      `${this.basePath}/${dataSourceId}/entries/${entryId}`
    )
  }

  public async createEntry(dataSourceId: string, payload: CreateDataEntryPayload) {
    return this.client.post<ApiResponse<DataEntryResource>>(
      `${this.basePath}/${dataSourceId}/entries`,
      payload
    )
  }

  public async updateEntry(dataSourceId: string, entryId: string, payload: UpdateDataEntryPayload) {
    return this.client.patch<ApiResponse<DataEntryResource>>(
      `${this.basePath}/${dataSourceId}/entries/${entryId}`,
      payload
    )
  }

  public async deleteEntry(dataSourceId: string, entryId: string) {
    return this.client.delete(`${this.basePath}/${dataSourceId}/entries/${entryId}`)
  }

  public async exportEntries(
    dataSourceId: string,
    params: { as: DataEntryImportExportFormat }
  ): Promise<Blob> {
    return requestExportBlob({
      client: this.client,
      endpoint: `${this.basePath}/${dataSourceId}/entries/export`,
      payload: params,
    })
  }

  public async importEntries(
    dataSourceId: string,
    file: File,
    mode: DataEntryImportMode = 'addition'
  ): Promise<DataEntryImportResult> {
    return requestImportJson<DataEntryImportResult>({
      client: this.client,
      endpoint: `${this.basePath}/${dataSourceId}/entries/import`,
      file,
      extraFields: { import_mode: mode },
    })
  }
}
