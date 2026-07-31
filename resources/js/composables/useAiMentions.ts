import type { AiMentionItem } from '~/components/editor/extensions/AiMention'

import { useBlocks } from './useBlocks'
import { useContentMenu } from './useContentMenu'

const MENTION_LIMIT = 50

export function useAiMentions(spaceId: MaybeRef<string>) {
  const { useBlocksQuery } = useBlocks(spaceId)
  const { useContentMenuQuery, getRootItems, getChildren } = useContentMenu(spaceId)

  const useMentionItemsQuery = (searchQuery: MaybeRef<string> = '') => {
    const { data: blocksData } = useBlocksQuery({ per_page: 1000 })
    const { data: contentMenuData } = useContentMenuQuery()

    const items = computed<AiMentionItem[]>(() => {
      const search = toValue(searchQuery).trim().toLowerCase()
      const contentItems: AiMentionItem[] = []
      const blockItems: AiMentionItem[] = []

      const menuData = contentMenuData.value
      if (menuData) {
        // Traversed over the real `pid` links: a parent whose `children` flag is
        // stale would otherwise hide every descendant from the mention list.
        const allItems = [
          ...getRootItems(menuData),
          ...Object.values(menuData).flatMap((item) => getChildren(menuData, item.id)),
        ]

        const seen = new Set<string>()
        for (const content of allItems) {
          if (seen.has(content.id)) continue
          seen.add(content.id)

          const label = content.name ?? 'Untitled'
          if (!search || label.toLowerCase().includes(search)) {
            contentItems.push({
              id: content.id,
              label,
              type: 'content',
              color: content.color,
              icon: content.icon ?? 'lucide:file',
            })
          }
        }
      }

      if (blocksData.value?.data) {
        for (const block of blocksData.value.data) {
          const label = block.name ?? block.slug
          if (
            !search ||
            label.toLowerCase().includes(search) ||
            block.slug.toLowerCase().includes(search)
          ) {
            blockItems.push({
              id: block.slug,
              label,
              type: 'block',
              color: block.color,
              icon: block.icon ?? 'lucide:box',
            })
          }
        }
      }

      // Each source keeps at least half the cap, plus whatever the other leaves
      // unused — capping the concatenated list meant a space with 50+ contents
      // could never mention a block.
      const contentCount = Math.min(
        contentItems.length,
        Math.max(MENTION_LIMIT - blockItems.length, MENTION_LIMIT / 2)
      )

      return [
        ...contentItems.slice(0, contentCount),
        ...blockItems.slice(0, MENTION_LIMIT - contentCount),
      ]
    })

    const isLoading = computed(() => !blocksData.value || !contentMenuData.value)

    return {
      items,
      isLoading,
    }
  }

  return {
    useMentionItemsQuery,
  }
}
