export interface IconResource {
  id: string
  external_id: string | null
  key: string
  name: string
  description: string | null
  body: string
  width: number
  height: number
  tags: string[]
  created_at: string
  updated_at: string
}

/**
 * Value stored by the `icon` content field.
 *
 * Always a fully-qualified icon name: `b10cks:{key}` for space registry icons,
 * or `{collection}:{name}` for any public Iconify icon (e.g. `mdi:home`).
 * The SVG body is never stored here — it is fetched from the Iconify-compatible
 * delivery API at render time.
 */
export type IconValue = string

export interface UploadIconPayload {
  file?: File
  body?: string
  key?: string
  name?: string
  description?: string | null
  tags?: string[]
  external_id?: string | null
  width?: number
  height?: number
}

export interface UpdateIconPayload {
  file?: File
  body?: string
  key?: string
  name?: string
  description?: string | null
  tags?: string[]
  external_id?: string | null
  width?: number
  height?: number
}
