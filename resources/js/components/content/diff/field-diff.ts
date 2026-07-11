import type { Component } from 'vue'
import AssetDiff from '~/components/content/diff/AssetDiff.vue'
import InlineTextDiff from '~/components/content/diff/InlineTextDiff.vue'
import KeyValueDiff from '~/components/content/diff/KeyValueDiff.vue'
import ListDiff from '~/components/content/diff/ListDiff.vue'
import TableDiff from '~/components/content/diff/TableDiff.vue'
import RichTextDiff from '~/components/content/RichTextDiff.vue'
import { isRichTextDoc } from '~/utils/richtext-diff'

// Async to break the circular import: BlockItemDiff renders its children
// through resolveDiffComponent.
const BlockItemDiff = defineAsyncComponent(
  () => import('~/components/content/diff/BlockItemDiff.vue')
)

interface DiffChange {
  type: 'added' | 'removed' | 'changed'
  path: string
  oldValue?: unknown
  newValue?: unknown
  fieldType?: string | null
  children?: DiffChange[]
}

export function isRichTextChange(change: DiffChange): boolean {
  // For a changed entry both sides must be docs — a doc↔scalar transition
  // falls back to the two-column view so neither side is dropped.
  if (change.type === 'changed') {
    return isRichTextDoc(change.oldValue) && isRichTextDoc(change.newValue)
  }
  return isRichTextDoc(change.oldValue) || isRichTextDoc(change.newValue)
}

export function resolveDiffComponent(change: DiffChange): Component | undefined {
  switch (change.fieldType) {
    case 'text':
    case 'textarea':
    case 'markdown':
      return change.type === 'changed' ? InlineTextDiff : undefined
    case 'link':
    case 'geo':
    case 'price':
    case 'meta':
      return KeyValueDiff
    case 'block':
      return BlockItemDiff
    case 'options':
    case 'references':
      return ListDiff
    case 'asset':
    case 'multi_assets':
      return AssetDiff
    case 'table':
      return TableDiff
    default:
      // richtext plus structural detection for diffs without type metadata
      return isRichTextChange(change) ? RichTextDiff : undefined
  }
}

export type { DiffChange }
