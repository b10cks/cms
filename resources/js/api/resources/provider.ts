import { BaseResource } from './base-resource'

export class Provider extends BaseResource<
  ProviderNote,
  ProviderNotePayload,
  Partial<ProviderNotePayload>,
  BaseQueryParams
> {
  protected basePath = '/mgmt/v1/provider/notes'

  public async getStats(params: ProviderStatsQueryParams = {}): Promise<ApiResponse<ProviderStats>> {
    return this.client.get<ApiResponse<ProviderStats>>('/mgmt/v1/provider/stats', {
      ...params,
    })
  }

  public async getMetrics(range: ProviderMetricsRange): Promise<ApiResponse<ProviderMetrics>> {
    return this.client.get<ApiResponse<ProviderMetrics>>('/mgmt/v1/provider/metrics', { range })
  }

  public async listNotes(
    params: BaseQueryParams = {}
  ): Promise<ApiCollectionResponse<ProviderNote>> {
    return this.index(params)
  }
}
