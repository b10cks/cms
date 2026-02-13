import { BaseResource } from './base-resource'

export interface TwoFactorSetupResponse {
  secret: string
  qrCodeUrl: string
}

export interface TwoFactorConfirmPayload {
  code: string
}

export interface TwoFactorConfirmResponse {
  message: string
  backup_codes: string[]
}

export interface TwoFactorVerifyPayload {
  code: string
}

export interface TwoFactorDisablePayload {
  password: string
}

export interface TwoFactorBackupCodesResponse {
  backup_codes: string[]
}

export interface TwoFactorStatusResponse {
  enabled: boolean
}

export class TwoFactorAuth extends BaseResource<TwoFactorStatusResponse, never, never, never> {
  protected basePath: string = '/auth/v1/2fa'

  public async setup(): Promise<TwoFactorSetupResponse> {
    return this.client.post<TwoFactorSetupResponse>(`${this.basePath}/setup`)
  }

  public async confirm(payload: TwoFactorConfirmPayload): Promise<TwoFactorConfirmResponse> {
    return this.client.post<TwoFactorConfirmResponse>(`${this.basePath}/setup/confirm`, payload)
  }

  public async verify(payload: TwoFactorVerifyPayload): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(`${this.basePath}/verify`, payload)
  }

  public async disable(payload: TwoFactorDisablePayload): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(`${this.basePath}/disable`, payload)
  }

  public async regenerateBackupCodes(): Promise<TwoFactorBackupCodesResponse> {
    return this.client.post<TwoFactorBackupCodesResponse>(
      `${this.basePath}/backup-codes/regenerate`
    )
  }

  public async status(): Promise<TwoFactorStatusResponse> {
    return this.client.get<TwoFactorStatusResponse>(`${this.basePath}/status`)
  }
}
