import type {
  MassEditField,
  MassEditRowsParams,
  MassEditRowsResponse,
  MassEditSavePayload,
  MassEditSaveResult,
} from '~/types/mass-edit'

import type { ApiClient } from '../client'

export class MassEdit {
  protected basePath: string

  constructor(
    protected client: ApiClient,
    spaceId: string
  ) {
    this.basePath = `/mgmt/v1/spaces/${spaceId}/mass-edit`
  }

  public async getFields(): Promise<{ data: MassEditField[] }> {
    return this.client.get<{ data: MassEditField[] }>(`${this.basePath}/fields`)
  }

  public async getRows(params: MassEditRowsParams): Promise<MassEditRowsResponse> {
    return this.client.get<MassEditRowsResponse>(
      `${this.basePath}/rows`,
      params as unknown as Record<string, unknown>
    )
  }

  public async save(payload: MassEditSavePayload): Promise<MassEditSaveResult> {
    return this.client.patch<MassEditSaveResult>(`${this.basePath}/rows`, payload)
  }
}
