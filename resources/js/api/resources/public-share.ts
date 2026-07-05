import type {
  DownloadUrlResponse,
  PublicShareAssetsResponse,
  PublicShareResource,
  ShareDownloadBuildingResponse,
  ShareUnlockResponse,
} from '~/types/asset-distribution'

import type { ApiClient } from '../client'

/**
 * Unauthenticated public share API (`/mgmt/v1/shares/{space}/{token}`). The
 * space id is part of the address because shares live in each space's own
 * database.
 *
 * Auth in this app is cookie/session based, so the shared ApiClient issues no
 * Authorization header by itself — these endpoints simply ignore any session.
 * Password-protected shares are unlocked via a short-lived access token that
 * is sent explicitly as `Authorization: Bearer <token>` on each call.
 */
export class PublicShare {
  private readonly client: ApiClient
  private readonly basePath: string

  constructor(client: ApiClient, spaceId: string, token: string) {
    this.client = client
    this.basePath = `/mgmt/v1/shares/${spaceId}/${token}`
  }

  private headers(accessToken?: string | null): Record<string, string> {
    return accessToken ? { Authorization: `Bearer ${accessToken}` } : {}
  }

  public async show(accessToken?: string | null): Promise<{ data: PublicShareResource }> {
    return this.client.get<{ data: PublicShareResource }>(
      this.basePath,
      {},
      { headers: this.headers(accessToken) }
    )
  }

  public async unlock(password: string): Promise<ShareUnlockResponse> {
    return this.client.post<ShareUnlockResponse>(`${this.basePath}/unlock`, { password })
  }

  public async assets(
    params: { page?: number; per_page?: number } = {},
    accessToken?: string | null
  ): Promise<PublicShareAssetsResponse> {
    return this.client.get<PublicShareAssetsResponse>(
      `${this.basePath}/assets`,
      params as Record<string, unknown>,
      { headers: this.headers(accessToken) }
    )
  }

  /** Returns either a signed URL (200) or a building state (202). */
  public async download(
    accessToken?: string | null
  ): Promise<DownloadUrlResponse | ShareDownloadBuildingResponse> {
    return this.client.get<DownloadUrlResponse | ShareDownloadBuildingResponse>(
      `${this.basePath}/download`,
      {},
      { headers: this.headers(accessToken) }
    )
  }

  public async downloadAsset(
    assetId: string,
    accessToken?: string | null
  ): Promise<DownloadUrlResponse> {
    return this.client.get<DownloadUrlResponse>(
      `${this.basePath}/assets/${assetId}/download`,
      {},
      { headers: this.headers(accessToken) }
    )
  }
}
