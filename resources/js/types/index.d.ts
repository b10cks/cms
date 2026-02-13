import type { ComputedRef, MaybeRef } from 'vue'

interface ApiMetaLink {
  url: string | null
  label: string | null
  active: boolean
}

interface ApiResponse<T> {
  data: T
}

interface LaravelMeta {
  current_page: number
  from: number
  last_page: number
  links: ApiMetaLink[]
  path: string
  per_page: number
  to: number
  total: number
}

interface ApiCollectionResponse<T> {
  data: T[]
  links: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
  meta: LaravelMeta
}

export interface PaginationParams {
  page?: number
  per_page?: number
}

export interface SortParams {
  sort?: string
}

export interface BaseQueryParams extends PaginationParams, SortParams {}

export type MaybeRefOrComputed<T> = MaybeRef<T> | ComputedRef<T>
