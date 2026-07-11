import { diffArrays, diffWordsWithSpace } from 'diff'

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
}

interface DiffSegment {
  type: 'equal' | 'added' | 'removed'
  text: string
}

type DiffBlockKind = 'unchanged' | 'added' | 'removed' | 'changed'

interface DiffBlock {
  kind: DiffBlockKind
  label?: string
  segments: DiffSegment[]
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

function collectBlocks(nodes: RteNode[] | undefined, blocks: TextBlock[], prefix = ''): void {
  if (!nodes) return

  for (const [index, node] of nodes.entries()) {
    switch (node.type) {
      case 'paragraph':
      case 'codeBlock':
        blocks.push({ text: prefix + inlineText(node.content) })
        break
      case 'heading':
        blocks.push({ label: `H${node.attrs?.level ?? 1}`, text: prefix + inlineText(node.content) })
        break
      case 'bulletList':
        collectBlocks(node.content, blocks, prefix + '• ')
        break
      case 'orderedList':
        collectBlocks(node.content, blocks, prefix)
        break
      case 'listItem':
        collectBlocks(node.content, blocks, prefix || `${index + 1}. `)
        break
      case 'blockquote':
        collectBlocks(node.content, blocks, prefix + '> ')
        break
      case 'horizontalRule':
        blocks.push({ text: '―――' })
        break
      case 'table':
      case 'tableRow':
        if (node.type === 'tableRow') {
          blocks.push({
            text: (node.content ?? []).map((cell) => inlineText(cell.content)).join(' | '),
          })
        } else {
          collectBlocks(node.content, blocks, prefix)
        }
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
  const kindMap: Record<DiffSegment['type'], DiffBlockKind> = {
    equal: 'unchanged',
    added: 'added',
    removed: 'removed',
  }
  return { kind: kindMap[type], label: block.label, segments: [{ type, text: block.text }] }
}

function wordDiff(oldBlock: TextBlock, newBlock: TextBlock): { block: DiffBlock; similarity: number } {
  const parts = diffWordsWithSpace(oldBlock.text, newBlock.text)
  const segments: DiffSegment[] = parts.map((part) => ({
    type: part.added ? 'added' : part.removed ? 'removed' : 'equal',
    text: part.value,
  }))

  const equalLength = parts
    .filter((part) => !part.added && !part.removed)
    .reduce((sum, part) => sum + part.value.length, 0)
  const totalLength = oldBlock.text.length + newBlock.text.length
  const similarity = totalLength === 0 ? 1 : (equalLength * 2) / totalLength

  return { block: { kind: 'changed', label: newBlock.label, segments }, similarity }
}

/**
 * Pair each removed block with the added block at the same offset and
 * word-diff the pair, unless the two texts are too dissimilar to be an
 * edit of the same block.
 */
function mergeRuns(removed: TextBlock[], added: TextBlock[], result: DiffBlock[]): void {
  const pairCount = Math.min(removed.length, added.length)

  for (let i = 0; i < pairCount; i++) {
    const { block, similarity } = wordDiff(removed[i], added[i])
    if (similarity >= PAIR_SIMILARITY_THRESHOLD) {
      result.push(block)
    } else {
      result.push(toSegments(removed[i], 'removed'), toSegments(added[i], 'added'))
    }
  }

  for (const block of removed.slice(pairCount)) result.push(toSegments(block, 'removed'))
  for (const block of added.slice(pairCount)) result.push(toSegments(block, 'added'))
}

export function diffRichText(oldValue: unknown, newValue: unknown): DiffBlock[] {
  const oldBlocks = docToBlocks(isRichTextDoc(oldValue) ? oldValue : null)
  const newBlocks = docToBlocks(isRichTextDoc(newValue) ? newValue : null)

  const parts = diffArrays(oldBlocks, newBlocks, {
    comparator: (a, b) => a.text === b.text && a.label === b.label,
  })

  const result: DiffBlock[] = []
  let pendingRemoved: TextBlock[] = []

  for (const part of parts) {
    if (part.removed) {
      pendingRemoved.push(...part.value)
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
