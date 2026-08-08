import { getXsrfHeaders, hasXsrfToken } from '~/lib/csrf'
import { isClient } from '~/lib/env'

interface AuthHandler {
  handleUnauthorized: (endpoint: string, options: any) => Promise<{ retry?: boolean } | void>
}

// Laravel answers an expired/mismatched session with 419 on stateful writes.
const CSRF_EXPIRED_STATUS = 419

const CSRF_COOKIE_ENDPOINT = '/auth/v1/csrf-cookie'

// An HTML error page can be arbitrarily large; keep just enough to identify it.
const MAX_ERROR_BODY_LENGTH = 500

/**
 * The Echo socket id, so broadcast(...)->toOthers() can exclude the client
 * that caused the change — without it every save self-echoes and triggers
 * the saver's own cache invalidations.
 */
const getSocketId = (): string | null => {
  if (!isClient) return null
  try {
    return (
      (window as { Echo?: { socketId?: () => string | undefined } }).Echo?.socketId?.() ?? null
    )
  } catch {
    return null
  }
}

export interface RequestOptions extends RequestInit {
  query?: Record<string, unknown>
  body?: any
  /**
   * Skip the CSRF machinery entirely: no cookie priming, no X-XSRF-TOKEN
   * header, no 419 retry. For anonymous endpoints (public shares) that must
   * not touch the session — pair it with `credentials: 'omit'`.
   */
  skipCsrf?: boolean
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

  // Laravel parses `filter[parent_id]=x` into a nested array and `tags[]=a&tags[]=b`
  // into a list, so nested objects get bracket notation and arrays repeat their key.
  private appendQueryParam(params: URLSearchParams, key: string, value: unknown): void {
    if (value === undefined || value === null) return

    if (Array.isArray(value)) {
      for (const item of value) {
        this.appendQueryParam(params, `${key}[]`, item)
      }
      return
    }

    if (value instanceof Date) {
      params.append(key, value.toISOString())
      return
    }

    if (typeof value === 'object') {
      for (const [nestedKey, nestedValue] of Object.entries(value as Record<string, unknown>)) {
        this.appendQueryParam(params, `${key}[${nestedKey}]`, nestedValue)
      }
      return
    }

    params.append(key, String(value))
  }

  private resolveUrl(endpoint: string, query?: Record<string, unknown>): string {
    let url = endpoint.startsWith('http') ? endpoint : `${this.baseURL}${endpoint}`
    if (query) {
      const params = new URLSearchParams()
      for (const [key, value] of Object.entries(query)) {
        this.appendQueryParam(params, key, value)
      }
      const serialized = params.toString()
      if (serialized) {
        url += `?${serialized}`
      }
    }
    return url
  }

  public async ensureCsrfCookie(force: boolean = false): Promise<void> {
    if (!isClient) return
    if (this.csrfReady && !force) return

    try {
      const response = await fetch(`${this.baseURL}${CSRF_COOKIE_ENDPOINT}`, {
        method: 'GET',
        credentials: 'include',
        headers: {
          Accept: 'application/json',
        },
      })

      if (!response.ok) {
        console.warn('[API] CSRF cookie request failed with status:', response.status)
        return
      }

      // A 200 only means the endpoint answered; the cookie is what the next
      // request actually needs, so stay unprimed until it is really there.
      if (hasXsrfToken()) {
        this.csrfReady = true
      } else {
        console.warn('[API] CSRF cookie request succeeded but no XSRF-TOKEN cookie was set')
      }
    } catch (error) {
      console.warn('[API] Failed to fetch CSRF cookie:', error)
    }
  }

  private async parseResponse<T>(response: Response): Promise<T> {
    const contentType = response.headers.get('content-type')
    const isJson =
      !!contentType &&
      (contentType.includes('application/json') || contentType.includes('application/problem+json'))

    if (!response.ok) {
      let errorData: any = {}
      if (isJson) {
        try {
          errorData = await response.json()
        } catch {
          // ignore json parse errors
        }
      } else {
        // An HTML 502 or a text/plain Laravel error is all the debug info there
        // is; keep a trimmed copy rather than dropping it on the floor.
        try {
          const body = (await response.text()).trim()
          if (body) {
            errorData = { message: body.slice(0, MAX_ERROR_BODY_LENGTH) }
          }
        } catch {
          // ignore body read errors
        }
      }
      // statusText is empty over HTTP/2, so without the status fallback callers
      // showing `error.message` render an empty toast.
      const error: any = new Error(
        errorData.message || response.statusText || `HTTP ${response.status}`
      )
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
    const { query, body, skipCsrf, ...fetchOptions } = options
    const method = (fetchOptions.method || 'GET').toString().toUpperCase()
    const isFormData = typeof FormData !== 'undefined' && body instanceof FormData
    const isSafeMethod = method === 'GET' || method === 'HEAD' || method === 'OPTIONS'
    const needsCsrf = !isSafeMethod && !skipCsrf

    if (needsCsrf) {
      await this.ensureCsrfCookie()
    }

    const url = this.resolveUrl(endpoint, query)

    // Anonymous transports (skipCsrf) hold no session and no socket worth
    // excluding; safe methods change nothing, so nothing self-echoes.
    const socketId = !isSafeMethod && !skipCsrf ? getSocketId() : null

    const makeRequest = async (requestHeaders: Record<string, string>): Promise<T> => {
      const headers = {
        ...this.getAuthHeaders(),
        ...(socketId ? { 'X-Socket-ID': socketId } : {}),
        ...requestHeaders,
      }
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

    try {
      return await makeRequest({
        ...this.defaultHeaders,
        ...(needsCsrf ? getXsrfHeaders() : {}),
      })
    } catch (error: any) {
      // A 419 usually just means the CSRF token went stale (long-lived tab, or the
      // session was rotated). Refresh the cookie and retry once before assuming the
      // session is gone for good.
      if (error?.status === CSRF_EXPIRED_STATUS && needsCsrf) {
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
