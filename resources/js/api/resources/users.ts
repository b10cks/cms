import type { User } from '~/types/users'

import { BaseResource } from './base-resource'
import { stepUpHeaders, type StepUpCredential } from './personal-access-tokens'

export interface UpdateUserPayload {
  firstname?: string
  lastname?: string
}

export type UpdateUserSettingsPayload = NonNullable<User['settings']>

export interface ChangePasswordPayload {
  old_password: string
  new_password: string
}

export interface UserSocialLinkProvider {
  provider: string
  label: string
  linked: boolean
  linked_at?: string | null
}

export class Users extends BaseResource<User, never, UpdateUserPayload, never> {
  protected basePath: string = '/mgmt/v1/users'

  public async getMe(): Promise<ApiResponse<User>> {
    return this.client.get<ApiResponse<User>>(`${this.basePath}/me`)
  }

  public async updateMe(payload: UpdateUserPayload): Promise<ApiResponse<User>> {
    return this.client.patch<ApiResponse<User>>(`${this.basePath}/me`, payload)
  }

  public async updateSettings(payload: UpdateUserSettingsPayload): Promise<void> {
    return this.client.post(`${this.basePath}/me/settings`, payload)
  }

  public async changePassword(payload: ChangePasswordPayload): Promise<void> {
    return this.client.post(`${this.basePath}/me/password`, payload)
  }

  public async socialLinks(): Promise<ApiResponse<UserSocialLinkProvider[]>> {
    return this.client.get<ApiResponse<UserSocialLinkProvider[]>>(`${this.basePath}/me/social-links`)
  }

  /** A short-lived URL that starts the provider's OAuth flow. Needs a step-up credential. */
  public async startSocialLink(
    provider: string,
    returnPath: string,
    credential?: StepUpCredential
  ): Promise<ApiResponse<{ url: string }>> {
    return this.client.post<ApiResponse<{ url: string }>>(
      `${this.basePath}/me/social-links/${provider}`,
      { return: returnPath },
      stepUpHeaders(credential)
    )
  }

  public async unlinkSocialProvider(provider: string): Promise<void> {
    return this.client.delete(`${this.basePath}/me/social-links/${provider}`)
  }
}
