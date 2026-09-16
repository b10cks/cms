import { sanitizeHtml } from '~/lib/sanitize'

/** The part of a block needed to present an item of it. */
export type ItemBlock = Pick<BlockResource, 'id' | 'slug' | 'name' | 'icon' | 'color' | 'preview_template'>

export type PreviewTemplateRenderer = (template: string, data: Record<string, unknown>) => string

const escapeHtml = (value: string): string =>
  value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')

/** The block a content item points at, or a stand-in named after its slug when the block is gone. */
export const resolveItemBlock = (
  blocks: readonly BlockResource[] | null | undefined,
  item: Record<string, unknown>
): ItemBlock =>
  blocks?.find((entry) => entry.slug === item.block) ?? {
    id: '',
    slug: String(item.block || ''),
    name: String(item.block || ''),
  }

/**
 * Human-readable title of a block item as HTML, for a v-html sink.
 *
 * Uses the block's preview template when it renders something, then the first
 * string field when it is not blank, then the block name.
 */
export const blockItemTitle = (
  item: Record<string, unknown> | null | undefined,
  block: ItemBlock | null | undefined,
  render: PreviewTemplateRenderer
): string => {
  if (!item) return 'Untitled'

  if (block?.preview_template) {
    try {
      // The template renderer escapes interpolated values, but the template
      // markup itself is tenant-authored, so the result is sanitized as well.
      const rendered = sanitizeHtml(render(block.preview_template, item) ?? '').trim()
      if (rendered) return rendered
    } catch {
      /* fall through to the plain fallbacks */
    }
  }

  // The fallbacks use raw content strings, so they are escaped to avoid stored XSS.
  const firstString = Object.entries(item).find(
    ([key, value]) => !['id', 'block', 'hidden'].includes(key) && typeof value === 'string'
  )
  const firstStringValue = String(firstString?.[1] ?? '').trim()
  if (firstStringValue) return escapeHtml(firstStringValue)

  return escapeHtml(block?.name || block?.slug || (item.block ? 'Untitled Block' : 'Untitled'))
}
