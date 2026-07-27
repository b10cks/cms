import type { RequestOptions } from '../client'

import { BaseResource } from './base-resource'

/**
 * The password travels in `x-password-confirmation`, where the step-up
 * middleware verifies it and counts failures, rather than in the body where
 * each endpoint would have to re-check it for itself.
 */
const stepUpOptions = (password: string): RequestOptions => ({
  headers: { 'x-password-confirmation': password },
})

export interface TwoFactorSetupResponse {
  secret: string
  qrCodeUrl: string
}

export interface TwoFactorConfirmPayload {
  code: string
  password: string
}

export interface TwoFactorRegenerateBackupCodesPayload {
  password: string
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
    const { password, ...body } = payload

    return this.client.post<TwoFactorConfirmResponse>(
      `${this.basePath}/setup/confirm`,
      body,
      stepUpOptions(password)
    )
  }

  public async verify(payload: TwoFactorVerifyPayload): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(`${this.basePath}/verify`, payload)
  }

  public async disable(payload: TwoFactorDisablePayload): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(
      `${this.basePath}/disable`,
      undefined,
      stepUpOptions(payload.password)
    )
  }

  public async regenerateBackupCodes(
    payload: TwoFactorRegenerateBackupCodesPayload
  ): Promise<TwoFactorBackupCodesResponse> {
    return this.client.post<TwoFactorBackupCodesResponse>(
      `${this.basePath}/backup-codes/regenerate`,
      undefined,
      stepUpOptions(payload.password)
    )
  }

  public async status(): Promise<TwoFactorStatusResponse> {
    return this.client.get<TwoFactorStatusResponse>(`${this.basePath}/status`)
  }
}
