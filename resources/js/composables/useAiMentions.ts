import type { AiMentionItem } from '~/components/editor/extensions/AiMention'

import { useBlocks } from './useBlocks'
import { useContentMenu } from './useContentMenu'

export function useAiMentions(spaceId: MaybeRef<string>) {
  const { useBlocksQuery } = useBlocks(spaceId)
  const { useContentMenuQuery, getRootItems, getChildren } = useContentMenu(spaceId)

  const useMentionItemsQuery = (searchQuery: MaybeRef<string> = '') => {
    const { data: blocksData } = useBlocksQuery({ per_page: 1000 })
    const { data: contentMenuData } = useContentMenuQuery()

    const items = computed<AiMentionItem[]>(() => {
      const search = toValue(searchQuery).toLowerCase()
      const items: AiMentionItem[] = []

      if (contentMenuData.value) {
        const allItems = [
          ...getRootItems(contentMenuData.value),
          ...Object.values(contentMenuData.value).flatMap((item) =>
            item.children ? getChildren(contentMenuData.value, item.id) : []
          ),
        ]

        const seen = new Set<string>()
        for (const content of allItems) {
          if (seen.has(content.id)) continue
          seen.add(content.id)

          const label = content.name ?? 'Untitled'
          if (!search || label.toLowerCase().includes(search)) {
            items.push({
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
            items.push({
              id: block.slug,
              label,
              type: 'block',
              color: block.color,
              icon: block.icon ?? 'lucide:box',
            })
          }
        }
      }

      return items.slice(0, 50)
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
