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

interface PaginationParams {
  page?: number
  per_page?: number
}

interface SortParams {
  sort?: string
}

interface BaseQueryParams extends PaginationParams, SortParams {}

type MaybeRefOrComputed<T> = import('vue').MaybeRef<T> | import('vue').ComputedRef<T>
