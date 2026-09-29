import type { AnyExtension } from '@tiptap/core'
import { Table } from '@tiptap/extension-table'
import { TableCell } from '@tiptap/extension-table-cell'
import { TableHeader } from '@tiptap/extension-table-header'
import { TableRow } from '@tiptap/extension-table-row'
import { StarterKit } from '@tiptap/starter-kit'

import { InternalLink } from './InternalLink'
import { ListStyle } from './ListStyle'
import { PlaceholderToken } from './PlaceholderToken'
import { TextClass } from './TextClass'

/** A feature is on unless the field config explicitly disables it. */
export const isRichTextFeatureEnabled = (
  features: Partial<Record<RichTextFeature, boolean>> | undefined,
  feature: RichTextFeature
): boolean => features?.[feature] !== false

const TOGGLEABLE: RichTextFeature[] = [
  'bold',
  'italic',
  'underline',
  'strike',
  'code',
  'bulletList',
  'orderedList',
  'blockquote',
  'codeBlock',
  'horizontalRule',
]

/**
 * The rich text schema. Disabling a feature drops its extension (not just its
 * button) so the node/mark can't slip in via paste or input rules either.
 *
 * The site SDK's preview editor (`@b10cks/richtext/editor`) mirrors this list;
 * tests/fixtures/richtext-documents.json, shared with the SDK, guards that
 * both load and store the same documents.
 */
export const createRichTextExtensions = (
  features?: Partial<Record<RichTextFeature, boolean>>
): AnyExtension[] => {
  const isEnabled = (feature: RichTextFeature) => isRichTextFeatureEnabled(features, feature)
  const starterKitConfig: Record<string, unknown> = {
    heading: isEnabled('heading') ? { levels: [1, 2, 3, 4, 5, 6] } : false,
    link: isEnabled('link') ? { openOnClick: false, autolink: true } : false,
  }
  for (const feature of TOGGLEABLE) {
    if (!isEnabled(feature)) starterKitConfig[feature] = false
  }

  const extensions: AnyExtension[] = [
    StarterKit.configure(starterKitConfig),
    TextClass,
    PlaceholderToken,
  ]

  if (isEnabled('internalLink')) extensions.push(InternalLink)
  if (isEnabled('bulletList') || isEnabled('orderedList')) extensions.push(ListStyle)
  if (isEnabled('table')) {
    extensions.push(
      Table.configure({
        resizable: true,
        handleWidth: 4,
        cellMinWidth: 50,
        lastColumnResizable: true,
        allowTableNodeSelection: true,
      }),
      TableRow,
      TableHeader,
      TableCell
    )
  }

  return extensions
}
