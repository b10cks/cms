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
 * Registry icons keep a denormalized SVG snapshot (so delivered content renders without a lookup)
 * and a `b10cks:`-prefixed key. Public Iconify icons only store the fully-qualified key
 * (e.g. `mdi:home`) and render through `@iconify/vue` directly.
 */
export type IconValue =
  | {
      source: 'registry'
      id: string
      external_id?: string | null
      key: string
      name: string
      body: string
      width: number
      height: number
    }
  | {
      source: 'iconify'
      key: string
      name: string
    }

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
