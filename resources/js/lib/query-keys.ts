import type { MaybeRef } from 'vue'

/**
 * Query-key generators for the app's TanStack Query namespaces.
 *
 * Nearly every entity keys the same five levels off `['spaces', spaceId, segment]`,
 * and the whole app invalidates through those keys — so the arrays these produce
 * must stay byte-for-byte what the hand-written blocks produced.
 *
 * Deliberately typed with `unknown` rather than `any`: a filter object is opaque
 * to the key builder, it only ever gets hashed.
 */

export type QueryKey = readonly unknown[]

export interface EntityListKeys {
  all: () => QueryKey
  lists: () => QueryKey
  list: (filters?: unknown) => QueryKey
}

export interface EntityKeys extends EntityListKeys {
  details: () => QueryKey
  detail: (id: MaybeRef<string>) => QueryKey
}

/** `all` / `lists` / `list` off an arbitrary root — for namespaces with no detail route. */
export const listKeys = (all: () => QueryKey): EntityListKeys => ({
  all,
  lists: () => [...all(), 'list'] as const,
  list: (filters: unknown = {}) => [...all(), 'list', filters] as const,
})

/** The full five-level shape off an arbitrary root. */
export const entityKeys = (all: () => QueryKey): EntityKeys => ({
  ...listKeys(all),
  details: () => [...all(), 'detail'] as const,
  detail: (id: MaybeRef<string>) => [...all(), 'detail', id] as const,
})

/** `['spaces', spaceId, segment, …]` — the shape 25+ entities share. */
export const spaceEntityKeys =
  (segment: string) =>
  (spaceId: MaybeRef<string>): EntityKeys =>
    entityKeys(() => ['spaces', spaceId, segment] as const)

/** Same, for the space endpoints that only ever list (no detail route). */
export const spaceListKeys =
  (segment: string) =>
  (spaceId: MaybeRef<string>): EntityListKeys =>
    listKeys(() => ['spaces', spaceId, segment] as const)

/**
 * `['spaces', spaceId, parentSegment, parentId, segment, …]` — entities that live
 * under a parent record (block templates/versions, content versions, comments,
 * data entries), so invalidating the parent cascades.
 */
export const nestedEntityKeys =
  (parentSegment: string, segment: string) =>
  (spaceId: MaybeRef<string>, parentId: MaybeRef<string>): EntityKeys =>
    entityKeys(() => ['spaces', spaceId, parentSegment, parentId, segment] as const)
