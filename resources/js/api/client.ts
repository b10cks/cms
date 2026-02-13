import { getXsrfHeaders } from '~/lib/csrf'
import { isClient } from '~/lib/env'

interface AuthHandler {
  handleUnauthorized: (endpoint: string, options: any) => Promise<{ retry?: boolean } | void>
}

interface RequestOptions extends RequestInit {
  query?: Record<string, unknown>
  body?: any
}

const isClient = typeof window !== 'undefined'

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

  public async ensureCsrfCookie(): Promise<void> {
    if (!isClient || this.csrfReady) return

    console.log('[API] Fetching CSRF cookie from /auth/v1/csrf-cookie')

    try {
      const response = await fetch('/auth/v1/csrf-cookie', {
        method: 'GET',
        credentials: 'include',
        headers: {
          Accept: 'application/json',
        },
      })

      console.log('[API] CSRF response status:', response.status)
      console.log('[API] CSRF response headers:', Object.fromEntries(response.headers.entries()))
      console.log('[API] Document cookies after CSRF request:', document.cookie)

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

    if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS') {
      await this.ensureCsrfCookie()
    }

    const url = this.resolveUrl(endpoint, query)

    const makeRequest = async (headers: Record<string, string>): Promise<T> => {
      const response = await fetch(url, {
        ...fetchOptions,
        credentials: fetchOptions.credentials || 'include',
        headers: {
          ...headers,
          ...fetchOptions.headers,
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
      })
      return this.parseResponse<T>(response)
    }

    try {
      const csrfHeaders =
        method === 'GET' || method === 'HEAD' || method === 'OPTIONS' ? {} : getXsrfHeaders()
      return await makeRequest({
        ...this.defaultHeaders,
        ...csrfHeaders,
      })
    } catch (error: any) {
      if (error?.status === 401 && this.authHandler) {
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
}
