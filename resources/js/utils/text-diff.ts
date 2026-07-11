import { diffWordsWithSpace } from 'diff'

interface DiffSegment {
  type: 'equal' | 'added' | 'removed'
  text: string
}

export function toDisplayText(value: unknown): string {
  if (value == null) return ''
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

export function diffTextSegments(oldText: string, newText: string): DiffSegment[] {
  return diffWordsWithSpace(oldText, newText).map((part) => ({
    type: part.added ? 'added' : part.removed ? 'removed' : 'equal',
    text: part.value,
  }))
}

export type { DiffSegment }
