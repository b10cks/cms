import type { PasskeyCredentialJSON } from '~/lib/passkeys'

import type { ApiClient } from '../client'
import { stepUpHeaders, type StepUpCredential } from './personal-access-tokens'

export interface Passkey {
  id: string
  name: string
  aaguid: string | null
  created_at: string
  last_used_at: string | null
}

export interface PasskeyStorePayload {
  name: string
  credential: PasskeyCredentialJSON
}

/**
 * Passkeys of the signed-in user (`/mgmt/v1/users/me/passkeys`) and the
 * sign-in and confirmation ceremonies (`/auth/v1/passkeys`). Adding and
 * removing a passkey asks for a step-up first, like minting a token.
 */
export class Passkeys {
  private readonly basePath = '/mgmt/v1/users/me/passkeys'

  private readonly authPath = '/auth/v1/passkeys'

  constructor(private readonly client: ApiClient) {}

  public async index(): Promise<ApiResponse<Passkey[]>> {
    return this.client.get<ApiResponse<Passkey[]>>(this.basePath)
  }

  public async creationOptions(
    credential?: StepUpCredential
  ): Promise<PublicKeyCredentialCreationOptionsJSON> {
    return this.client.post<PublicKeyCredentialCreationOptionsJSON>(
      `${this.basePath}/options`,
      undefined,
      stepUpHeaders(credential)
    )
  }

  public async store(payload: PasskeyStorePayload): Promise<ApiResponse<Passkey>> {
    return this.client.post<ApiResponse<Passkey>>(this.basePath, payload)
  }

  public async rename(id: string, name: string): Promise<ApiResponse<Passkey>> {
    return this.client.patch<ApiResponse<Passkey>>(`${this.basePath}/${id}`, { name })
  }

  public async delete(id: string, credential?: StepUpCredential): Promise<void> {
    return this.client.delete(`${this.basePath}/${id}`, stepUpHeaders(credential))
  }

  public async loginOptions(): Promise<PublicKeyCredentialRequestOptionsJSON> {
    return this.client.post<PublicKeyCredentialRequestOptionsJSON>(`${this.authPath}/login/options`)
  }

  public async login(credential: PasskeyCredentialJSON): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(`${this.authPath}/login`, { credential })
  }

  public async confirmOptions(): Promise<PublicKeyCredentialRequestOptionsJSON> {
    return this.client.post<PublicKeyCredentialRequestOptionsJSON>(`${this.authPath}/confirm/options`)
  }

  public async confirm(credential: PasskeyCredentialJSON): Promise<{ message: string }> {
    return this.client.post<{ message: string }>(`${this.authPath}/confirm`, { credential })
  }
}
