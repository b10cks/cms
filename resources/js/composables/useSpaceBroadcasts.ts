import { useQueryClient } from '@tanstack/vue-query'
import type { QueryKey } from '@tanstack/vue-query'

import { isClient } from '~/lib/env'

import { queryKeys } from './useQueryClient'

interface ConnectionStates {
  previous: string
  current: string
}

interface PusherConnectionLike {
  bind: (event: string, callback: (states: ConnectionStates) => void) => void
  unbind: (event: string, callback: (states: ConnectionStates) => void) => void
}

interface EchoChannelLike {
  listen: (event: string, callback: (payload: never) => void) => EchoChannelLike
}

/**
 * `spaces.{space}.{resource}` broadcast payload. `data` is a slim resource
 * representation carried along when it fits into Reverb's message size cap —
 * present, it lets caches be patched in place; absent, we invalidate.
 */
interface SpaceModelBroadcast {
  id: string
  action?: string
  /** Parent ids some models broadcast so listeners can target nested caches. */
  data_source_id?: string
  block_id?: string
  data?: { id: string } & Record<string, unknown>
}

type ResourceKeys = {
  lists: () => QueryKey
  /** Prefix covering every cached detail entry, for in-place patches. */
  details?: () => QueryKey
  /** A single detail key, for targeted invalidation fallback. */
  detail?: (modelId: string) => QueryKey
  /** Derived keys (facets) a patch cannot maintain — always invalidated. */
  extra?: () => QueryKey[]
}

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)

const mergeItems = (items: unknown[], data: { id: string }): unknown[] | null => {
  let hit = false
  const next = items.map((item) => {
    if (isRecord(item) && item.id === data.id) {
      hit = true
      return { ...item, ...data }
    }
    return item
  })

  return hit ? next : null
}

/**
 * Merge `data` into every entry matching its id inside a cached query value.
 * Understands the cache shapes in use — a bare item, an item array, a
 * `{ data: [...] }` response and infinite `{ pages: [...] }` results — and
 * returns null when nothing matched so callers can leave the cache untouched.
 */
const patchCache = (cache: unknown, data: { id: string }): unknown | null => {
  if (Array.isArray(cache)) {
    return mergeItems(cache, data)
  }

  if (!isRecord(cache)) return null

  if (Array.isArray(cache.pages)) {
    let hit = false
    const pages = cache.pages.map((page) => {
      const patched = patchCache(page, data)
      if (patched !== null) hit = true
      return patched ?? page
    })

    return hit ? { ...cache, pages } : null
  }

  if (Array.isArray(cache.data)) {
    const patched = mergeItems(cache.data, data)
    return patched ? { ...cache, data: patched } : null
  }

  return cache.id === data.id ? { ...cache, ...data } : null
}

const removeItems = (items: unknown[], id: string): unknown[] | null => {
  const next = items.filter((item) => !(isRecord(item) && item.id === id))
  return next.length !== items.length ? next : null
}

/** Drop every entry matching `id`; null when nothing matched. Counts in a
 * sibling `meta` are left stale — the paired invalidation refetches them. */
const removeFromCache = (cache: unknown, id: string): unknown | null => {
  if (Array.isArray(cache)) {
    return removeItems(cache, id)
  }

  if (!isRecord(cache)) return null

  if (Array.isArray(cache.pages)) {
    let hit = false
    const pages = cache.pages.map((page) => {
      const removed = removeFromCache(page, id)
      if (removed !== null) hit = true
      return removed ?? page
    })

    return hit ? { ...cache, pages } : null
  }

  if (Array.isArray(cache.data)) {
    const removed = removeItems(cache.data, id)
    return removed ? { ...cache, data: removed } : null
  }

  return null
}

export function useSpaceBroadcasts(spaceId: MaybeRef<string | null>) {
  const queryClient = useQueryClient()

  const invalidate = (...keys: QueryKey[]) => {
    keys.forEach((k) => queryClient.invalidateQueries({ queryKey: k }))
  }

  const patchQueries = (keys: QueryKey[], data: { id: string }) => {
    keys.forEach((key) =>
      queryClient.setQueriesData({ queryKey: key }, (old: unknown) => patchCache(old, data) ?? undefined)
    )
  }

  const removeFromQueries = (keys: QueryKey[], id: string) => {
    keys.forEach((key) =>
      queryClient.setQueriesData({ queryKey: key }, (old: unknown) => removeFromCache(old, id) ?? undefined)
    )
  }

  /**
   * Standard lifecycle wiring for one broadcast model. Updates carrying a
   * slim `data` payload are patched straight into the list and detail caches
   * (no refetch round-trip); everything else falls back to invalidation.
   * Keys are resolved per payload — nested resources (data entries, block
   * templates) derive their cache keys from the broadcast's parent id.
   */
  const listenModel = (
    channel: EchoChannelLike,
    model: string,
    keysFor: (payload?: SpaceModelBroadcast) => ResourceKeys
  ) => {
    channel
      .listen(`.${model}:created`, (payload: SpaceModelBroadcast) => {
        const keys = keysFor(payload)
        invalidate(keys.lists(), ...(keys.extra?.() ?? []))
      })
      .listen(`.${model}:updated`, (payload: SpaceModelBroadcast) => {
        const keys = keysFor(payload)
        const extra = keys.extra?.() ?? []

        if (payload?.data?.id) {
          // The patch is an instant optimistic overlay; the list refetch
          // behind it settles what a per-item merge cannot decide — membership
          // and ordering (moves, tag filters, sorted columns). Details carry
          // no membership, so they stay patch-only.
          patchQueries([keys.lists(), ...(keys.details ? [keys.details()] : [])], payload.data)
          invalidate(keys.lists(), ...extra)
          return
        }

        invalidate(
          keys.lists(),
          ...(keys.detail && payload?.id ? [keys.detail(payload.id)] : []),
          ...extra
        )
      })
      .listen(`.${model}:deleted`, (payload: SpaceModelBroadcast) => {
        const keys = keysFor(payload)
        removeFromQueries([keys.lists()], payload?.id)
        invalidate(
          keys.lists(),
          ...(keys.detail ? [keys.detail(payload?.id)] : []),
          ...(keys.extra?.() ?? [])
        )
      })
  }

  const getConnection = (): PusherConnectionLike | null => {
    const echo = useEcho() as unknown as {
      connector?: { pusher?: { connection?: PusherConnectionLike } }
    } | null

    return echo?.connector?.pusher?.connection ?? null
  }

  // Invalidations broadcast while the socket was down are gone for good — and
  // the content menu is patched via setQueryData, so it would drift forever.
  // After a reconnect, refetch everything space-scoped once to catch up.
  let wasDown = false
  const onConnectionStateChange = ({ previous, current }: ConnectionStates) => {
    // A transient drop reconnecting within pusher-js's 10s unavailableTimeout
    // only ever shows as connected → connecting → connected, so leaving
    // 'connected' at all has to arm the catch-up — the terminal down states
    // alone would miss every fast drop.
    if (
      (previous === 'connected' && current === 'connecting') ||
      current === 'unavailable' ||
      current === 'failed' ||
      current === 'disconnected'
    ) {
      wasDown = true
      return
    }

    const id = toValue(spaceId)
    if (current === 'connected' && wasDown && id) {
      wasDown = false
      invalidate(['spaces', id])
    }
  }

  const setup = () => {
    const id = toValue(spaceId)
    if (!isClient || !id) return

    try {
      const echo = useEcho()
      if (!echo) return

      const blocks = echo.private(`spaces.${id}.blocks`)
      listenModel(blocks, 'block', () => ({
        lists: () => queryKeys.blocks(id).lists(),
        details: () => queryKeys.blocks(id).details(),
        detail: (modelId) => queryKeys.blocks(id).detail(modelId),
      }))
      listenModel(blocks, 'block_folder', () => ({
        lists: () => queryKeys.blockFolders(id).lists(),
        details: () => queryKeys.blockFolders(id).details(),
        detail: (modelId) => queryKeys.blockFolders(id).detail(modelId),
      }))
      listenModel(blocks, 'block_tag', () => ({
        lists: () => queryKeys.blockTags(id).lists(),
      }))
      // Templates are cached per block; without the parent id (older message
      // format) the blocks prefix covers their nested keys.
      listenModel(blocks, 'block_template', (payload) =>
        payload?.block_id
          ? {
              lists: () => queryKeys.blockTemplates(id, payload.block_id as string).lists(),
              details: () => queryKeys.blockTemplates(id, payload.block_id as string).details(),
              detail: (modelId) =>
                queryKeys.blockTemplates(id, payload.block_id as string).detail(modelId),
            }
          : { lists: () => queryKeys.blocks(id).all() }
      )

      const assets = echo.private(`spaces.${id}.assets`)
      listenModel(assets, 'asset', () => ({
        lists: () => queryKeys.assets(id).lists(),
        details: () => queryKeys.assets(id).details(),
        detail: (modelId) => queryKeys.assets(id).detail(modelId),
        // Smart collections resolve membership from rules, so any asset
        // change can alter any collection's asset list. One prefix covers
        // them all; only actively viewed lists actually refetch.
        extra: () => [[...queryKeys.assetCollections(id).all(), 'assets']],
      }))
      listenModel(assets, 'asset_folder', () => ({
        lists: () => queryKeys.assetFolders(id).lists(),
        details: () => queryKeys.assetFolders(id).details(),
        detail: (modelId) => queryKeys.assetFolders(id).detail(modelId),
      }))
      listenModel(assets, 'asset_tag', () => ({
        lists: () => queryKeys.assetTags(id).lists(),
      }))
      listenModel(assets, 'asset_collection', (payload) => ({
        lists: () => queryKeys.assetCollections(id).lists(),
        details: () => queryKeys.assetCollections(id).details(),
        detail: (modelId) => queryKeys.assetCollections(id).detail(modelId),
        // A saved collection may have new rules — its resolved asset list is
        // derived state a patch cannot maintain.
        extra: () =>
          payload?.id ? [queryKeys.assetCollections(id).assets(payload.id)] : [],
      }))
      listenModel(assets, 'asset_package', () => ({
        lists: () => queryKeys.assetPackages(id).lists(),
        details: () => queryKeys.assetPackages(id).details(),
        detail: (modelId) => queryKeys.assetPackages(id).detail(modelId),
      }))
      listenModel(assets, 'asset_share', () => ({
        lists: () => queryKeys.assetShares(id).lists(),
        details: () => queryKeys.assetShares(id).details(),
        detail: (modelId) => queryKeys.assetShares(id).detail(modelId),
      }))
      // Manual membership edits and smart-rule updates change a collection's
      // content without touching the row the model events cover. Packages of
      // the collection were marked stale in the same transaction.
      assets.listen('.asset_collection:content_changed', (payload: SpaceModelBroadcast) => {
        if (!payload?.id) return
        invalidate(
          queryKeys.assetCollections(id).assets(payload.id),
          queryKeys.assetPackages(id).lists()
        )
      })

      listenModel(echo.private(`spaces.${id}.icons`), 'icon', () => ({
        lists: () => queryKeys.icons(id).lists(),
        details: () => queryKeys.icons(id).details(),
        detail: (modelId) => queryKeys.icons(id).detail(modelId),
        // The tag facet is an aggregate a single-item patch cannot maintain.
        extra: () => [queryKeys.icons(id).tags()],
      }))

      listenModel(echo.private(`spaces.${id}.redirects`), 'redirect', () => ({
        lists: () => queryKeys.redirects(id).lists(),
        details: () => queryKeys.redirects(id).details(),
        detail: (modelId) => queryKeys.redirects(id).detail(modelId),
      }))

      const dataSources = echo.private(`spaces.${id}.data_sources`)
      listenModel(dataSources, 'data_source', () => ({
        lists: () => queryKeys.dataSources(id).lists(),
        details: () => queryKeys.dataSources(id).details(),
        detail: (modelId) => queryKeys.dataSources(id).detail(modelId),
      }))
      // Entries are cached per data source; without the parent id the
      // data-sources prefix covers their nested keys.
      listenModel(dataSources, 'data_entry', (payload) =>
        payload?.data_source_id
          ? {
              lists: () => queryKeys.dataEntries(id, payload.data_source_id as string).lists(),
              details: () => queryKeys.dataEntries(id, payload.data_source_id as string).details(),
              detail: (modelId) =>
                queryKeys.dataEntries(id, payload.data_source_id as string).detail(modelId),
            }
          : { lists: () => queryKeys.dataSources(id).all() }
      )
      // Bulk operations (import, replacement delete, bulk translation) mute
      // their per-entry broadcasts and send this single event instead.
      dataSources.listen('.data_source:content_changed', (payload: SpaceModelBroadcast) => {
        if (!payload?.id) return
        invalidate(queryKeys.dataEntries(id, payload.id).all(), queryKeys.dataSources(id).lists())
      })

      getConnection()?.bind('state_change', onConnectionStateChange)
    } catch {
      /** */
    }
  }

  // idToLeave must be passed when spaceId already changed (see the watcher) —
  // reading toValue(spaceId) there would leak the old space's subscriptions.
  const teardown = (idToLeave: string | null = toValue(spaceId)) => {
    if (!isClient || !idToLeave) return

    try {
      const echo = useEcho()
      if (!echo) return
      for (const channel of ['blocks', 'assets', 'icons', 'redirects', 'data_sources']) {
        echo.leave(`spaces.${idToLeave}.${channel}`)
      }

      getConnection()?.unbind('state_change', onConnectionStateChange)
    } catch {
      /** */
    }
  }

  onMounted(setup)
  onUnmounted(() => teardown())

  watch(
    () => toValue(spaceId),
    (newId, oldId) => {
      if (oldId) teardown(oldId)
      if (newId) setup()
    }
  )
}
