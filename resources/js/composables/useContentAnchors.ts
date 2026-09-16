import { blockItemTitle, type ItemBlock, resolveItemBlock } from '~/lib/blockItemTitle'
import { collectBlockItems } from '~/lib/contentAnchors'

/** A block item of a content that an internal link can point at via its id. */
export interface ContentAnchor {
  id: string
  depth: number
  block: ItemBlock
  /** Title HTML from the block's preview template or its fallback, for v-html. */
  title: string
}

/** The linkable block items of a content, titled like the block editor titles them. */
export function useContentAnchors(
  spaceId: string,
  contentId: MaybeRef<string | null | undefined>
) {
  const targetId = computed(() => (spaceId ? toValue(contentId) || null : null))

  const { useContentQuery } = useContent(spaceId)
  const { useBlocksQuery } = useBlocks(spaceId)
  const { data: content, isLoading } = useContentQuery(targetId)
  const { data: blocks } = useBlocksQuery(
    { per_page: 1000 },
    computed(() => !!targetId.value)
  )
  const handlebars = useHandlebars()

  const anchors = computed<ContentAnchor[]>(() => {
    if (!targetId.value || content.value?.id !== targetId.value) return []

    return collectBlockItems(content.value.content).map(({ id, item, depth }) => {
      const block = resolveItemBlock(blocks.value?.data, item)
      return {
        id,
        depth,
        block,
        title: blockItemTitle(item, block, (template, data) => handlebars.render(template, data)),
      }
    })
  })

  /** False until the target content is loaded, so a missing anchor is only reported once known. */
  const isLoaded = computed(() => !!targetId.value && content.value?.id === targetId.value)

  const findAnchor = (id: string | null | undefined): ContentAnchor | undefined =>
    id ? anchors.value.find((anchor) => anchor.id === id) : undefined

  return { anchors, findAnchor, isLoaded, isLoading }
}
