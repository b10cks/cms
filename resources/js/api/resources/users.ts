import type { ApiResponse } from '~/types'
import type { User } from '~/types/users'

import { BaseResource } from './base-resource'

export interface UpdateUserPayload {
  firstname?: string
  lastname?: string
  settings?: {
    languageIso?: string
  }
}

export interface ChangePasswordPayload {
  old_password: string
  new_password: string
}

export interface UploadAvatarResponse {
  avatar: string
}

export class Users extends BaseResource<User, never, UpdateUserPayload, never> {
  protected basePath: string = '/mgmt/v1/users'

  public async getMe(): Promise<ApiResponse<User>> {
    return this.client.get<ApiResponse<User>>(`${this.basePath}/me`)
  }

  public async updateMe(payload: UpdateUserPayload): Promise<ApiResponse<User>> {
    return this.client.patch<ApiResponse<User>>(`${this.basePath}/me`, payload)
  }

  public async changePassword(payload: ChangePasswordPayload): Promise<void> {
    return this.client.post(`${this.basePath}/me/password`, payload)
  }

  public async uploadAvatar(file: File): Promise<ApiResponse<UploadAvatarResponse>> {
    const formData = new FormData()
    formData.append('avatar', file)

    return this.client.post<ApiResponse<UploadAvatarResponse>>(
      `${this.basePath}/me/avatar`,
      formData,
      {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      }
    )
  }
}
