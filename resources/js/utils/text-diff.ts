import { diffWordsWithSpace } from 'diff'

interface DiffSegment {
  type: 'equal' | 'added' | 'removed'
  text: string
}

export function diffTextSegments(oldText: string, newText: string): DiffSegment[] {
  return diffWordsWithSpace(oldText, newText).map((part) => ({
    type: part.added ? 'added' : part.removed ? 'removed' : 'equal',
    text: part.value,
  }))
}

export type { DiffSegment }
