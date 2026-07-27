import type { BaseQueryParams } from '~/types'

import type { ApiClient, RequestOptions } from '../client'

export interface PersonalAccessTokenQueryParams extends BaseQueryParams {
  per_page?: number
}

export interface PersonalAccessTokenCreatePayload {
  name: string
  expires_at: string
}

/**
 * The step-up credential the API asks for before it mints a token: a TOTP (or
 * backup) code when a second factor is enrolled, the account password when it
 * is not. Which one is required comes from the 423 the API answers with, so
 * the client never has to guess.
 */
export interface StepUpCredential {
  factor: 'totp' | 'password'
  value: string
}

const stepUpHeaders = (credential?: StepUpCredential): RequestOptions => {
  if (!credential) return {}

  return {
    headers: {
      [credential.factor === 'totp' ? 'x-totp-code' : 'x-password-confirmation']: credential.value,
    },
  }
}

export class PersonalAccessTokens {
  private client: ApiClient
  private basePath: string = '/mgmt/v1/users/me/tokens'

  constructor(client: ApiClient) {
    this.client = client
  }

  public async index(
    query: PersonalAccessTokenQueryParams = {}
  ): Promise<PersonalAccessTokenListResponse> {
    return this.client.get<PersonalAccessTokenListResponse>(
      this.basePath,
      query as Record<string, unknown>
    )
  }

  public async create(
    payload: PersonalAccessTokenCreatePayload,
    credential?: StepUpCredential
  ): Promise<PersonalAccessTokenCreateResponse> {
    return this.client.post<PersonalAccessTokenCreateResponse>(
      this.basePath,
      payload,
      stepUpHeaders(credential)
    )
  }

  public async delete(id: number | string): Promise<void> {
    return this.client.delete(`${this.basePath}/${id}`)
  }
}
