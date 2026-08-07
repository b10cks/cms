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
   */
  const listenModel = (channel: EchoChannelLike, model: string, keys: ResourceKeys) => {
    channel
      .listen(`.${model}:created`, () => invalidate(keys.lists(), ...(keys.extra?.() ?? [])))
      .listen(`.${model}:updated`, (payload: SpaceModelBroadcast) => {
        const extra = keys.extra?.() ?? []

        if (payload?.data?.id) {
          patchQueries([keys.lists(), ...(keys.details ? [keys.details()] : [])], payload.data)
          invalidate(...extra)
          return
        }

        invalidate(
          keys.lists(),
          ...(keys.detail && payload?.id ? [keys.detail(payload.id)] : []),
          ...extra
        )
      })
      .listen(`.${model}:deleted`, (payload: SpaceModelBroadcast) => {
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
  const onConnectionStateChange = ({ current }: ConnectionStates) => {
    if (current === 'unavailable' || current === 'failed' || current === 'disconnected') {
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

      const blocks = echo.channel(`spaces.${id}.blocks`)
      listenModel(blocks, 'block', {
        lists: () => queryKeys.blocks(id).lists(),
        details: () => queryKeys.blocks(id).details(),
        detail: (modelId) => queryKeys.blocks(id).detail(modelId),
      })
      listenModel(blocks, 'block_folder', {
        lists: () => queryKeys.blockFolders(id).lists(),
        details: () => queryKeys.blockFolders(id).details(),
        detail: (modelId) => queryKeys.blockFolders(id).detail(modelId),
      })
      listenModel(blocks, 'block_tag', {
        lists: () => queryKeys.blockTags(id).lists(),
      })

      const assets = echo.channel(`spaces.${id}.assets`)
      listenModel(assets, 'asset', {
        lists: () => queryKeys.assets(id).lists(),
        details: () => queryKeys.assets(id).details(),
        detail: (modelId) => queryKeys.assets(id).detail(modelId),
      })
      listenModel(assets, 'asset_folder', {
        lists: () => queryKeys.assetFolders(id).lists(),
        details: () => queryKeys.assetFolders(id).details(),
        detail: (modelId) => queryKeys.assetFolders(id).detail(modelId),
      })
      listenModel(assets, 'asset_tag', {
        lists: () => queryKeys.assetTags(id).lists(),
      })

      listenModel(echo.channel(`spaces.${id}.icons`), 'icon', {
        lists: () => queryKeys.icons(id).lists(),
        details: () => queryKeys.icons(id).details(),
        detail: (modelId) => queryKeys.icons(id).detail(modelId),
        // The tag facet is an aggregate a single-item patch cannot maintain.
        extra: () => [queryKeys.icons(id).tags()],
      })

      listenModel(echo.channel(`spaces.${id}.redirects`), 'redirect', {
        lists: () => queryKeys.redirects(id).lists(),
        details: () => queryKeys.redirects(id).details(),
        detail: (modelId) => queryKeys.redirects(id).detail(modelId),
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
      for (const channel of ['blocks', 'assets', 'icons', 'redirects']) {
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
