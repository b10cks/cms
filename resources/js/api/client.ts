import { getXsrfHeaders } from '~/lib/csrf'
import { isClient } from '~/lib/env'

interface AuthHandler {
  handleUnauthorized: (endpoint: string, options: any) => Promise<{ retry?: boolean } | void>
}

// Laravel answers an expired/mismatched session with 419 on stateful writes.
const CSRF_EXPIRED_STATUS = 419

export interface RequestOptions extends RequestInit {
  query?: Record<string, unknown>
  body?: any
}

export class ApiClient {
  private readonly baseURL: string
  private readonly defaultHeaders: Record<string, string>
  private authToken?: string
  private authHandler?: AuthHandler
  private csrfReady: boolean = false

  constructor(
    options: {
      baseURL?: string
      authToken?: string
      defaultHeaders?: Record<string, string>
    } = {}
  ) {
    this.baseURL = options.baseURL || ''
    this.authToken = options.authToken
    this.defaultHeaders = {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...options.defaultHeaders,
    }
  }

  public setAuthToken(token?: string): void {
    this.authToken = token
  }

  public setAuthHandler(handler: AuthHandler): void {
    this.authHandler = handler
  }

  public getAuthHeaders(): Record<string, string> {
    return this.authToken ? { Authorization: `Bearer ${this.authToken}` } : {}
  }

  private resolveUrl(endpoint: string, query?: Record<string, unknown>): string {
    let url = endpoint.startsWith('http') ? endpoint : `${this.baseURL}${endpoint}`
    if (query && Object.keys(query).length > 0) {
      const params = new URLSearchParams()
      for (const [key, value] of Object.entries(query)) {
        if (value !== undefined && value !== null) {
          params.set(key, String(value))
        }
      }
      url += `?${params.toString()}`
    }
    return url
  }

  public async ensureCsrfCookie(force: boolean = false): Promise<void> {
    if (!isClient) return
    if (this.csrfReady && !force) return

    try {
      const response = await fetch('/auth/v1/csrf-cookie', {
        method: 'GET',
        credentials: 'include',
        headers: {
          Accept: 'application/json',
        },
      })

      if (response.ok) {
        this.csrfReady = true
      } else {
        console.warn('[API] CSRF cookie request failed with status:', response.status)
      }
    } catch (error) {
      console.warn('[API] Failed to fetch CSRF cookie:', error)
    }
  }

  private async parseResponse<T>(response: Response): Promise<T> {
    const contentType = response.headers.get('content-type')
    const isJson = contentType?.includes('application/json')

    if (!response.ok) {
      let errorData: any = {}
      if (isJson) {
        try {
          errorData = await response.json()
        } catch {
          // ignore json parse errors
        }
      }
      const error: any = new Error(errorData.message || response.statusText)
      error.response = response
      error.data = errorData
      error.status = response.status
      throw error
    }

    if (isJson) {
      return response.json()
    }
    return response.text() as unknown as T
  }

  public async request<T>(endpoint: string, options: RequestOptions = {}): Promise<T> {
    const { query, body, ...fetchOptions } = options
    const method = (fetchOptions.method || 'GET').toString().toUpperCase()
    const isFormData = typeof FormData !== 'undefined' && body instanceof FormData

    if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS') {
      await this.ensureCsrfCookie()
    }

    const url = this.resolveUrl(endpoint, query)

    const makeRequest = async (headers: Record<string, string>): Promise<T> => {
      const response = await fetch(url, {
        ...fetchOptions,
        credentials: fetchOptions.credentials || 'include',
        headers: {
          ...(isFormData
            ? Object.fromEntries(
                Object.entries(headers).filter(([key]) => key.toLowerCase() !== 'content-type')
              )
            : headers),
          ...fetchOptions.headers,
        },
        body: body !== undefined ? (isFormData ? body : JSON.stringify(body)) : undefined,
      })
      return this.parseResponse<T>(response)
    }

    const isSafeMethod = method === 'GET' || method === 'HEAD' || method === 'OPTIONS'

    try {
      return await makeRequest({
        ...this.defaultHeaders,
        ...(isSafeMethod ? {} : getXsrfHeaders()),
      })
    } catch (error: any) {
      // A 419 usually just means the CSRF token went stale (long-lived tab, or the
      // session was rotated). Refresh the cookie and retry once before assuming the
      // session is gone for good.
      if (error?.status === CSRF_EXPIRED_STATUS && !isSafeMethod) {
        try {
          await this.ensureCsrfCookie(true)
          return await makeRequest({
            ...this.defaultHeaders,
            ...getXsrfHeaders(),
          })
        } catch (retryError: any) {
          return await this.handleAuthError(retryError, endpoint, options, makeRequest)
        }
      }

      return await this.handleAuthError(error, endpoint, options, makeRequest)
    }
  }

  private async handleAuthError<T>(
    error: any,
    endpoint: string,
    options: RequestOptions,
    makeRequest: (headers: Record<string, string>) => Promise<T>
  ): Promise<T> {
    const isAuthError = error?.status === 401 || error?.status === CSRF_EXPIRED_STATUS

    if (isAuthError && this.authHandler) {
      // Reset so the next request re-primes the cookie against a fresh session.
      this.csrfReady = false

      const retryInfo = await this.authHandler.handleUnauthorized(endpoint, options)

      if (retryInfo?.retry) {
        return await makeRequest({
          ...this.defaultHeaders,
          ...getXsrfHeaders(),
        })
      }
    }

    throw error
  }

  public get<T>(
    endpoint: string,
    query: Record<string, unknown> = {},
    options: RequestOptions = {}
  ): Promise<T> {
    return this.request<T>(endpoint, { method: 'GET', query, ...options })
  }

  public post<T>(endpoint: string, data?: any, options: RequestOptions = {}): Promise<T> {
    return this.request<T>(endpoint, { method: 'POST', body: data, ...options })
  }

  public put<T>(endpoint: string, data?: any, options: RequestOptions = {}): Promise<T> {
    return this.request<T>(endpoint, { method: 'PUT', body: data, ...options })
  }

  public patch<T>(endpoint: string, data?: any, options: RequestOptions = {}): Promise<T> {
    return this.request<T>(endpoint, { method: 'PATCH', body: data, ...options })
  }

  public delete<T>(endpoint: string, options: RequestOptions = {}): Promise<T> {
    return this.request<T>(endpoint, { method: 'DELETE', ...options })
  }

  public getBaseUrl(): string {
    return this.baseURL
  }
}
