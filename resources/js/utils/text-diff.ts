import { diffWordsWithSpace } from 'diff'

interface DiffSegment {
  type: 'equal' | 'added' | 'removed'
  text: string
}

export function toDisplayText(value: unknown): string {
  if (value == null) return ''
  if (typeof value === 'object') {
    try {
      // `undefined` members are dropped by JSON.stringify, which would render
      // `{ a: undefined }` and `{}` identically and report "no change".
      // Arrays already serialise a hole as `null`, so do the same for objects.
      return JSON.stringify(value, (_key, member) => (member === undefined ? null : member)) ?? ''
    } catch {
      // Circular (or otherwise unserialisable) content must not throw out of a
      // display formatter.
      return String(value)
    }
  }
  return String(value)
}

export function diffTextSegments(oldText: string, newText: string): DiffSegment[] {
  return diffWordsWithSpace(oldText, newText).map((part) => ({
    type: part.added ? 'added' : part.removed ? 'removed' : 'equal',
    text: part.value,
  }))
}

export type { DiffSegment }
