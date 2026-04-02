import type { ContentSettings } from '~/types/contents'

export const CHILD_CONTENT_BLOCK_TYPES: Array<BlockResource['type']> = ['root', 'universal']
export const ROOT_CONTENT_BLOCK_TYPES: Array<BlockResource['type']> = ['root', 'universal', 'single']

export function getEligibleChildContentBlocks(blocks: BlockResource[] | null | undefined) {
  return (blocks || []).filter((block) => CHILD_CONTENT_BLOCK_TYPES.includes(block.type))
}

export function getRootCreateContentBlocks(blocks: BlockResource[] | null | undefined) {
  return (blocks || []).filter((block) => ROOT_CONTENT_BLOCK_TYPES.includes(block.type))
}

export function resolveAllowedChildContentBlocks(
  blocks: BlockResource[] | null | undefined,
  settings?: Partial<ContentSettings> | null
) {
  const eligibleBlocks = getEligibleChildContentBlocks(blocks)

  if (!settings?.restrict_child_blocks) {
    return eligibleBlocks
  }

  const activeBlockWhitelist = (settings.child_block_whitelist || []).filter(
    (slug): slug is string => Boolean(slug)
  )
  const activeTagWhitelist = (settings.child_tag_whitelist || []).filter(
    (tag): tag is string => Boolean(tag)
  )

  if (activeBlockWhitelist.length === 0 && activeTagWhitelist.length === 0) {
    return eligibleBlocks
  }

  return eligibleBlocks.filter((block) => {
    const matchesBlockWhitelist = activeBlockWhitelist.includes(block.slug)
    const matchesTagWhitelist = Boolean(
      block.tags?.some((tag) => activeTagWhitelist.includes(tag))
    )

    return matchesBlockWhitelist || matchesTagWhitelist
  })
}

export function resolveCreateContentBlocks(options: {
  blocks: BlockResource[] | null | undefined
  parentSettings?: Partial<ContentSettings> | null
  isChild: boolean
}) {
  if (options.isChild) {
    return resolveAllowedChildContentBlocks(options.blocks, options.parentSettings)
  }

  return getRootCreateContentBlocks(options.blocks)
}

export function resolvePreferredCreateContentBlock(options: {
  availableBlocks: BlockResource[]
  parentSettings?: Partial<ContentSettings> | null
  spaceDefaultBlockId?: string | null
}) {
  const availableBlockIds = options.availableBlocks.map((block) => block.id)
  const parentDefaultBlock = options.parentSettings?.default_child_block

  if (parentDefaultBlock && availableBlockIds.includes(parentDefaultBlock)) {
    return parentDefaultBlock
  }

  if (options.spaceDefaultBlockId && availableBlockIds.includes(options.spaceDefaultBlockId)) {
    return options.spaceDefaultBlockId
  }

  if (availableBlockIds.length === 1) {
    return availableBlockIds[0]
  }

  return ''
}
