import { useQueryClient } from '@tanstack/vue-query'
import type { QueryKey } from '@tanstack/vue-query'

import { isClient } from '~/lib/env'

import { queryKeys } from './useQueryClient'

export function useSpaceBroadcasts(spaceId: MaybeRef<string | null>) {
  const queryClient = useQueryClient()

  const invalidate = (...keys: QueryKey[]) => {
    keys.forEach((k) => queryClient.invalidateQueries({ queryKey: k }))
  }

  const setup = () => {
    const id = toValue(spaceId)
    if (!isClient || !id) return

    try {
      const echo = useEcho()
      if (!echo) return

      echo
        .channel(`spaces.${id}.blocks`)
        .listen('.block:created', () => invalidate(queryKeys.blocks(id).lists()))
        .listen('.block:updated', () => invalidate(queryKeys.blocks(id).lists()))
        .listen('.block:deleted', ({ id: modelId }: { id: string }) =>
          invalidate(queryKeys.blocks(id).lists(), queryKeys.blocks(id).detail(modelId))
        )
        .listen('.block_folder:created', () => invalidate(queryKeys.blockFolders(id).lists()))
        .listen('.block_folder:updated', () => invalidate(queryKeys.blockFolders(id).lists()))
        .listen('.block_folder:deleted', ({ id: modelId }: { id: string }) =>
          invalidate(queryKeys.blockFolders(id).lists(), queryKeys.blockFolders(id).detail(modelId))
        )
        .listen('.block_tag:created', () => invalidate(queryKeys.blockTags(id).lists()))
        .listen('.block_tag:updated', () => invalidate(queryKeys.blockTags(id).lists()))
        .listen('.block_tag:deleted', () => invalidate(queryKeys.blockTags(id).lists()))

      echo
        .channel(`spaces.${id}.assets`)
        .listen('.asset:created', () => invalidate(queryKeys.assets(id).lists()))
        .listen('.asset:updated', () => invalidate(queryKeys.assets(id).lists()))
        .listen('.asset:deleted', ({ id: modelId }: { id: string }) =>
          invalidate(queryKeys.assets(id).lists(), queryKeys.assets(id).detail(modelId))
        )
        .listen('.asset_folder:created', () => invalidate(queryKeys.assetFolders(id).lists()))
        .listen('.asset_folder:updated', () => invalidate(queryKeys.assetFolders(id).lists()))
        .listen('.asset_folder:deleted', ({ id: modelId }: { id: string }) =>
          invalidate(
            queryKeys.assetFolders(id).lists(),
            queryKeys.assetFolders(id).detail(modelId)
          )
        )
        .listen('.asset_tag:created', () => invalidate(queryKeys.assetTags(id).lists()))
        .listen('.asset_tag:updated', () => invalidate(queryKeys.assetTags(id).lists()))
        .listen('.asset_tag:deleted', () => invalidate(queryKeys.assetTags(id).lists()))

      echo
        .channel(`spaces.${id}.icons`)
        .listen('.icon:created', () =>
          invalidate(queryKeys.icons(id).lists(), queryKeys.icons(id).tags())
        )
        .listen('.icon:updated', () =>
          invalidate(queryKeys.icons(id).lists(), queryKeys.icons(id).tags())
        )
        .listen('.icon:deleted', ({ id: modelId }: { id: string }) =>
          invalidate(
            queryKeys.icons(id).lists(),
            queryKeys.icons(id).tags(),
            queryKeys.icons(id).detail(modelId)
          )
        )

      echo
        .channel(`spaces.${id}.redirects`)
        .listen('.redirect:created', () => invalidate(queryKeys.redirects(id).lists()))
        .listen('.redirect:updated', () => invalidate(queryKeys.redirects(id).lists()))
        .listen('.redirect:deleted', ({ id: modelId }: { id: string }) =>
          invalidate(queryKeys.redirects(id).lists(), queryKeys.redirects(id).detail(modelId))
        )
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
