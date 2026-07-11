import { diffArrays } from 'diff'
import { diffTextSegments, type DiffSegment } from '~/utils/text-diff'

interface RteNode {
  type: string
  text?: string
  attrs?: Record<string, unknown>
  content?: RteNode[]
}

interface RteDoc extends RteNode {
  type: 'doc'
}

interface TextBlock {
  label?: string
  text: string
  /** Full node JSON, so formatting-only changes (marks, attrs) are detectable. */
  sig: string
}

type DiffBlockKind = 'unchanged' | 'added' | 'removed' | 'changed'

interface DiffBlock {
  kind: DiffBlockKind
  label?: string
  segments: DiffSegment[]
  /** Text is identical but marks/attrs differ (e.g. bold added, link retargeted). */
  formattingOnly?: boolean
}

/** Word-diff a removed/added block pair only if they share this much text. */
const PAIR_SIMILARITY_THRESHOLD = 0.25

export function isRichTextDoc(value: unknown): value is RteDoc {
  return (
    typeof value === 'object' &&
    value !== null &&
    (value as RteNode).type === 'doc' &&
    'content' in value
  )
}

function inlineText(nodes: RteNode[] | undefined): string {
  if (!nodes) return ''
  return nodes
    .map((node) => {
      switch (node.type) {
        case 'text':
          return node.text ?? ''
        case 'hardBreak':
          return '\n'
        case 'placeholderToken':
          return `{${node.attrs?.key ?? ''}}`
        case 'aiMention':
          return `@${node.attrs?.label ?? node.attrs?.id ?? ''}`
        default:
          return inlineText(node.content)
      }
    })
    .join('')
}

function pushBlock(blocks: TextBlock[], node: RteNode, text: string, label?: string): void {
  blocks.push({ label, text, sig: JSON.stringify(node) })
}

function collectBlocks(nodes: RteNode[] | undefined, blocks: TextBlock[], prefix = ''): void {
  if (!nodes) return

  for (const node of nodes) {
    switch (node.type) {
      case 'paragraph':
      case 'codeBlock':
        pushBlock(blocks, node, prefix + inlineText(node.content))
        break
      case 'heading':
        pushBlock(blocks, node, prefix + inlineText(node.content), `H${node.attrs?.level ?? 1}`)
        break
      case 'bulletList':
        for (const item of node.content ?? []) {
          collectBlocks(item.content, blocks, prefix + '• ')
        }
        break
      case 'orderedList': {
        const start = Number(node.attrs?.start ?? 1)
        for (const [itemIndex, item] of (node.content ?? []).entries()) {
          collectBlocks(item.content, blocks, `${prefix}${start + itemIndex}. `)
        }
        break
      }
      case 'blockquote':
        collectBlocks(node.content, blocks, prefix + '> ')
        break
      case 'horizontalRule':
        pushBlock(blocks, node, '―――')
        break
      case 'tableRow':
        pushBlock(blocks, node, (node.content ?? []).map((cell) => inlineText(cell.content)).join(' | '))
        break
      default:
        collectBlocks(node.content, blocks, prefix)
    }
  }
}

function docToBlocks(doc: RteDoc | null): TextBlock[] {
  const blocks: TextBlock[] = []
  if (doc) collectBlocks(doc.content, blocks)
  return blocks
}

function toSegments(block: TextBlock, type: DiffSegment['type']): DiffBlock {
  const kind = type === 'equal' ? 'unchanged' : type
  return { kind, label: block.label, segments: [{ type, text: block.text }] }
}

function wordDiff(oldBlock: TextBlock, newBlock: TextBlock): { block: DiffBlock; similarity: number } {
  const segments = diffTextSegments(oldBlock.text, newBlock.text)

  const equalLength = segments
    .filter((segment) => segment.type === 'equal')
    .reduce((sum, segment) => sum + segment.text.length, 0)
  const totalLength = oldBlock.text.length + newBlock.text.length
  const similarity = totalLength === 0 ? 1 : (equalLength * 2) / totalLength
  const formattingOnly = segments.every((segment) => segment.type === 'equal')

  return {
    block: { kind: 'changed', label: newBlock.label, segments, formattingOnly },
    similarity,
  }
}

/**
 * Merge a removed run and an added run into changed/removed/added blocks.
 * Pairs advance through both runs in order, with a one-block lookahead on
 * each side so a deletion or insertion inside the run doesn't shift every
 * later block onto the wrong partner. Dissimilar pairs stay separate
 * removed/added blocks instead of degenerating into word soup.
 */
function mergeRuns(removed: TextBlock[], added: TextBlock[], result: DiffBlock[]): void {
  let i = 0
  let j = 0

  while (i < removed.length && j < added.length) {
    const current = wordDiff(removed[i], added[j])
    const nextRemoved = i + 1 < removed.length ? wordDiff(removed[i + 1], added[j]) : null
    const nextAdded = j + 1 < added.length ? wordDiff(removed[i], added[j + 1]) : null

    if (
      nextRemoved &&
      nextRemoved.similarity > current.similarity &&
      nextRemoved.similarity >= PAIR_SIMILARITY_THRESHOLD &&
      nextRemoved.similarity >= (nextAdded?.similarity ?? -1)
    ) {
      result.push(toSegments(removed[i], 'removed'))
      i++
      continue
    }

    if (nextAdded && nextAdded.similarity > current.similarity && nextAdded.similarity >= PAIR_SIMILARITY_THRESHOLD) {
      result.push(toSegments(added[j], 'added'))
      j++
      continue
    }

    if (current.similarity >= PAIR_SIMILARITY_THRESHOLD) {
      result.push(current.block)
    } else {
      result.push(toSegments(removed[i], 'removed'), toSegments(added[j], 'added'))
    }
    i++
    j++
  }

  for (; i < removed.length; i++) result.push(toSegments(removed[i], 'removed'))
  for (; j < added.length; j++) result.push(toSegments(added[j], 'added'))
}

export function diffRichText(oldValue: unknown, newValue: unknown): DiffBlock[] {
  const oldBlocks = docToBlocks(isRichTextDoc(oldValue) ? oldValue : null)
  const newBlocks = docToBlocks(isRichTextDoc(newValue) ? newValue : null)

  const parts = diffArrays(oldBlocks, newBlocks, {
    comparator: (a, b) => a.text === b.text && a.label === b.label && a.sig === b.sig,
  })

  const result: DiffBlock[] = []
  let pendingRemoved: TextBlock[] = []

  for (const part of parts) {
    if (part.removed) {
      pendingRemoved = pendingRemoved.concat(part.value)
    } else if (part.added) {
      mergeRuns(pendingRemoved, part.value, result)
      pendingRemoved = []
    } else {
      mergeRuns(pendingRemoved, [], result)
      pendingRemoved = []
      for (const block of part.value) result.push(toSegments(block, 'equal'))
    }
  }
  mergeRuns(pendingRemoved, [], result)

  return result
}

export type { DiffBlock, DiffSegment, RteDoc }
